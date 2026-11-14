<?php

namespace App\Database;

use App\Prediccion\Estadistica;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Capa de datos del módulo de predicción de carga (sólo lectura).
 *
 * Todo lo que el motor necesita sale de aquí, en pocas consultas por request:
 * la referencia a predecir, los viajes del camión, los pares consecutivos de
 * cada placa (que son la calibración del hueco), las etapas por ruta, los
 * compilados de las dos APIs de tiempos y el catálogo de rutas/plantas.
 *
 * Reglas heredadas del resto del proyecto:
 *  - los hitos anteriores a 2000-01-01 son basura de importación y se ignoran;
 *  - el conductor se toma de la referencia y, si viene vacío, de sus llamadas;
 *  - las tablas compiladas (`tiempos_*`) pueden no existir en el servidor: si
 *    faltan, se devuelve una lista vacía y el motor cae a sus fallbacks.
 */
class DBPrediccion
{
    /** Hitos del ciclo, en el orden en que ocurren. */
    public const HITOS = [
        'compromiso_carga', 'presenta_para_carga', 'inicio_de_carga', 'fin_de_carga',
        'inicio_ruta', 'qr_llegada_destino', 'inicio_descargue', 'fin_descargue',
    ];

    /** Primera fecha válida de un hito (la base trae hitos de 1998). */
    public const FECHA_MINIMA = '2000-01-01';

    /** Tope por etapa, en minutos (mismo valor que el módulo de tiempos). */
    public const TOPE_ETAPA = 2880;

    /** Cachés por request: catálogos que no cambian entre llamadas. */
    private static ?array $rutas = null;

    private static ?array $promedios_ruta = null;

    private static ?array $promedios_planta = null;

    private static array $etapas = [];

    private static array $hitos = [];

    private static ?array $locales = null;

    private static array $ubigeos_texto = [];

    private static array $pares = [];

    /** @var array<string, array{n: int, mediana: ?int}> viaje por placa+ruta (cruce con el compilado) */
    private static array $viajesPlaca = [];

    /** @var array<string, object|null> celdas de `tiempos_ruta_horario` ya leídas */
    private static array $horarios = [];

    /** Expresión SQL del último hito con fecha: 1970-01-01 si no hay ninguno. */
    public static function ultimo_hito_sql(string $alias = 'r'): string
    {
        $partes = [];
        foreach (self::HITOS as $hito) {
            $partes[] = "COALESCE($alias.$hito, '1970-01-01')";
        }

        return 'GREATEST(' . implode(', ', $partes) . ')';
    }

    /**
     * Nombre del hito más reciente de una fila de `referencias` (o NULL).
     *
     * `$ignorar` deja afuera hitos que no son eventos del camión: el
     * `compromiso_carga` es una cita planificada, no algo que haya pasado, y
     * puede quedar con fecha posterior a la presentación real (pasa en ~2 % de
     * las refs). Para anclar la predicción no sirve: se busca el último evento
     * de verdad.
     *
     * @param  array<int, string>  $ignorar
     */
    public static function hito_mas_reciente($fila, array $ignorar = []): ?string
    {
        $mejor = null;
        $mejorTs = null;

        foreach (self::HITOS as $hito) {
            if (in_array($hito, $ignorar, true)) {
                continue;
            }
            $valor = is_object($fila) ? ($fila->{$hito} ?? null) : ($fila[$hito] ?? null);
            if ($valor === null || $valor === '' || (string) $valor < self::FECHA_MINIMA) {
                continue;
            }
            $ts = strtotime((string) $valor);
            if ($ts === false) {
                continue;
            }
            if ($mejorTs === null || $ts > $mejorTs) {
                $mejorTs = $ts;
                $mejor = $hito;
            }
        }

        return $mejor;
    }

    //------------------- referencias -------------------

    /** Una referencia con su ruta, nombres y el conductor efectivo. */
    public static function ref(string $ref): ?object
    {
        $fila = DB::selectOne(
            "SELECT r.*, " . self::ultimo_hito_sql('r') . " AS ultimo_hito_ts,
                    ru.origen_id, ru.destino_id, ru.ubigeo_origen, ru.ubigeo_destino,
                    lo.nombre AS origen_nombre, ld.nombre AS destino_nombre,
                    co.nombres AS conductor_nombre, tr.nombres AS trt,
                    COALESCE(r.conductor_id, lc.conductor_id) AS conductor_clave
             FROM referencias r
             LEFT JOIN rutas ru ON ru.id = r.ruta_id
             LEFT JOIN locales lo ON lo.id = ru.origen_id
             LEFT JOIN locales ld ON ld.id = ru.destino_id
             -- la derivada se resuelve por PRIMARY/idx_llamadas_ref con UNA ref:
             -- agrupar toda la tabla costaba ~370 ms por llamada
             LEFT JOIN (
                 SELECT MAX(conductor_id) AS conductor_id FROM llamadas WHERE ref = ?
             ) lc ON 1 = 1
             LEFT JOIN conductores co ON co.id = COALESCE(r.conductor_id, lc.conductor_id)
             LEFT JOIN trts tr ON tr.id = r.trt_id
             WHERE r.ref = ?
             LIMIT 1",
            [$ref, $ref]
        );

        return $fila ?: null;
    }

    /**
     * Viajes de una placa con algún hito, del más reciente al más viejo: son los
     * candidatos a "último viaje" que el usuario puede elegir como ancla.
     *
     * El conductor sale de la propia referencia (sin el fallback de `llamadas`):
     * acá sólo se lista para elegir el ancla, y ese fallback obligaba a agrupar
     * las 40k llamadas en cada consulta.
     *
     * @return array<int, object>
     */
    public static function refs_por_placa(string $placa, ?Carbon $antes = null, int $limite = 50): array
    {
        $ultimo = self::ultimo_hito_sql('r');
        $sql = "SELECT r.*, $ultimo AS ultimo_hito_ts,
                    ru.origen_id, ru.destino_id, ru.ubigeo_origen, ru.ubigeo_destino,
                    lo.nombre AS origen_nombre, ld.nombre AS destino_nombre,
                    r.conductor_id AS conductor_clave,
                    co.nombres AS conductor_nombre
                FROM referencias r
                LEFT JOIN rutas ru ON ru.id = r.ruta_id
                LEFT JOIN locales lo ON lo.id = ru.origen_id
                LEFT JOIN locales ld ON ld.id = ru.destino_id
                LEFT JOIN conductores co ON co.id = r.conductor_id
                WHERE r.placa = ? AND $ultimo > ?";
        $params = [$placa, self::FECHA_MINIMA];

        if ($antes !== null) {
            $sql .= ' AND ' . $ultimo . ' <= ?';
            $params[] = $antes->toDateTimeString();
        }

        $sql .= ' ORDER BY ultimo_hito_ts DESC LIMIT ' . max(1, min(200, $limite));

        return DB::select($sql, $params);
    }

    //------------------- pares (calibración y tabla de aciertos) -------------------

    /**
     * Pares consecutivos de la misma placa: el `fin_descargue` del viaje anterior
     * y el `presenta_para_carga` del siguiente. Es el insumo del calibrador y de
     * la tabla de aciertos.
     *
     * El LAG se calcula sobre toda la historia y el rango de fechas se aplica
     * después, para que la primera referencia del rango también tenga su ancla.
     *
     * El conductor sale de la propia referencia: el fallback por `llamadas`
     * obligaba a agrupar 40k filas en la consulta más pesada del módulo.
     *
     * @return array<int, object> objetos con ancla, presenta, ruta_ancla, ruta_siguiente, entidad,
     *                            hueco_min y los locales/ubigeos del par (para saber si quedó cerca)
     */
    public static function pares(?string $excluirRef, Carbon $desde, Carbon $hasta, ?string $placa = null,
                                 ?int $conductorId = null, int $limite = 20000): array
    {
        $sql = "SELECT x.*,
                       rap.destino_id AS ancla_destino, rap.ubigeo_destino AS ancla_ubigeo,
                       rsi.origen_id AS carga_origen, rsi.ubigeo_origen AS carga_ubigeo
                FROM (
                    SELECT r.ref, r.placa, r.conductor_id AS conductor_clave,
                           r.ruta_id, r.presenta_para_carga,
                           LAG(r.ruta_id) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS ruta_ancla,
                           LAG(r.ref) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS ref_ancla,
                           LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS fin_ancla
                    FROM referencias r
                    WHERE r.placa <> '' AND r.presenta_para_carga >= ?
                ) x
                -- de dónde vuelve el camión y dónde carga: la cercanía entre esos dos
                -- puntos es lo que hace más probable que cargue enseguida
                LEFT JOIN rutas rap ON rap.id = x.ruta_ancla
                LEFT JOIN rutas rsi ON rsi.id = x.ruta_id
                WHERE x.presenta_para_carga BETWEEN ? AND ? AND x.fin_ancla IS NOT NULL";
        $params = [self::FECHA_MINIMA, $desde->toDateTimeString(), $hasta->toDateTimeString()];

        if ($placa !== null && $placa !== '') {
            $sql .= ' AND x.placa = ?';
            $params[] = $placa;
        }
        if ($conductorId !== null) {
            $sql .= ' AND x.conductor_clave = ?';
            $params[] = $conductorId;
        }
        if ($excluirRef !== null && $excluirRef !== '') {
            $sql .= ' AND x.ref <> ? AND x.ref_ancla <> ?';
            $params[] = $excluirRef;
            $params[] = $excluirRef;
        }

        $sql .= ' ORDER BY x.presenta_para_carga DESC LIMIT ' . max(1, $limite);

        $salida = [];
        foreach (DB::select($sql, $params) as $fila) {
            $salida[] = (object) [
                'ref' => (string) $fila->ref,
                'ref_ancla' => (string) $fila->ref_ancla,
                'placa' => (string) $fila->placa,
                'conductor' => $fila->conductor_clave !== null ? (int) $fila->conductor_clave : null,
                'ancla' => Carbon::parse($fila->fin_ancla),
                'presenta' => Carbon::parse($fila->presenta_para_carga),
                'ruta_ancla' => $fila->ruta_ancla,
                'ruta_siguiente' => $fila->ruta_id,
                'entidad' => (string) $fila->placa,
                'hueco_min' => (int) round((strtotime($fila->presenta_para_carga) - strtotime($fila->fin_ancla)) / 60),
                'ancla_destino' => (string) ($fila->ancla_destino ?? ''),
                'ancla_ubigeo' => (string) ($fila->ancla_ubigeo ?? ''),
                'carga_origen' => (string) ($fila->carga_origen ?? ''),
                'carga_ubigeo' => (string) ($fila->carga_ubigeo ?? ''),
            ];
        }

        return $salida;
    }

    //------------------- régimen "cerca" de la flota -------------------

    /**
     * Huecos de los viajes en que el camión quedó CERCA de la planta donde
     * volvió a cargar (mismo local o mismo distrito), de toda la flota.
     *
     * Es el régimen que usa el motor cuando la placa/chofer no tienen suficientes
     * pares propios: quedando cerca, la flota carga mucho más seguido. Se
     * devuelve el resumen global y el de cada conductor (con muestra).
     *
     * @return array{global: array|null, conductores: array<string, array>}
     */
    public static function resumen_cerca(Carbon $desde, Carbon $hasta, int $limite = 8000): array
    {
        $vacio = ['global' => null, 'conductores' => []];

        try {
            $filas = DB::select("
                SELECT x.conductor_clave, x.hueco
                FROM (
                    SELECT r.placa, r.conductor_id AS conductor_clave, r.ruta_id,
                           LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS fin_ancla,
                           LAG(r.ruta_id) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS ruta_ancla,
                           r.presenta_para_carga,
                           TIMESTAMPDIFF(MINUTE,
                               LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga),
                               r.presenta_para_carga) AS hueco
                    FROM referencias r
                    WHERE r.placa <> '' AND r.presenta_para_carga >= ?
                ) x
                LEFT JOIN rutas rap ON rap.id = x.ruta_ancla
                LEFT JOIN rutas rsi ON rsi.id = x.ruta_id
                WHERE x.presenta_para_carga BETWEEN ? AND ?
                  AND x.fin_ancla IS NOT NULL
                  AND x.hueco > 0 AND x.hueco <= ?
                  AND (
                        (rap.destino_id IS NOT NULL AND rap.destino_id <> '' AND rap.destino_id = rsi.origen_id)
                     OR (rap.ubigeo_destino IS NOT NULL AND rap.ubigeo_destino <> ''
                         AND rap.ubigeo_destino = rsi.ubigeo_origen)
                  )
                ORDER BY x.presenta_para_carga DESC
                LIMIT " . max(1, $limite),
                [$desde->toDateTimeString(), $desde->toDateTimeString(), $hasta->toDateTimeString(),
                 \App\Prediccion\Calibrador::TOPE_HUECO]
            );
        } catch (\Throwable $e) {
            return $vacio;
        }

        $todos = [];
        $porConductor = [];
        foreach ($filas as $fila) {
            $hueco = (int) $fila->hueco;
            $todos[] = $hueco;
            if ($fila->conductor_clave !== null) {
                $porConductor[(string) $fila->conductor_clave][] = $hueco;
            }
        }

        $resumen = fn (array $huecos) => $huecos === [] ? null : [
            'n' => count($huecos),
            'base_min' => (int) round((float) \App\Prediccion\Estadistica::mediana($huecos)),
            'p25_min' => (int) round((float) \App\Prediccion\Estadistica::cuantil($huecos, 0.25)),
            'p75_min' => (int) round((float) \App\Prediccion\Estadistica::cuantil($huecos, 0.75)),
        ];

        $conductores = [];
        foreach ($porConductor as $id => $huecos) {
            if (count($huecos) >= 3) {
                $conductores[$id] = $resumen($huecos);
            }
        }

        return ['global' => $resumen($todos), 'conductores' => $conductores];
    }

    //------------------- promedios y etapas -------------------

    /**
     * Etapas E2..E6 por ruta.
     *
     * EL VIAJE (e4) SALE DEL COMPILADO del módulo de tiempos
     * (`tiempos_ruta_promedio`, lo que sirve `/api/tiempos/promedio`) cuando hay
     * fila: es el número que el usuario ve en esa pantalla, calculado con topes y
     * descarte de viajes raros. Sin compilado, se usa la mediana de `referencias`.
     *
     * El resto de las etapas (e2, e3, e5, e6) se calculan desde `referencias` y se
     * resumen con la MEDIANA, no con el promedio: el promedio lo mueven los casos
     * raros y acá los casos raros son enormes (la cola en el destino, e5, de la
     * ruta CARAL → HUANCAVELICA tiene viajes de 17-22 h que subían el promedio a
     * 264 min cuando la mediana es 147). Las negativas y las que pasan el tope se
     * descartan, igual que en el módulo de tiempos.
     *
     * @return array<string, array{e2: ?int, e3: ?int, e4: ?int, e5: ?int, e6: ?int,
     *                              e4_p75?: ?int, e5_p75?: ?int, e6_p75?: ?int, ciclo: ?int, n: int}>
     */
    public static function etapas_por_ruta(Carbon $desde, Carbon $hasta): array
    {
        $llave = $desde->toDateString() . '|' . $hasta->toDateString();
        if (isset(self::$etapas[$llave])) {
            return self::$etapas[$llave];
        }

        $tope = self::TOPE_ETAPA;
        $etapasClaves = ['e2', 'e3', 'e4', 'e5', 'e6'];

        // una fila por viaje: hace falta para la mediana de cada etapa
        $filas = DB::select("
            SELECT r.ruta_id,
                   TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga) AS e2,
                   TIMESTAMPDIFF(MINUTE, r.inicio_de_carga, r.inicio_ruta) AS e3,
                   TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) AS e4,
                   TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue) AS e5,
                   TIMESTAMPDIFF(MINUTE, r.inicio_descargue, r.fin_descargue) AS e6
            FROM referencias r
            WHERE r.ruta_id IS NOT NULL AND r.ruta_id <> ''
              AND r.presenta_para_carga BETWEEN ? AND ?
        ", [$desde->toDateTimeString(), $hasta->toDateTimeString()]);

        $porRuta = [];
        foreach ($filas as $fila) {
            $ruta = (string) $fila->ruta_id;
            $porRuta[$ruta] = $porRuta[$ruta] ?? ['n' => 0, 'valores' => array_fill_keys($etapasClaves, [])];
            $porRuta[$ruta]['n']++;

            foreach ($etapasClaves as $clave) {
                $valor = $fila->{$clave};
                if ($valor === null) {
                    continue;
                }
                $valor = (int) $valor;
                if ($valor < 0 || $valor > $tope) {
                    continue;
                }
                $porRuta[$ruta]['valores'][$clave][] = $valor;
            }
        }

        $salida = [];
        foreach ($porRuta as $ruta => $datos) {
            $etapas = ['n' => (int) $datos['n'], 'ciclo' => null];

            foreach ($etapasClaves as $clave) {
                $lista = $datos['valores'][$clave];
                $etapas[$clave] = $lista === []
                    ? null
                    : (int) round((float) \App\Prediccion\Estadistica::mediana($lista));
            }

            $salida[$ruta] = $etapas;
        }

        // El VIAJE (e4) se toma del compilado del módulo de tiempos
        // (`tiempos_ruta_promedio`, lo que sirve /api/tiempos/promedio) cuando
        // existe: es el numero que ve el usuario en esa pantalla, calculado con
        // topes y descarte de viajes raros. Sin compilado queda la mediana de refs.
        $compilados = self::promedios_ruta();
        foreach ($salida as $ruta => $etapas) {
            $fila = $compilados[(string) $ruta] ?? null;
            if ($fila === null || (int) ($fila->viajes ?? 0) < 3) {
                continue;
            }

            $viaje = $fila->mediana_min ?? null;
            if ($viaje === null || (float) $viaje <= 0) {
                $viaje = $fila->prom_min ?? null;
            }
            if ($viaje === null || (float) $viaje <= 0) {
                continue;
            }

            $salida[$ruta]['e4'] = (int) round((float) $viaje);

            // p75 del viaje: es el techo que usa el motor cuando la mediana de la
            // etapa ya se consumió (el camión va con retraso, no "ya llegó").
            $p75 = $fila->p75_min ?? null;
            if ($p75 !== null && (float) $p75 > 0) {
                $salida[$ruta]['e4_p75'] = (int) round((float) $p75);
            }
        }

        // La COLA Y EL DESCARGUE (e5 + e6) salen del compilado por PLANTA
        // (`tiempos_planta_promedio`, lo que sirve /api/tiempos/promedio/plantas)
        // cuando la ruta termina en una planta del catalogo: `prom_fuera_descarga`
        // es la cola y `prom_dentro_descarga` el descargue, con la muestra de
        // todas las rutas que entran a esa planta.
        $plantas = self::promedios_planta();
        $catalogoRutas = self::rutas();
        foreach ($salida as $ruta => $etapas) {
            $destino = (string) ($catalogoRutas[(string) $ruta]->destino_id ?? '');
            $planta = $destino !== '' ? ($plantas[$destino] ?? null) : null;
            if ($planta === null) {
                continue;
            }

            $fuera = $planta->prom_fuera_descarga_min ?? null;
            $dentro = $planta->prom_dentro_descarga_min ?? null;
            if ($fuera === null || $dentro === null) {
                continue;
            }

            $salida[$ruta]['e5'] = (int) round((float) $fuera);
            $salida[$ruta]['e6'] = (int) round((float) $dentro);

            // el compilado de planta trae p82 (no p75): es el techo disponible
            $fueraP82 = $planta->p82_fuera_min ?? null;
            $dentroP82 = $planta->p82_dentro_min ?? null;
            if ($fueraP82 !== null && (float) $fueraP82 > 0) {
                $salida[$ruta]['e5_p75'] = (int) round((float) $fueraP82);
            }
            if ($dentroP82 !== null && (float) $dentroP82 > 0) {
                $salida[$ruta]['e6_p75'] = (int) round((float) $dentroP82);
            }
        }

        // ciclo = E2..E6, con las etapas ya definitivas (compilado de ruta + planta)
        foreach ($salida as $ruta => $etapas) {
            $suma = 0;
            foreach ($etapasClaves as $clave) {
                if ($etapas[$clave] === null) {
                    $suma = null;
                    break;
                }
                $suma += $etapas[$clave];
            }
            $salida[$ruta]['ciclo'] = $suma;
        }

        return self::$etapas[$llave] = $salida;
    }

    /**
     * El viaje (e4) compilado POR HORA de salida: `tiempos_ruta_horario`.
     *
     * El módulo de tiempos compila cada ruta por día de la semana y hora de
     * `inicio_ruta` (MySQL WEEKDAY: 0 = lunes) y ahí está la demora de la hora
     * pico, que la mediana diaria del compilado no tiene: en
     * RUT_CARAL-BABEL_MAXO el martes a las 04 h el promedio es 358 min y a las
     * 23 h 233. Es el mismo dato que sirve `/api/tiempos/promedio/horario`.
     *
     * Devuelve null si la tabla no existe (servidor sin compilar), si la ruta no
     * tiene esa celda o si la celda tiene menos viajes que `$minViajes`.
     *
     * @return object{min: int, viajes: int, dia: int, hora: int}|null
     */
    public static function viaje_horario(string $ruta, Carbon $fecha, int $minViajes = 3): ?object
    {
        $ruta = trim($ruta);
        if ($ruta === '') {
            return null;
        }

        $dia = (int) $fecha->format('N') - 1;   // MySQL WEEKDAY: 0 = lunes
        $hora = (int) $fecha->format('G');
        $llave = $ruta . '|' . $dia . '|' . $hora . '|' . $minViajes;

        if (array_key_exists($llave, self::$horarios)) {
            return self::$horarios[$llave];
        }

        try {
            $fila = DB::table('tiempos_ruta_horario')
                ->where('ruta_id', $ruta)
                ->where('dia', $dia)
                ->where('hora', $hora)
                ->first(['viajes', 'prom_min']);
        } catch (\Throwable $e) {
            return self::$horarios[$llave] = null;   // tabla sin crear todavía
        }

        if ($fila === null || (float) ($fila->prom_min ?? 0) <= 0
            || (int) ($fila->viajes ?? 0) < max(1, $minViajes)) {
            return self::$horarios[$llave] = null;
        }

        return self::$horarios[$llave] = (object) [
            'min' => (int) round((float) $fila->prom_min),
            'viajes' => (int) $fila->viajes,
            'dia' => $dia,
            'hora' => $hora,
        ];
    }

    /**
     * Viaje (e4) que hizo UNA placa en UNA ruta: mediana y cantidad de viajes.
     *
     * Es la mitad "entidad" del cruce con el compilado de la ruta. Memoizado por
     * request: el backtest pide la misma placa/ruta muchas veces.
     *
     * @return array{n: int, mediana: ?int}
     */
    public static function viaje_placa(string $placa, string $ruta, Carbon $desde, Carbon $hasta): array
    {
        $placa = trim($placa);
        $ruta = trim($ruta);
        $vacio = ['n' => 0, 'mediana' => null];

        if ($placa === '' || $ruta === '') {
            return $vacio;
        }

        $llave = $placa . '|' . $ruta . '|' . $desde->toDateTimeString() . '|' . $hasta->toDateTimeString();
        if (isset(self::$viajesPlaca[$llave])) {
            return self::$viajesPlaca[$llave];
        }

        try {
            $filas = DB::select(
                "SELECT TIMESTAMPDIFF(MINUTE, inicio_ruta, qr_llegada_destino) AS e4
                 FROM referencias
                 WHERE placa = ? AND ruta_id = ? AND presenta_para_carga BETWEEN ? AND ?
                   AND inicio_ruta IS NOT NULL AND qr_llegada_destino IS NOT NULL",
                [$placa, $ruta, $desde->toDateTimeString(), $hasta->toDateTimeString()]
            );
        } catch (\Throwable $e) {
            return self::$viajesPlaca[$llave] = $vacio;
        }

        $valores = [];
        foreach ($filas as $fila) {
            $valor = (int) $fila->e4;
            if ($valor >= 0 && $valor <= self::TOPE_ETAPA) {
                $valores[] = $valor;
            }
        }

        return self::$viajesPlaca[$llave] = [
            'n' => count($valores),
            'mediana' => $valores === []
                ? null
                : (int) round((float) \App\Prediccion\Estadistica::mediana($valores)),
        ];
    }

    /**
     * Filas compiladas de `tiempos_ruta_promedio` (lo que sirve el API
     * `/api/tiempos/promedio`), indexadas por ruta.
     *
     * @return array<string, object>
     */
    public static function promedios_ruta(): array
    {
        if (self::$promedios_ruta !== null) {
            return self::$promedios_ruta;
        }

        try {
            $filas = DB::select("SELECT ruta_id, origen_id, destino_id, modo, viajes,
                    prom_min, prom_ciclo_min, prom_fuera_min, prom_dentro_min, prom_fusion_min,
                    mediana_min, p25_min, p75_min, desv_min, actualizado_en
                FROM tiempos_ruta_promedio");
        } catch (\Throwable $e) {
            return self::$promedios_ruta = [];
        }

        $salida = [];
        foreach ($filas as $fila) {
            $salida[(string) $fila->ruta_id] = $fila;
        }

        return self::$promedios_ruta = $salida;
    }

    /**
     * Filas compiladas de `tiempos_planta_promedio` (lo que sirve el API
     * `/api/tiempos/promedio/plantas`), indexadas por planta.
     *
     * @return array<string, object>
     */
    public static function promedios_planta(): array
    {
        if (self::$promedios_planta !== null) {
            return self::$promedios_planta;
        }

        try {
            $filas = DB::select("SELECT planta_id, nombre, ubigeo, departamento, viajes,
                    prom_fuera_carga_min, prom_dentro_carga_min, prom_fuera_descarga_min,
                    prom_dentro_descarga_min, prom_fuera_min, prom_dentro_min, prom_espera_min
                FROM tiempos_planta_promedio");
        } catch (\Throwable $e) {
            return self::$promedios_planta = [];
        }

        $salida = [];
        foreach ($filas as $fila) {
            $salida[(string) $fila->planta_id] = $fila;
        }

        return self::$promedios_planta = $salida;
    }

    /** Catálogo de rutas: id => fila con origen/destino locales y ubigeos. */
    public static function rutas(): array
    {
        if (self::$rutas !== null) {
            return self::$rutas;
        }

        try {
            $filas = DB::select('SELECT id, origen_id, destino_id, ubigeo_origen, ubigeo_destino FROM rutas');
        } catch (\Throwable $e) {
            return self::$rutas = [];
        }

        $salida = [];
        foreach ($filas as $fila) {
            $salida[(string) $fila->id] = $fila;
        }

        return self::$rutas = $salida;
    }

    //------------------- traslado y espera -------------------

    /**
     * Traslado estimado desde la posición del camión hasta la planta donde tiene
     * que cargar, con la jerarquía del diseño:
     *
     *   1. la ruta medida entre esos dos locales (en cualquier sentido);
     *   2. el mismo par de ubigeos;
     *   3. la ruta de la propia referencia (el regreso se parece a la ida);
     *   4. Geoapify: tiempo de manejo real entre los dos ubigeos (cuando no hay
     *      promedio medido, o cuando el operador declaró dónde está el camión);
     *   5. la mediana de las rutas con ese par de departamentos;
     *   6. el valor por defecto de la configuración.
     *
     * Todas las respuestas traen `min` (minutos), `fuente`, `ruta` y `texto`
     * (legible: "PLANTA CARAL (Supe, Barranca, Lima) → PLANTA CHICLAYO (...)").
     * Geoapify agrega `km`.
     *
     * @param  array<string, array<string, int|null>>  $etapas  salida de etapas_por_ruta()
     */
    public static function traslado(?string $plantaCarga, ?string $desdePlanta, ?string $desdeUbigeo,
                                    ?string $plantaUbigeo, array $etapas, ?string $rutaPropia = null,
                                    ?int $defectoMin = null, bool $posicionDeclarada = false,
                                    ?Carbon $salida = null): ?object
    {
        if ($plantaCarga === null || $plantaCarga === '') {
            return null;
        }

        $desdeTexto = self::texto_ubicacion($desdePlanta, $desdeUbigeo);
        $hastaTexto = self::texto_ubicacion($plantaCarga, $plantaUbigeo);
        $texto = ($desdeTexto !== '' ? $desdeTexto : 'la posición del camión')
            . ' → ' . ($hastaTexto !== '' ? $hastaTexto : 'la planta de carga');

        if ($desdePlanta === $plantaCarga || ($desdeUbigeo !== null && $desdeUbigeo === $plantaUbigeo && $desdeUbigeo !== '')) {
            return (object) ['min' => 0, 'fuente' => 'ya_esta_en_planta', 'ruta' => null, 'texto' => $texto, 'km' => null];
        }

        $rutas = self::rutas();
        $compilados = self::promedios_ruta();
        $minutos = fn (?string $ruta): ?int => self::minutos_ruta($ruta, $etapas, $compilados);

        // 1..3: lo medido (ruta entre los dos locales, mismo par de ubigeos, la ruta
        // de la propia referencia). Geoapify NO se llama si hay promedio: el dato de
        // la flota siempre manda.
        foreach ($rutas as $id => $ruta) {
            $mismoLocal = ($ruta->origen_id === $plantaCarga && $ruta->destino_id === $desdePlanta)
                || ($ruta->origen_id === $desdePlanta && $ruta->destino_id === $plantaCarga);
            if ($mismoLocal) {
                $min = $minutos((string) $id);
                if ($min !== null) {
                    return (object) ['min' => $min, 'fuente' => 'ruta_medida', 'ruta' => (string) $id,
                                     'texto' => $texto, 'km' => null];
                }
            }
        }

        if ($desdeUbigeo !== null && $desdeUbigeo !== '' && $plantaUbigeo !== null && $plantaUbigeo !== '') {
            foreach ($rutas as $id => $ruta) {
                $mismoUbigeo = ($ruta->ubigeo_origen === $plantaUbigeo && $ruta->ubigeo_destino === $desdeUbigeo)
                    || ($ruta->ubigeo_origen === $desdeUbigeo && $ruta->ubigeo_destino === $plantaUbigeo);
                if ($mismoUbigeo) {
                    $min = $minutos((string) $id);
                    if ($min !== null) {
                        return (object) ['min' => $min, 'fuente' => 'mismo_ubigeo', 'ruta' => (string) $id,
                                         'texto' => $texto, 'km' => null];
                    }
                }
            }
        }

        if ($rutaPropia !== null && !$posicionDeclarada) {
            $min = $minutos($rutaPropia);
            if ($min !== null) {
                return (object) ['min' => $min, 'fuente' => 'ruta_de_la_ref', 'ruta' => $rutaPropia,
                                 'texto' => $texto, 'km' => null];
            }
        }

        // 4: Geoapify. SÓLO acá: no hay ningún promedio compilado para ese par.
        // `$salida` es la hora a la que el camión sale de verdad: si cae en hora
        // pico el tiempo se pide con tráfico (ver App\Prediccion\HoraPico).
        $geo = \App\Prediccion\Geoapify::ruta($desdeUbigeo, $plantaUbigeo, $salida);
        if ($geo !== null) {
            return (object) [
                'min' => max(1, (int) $geo->min),
                'fuente' => 'geoapify',
                'ruta' => null,
                'texto' => $texto,
                'km' => $geo->km,
                'min_medido' => null,
            ];
        }

        // 5: mediana por par de departamentos
        $depOrigen = strlen((string) $plantaUbigeo) >= 2 ? substr((string) $plantaUbigeo, 0, 2) : null;
        $depDestino = strlen((string) $desdeUbigeo) >= 2 ? substr((string) $desdeUbigeo, 0, 2) : null;
        if ($depOrigen !== null && $depDestino !== null) {
            $candidatos = [];
            foreach ($rutas as $id => $ruta) {
                if (substr((string) $ruta->ubigeo_origen, 0, 2) !== $depOrigen
                    || substr((string) $ruta->ubigeo_destino, 0, 2) !== $depDestino) {
                    continue;
                }
                $min = $minutos((string) $id);
                if ($min !== null) {
                    $candidatos[] = $min;
                }
            }
            if ($candidatos !== []) {
                return (object) [
                    'min' => (int) round((float) Estadistica::mediana($candidatos)),
                    'fuente' => 'departamentos',
                    'ruta' => null,
                    'texto' => $texto,
                    'km' => null,
                ];
            }
        }

        // 6: defecto
        return (object) [
            'min' => max(0, (int) ($defectoMin ?? 240)),
            'fuente' => 'defecto',
            'ruta' => null,
            'texto' => $texto,
            'km' => null,
        ];
    }

    /**
     * Nombre legible de una posición: el local si se conoce, con su distrito
     * entre paréntesis cuando se puede resolver. Es lo que aparece en el JSON
     * de la predicción y en la pantalla.
     */
    public static function texto_ubicacion(?string $local, ?string $ubigeo): string
    {
        $local = trim((string) $local);
        $ubigeo = trim((string) $ubigeo);
        $nombreLocal = $local !== '' ? (self::locales()[$local] ?? $local) : '';

        if ($ubigeo !== '' && !isset(self::$ubigeos_texto[$ubigeo])) {
            self::$ubigeos_texto[$ubigeo] = \App\Prediccion\Geoapify::nombre_ubigeo($ubigeo) ?? '';
        }
        $nombreUbigeo = $ubigeo !== '' ? (self::$ubigeos_texto[$ubigeo] ?? '') : '';

        if ($nombreLocal !== '' && $nombreUbigeo !== '' && mb_strtoupper($nombreLocal) !== mb_strtoupper($nombreUbigeo)) {
            return $nombreLocal . ' (' . $nombreUbigeo . ')';
        }

        return $nombreLocal !== '' ? $nombreLocal : $nombreUbigeo;
    }

    /** Catálogo de locales por id (memoizado por request). */
    public static function locales(): array
    {
        if (self::$locales !== null) {
            return self::$locales;
        }

        try {
            $filas = DB::select('SELECT id, nombre FROM locales');
        } catch (\Throwable $e) {
            return self::$locales = [];
        }

        $salida = [];
        foreach ($filas as $fila) {
            $salida[(string) $fila->id] = (string) $fila->nombre;
        }

        return self::$locales = $salida;
    }

    /**
     * Resuelve un TEXTO LIBRE a una ubicación del catálogo: ubigeo, local, o nada.
     *
     * Orden (el primero que da un único candidato gana):
     *   1. ubigeo de 6 dígitos;
     *   2. distrito con ese nombre exacto;
     *   3. local con ese nombre exacto;
     *   4. distrito que lo contenga (prefijo/substring);
     *   5. local que lo contenga.
     * Si un paso da VARIOS candidatos, se devuelve ambiguo con la lista: adivinar
     * "SAN JOSE" (hay tres) es peor que preguntar.
     *
     * @return array{encontrada: bool, ambigua: bool, tipo: ?string, id: ?string, texto: string,
     *               fuente: ?string, candidatos: array<int, array{id: string, texto: string}>}
     */
    public static function resolver_ubicacion(string $texto): array
    {
        $texto = trim($texto);
        $normalizado = mb_strtoupper(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        $vacio = ['encontrada' => false, 'ambigua' => false, 'tipo' => null, 'id' => null,
                  'texto' => $texto, 'fuente' => null, 'candidatos' => []];

        if ($normalizado === '') {
            return $vacio;
        }

        // 1) ya es un ubigeo
        if (preg_match('/^\d{6}$/', $normalizado) === 1) {
            $distrito = self::ubigeo_texto($normalizado);

            return $distrito === null
                ? $vacio
                : ['encontrada' => true, 'ambigua' => false, 'tipo' => 'ubigeo', 'id' => $normalizado,
                   'texto' => $distrito . ' · ' . $normalizado, 'fuente' => 'ubigeo', 'candidatos' => []];
        }

        try {
            // 2) distrito exacto
            $porNombre = fn (string $like) => DB::select("
                SELECT d.ubigeo, d.distrito, p.provincia, dep.departamento
                FROM ubigeo_distritos d
                LEFT JOIN ubigeo_provincias p ON p.id = d.provincia_id
                LEFT JOIN ubigeo_departamentos dep ON dep.id = d.departamento_id
                WHERE UPPER(d.distrito) LIKE ?
                ORDER BY d.distrito, dep.departamento
                LIMIT 9
            ", [$like]);

            $distritos = $porNombre($normalizado);

            if (count($distritos) === 1) {
                $fila = $distritos[0];

                return ['encontrada' => true, 'ambigua' => false, 'tipo' => 'ubigeo', 'id' => (string) $fila->ubigeo,
                        'texto' => self::texto_distrito($fila), 'fuente' => 'ubigeo', 'candidatos' => []];
            }
            if (count($distritos) > 1) {
                $candidatos = [];
                foreach ($distritos as $fila) {
                    $candidatos[] = ['id' => (string) $fila->ubigeo, 'texto' => self::texto_distrito($fila)];
                }

                return ['encontrada' => false, 'ambigua' => true, 'tipo' => 'ubigeo', 'id' => null,
                        'texto' => $texto, 'fuente' => 'ubigeo', 'candidatos' => $candidatos];
            }

            // 3) local exacto
            $locales = self::locales_por_texto($normalizado, true);
            if (count($locales) === 1) {
                return ['encontrada' => true, 'ambigua' => false, 'tipo' => 'local', 'id' => $locales[0]['id'],
                        'texto' => $locales[0]['texto'], 'fuente' => 'local', 'candidatos' => []];
            }
            if (count($locales) > 1) {
                return ['encontrada' => false, 'ambigua' => true, 'tipo' => 'local', 'id' => null,
                        'texto' => $texto, 'fuente' => 'local', 'candidatos' => $locales];
            }

            // 4) distrito que lo contenga
            $distritos = $porNombre('%' . $normalizado . '%');
            if (count($distritos) === 1) {
                $fila = $distritos[0];

                return ['encontrada' => true, 'ambigua' => false, 'tipo' => 'ubigeo', 'id' => (string) $fila->ubigeo,
                        'texto' => self::texto_distrito($fila), 'fuente' => 'ubigeo', 'candidatos' => []];
            }
            if (count($distritos) > 1) {
                $candidatos = [];
                foreach ($distritos as $fila) {
                    $candidatos[] = ['id' => (string) $fila->ubigeo, 'texto' => self::texto_distrito($fila)];
                }

                return ['encontrada' => false, 'ambigua' => true, 'tipo' => 'ubigeo', 'id' => null,
                        'texto' => $texto, 'fuente' => 'ubigeo', 'candidatos' => $candidatos];
            }

            // 5) local que lo contenga
            $locales = self::locales_por_texto($normalizado, false);
            if (count($locales) === 1) {
                return ['encontrada' => true, 'ambigua' => false, 'tipo' => 'local', 'id' => $locales[0]['id'],
                        'texto' => $locales[0]['texto'], 'fuente' => 'local', 'candidatos' => []];
            }
            if (count($locales) > 1) {
                return ['encontrada' => false, 'ambigua' => true, 'tipo' => 'local', 'id' => null,
                        'texto' => $texto, 'fuente' => 'local', 'candidatos' => $locales];
            }
        } catch (\Throwable $e) {
            return $vacio;
        }

        return $vacio;
    }

    /**
     * Locales activos por nombre (exacto) o que contengan el texto (también por
     * palabra clave, que es como el catálogo guarda "BABEL CAMPOY|CAMPOY").
     *
     * @return array<int, array{id: string, texto: string}>
     */
    private static function locales_por_texto(string $normalizado, bool $exacto): array
    {
        $filas = DB::select("
            SELECT id, nombre, palabra_clave, distrito_ubigeo
            FROM locales
            WHERE activo = 1
              AND (
                    UPPER(nombre) = ?
                 OR (? = 0 AND (UPPER(nombre) LIKE ? OR UPPER(palabra_clave) LIKE ?))
              )
            ORDER BY (UPPER(nombre) = ?) DESC, nombre
            LIMIT 9
        ", [
            $normalizado,
            $exacto ? 1 : 0,
            '%' . $normalizado . '%',
            '%' . $normalizado . '%',
            $normalizado,
        ]);

        $salida = [];
        foreach ($filas as $fila) {
            $ubicacion = self::texto_ubicacion((string) $fila->id, (string) ($fila->distrito_ubigeo ?? ''));
            $salida[] = ['id' => (string) $fila->id, 'texto' => $ubicacion];
        }

        return $salida;
    }

    /** Texto de un ubigeo de distrito ("Chiclayo, Chiclayo, Lambayeque") o null. */
    private static function ubigeo_texto(string $ubigeo): ?string
    {
        try {
            $fila = DB::selectOne("
                SELECT d.ubigeo, d.distrito, p.provincia, dep.departamento
                FROM ubigeo_distritos d
                LEFT JOIN ubigeo_provincias p ON p.id = d.provincia_id
                LEFT JOIN ubigeo_departamentos dep ON dep.id = d.departamento_id
                WHERE d.ubigeo = ?
            ", [$ubigeo]);
        } catch (\Throwable $e) {
            return null;
        }

        return $fila === null ? null : self::texto_distrito($fila);
    }

    private static function texto_distrito(object $fila): string
    {
        $partes = array_filter([
            trim((string) ($fila->distrito ?? '')),
            trim((string) ($fila->provincia ?? '')),
            trim((string) ($fila->departamento ?? '')),
        ], fn (string $parte) => $parte !== '');

        return implode(', ', $partes);
    }

    /** Minutos de tránsito medidos de una ruta: primero las refs, después el compilado. */
    private static function minutos_ruta(?string $ruta, array $etapas, array $compilados): ?int
    {
        if ($ruta === null || $ruta === '') {
            return null;
        }

        $e4 = $etapas[$ruta]['e4'] ?? null;
        if ($e4 !== null && $e4 > 0) {
            return (int) $e4;
        }

        $fila = $compilados[$ruta] ?? null;
        if ($fila === null) {
            return null;
        }

        foreach (['prom_fusion_min', 'prom_min', 'mediana_min'] as $columna) {
            if (($fila->{$columna} ?? null) !== null && (float) $fila->{$columna} > 0) {
                return (int) round((float) $fila->{$columna});
            }
        }

        return null;
    }

    /**
     * Cola de una planta de carga en un momento dado (C1).
     *
     * Dos números, los dos medidos sobre `referencias`:
     *  - `esperando`: camiones de esa planta ya presentados y todavía sin cargar;
     *  - `llegadas_hora`: cuántos se presentaron en la hora anterior al corte.
     *
     * Medido con 10.293 refs (sept-nov 2026): la espera para cargar
     * (`presenta` -> `inicio_de_carga`) tiene mediana 36 min cuando llegan 1-2 por
     * hora y 55 min con 11 o más (p90 139 contra 102). La cola "esperando" sola no
     * discrimina: hay plantas donde casi nadie registra el inicio de carga.
     *
     * @return object{esperando: int, llegadas_hora: int}
     */
    public static function cola_planta(?string $plantaLocal, ?string $ubigeo, Carbon $corte): object
    {
        $vacio = (object) ['esperando' => 0, 'llegadas_hora' => 0];

        $columna = null;
        $valor = null;
        if (trim((string) $plantaLocal) !== '') {
            $columna = 'ru.origen_id';
            $valor = trim((string) $plantaLocal);
        } elseif (trim((string) $ubigeo) !== '') {
            $columna = 'ru.ubigeo_origen';
            $valor = trim((string) $ubigeo);
        }

        if ($columna === null) {
            return $vacio;
        }

        try {
            $esperando = (int) (DB::selectOne(
                "SELECT COUNT(*) AS n
                 FROM referencias r
                 JOIN rutas ru ON ru.id = r.ruta_id
                 WHERE {$columna} = ?
                   AND r.presenta_para_carga IS NOT NULL AND r.presenta_para_carga <= ?
                   AND (r.inicio_de_carga IS NULL OR r.inicio_de_carga > ?)",
                [$valor, $corte->toDateTimeString(), $corte->toDateTimeString()]
            )->n ?? 0);

            $llegadas = (int) (DB::selectOne(
                "SELECT COUNT(*) AS n
                 FROM referencias r
                 JOIN rutas ru ON ru.id = r.ruta_id
                 WHERE {$columna} = ? AND r.presenta_para_carga BETWEEN ? AND ?",
                [$valor, $corte->copy()->subHour()->toDateTimeString(), $corte->toDateTimeString()]
            )->n ?? 0);
        } catch (\Throwable $e) {
            return $vacio;
        }

        return (object) ['esperando' => $esperando, 'llegadas_hora' => $llegadas];
    }

    /**
     * Espera en la planta de carga (fuera + dentro), del compilado por planta
     * (API `/api/tiempos/promedio/plantas`) y, si no hay, del compilado por ruta.
     */
    public static function espera_carga(?string $planta, ?string $rutaId = null): ?int
    {
        $plantas = self::promedios_planta();
        $fila = $planta !== null ? ($plantas[$planta] ?? null) : null;

        if ($fila !== null) {
            $fuera = $fila->prom_fuera_carga_min !== null ? (float) $fila->prom_fuera_carga_min : null;
            $dentro = $fila->prom_dentro_carga_min !== null ? (float) $fila->prom_dentro_carga_min : null;
            if ($fuera !== null && $dentro !== null) {
                return (int) round($fuera + $dentro);
            }
            if (($fila->prom_espera_min ?? null) !== null) {
                return (int) round((float) $fila->prom_espera_min);
            }
        }

        $ruta = $rutaId !== null ? (self::promedios_ruta()[$rutaId] ?? null) : null;
        if ($ruta !== null && ($ruta->prom_fuera_min ?? null) !== null && ($ruta->prom_dentro_min ?? null) !== null) {
            return (int) round((float) $ruta->prom_fuera_min + (float) $ruta->prom_dentro_min);
        }

        return null;
    }

    //------------------- actividad (franjas inactivas) -------------------

    /**
     * Marcas horarias (todos los hitos) del camión o del conductor en el rango:
     * la base de las franjas de no-actividad (descanso y almuerzo).
     *
     * Se devuelven hitos y no intervalos a propósito: el hueco entre un fin de
     * descargue y la presentación siguiente también sería un intervalo, y eso
     * haría invisible el descanso.
     *
     * @return array<int, Carbon>
     */
    public static function hitos_entidad(string $grupo, string $clave, Carbon $desde, Carbon $hasta): array
    {
        $llave = $grupo . '|' . $clave . '|' . $desde->toDateString() . '|' . $hasta->toDateString();
        if (isset(self::$hitos[$llave])) {
            return self::$hitos[$llave];
        }

        $columna = $grupo === 'conductor' ? 'conductor_id' : 'placa';
        $uniones = [];
        $params = [];
        foreach (self::HITOS as $hito) {
            $uniones[] = "SELECT $hito AS ts FROM referencias
                          WHERE $columna = ? AND $hito BETWEEN ? AND ?";
            $params[] = $clave;
            $params[] = $desde->toDateTimeString();
            $params[] = $hasta->toDateTimeString();
        }

        $filas = DB::select('SELECT t.ts FROM (' . implode(' UNION ALL ', $uniones) . ') t ORDER BY t.ts', $params);

        $salida = [];
        foreach ($filas as $fila) {
            if ($fila->ts !== null && (string) $fila->ts >= self::FECHA_MINIMA) {
                $salida[] = Carbon::parse($fila->ts);
            }
        }

        return self::$hitos[$llave] = $salida;
    }

    /**
     * Lo mismo que hitos_entidad() pero para muchas placas en una sola consulta:
     * la tabla de aciertos necesita las franjas de todos los camiones del rango
     * sin pagar una consulta por placa.
     *
     * @param  array<int, string>  $placas
     * @return array<string, array<int, Carbon>> placa => hitos
     */
    public static function hitos_por_placa(Carbon $desde, Carbon $hasta, array $placas, int $maxPlacas = 300): array
    {
        $placas = array_values(array_unique(array_filter(array_map(
            fn ($placa) => is_scalar($placa) ? trim((string) $placa) : '',
            $placas
        ), fn ($placa) => $placa !== '')));

        if ($placas === []) {
            return [];
        }

        $placas = array_slice($placas, 0, max(1, $maxPlacas));
        $in = implode(', ', array_fill(0, count($placas), '?'));

        $uniones = [];
        $params = [];
        foreach (self::HITOS as $hito) {
            $uniones[] = "SELECT placa, $hito AS ts FROM referencias
                          WHERE placa IN ($in) AND $hito BETWEEN ? AND ?";
            foreach ($placas as $placa) {
                $params[] = $placa;
            }
            $params[] = $desde->toDateTimeString();
            $params[] = $hasta->toDateTimeString();
        }

        $filas = DB::select(
            'SELECT t.placa, t.ts FROM (' . implode(' UNION ALL ', $uniones) . ') t ORDER BY t.placa, t.ts',
            $params
        );

        $salida = [];
        foreach ($filas as $fila) {
            if ($fila->ts === null || (string) $fila->ts < self::FECHA_MINIMA) {
                continue;
            }
            $salida[(string) $fila->placa][] = Carbon::parse($fila->ts);
        }

        return $salida;
    }

    //------------------- diagnóstico -------------------

    /** Qué hay realmente en la base para predecir, en números. */
    public static function diagnostico(int $dias = 120): stdClass
    {
        $hasta = Carbon::now();
        $desde = $hasta->copy()->subDays(max(1, $dias));
        $ultimo = self::ultimo_hito_sql('r');

        $refs = DB::selectOne(
            "SELECT COUNT(*) AS refs,
                    SUM(r.ruta_id IS NOT NULL AND r.ruta_id <> '') AS con_ruta,
                    SUM(r.placa IS NOT NULL AND r.placa <> '') AS con_placa,
                    SUM(r.presenta_para_carga IS NOT NULL) AS con_presenta,
                    SUM(r.fin_descargue IS NOT NULL) AS con_fin
             FROM referencias r
             WHERE $ultimo BETWEEN ? AND ?",
            [$desde->toDateTimeString(), $hasta->toDateTimeString()]
        );

        $pares = self::pares(null, $desde, $hasta);
        $huecos = [];
        foreach ($pares as $par) {
            if ($par->hueco_min > 0 && $par->hueco_min <= 10080) {
                $huecos[] = $par->hueco_min;
            }
        }

        $etapas = self::etapas_por_ruta($desde, $hasta);
        $completas = 0;
        foreach ($etapas as $fila) {
            if ($fila['ciclo'] !== null) {
                $completas++;
            }
        }

        return (object) [
            'dias' => $dias,
            'refs' => (int) ($refs->refs ?? 0),
            'con_ruta' => (int) ($refs->con_ruta ?? 0),
            'con_placa' => (int) ($refs->con_placa ?? 0),
            'con_presenta' => (int) ($refs->con_presenta ?? 0),
            'con_fin' => (int) ($refs->con_fin ?? 0),
            'pares' => count($huecos),
            'hueco_p25' => Estadistica::cuantil($huecos, 0.25),
            'hueco_mediana' => Estadistica::mediana($huecos),
            'hueco_p75' => Estadistica::cuantil($huecos, 0.75),
            'rutas_con_etapas' => $completas,
            'plantas_compiladas' => count(self::promedios_planta()),
            'rutas_compiladas' => count(self::promedios_ruta()),
        ];
    }

    /** Texto del comando de diagnóstico (puro: sólo formatea los números). */
    public static function texto_diagnostico(object $d, int $dias = 120): string
    {
        $refs = (int) ($d->refs ?? 0);
        $conRuta = (int) ($d->con_ruta ?? 0);
        $pct = $refs > 0 ? round(100 * $conRuta / $refs, 1) : 0.0;
        $fmt = fn ($valor) => $valor === null ? 'sin dato' : (string) (int) round((float) $valor);

        $lineas = [];
        $lineas[] = 'Diagnóstico de datos de los últimos ' . $dias . ' días';
        $lineas[] = '  referencias con hitos: ' . $refs;
        $lineas[] = '  con ruta_id: ' . $conRuta . '/' . $refs . ' (' . $pct . '%)';
        $lineas[] = '  con presenta_para_carga: ' . (int) ($d->con_presenta ?? 0)
            . ' · con fin_descargue: ' . (int) ($d->con_fin ?? 0);
        $lineas[] = '  pares consecutivos por placa: ' . (int) ($d->pares ?? 0);
        $lineas[] = '  hueco fin_descargue -> presenta: p25 ' . $fmt($d->hueco_p25 ?? null)
            . ' min · mediana ' . $fmt($d->hueco_mediana ?? null)
            . ' min · p75 ' . $fmt($d->hueco_p75 ?? null) . ' min';
        $lineas[] = '  rutas con las 5 etapas medidas: ' . (int) ($d->rutas_con_etapas ?? 0);
        $lineas[] = '  plantas con espera compilada: ' . (int) ($d->plantas_compiladas ?? 0)
            . ' · rutas compiladas: ' . (int) ($d->rutas_compiladas ?? 0);

        return implode("\n", $lineas);
    }

    /** Limpia las cachés por request (pruebas y comando de diagnóstico). */
    public static function olvidar(): void
    {
        self::$rutas = null;
        self::$promedios_ruta = null;
        self::$promedios_planta = null;
        self::$etapas = [];
        self::$hitos = [];
        self::$pares = [];
        self::$viajesPlaca = [];
        self::$horarios = [];
        self::$locales = null;
        self::$ubigeos_texto = [];
    }
}
