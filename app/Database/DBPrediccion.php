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

    /** Expresión SQL del último hito con fecha: 1970-01-01 si no hay ninguno. */
    public static function ultimo_hito_sql(string $alias = 'r'): string
    {
        $partes = [];
        foreach (self::HITOS as $hito) {
            $partes[] = "COALESCE($alias.$hito, '1970-01-01')";
        }

        return 'GREATEST(' . implode(', ', $partes) . ')';
    }

    /** Nombre del hito más reciente de una fila de `referencias` (o NULL). */
    public static function hito_mas_reciente($fila): ?string
    {
        $mejor = null;
        $mejorTs = null;

        foreach (self::HITOS as $hito) {
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
     * @return array<int, object> objetos con ancla, presenta, ruta_ancla, ruta_siguiente, entidad, hueco_min
     */
    public static function pares(?string $excluirRef, Carbon $desde, Carbon $hasta, ?string $placa = null,
                                 ?int $conductorId = null, int $limite = 20000): array
    {
        $sql = "SELECT x.* FROM (
                    SELECT r.ref, r.placa, r.conductor_id AS conductor_clave,
                           r.ruta_id, r.presenta_para_carga,
                           LAG(r.ruta_id) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS ruta_ancla,
                           LAG(r.ref) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS ref_ancla,
                           LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS fin_ancla
                    FROM referencias r
                    WHERE r.placa <> '' AND r.presenta_para_carga >= ?
                ) x
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
            ];
        }

        return $salida;
    }

    //------------------- promedios y etapas -------------------

    /**
     * Etapas E2..E6 promedio por ruta, calculadas desde `referencias` con las
     * mismas expresiones del módulo de tiempos (las negativas se descartan).
     *
     * @return array<string, array{e2: ?int, e3: ?int, e4: ?int, e5: ?int, e6: ?int, ciclo: ?int, n: int}>
     */
    public static function etapas_por_ruta(Carbon $desde, Carbon $hasta): array
    {
        $llave = $desde->toDateString() . '|' . $hasta->toDateString();
        if (isset(self::$etapas[$llave])) {
            return self::$etapas[$llave];
        }

        $tope = self::TOPE_ETAPA;
        $sql = "SELECT t.ruta_id, COUNT(*) AS n,
                   ROUND(AVG(CASE WHEN t.e2 BETWEEN 0 AND $tope THEN t.e2 END)) AS e2,
                   ROUND(AVG(CASE WHEN t.e3 BETWEEN 0 AND $tope THEN t.e3 END)) AS e3,
                   ROUND(AVG(CASE WHEN t.e4 BETWEEN 0 AND $tope THEN t.e4 END)) AS e4,
                   ROUND(AVG(CASE WHEN t.e5 BETWEEN 0 AND $tope THEN t.e5 END)) AS e5,
                   ROUND(AVG(CASE WHEN t.e6 BETWEEN 0 AND $tope THEN t.e6 END)) AS e6
                FROM (
                    SELECT r.ruta_id,
                           TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga) AS e2,
                           TIMESTAMPDIFF(MINUTE, r.inicio_de_carga, r.inicio_ruta) AS e3,
                           TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) AS e4,
                           TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue) AS e5,
                           TIMESTAMPDIFF(MINUTE, r.inicio_descargue, r.fin_descargue) AS e6
                    FROM referencias r
                    WHERE r.ruta_id IS NOT NULL AND r.ruta_id <> ''
                      AND r.presenta_para_carga BETWEEN ? AND ?
                ) t
                GROUP BY t.ruta_id";

        $salida = [];
        foreach (DB::select($sql, [$desde->toDateTimeString(), $hasta->toDateTimeString()]) as $fila) {
            $etapas = [
                'e2' => $fila->e2 !== null ? (int) $fila->e2 : null,
                'e3' => $fila->e3 !== null ? (int) $fila->e3 : null,
                'e4' => $fila->e4 !== null ? (int) $fila->e4 : null,
                'e5' => $fila->e5 !== null ? (int) $fila->e5 : null,
                'e6' => $fila->e6 !== null ? (int) $fila->e6 : null,
                'n' => (int) $fila->n,
                'ciclo' => null,
            ];

            $completas = true;
            $suma = 0;
            foreach (['e2', 'e3', 'e4', 'e5', 'e6'] as $clave) {
                if ($etapas[$clave] === null) {
                    $completas = false;
                    break;
                }
                $suma += $etapas[$clave];
            }
            if ($completas) {
                $etapas['ciclo'] = $suma;
            }

            $salida[(string) $fila->ruta_id] = $etapas;
        }

        return self::$etapas[$llave] = $salida;
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
                                    ?int $defectoMin = null, bool $posicionDeclarada = false): ?object
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
        $geo = \App\Prediccion\Geoapify::ruta($desdeUbigeo, $plantaUbigeo);
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
        self::$locales = null;
        self::$ubigeos_texto = [];
    }
}
