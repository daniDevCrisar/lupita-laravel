<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte de COMPORTAMIENTO por placa y por conductor, sobre la tabla `referencias`.
 *
 * Mide los habitos del camion / chofer con las mismas marcas del ciclo logistico
 * que usa el modulo de tiempos:
 *   compromiso_carga -> presenta_para_carga -> inicio_de_carga -> inicio_ruta
 *   -> qr_llegada_destino -> inicio_descargue -> fin_descargue
 *
 * Bloques que publica:
 *  - actividad: viajes, dias operados, primera/ultima actividad, viajes por semana
 *  - ruta habitual: ruta mas repetida y su participacion
 *  - horario habitual: hora modal de `inicio_ruta` y regularidad dentro de +/-1 h
 *  - puntualidad: desvio mediano contra `compromiso_carga`, % puntual y % tarde
 *  - tiempos en planta: promedio de las etapas E2..E6 y ciclo (suma de E2..E6)
 *  - consistencia: % cerrados, % sin conductor y par placa - conductor habitual
 *
 * Reglas heredadas del resto del proyecto:
 *  - El rango de fechas entra por los hitos E2..E6 de `referencias`, no por `llamadas`.
 *  - El conductor se toma de la referencia y, si viene vacio, de sus llamadas.
 *  - El tope descarta el viaje completo cuando una etapa registrada cae fuera de [0, tope].
 *  - Los tiempos de etapa se promedian (el tope ya dejo fuera los hitos errados); el
 *    desvio de puntualidad usa MEDIANA porque `compromiso_carga` trae pares con dias
 *    de error y el promedio se dispara.
 *  - El ciclo solo se publica cuando las cinco etapas E2..E6 tienen promedio.
 */
class DBComportamiento
{
    public static $filtro;

    /** Etapas del ciclo medidas en minutos (la etapa 1 queda fuera, igual que en tiempos). */
    public static $etapas = [
        2 => 'Espera en carga',
        3 => 'Carga',
        4 => 'Tránsito',
        5 => 'Espera en descarga',
        6 => 'Descargue',
    ];

    /** Topes de duracion por etapa, en minutos (0 = sin tope). */
    public const TOPES = [
        0 => 'Sin tope',
        1440 => 'Máximo 24 h',
        2880 => 'Máximo 48 h',
        4320 => 'Máximo 72 h',
    ];

    /**
     * Tolerancia del desvio de puntualidad: los pares compromiso/presenta que se
     * alejan mas de +/- 24 h son error de registro y no entran en la mediana.
     */
    public const DESVIO_MAX = 1440;

    /**
     * Techo del ciclo real del viaje (presenta para carga -> fin de descargue):
     * 7 dias en minutos. Los pares que lo superan son error de registro.
     */
    public const CICLO_MAX = 10080;

    /** Ancho (horas) de la franja habitual alrededor de la hora modal. */
    public const VENTANA_HABITUAL = 1;

    /** Candidatos que se enriquecen antes de ordenar por hora/regularidad en PHP. */
    private const CANDIDATOS = 300;
    /** Minimo de viajes de una placa para entrar al promedio global de confiabilidad. */
    private const MINIMO_CONFIABILIDAD = 5;

    /**
     * Marcas horarias que describen la jornada fuera de planta: la salida de ruta
     * (sale de la planta de carga), el presenta para carga y la llegada a destino.
     */
    private const MARCAS = [
        'ruta' => 'hora_ruta',
        'presenta' => 'hora_presenta',
        'llegada' => 'hora_llegada',
    ];

    /** Campos de orden que solo existen en PHP: obligan a traer CANDIDATOS. */
    private const ORDEN_EN_PHP = [
        'hora', 'regularidad',
        'hora_presenta', 'regularidad_presenta',
        'hora_llegada', 'regularidad_llegada',
        'confiabilidad',
        'ruta_pct', 'par_pct',
    ];

    /** WEEKDAY() de mysql: 0 = lunes. */
    public static $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    public static function set_filtro($request): void
    {
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio;
        self::$filtro->fecha_fin = $request->fecha_fin;
        self::$filtro->placa = $request->placa;
        self::$filtro->conductor = $request->conductor;
        self::$filtro->trt = $request->trt;
        self::$filtro->ruta_id = $request->ruta_id;
        self::$filtro->tope = $request->tope;
        self::$filtro->min_viajes = $request->min_viajes;
        self::$filtro->ordenar_por = $request->ordenar_por;
        self::$filtro->orden = $request->orden;
        self::$filtro->limite = $request->limite;
    }

    //------------------- filtros -------------------

    public static function tope(): int
    {
        $tope = (int) (self::$filtro->tope ?? 0);

        return isset(self::TOPES[$tope]) ? $tope : 0;
    }

    public static function min_viajes(): int
    {
        return max(1, (int) (self::$filtro->min_viajes ?? 1));
    }

    public static function limite(): int
    {
        return max(1, min(500, (int) (self::$filtro->limite ?? 100)));
    }

    /**
     * Rango por los hitos de etapa E2..E6: el viaje entra si CUALQUIERA de sus
     * marcas cae dentro. Un hito NULL nunca cumple la condicion.
     */
    private static function filtro_fechas(): array
    {
        $inicio = (string) (self::$filtro->fecha_inicio ?? '');
        $fin = (string) (self::$filtro->fecha_fin ?? '');

        if ($inicio === '' && $fin === '') {
            return ['', []];
        }

        $hitos = [
            'r.presenta_para_carga',
            'r.inicio_de_carga',
            'r.inicio_ruta',
            'r.qr_llegada_destino',
            'r.inicio_descargue',
            'r.fin_descargue',
        ];

        $desde = $inicio !== '' ? Carbon::parse($inicio)->startOfDay() : null;
        $hasta = $fin !== '' ? Carbon::parse($fin)->addDay()->startOfDay() : null;

        $condiciones = [];
        $params = [];
        foreach ($hitos as $hito) {
            $partes = [];
            if ($desde !== null) {
                $partes[] = "$hito >= ?";
                $params[] = $desde;
            }
            if ($hasta !== null) {
                $partes[] = "$hito < ?";
                $params[] = $hasta;
            }
            $condiciones[] = '(' . implode(' AND ', $partes) . ')';
        }

        return [' AND (' . implode(' OR ', $condiciones) . ') ', $params];
    }

    /** Placa / conductor / transportista / ruta elegidos en el formulario. */
    private static function filtro_extra(): array
    {
        $sql = '';
        $params = [];

        $placa = trim((string) (self::$filtro->placa ?? ''));
        if ($placa !== '') {
            $sql .= ' AND r.placa LIKE ? ';
            $params[] = '%' . $placa . '%';
        }

        $conductor = trim((string) (self::$filtro->conductor ?? ''));
        if ($conductor !== '') {
            if (is_numeric($conductor)) {
                $sql .= ' AND COALESCE(r.conductor_id, lc.conductor_id) = ? ';
                $params[] = (int) $conductor;
            } else {
                $sql .= ' AND co.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($conductor) . '%';
            }
        }

        $trt = trim((string) (self::$filtro->trt ?? ''));
        if ($trt !== '') {
            if (is_numeric($trt)) {
                $sql .= ' AND COALESCE(r.trt_id, lc.trt_id) = ? ';
                $params[] = (int) $trt;
            } else {
                $sql .= ' AND tr.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($trt) . '%';
            }
        }

        $ruta = trim((string) (self::$filtro->ruta_id ?? ''));
        if ($ruta !== '') {
            $sql .= ' AND r.ruta_id = ? ';
            $params[] = $ruta;
        }

        return [$sql, $params];
    }

    /** Duracion (min) de cada etapa del ciclo. */
    private static function expresiones_etapas(): array
    {
        return [
            2 => 'TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga)',
            3 => 'TIMESTAMPDIFF(MINUTE, r.inicio_de_carga, r.inicio_ruta)',
            4 => 'TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino)',
            5 => 'TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue)',
            6 => 'TIMESTAMPDIFF(MINUTE, r.inicio_descargue, r.fin_descargue)',
        ];
    }

    /**
     * Descarta el viaje completo si alguna etapa registrada cae fuera de [0, tope]:
     * duracion excesiva o hitos invertidos. Las etapas sin hito (NULL) no descartan.
     */
    private static function filtro_tope(): array
    {
        $tope = self::tope();
        if ($tope === null || $tope <= 0) {
            return ['', []];
        }

        $sql = '';
        $params = [];
        foreach (self::expresiones_etapas() as $expr) {
            $sql .= " AND ($expr IS NULL OR $expr BETWEEN 0 AND ?) ";
            $params[] = $tope;
        }

        return [$sql, $params];
    }

    //------------------- vista base -------------------

    /**
     * Una fila por referencia con el conductor/trt efectivo, la hora y el dia de
     * salida, los minutos de cada etapa y el desvio de puntualidad ya saneado.
     */
    private static function base_sql(): array
    {
        $fechas = self::filtro_fechas();
        $extra = self::filtro_extra();
        $tope = self::filtro_tope();

        $etapas = [];
        foreach (self::expresiones_etapas() as $numero => $expr) {
            $etapas[] = "$expr AS etapa_$numero";
        }

        $sql = "
        SELECT
            r.ref AS ref,
            r.placa AS placa,
            COALESCE(r.conductor_id, lc.conductor_id) AS conductor_id,
            -- clave de agrupamiento: 0 = viaje sin conductor en la referencia ni en sus llamadas
            COALESCE(r.conductor_id, lc.conductor_id, 0) AS conductor_clave,
            co.nombres AS conductor,
            COALESCE(r.trt_id, lc.trt_id) AS trt_id,
            tr.nombres AS trt,
            r.ruta_id AS ruta_id,
            r.inicio_ruta AS inicio_ruta,
            HOUR(r.inicio_ruta) AS hora_ruta,
            HOUR(r.presenta_para_carga) AS hora_presenta,
            HOUR(r.qr_llegada_destino) AS hora_llegada,
            WEEKDAY(r.inicio_ruta) AS dia_semana,
            -- dia de la semana de la presentacion en carga: es la marca del mapa de calor
            WEEKDAY(r.presenta_para_carga) AS dia_presenta,
            (r.fin_descargue IS NOT NULL) AS cerrado,
            (r.conductor_id IS NULL AND lc.conductor_id IS NULL) AS sin_conductor,
            (r.compromiso_carga IS NOT NULL AND r.presenta_para_carga IS NULL) AS sin_presenta,
            -- actividad: solo marcas con sentido (hay hitos con anio 1998 en la base)
            CASE WHEN r.inicio_ruta >= '2000-01-01' THEN r.inicio_ruta END AS primera_marca,
            CASE WHEN r.presenta_para_carga >= '2000-01-01' THEN r.presenta_para_carga END AS marca_presenta,
            CASE WHEN r.fin_descargue >= '2000-01-01' THEN r.fin_descargue
                 WHEN r.inicio_ruta >= '2000-01-01' THEN r.inicio_ruta END AS ultima_marca,
            -- desvio de puntualidad: compromiso -> presenta, solo pares confiables
            CASE WHEN TIMESTAMPDIFF(MINUTE, r.compromiso_carga, r.presenta_para_carga)
                      BETWEEN -" . self::DESVIO_MAX . ' AND ' . self::DESVIO_MAX . "
                 THEN TIMESTAMPDIFF(MINUTE, r.compromiso_carga, r.presenta_para_carga) END AS desvio,
            -- ciclo real: del presenta para carga al fin de descargue del mismo viaje
            CASE WHEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue)
                      BETWEEN 0 AND " . self::CICLO_MAX . "
                 THEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue) END AS ciclo_real,
            " . implode(",\n            ", $etapas) . "
        FROM referencias r
        LEFT JOIN (
            SELECT lf.ref AS ref,
                   MAX(lf.conductor_id) AS conductor_id,
                   MAX(lf.trt_id) AS trt_id
            FROM llamadas lf
            WHERE lf.ref IS NOT NULL
            GROUP BY lf.ref
        ) lc ON lc.ref = r.ref
        LEFT JOIN conductores co ON co.id = COALESCE(r.conductor_id, lc.conductor_id)
        LEFT JOIN trts tr ON tr.id = COALESCE(r.trt_id, lc.trt_id)
        WHERE 1=1 " . $fechas[0] . $extra[0] . $tope[0];

        return [$sql, array_merge($fechas[1], $extra[1], $tope[1])];
    }

    /** Base acotada a un grupo (una placa o un conductor), para la ficha. */
    private static function base_con_clave(string $grupo, $clave): array
    {
        [$base, $params] = self::base_sql();

        if ($grupo === 'conductor') {
            $base .= ' AND COALESCE(r.conductor_id, lc.conductor_id, 0) = ? ';
            $params[] = (int) $clave;
        } else {
            $base .= ' AND r.placa = ? ';
            $params[] = (string) $clave;
        }

        return [$base, $params];
    }

    /** Columna que identifica al grupo dentro de la base y del agregado. */
    private static function clave_sql(string $grupo, string $alias = 'b'): string
    {
        return $grupo === 'conductor' ? "$alias.conductor_clave" : "$alias.placa";
    }

    /** Etiqueta del grupo: el nombre del conductor (o SIN CONDUCTOR) o la placa. */
    private static function nombre_sql(string $grupo, string $alias = 'b'): string
    {
        return $grupo === 'conductor'
            ? "COALESCE(MAX($alias.conductor), 'SIN CONDUCTOR')"
            : "MAX($alias.placa)";
    }

    //------------------- columnas calculadas -------------------

    /** Promedio de cada etapa ignorando hitos invertidos. */
    private static function promedio_etapas_sql(string $alias): string
    {
        $columnas = [];
        foreach (array_keys(self::$etapas) as $numero) {
            $expr = 'AVG(CASE WHEN ' . $alias . '.etapa_' . $numero . ' >= 0 THEN ' . $alias . '.etapa_' . $numero . ' END)';
            $columnas[] = 'ROUND(' . $expr . ', 0) AS e' . $numero;
        }

        return implode(",\n            ", $columnas);
    }

    /** Cuantas etapas del ciclo aportan promedio. */
    private static function ciclo_etapas_sql(string $alias): string
    {
        $partes = [];
        foreach (array_keys(self::$etapas) as $numero) {
            $expr = 'AVG(CASE WHEN ' . $alias . '.etapa_' . $numero . ' >= 0 THEN ' . $alias . '.etapa_' . $numero . ' END)';
            $partes[] = '(' . $expr . ' IS NOT NULL)';
        }

        return '(' . implode(' + ', $partes) . ')';
    }

    /** Ciclo = suma de los promedios E2..E6; NULL si alguna etapa no tiene datos. */
    private static function ciclo_sql(string $alias): string
    {
        $suma = [];
        foreach (array_keys(self::$etapas) as $numero) {
            $suma[] = 'COALESCE(AVG(CASE WHEN ' . $alias . '.etapa_' . $numero . ' >= 0 THEN ' . $alias . '.etapa_' . $numero . ' END), 0)';
        }

        return 'ROUND(CASE WHEN ' . self::ciclo_etapas_sql($alias) . ' < ' . count(self::$etapas)
            . ' THEN NULL ELSE (' . implode(' + ', $suma) . ') END, 0) AS ciclo';
    }

    /** Columnas comunes de agregacion: actividad, puntualidad y consistencia. */
    private static function select_agregado(string $alias): string
    {
        return "
            COUNT(*) AS viajes,
            SUM($alias.cerrado) AS cerrados,
            SUM($alias.sin_conductor) AS sin_conductor,
            SUM($alias.sin_presenta) AS sin_presenta,
            COUNT(DISTINCT $alias.ruta_id) AS rutas,
            COUNT(DISTINCT $alias.placa) AS placas,
            COUNT(DISTINCT $alias.conductor_id) AS conductores,
            COUNT(DISTINCT DATE($alias.inicio_ruta)) AS dias,
            MIN($alias.primera_marca) AS primera,
            MAX($alias.ultima_marca) AS ultima,
            SUM($alias.desvio BETWEEN -30 AND 30) AS puntual30,
            SUM($alias.desvio > 120) AS tarde120,
            SUM($alias.desvio IS NOT NULL) AS desvio_n,
            ROUND(AVG(CASE WHEN $alias.rn_d IN (FLOOR(($alias.tot_d + 1) / 2), CEIL(($alias.tot_d + 1) / 2))
                           THEN $alias.desvio END), 0) AS desvio_mediana,
            " . self::promedio_etapas_sql($alias) . ",
            " . self::ciclo_sql($alias) . ",
            -- promedios por viaje: ciclo medido en cada viaje y tramo de ruta
            ROUND(AVG($alias.ciclo_real), 0) AS ciclo_real,
            SUM($alias.ciclo_real IS NOT NULL) AS ciclo_real_n";
    }

    /** Orden del listado (solo campos calculables en SQL). */
    private static function order_by_sql(): string
    {
        $mapa = [
            'ciclo' => 'ciclo',
            'ciclo_real' => 'ciclo_real',
            'ruta_prom' => 'e4',
            'desvio' => 'desvio_mediana',
            'cerrados' => 'cerrados',
            'rutas' => 'rutas',
            'dias' => 'dias',
        ];

        $columna = $mapa[self::$filtro->ordenar_por ?? ''] ?? 'viajes';
        $direccion = (self::$filtro->orden ?? '') === 'asc' ? 'ASC' : 'DESC';

        return "ORDER BY $columna IS NULL, $columna $direccion, clave ASC";
    }

    //------------------- consultas -------------------

    /**
     * Ranking por placa o conductor. Devuelve hasta `limite` grupos ya enriquecidos
     * con horario habitual, regularidad, ruta habitual y par placa - conductor.
     *
     * Se traen CANDIDATOS grupos ordenados por SQL para poder ordenar en PHP por
     * los campos que no viven en la consulta (hora habitual y regularidad).
     *
     * La agregacion va en una subconsulta y el filtro y el orden se resuelven fuera:
     * algunas versiones de MySQL no aceptan el alias de un COUNT/SUM/AVG dentro de
     * una expresion (HAVING viajes >= ?, ORDER BY viajes IS NULL) cuando la FROM
     * trae funciones de ventana, y responden 1247 reference to group function.
     */
    public static function ranking(string $grupo = 'placa', int $limite = 100): array
    {
        [$base, $params] = self::base_sql();

        // Con orden en SQL basta con traer las filas que se muestran; con orden en
        // PHP hay que traer CANDIDATOS porque el reordenamiento mueve las filas.
        $candidatos = in_array(self::$filtro->ordenar_por ?? '', self::ORDEN_EN_PHP, true)
            ? max(self::CANDIDATOS, $limite)
            : $limite;

        $clave = self::clave_sql($grupo, 'w');
        $particion = self::clave_sql($grupo, 'b');
        $nombre = self::nombre_sql($grupo, 'w');

        $sql = "
        SELECT *
        FROM (
            SELECT
                $clave AS clave,
                $nombre AS nombre,
                MAX(w.trt) AS trt,
                " . self::select_agregado('w') . "
            FROM (
                SELECT b.*,
                    ROW_NUMBER() OVER (PARTITION BY $particion ORDER BY b.desvio IS NULL, b.desvio) AS rn_d,
                    COUNT(b.desvio) OVER (PARTITION BY $particion) AS tot_d
                FROM ($base) b
            ) w
            GROUP BY $clave
        ) g
        WHERE viajes >= ?
        " . self::order_by_sql() . "
        LIMIT " . $candidatos;

        $filas = DB::select($sql, array_merge($params, [self::min_viajes()]));

        $filas = self::completar($grupo, $filas);
        $filas = self::ordenar_en_php($filas);

        return array_slice($filas, 0, $limite);
    }

    /** Totales del filtro completo (sin agrupar). */
    public static function resumen(): stdClass
    {
        [$base, $params] = self::base_sql();

        $sql = "
        SELECT
            " . self::select_agregado('w') . "
        FROM (
            SELECT b.*,
                ROW_NUMBER() OVER (ORDER BY b.desvio IS NULL, b.desvio) AS rn_d,
                COUNT(b.desvio) OVER () AS tot_d
            FROM ($base) b
        ) w";

        $filas = DB::select($sql, $params);

        return self::fila_resumen($filas[0] ?? null);
    }

    /**
     * Confiabilidad de la prediccion de presenta en carga para todo el rango.
     *
     * La regularidad global no alcanza: hay que descontar a cada placa los
     * choferes efectivos que la manejan (HHI, suma de participaciones al cuadrado)
     * y promediar por viajes, porque la mezcla de rutinas es lo que rompe el
     * horario. Las placas con pocos viajes quedan fuera: con uno o dos viajes la
     * franja acierta siempre y falsearia el promedio.
     */
    public static function confiabilidad_global(): array
    {
        [$base, $params] = self::base_sql();

        //---- histograma por placa: sirve para la franja del rango (suma) y para
        // la regularidad de cada placa en su propia franja
        $columnas = [];
        for ($hora = 0; $hora < 24; $hora++) {
            $columnas[] = "SUM(CASE WHEN b.hora_presenta = $hora THEN 1 ELSE 0 END) AS h$hora";
        }
        $sqlPlacas = "SELECT b.placa AS placa, " . implode(",\n            ", $columnas) . "
                      FROM ($base) b
                      WHERE b.hora_presenta IS NOT NULL
                      GROUP BY b.placa";

        $histogramas = [];
        $total_rango = [];
        foreach (DB::select($sqlPlacas, $params) as $fila) {
            $hist = [];
            for ($hora = 0; $hora < 24; $hora++) {
                $n = (int) $fila->{'h' . $hora};
                if ($n > 0) {
                    $hist[$hora] = $n;
                    $total_rango[$hora] = ($total_rango[$hora] ?? 0) + $n;
                }
            }
            $histogramas[(string) $fila->placa] = $hist;
        }
        [$hora, $regularidad] = self::hora_modal($total_rango);

        //---- reparto de choferes por placa: cuantos pares hay y su concentracion
        $reparto = [];
        $pares = 0;
        $sqlPares = "SELECT b.placa AS placa, b.conductor_clave AS actor, COUNT(*) AS n
                     FROM ($base) b
                     GROUP BY b.placa, b.conductor_clave";
        foreach (DB::select($sqlPares, $params) as $fila) {
            $reparto[(string) $fila->placa][(string) $fila->actor] = (int) $fila->n;
            $pares++;
        }

        //---- promedio ponderado por viajes: la placa que mas viaja pesa mas
        $viajes = 0;
        $aciertos = 0.0;
        $efectivos = 0.0;
        $placas = 0;
        foreach ($histogramas as $placa => $hist) {
            $total = array_sum($hist);
            $actores = $reparto[$placa] ?? [];
            if ($total < self::MINIMO_CONFIABILIDAD || !$actores) {
                continue;
            }

            [, $propia] = self::hora_modal($hist);
            $suma = array_sum($actores);
            $hhi = 0.0;
            foreach ($actores as $n) {
                $p = $n / $suma;
                $hhi += $p * $p;
            }
            if ($propia === null || $hhi <= 0) {
                continue;
            }

            $viajes += $total;
            $aciertos += $propia * $hhi * $total;
            $efectivos += (1 / $hhi) * $total;
            $placas++;
        }

        return [
            'hora' => $hora,
            'franja' => self::franja_habitual($hora),
            'regularidad' => $regularidad,
            'pares' => $pares,
            'placas' => $placas,
            'efectivos' => $viajes > 0 ? round($efectivos / $viajes, 1) : null,
            'confiabilidad' => $viajes > 0 ? round($aciertos / $viajes, 1) : null,
        ];
    }

    /**
     * Ficha de una placa o conductor: agregado del grupo, histogramas de hora y
     * dia, rutas habituales, pares placa - conductor y los ultimos viajes.
     */
    public static function ficha(string $grupo, $clave): stdClass
    {
        [$base, $params] = self::base_con_clave($grupo, $clave);

        $sql = "
        SELECT
            " . self::select_agregado('w') . "
        FROM (
            SELECT b.*,
                ROW_NUMBER() OVER (ORDER BY b.desvio IS NULL, b.desvio) AS rn_d,
                COUNT(b.desvio) OVER () AS tot_d
            FROM ($base) b
        ) w";

        $filas = DB::select($sql, $params);

        $ficha = new stdClass();
        $ficha->grupo = $grupo;
        $ficha->clave = $clave;
        $ficha->nombre = $grupo === 'conductor'
            ? ((string) (DB::table('conductores')->where('id', (int) $clave)->value('nombres') ?? 'SIN CONDUCTOR'))
            : (string) $clave;
        $ficha->fila = self::fila_resumen($filas[0] ?? null);

        // histogramas de las tres marcas horarias, acotados al grupo
        $horas = self::horas_por_marca($grupo, [$clave], $base, $params);
        $indice = self::clave_indice($clave);
        $ficha->horas = $horas['ruta'][$indice] ?? [];
        $ficha->horas_presenta = $horas['presenta'][$indice] ?? [];
        $ficha->horas_llegada = $horas['llegada'][$indice] ?? [];

        $ficha->dias = self::dias_por_clave($grupo, $clave, $base, $params);
        $mapa = self::mapa_presenta($base, $params);
        $ficha->mapa = $mapa['conteo'];
        $ficha->pares = self::pares_lista($grupo, $clave, 5, $base, $params);
        $ficha->rutas = self::rutas_por_clave($grupo, [$clave], 8, $base, $params)[self::clave_indice($clave)] ?? [];
        $ficha->viajes = self::viajes_de_clave($grupo, $clave);

        // mismos habitos que el ranking, para que las dos pantallas cuadren
        $actores = self::actores_por_clave($grupo, [$clave], $base, $params);
        self::habitos($ficha->fila, [
            'ruta' => $ficha->horas,
            'presenta' => $ficha->horas_presenta,
            'llegada' => $ficha->horas_llegada,
        ], $ficha->rutas[0] ?? null, $ficha->pares[0] ?? null,
            $actores[$indice]['hhi'] ?? null, $actores[$indice]['actores'] ?? null);

        // veredicto de la proxima carga sobre el calendario dia x hora del grupo
        $ficha->proxima = self::veredicto_carga(
            $mapa['conteo'],
            $mapa['minutos'],
            $actores[$indice]['hhi'] ?? null
        );

        return $ficha;
    }

    /**
     * Resumen de un par placa - conductor: los viajes de esa placa manejados por
     * ese chofer. La ficha de la placa lo usa para mostrar los promedios del
     * chofer habitual cuando concentra mas de la mitad de los viajes.
     */
    public static function resumen_par(string $placa, $conductor): ?stdClass
    {
        [$base, $params] = self::base_con_clave('placa', $placa);

        $params[] = (int) $conductor;
        $base .= ' AND COALESCE(r.conductor_id, lc.conductor_id, 0) = ? ';

        $sql = "
        SELECT
            " . self::select_agregado('w') . "
        FROM (
            SELECT b.*,
                ROW_NUMBER() OVER (ORDER BY b.desvio IS NULL, b.desvio) AS rn_d,
                COUNT(b.desvio) OVER () AS tot_d
            FROM ($base) b
        ) w";

        $filas = DB::select($sql, $params);
        if (!$filas || (int) $filas[0]->viajes === 0) {
            return null;
        }

        $fila = self::fila_resumen($filas[0]);

        // un solo actor: su propia franja no se diluye por la mezcla de choferes
        $horas = self::horas_por_marca('placa', [$placa], $base, $params);
        $indice = self::clave_indice($placa);
        self::habitos($fila, [
            'ruta' => $horas['ruta'][$indice] ?? [],
            'presenta' => $horas['presenta'][$indice] ?? [],
            'llegada' => $horas['llegada'][$indice] ?? [],
        ], null, null, 1.0, 1);

        $fila->pct_puntual = self::pct($fila->puntual30, $fila->desvio_n);
        $fila->pct_tarde = self::pct($fila->tarde120, $fila->desvio_n);
        $fila->pct_cerrados = self::pct($fila->cerrados, $fila->viajes);

        return $fila;
    }

    //------------------- enriquecimiento -------------------

    /** Agrega los campos derivados (porcentajes, ciclo, semana, horario, ruta). */
    private static function completar(string $grupo, array $filas): array
    {
        if (!$filas) {
            return [];
        }

        $claves = array_map(fn($f) => $f->clave, $filas);

        $horas = self::horas_por_marca($grupo, $claves);
        $pares = self::pares_por_clave($grupo, $claves);
        $rutas = self::rutas_por_clave($grupo, $claves, 1);
        $actores = self::actores_por_clave($grupo, $claves);
        $calendario = self::calendario_presentas($grupo, $claves);

        foreach ($filas as $fila) {
            $indice = self::clave_indice($fila->clave);
            $viajes = (int) $fila->viajes;

            self::habitos(
                $fila,
                [
                    'ruta' => $horas['ruta'][$indice] ?? [],
                    'presenta' => $horas['presenta'][$indice] ?? [],
                    'llegada' => $horas['llegada'][$indice] ?? [],
                ],
                $rutas[$indice][0] ?? null,
                $pares[$indice] ?? null,
                $actores[$indice]['hhi'] ?? null,
                $actores[$indice]['actores'] ?? null
            );

            //---- veredicto de la proxima carga con el dia y la hora de ahora
            $fila->proxima = self::veredicto_carga(
                $calendario['conteo'][$indice] ?? [],
                $calendario['minutos'][$indice] ?? [],
                $actores[$indice]['hhi'] ?? null
            );

            //---- porcentajes de consistencia y puntualidad
            $fila->pct_cerrados = self::pct((int) $fila->cerrados, $viajes);
            $fila->pct_sin_conductor = self::pct((int) $fila->sin_conductor, $viajes);
            $fila->pct_sin_presenta = self::pct((int) $fila->sin_presenta, $viajes);
            $fila->pct_puntual = self::pct((int) $fila->puntual30, (int) $fila->desvio_n);
            $fila->pct_tarde = self::pct((int) $fila->tarde120, (int) $fila->desvio_n);

            //---- ritmo: viajes por semana entre la primera y la ultima salida
            $fila->viajes_semana = null;
            if ($fila->primera && $fila->ultima) {
                $dias = Carbon::parse($fila->primera)->startOfDay()
                    ->diffInDays(Carbon::parse($fila->ultima)->startOfDay()) + 1;
                $fila->viajes_semana = round($viajes / max(1, $dias / 7), 1);
            }
        }

        return $filas;
    }

    /**
     * Habitos del grupo: hora modal y regularidad de las marcas que describen la
     * jornada fuera de planta, ruta habitual y par placa - conductor habitual.
     * Lo comparten el ranking y la ficha.
     *
     * @param array $histogramas ['ruta' => [hora => n], 'presenta' => [...], 'llegada' => [...]]
     * @param float|null $hhi Concentracion de actores (suma de p^2): 1 = un solo chofer
     * @param int|null $actores Actores distintos que manejan el grupo
     */
    private static function habitos(stdClass $fila, array $histogramas, ?array $top, ?array $par,
                                   ?float $hhi = null, ?int $actores = null): void
    {
        $viajes = (int) $fila->viajes;

        //---- horario habitual de cada marca: salida de planta, presenta y llegada
        [$fila->hora_habitual, $fila->regularidad] = self::hora_modal($histogramas['ruta'] ?? []);
        [$fila->hora_presenta, $fila->regularidad_presenta] = self::hora_modal($histogramas['presenta'] ?? []);
        [$fila->hora_llegada, $fila->regularidad_llegada] = self::hora_modal($histogramas['llegada'] ?? []);

        //---- horas mas posibles de presenta: la modal y las que le siguen
        $fila->presenta_top = self::horas_probables($histogramas['presenta'] ?? [], 4);

        //---- confiabilidad de la prediccion: acertar la franja vale menos cuanto
        // mas choferes distintos manejan el grupo, porque cada uno trae su rutina.
        // Se divide por el numero efectivo de actores, o sea se multiplica por HHI.
        $fila->actores = $actores;
        $fila->actores_efectivos = ($hhi !== null && $hhi > 0) ? round(1 / $hhi, 1) : null;
        $fila->confiabilidad = ($hhi !== null && $hhi > 0 && $fila->regularidad_presenta !== null)
            ? round($fila->regularidad_presenta * $hhi, 1)
            : null;

        //---- ruta habitual (la mas repetida) y su participacion
        $fila->ruta_habitual = $top['nombre'] ?? null;
        $fila->ruta_pct = $top ? self::pct($top['n'], $viajes) : null;

        //---- par placa - conductor habitual (o conductor - placa)
        $fila->par_habitual = $par['nombre'] ?? null;
        $fila->par_pct = $par ? self::pct($par['n'], $viajes) : null;
    }

    /**
     * Hora modal de un histograma de 24 horas y porcentaje de marcas que caen en
     * la franja +/-1 h. Devuelve [hora, regularidad] y [null, null] sin datos.
     */
    private static function hora_modal(array $histograma): array
    {
        if (!$histograma) {
            return [null, null];
        }

        arsort($histograma);
        $modal = (int) array_key_first($histograma);
        $total = array_sum($histograma);
        $ventana = 0;
        for ($delta = -self::VENTANA_HABITUAL; $delta <= self::VENTANA_HABITUAL; $delta++) {
            $ventana += $histograma[($modal + $delta + 24) % 24] ?? 0;
        }

        return [$modal, self::pct($ventana, $total)];
    }

    /**
     * Horas alternativas de un histograma: las N horas sueltas con mas marcas que
     * quedan fuera de la franja habitual, con su peso sobre las marcas registradas.
     * Responde "que otras horas son posibles" sin repetir lo que ya dice la franja:
     * franja_habitual() dibuja hasta modal + VENTANA + 1, asi que se descarta esa
     * hora tambien o la lista se contradice con la etiqueta.
     *
     * @return array<int, array{hora: int, n: int, pct: float|null}>
     */
    private static function horas_probables(array $histograma, int $cuantas = 3): array
    {
        if (!$histograma) {
            return [];
        }

        $total = array_sum($histograma);
        arsort($histograma);
        $modal = (int) array_key_first($histograma);
        $limite = self::VENTANA_HABITUAL + 1;

        $salida = [];
        foreach ($histograma as $hora => $viajes) {
            $distancia = abs((int) $hora - $modal);
            $distancia = min($distancia, 24 - $distancia);
            if ($distancia <= $limite) {
                continue;
            }

            $salida[] = [
                'hora' => (int) $hora,
                'n' => (int) $viajes,
                'pct' => self::pct($viajes, $total),
            ];

            if (count($salida) >= $cuantas) {
                break;
            }
        }

        return $salida;
    }

    /**
     * Orden final. La hora habitual y la regularidad no existen en el SQL, asi que
     * se ordenan aqui sobre los CANDIDATOS ya traidos.
     */
    private static function ordenar_en_php(array $filas): array
    {
        $campo = self::$filtro->ordenar_por ?? '';
        $asc = (self::$filtro->orden ?? '') === 'asc';

        $llaves = [
            'hora' => fn($f) => $f->hora_habitual,
            'regularidad' => fn($f) => $f->regularidad,
            'hora_presenta' => fn($f) => $f->hora_presenta,
            'regularidad_presenta' => fn($f) => $f->regularidad_presenta,
            'hora_llegada' => fn($f) => $f->hora_llegada,
            'regularidad_llegada' => fn($f) => $f->regularidad_llegada,
            'confiabilidad' => fn($f) => $f->confiabilidad,
            'ruta_pct' => fn($f) => $f->ruta_pct,
            'par_pct' => fn($f) => $f->par_pct,
        ];

        if (!isset($llaves[$campo])) {
            return $filas;
        }

        usort($filas, function ($a, $b) use ($llaves, $campo, $asc) {
            $va = $llaves[$campo]($a);
            $vb = $llaves[$campo]($b);

            // los vacios siempre al final, en cualquier direccion
            if ($va === null || $vb === null) {
                return ($va === null) <=> ($vb === null);
            }

            return $asc ? $va <=> $vb : $vb <=> $va;
        });

        return $filas;
    }

    /**
     * Histogramas de 24 horas por grupo de las tres marcas de la jornada, en una
     * sola pasada sobre la base: se agrupa por clave y se cuentan las 24 horas de
     * cada marca con SUM(CASE ...), porque tres consultas repiten el escaneo.
     *
     * Devuelve ['ruta' => [clave => [hora => n]], 'presenta' => [...], 'llegada' => [...]]
     * con la forma que espera habitos().
     */
    private static function horas_por_marca(string $grupo, array $claves, ?string $base = null, ?array $params = null): array
    {
        $salida = ['ruta' => [], 'presenta' => [], 'llegada' => []];

        if (!$claves) {
            return $salida;
        }

        if ($base === null) {
            [$base, $params] = self::base_sql();
        }

        $clave = self::clave_sql($grupo, 'b');

        $columnas = [];
        foreach (self::MARCAS as $marca => $columna) {
            for ($hora = 0; $hora < 24; $hora++) {
                $columnas[] = "SUM(CASE WHEN b.$columna = $hora THEN 1 ELSE 0 END) AS {$marca}_$hora";
            }
        }

        $sql = "
        SELECT $clave AS clave,
            " . implode(",\n            ", $columnas) . "
        FROM ($base) b
        WHERE $clave IN (" . self::placeholders($claves) . ")
        GROUP BY $clave";

        foreach (DB::select($sql, array_merge($params, array_values($claves))) as $fila) {
            $indice = self::clave_indice($fila->clave);
            foreach (array_keys(self::MARCAS) as $marca) {
                for ($hora = 0; $hora < 24; $hora++) {
                    $n = (int) $fila->{$marca . '_' . $hora};
                    if ($n > 0) {
                        $salida[$marca][$indice][$hora] = $n;
                    }
                }
            }
        }

        return $salida;
    }

    /**
     * Cruce dia de la semana x hora de presenta para carga (mapa de calor). Se
     * arma con presenta y no con inicio de ruta porque el chofer declara la
     * llegada a planta; la salida depende de cuando lo atienden. Devuelve el
     * conteo para el mapa y el minuto promedio para el veredicto.
     *
     * Devuelve ['conteo' => [dia][hora] => viajes, 'minutos' => [dia][hora] => minuto].
     */
    private static function mapa_presenta(string $base, array $params): array
    {
        $sql = "
        SELECT b.dia_presenta AS dia, b.hora_presenta AS hora, COUNT(*) AS n,
               AVG(MINUTE(b.marca_presenta)) AS minuto
        FROM ($base) b
        WHERE b.dia_presenta IS NOT NULL AND b.hora_presenta IS NOT NULL
        GROUP BY b.dia_presenta, b.hora_presenta";

        $salida = ['conteo' => [], 'minutos' => []];
        foreach (DB::select($sql, $params) as $fila) {
            $salida['conteo'][(int) $fila->dia][(int) $fila->hora] = (int) $fila->n;
            $salida['minutos'][(int) $fila->dia][(int) $fila->hora] = (int) round((float) $fila->minuto);
        }

        return $salida;
    }

    /** Placas (o conductores) mas frecuentes del grupo, con su participacion. */
    private static function pares_lista(string $grupo, $clave, int $top, string $base, array $params): array
    {
        $par = $grupo === 'conductor' ? 'b.placa' : 'b.conductor_id';
        $parNombre = $grupo === 'conductor' ? 'MAX(b.placa)' : "COALESCE(MAX(b.conductor), 'SIN CONDUCTOR')";
        $noNulo = $grupo === 'conductor' ? 'b.placa IS NOT NULL' : 'b.conductor_id IS NOT NULL';

        $sql = "
        SELECT $par AS valor, $parNombre AS nombre, COUNT(*) AS n
        FROM ($base) b
        WHERE $noNulo
        GROUP BY $par
        ORDER BY n DESC
        LIMIT " . max(1, $top);

        $salida = [];
        foreach (DB::select($sql, $params) as $fila) {
            $salida[] = ['valor' => $fila->valor, 'nombre' => $fila->nombre, 'n' => (int) $fila->n];
        }

        return $salida;
    }

    /** Conteo por dia de la semana (0 = lunes) de un grupo. */
    private static function dias_por_clave(string $grupo, $clave, string $base, array $params): array
    {
        $sql = "
        SELECT b.dia_semana AS dia, COUNT(*) AS n
        FROM ($base) b
        WHERE b.dia_semana IS NOT NULL
        GROUP BY b.dia_semana
        ORDER BY b.dia_semana";

        $filas = DB::select($sql, $params);

        $salida = array_fill(0, 7, 0);
        foreach ($filas as $fila) {
            $salida[(int) $fila->dia] = (int) $fila->n;
        }

        return $salida;
    }

    /**
     * Par habitual: para el ranking por placa, el conductor que mas la maneja;
     * para el ranking por conductor, la placa que mas usa. Devuelve el conteo
     * de cada par para que el llamador calcule el porcentaje.
     */
    private static function pares_por_clave(string $grupo, array $claves, ?string $base = null, ?array $params = null): array
    {
        if (!$claves) {
            return [];
        }

        if ($base === null) {
            [$base, $params] = self::base_sql();
        }

        $clave = self::clave_sql($grupo, 'b');
        // el par es el otro extremo del binomio placa - conductor
        $par = $grupo === 'conductor' ? 'b.placa' : 'b.conductor_id';
        $parNombre = $grupo === 'conductor' ? 'MAX(b.placa)' : "COALESCE(MAX(b.conductor), 'SIN CONDUCTOR')";
        $noNulo = $grupo === 'conductor' ? 'b.placa IS NOT NULL' : 'b.conductor_id IS NOT NULL';

        $sql = "
        SELECT $clave AS clave, $par AS valor, $parNombre AS nombre, COUNT(*) AS n
        FROM ($base) b
        WHERE $noNulo
          AND $clave IN (" . self::placeholders($claves) . ")
        GROUP BY $clave, $par
        ORDER BY $clave, n DESC";

        $filas = DB::select($sql, array_merge($params, array_values($claves)));

        $salida = [];
        foreach ($filas as $fila) {
            $indice = self::clave_indice($fila->clave);
            // el ORDER BY ya dejo primero el par mas frecuente de cada grupo
            $salida[$indice] ??= ['nombre' => $fila->nombre, 'n' => (int) $fila->n];
        }

        return $salida;
    }

    /**
     * Reparto de actores por grupo: los choferes que manejan una placa (o las
     * placas que usa un chofer). Devuelve cuantos actores hay y la suma de sus
     * participaciones al cuadrado (HHI), que mide cuantas rutinas distintas
     * conviven: 1 = un solo actor, cerca de 0 cuando el grupo esta repartido.
     */
    private static function actores_por_clave(string $grupo, array $claves, ?string $base = null, ?array $params = null): array
    {
        if (!$claves) {
            return [];
        }

        if ($base === null) {
            [$base, $params] = self::base_sql();
        }

        $clave = self::clave_sql($grupo, 'b');
        // el actor es el otro extremo del binomio placa - conductor (0 = sin conductor)
        $actor = $grupo === 'conductor' ? 'b.placa' : 'b.conductor_clave';

        $sql = "
        SELECT $clave AS clave, $actor AS actor, COUNT(*) AS n
        FROM ($base) b
        WHERE $clave IN (" . self::placeholders($claves) . ")
        GROUP BY $clave, $actor";

        $conteo = [];
        foreach (DB::select($sql, array_merge($params, array_values($claves))) as $fila) {
            $conteo[self::clave_indice($fila->clave)][(string) $fila->actor] = (int) $fila->n;
        }

        $salida = [];
        foreach ($conteo as $indice => $actores) {
            $total = array_sum($actores);
            $hhi = 0.0;
            foreach ($actores as $n) {
                $p = $n / $total;
                $hhi += $p * $p;
            }

            $salida[$indice] = ['actores' => count($actores), 'hhi' => $hhi];
        }

        return $salida;
    }

    /**
     * Calendario dia x hora de presenta para carga por grupo. Es lo que permite
     * decir a que hora volvera a cargar mirando el dia de hoy, porque la rutina
     * de un martes no se parece a la de un domingo. Junto al conteo guarda el
     * minuto promedio de cada hora, que es el que se muestra en el veredicto.
     *
     * Devuelve ['conteo' => [clave => [dia][hora] => presentas],
     *           'minutos' => [clave => [dia][hora] => minuto promedio]].
     *
     * El minuto promedio se toma de `marca_presenta` porque la presentacion no cae
     * en punto: el veredicto muestra 10:38 y no una hora redondeada.
     */
    private static function calendario_presentas(string $grupo, array $claves, ?string $base = null, ?array $params = null): array
    {
        $salida = ['conteo' => [], 'minutos' => []];

        if (!$claves) {
            return $salida;
        }

        if ($base === null) {
            [$base, $params] = self::base_sql();
        }

        $clave = self::clave_sql($grupo, 'b');

        $sql = "
        SELECT $clave AS clave, b.dia_presenta AS dia, b.hora_presenta AS hora,
               COUNT(*) AS n, AVG(MINUTE(b.marca_presenta)) AS minuto
        FROM ($base) b
        WHERE b.dia_presenta IS NOT NULL AND b.hora_presenta IS NOT NULL
          AND $clave IN (" . self::placeholders($claves) . ")
        GROUP BY $clave, b.dia_presenta, b.hora_presenta";

        foreach (DB::select($sql, array_merge($params, array_values($claves))) as $fila) {
            $indice = self::clave_indice($fila->clave);
            $salida['conteo'][$indice][(int) $fila->dia][(int) $fila->hora] = (int) $fila->n;
            $salida['minutos'][$indice][(int) $fila->dia][(int) $fila->hora] = (int) round((float) $fila->minuto);
        }

        return $salida;
    }

    /**
     * Veredicto de la proxima carga: de las horas del calendario que todavia no
     * pasaron se toman las tres mas probables, empezando por hoy y avanzando dia
     * a dia cuando la jornada de hoy ya quedo atras. Cada opcion lleva el minuto
     * promedio real de esa hora, para no inventar precision de hora en punto.
     *
     * La probabilidad se mide sobre las presentas de ese mismo dia, y se ajusta
     * por el HHI de actores igual que la confiabilidad, porque con varios choferes
     * cada horario pesa menos.
     *
     * @param array $calendario [dia 0..6][hora 0..23] => presentas
     * @param array $minutos    [dia 0..6][hora 0..23] => minuto promedio
     */
    public static function veredicto_carga(array $calendario, array $minutos = [], ?float $hhi = null,
                                          ?Carbon $ahora = null, int $cuantas = 3): ?stdClass
    {
        if (!$calendario) {
            return null;
        }

        $ahora = $ahora ?? Carbon::now();
        $hoy = (int) $ahora->format('N') - 1;      // 0 = lunes, igual que WEEKDAY()
        $hora_actual = (int) $ahora->format('G');

        for ($salto = 0; $salto <= 7; $salto++) {
            $dia = ($hoy + $salto) % 7;
            $horas = $calendario[$dia] ?? [];
            if (!$horas) {
                continue;
            }

            // hoy solo cuentan las horas que todavia no empezaron
            $futuras = [];
            foreach ($horas as $hora => $n) {
                if ($salto > 0 || (int) $hora > $hora_actual) {
                    $futuras[(int) $hora] = (int) $n;
                }
            }
            if (!$futuras) {
                continue;
            }

            $total = array_sum($horas);
            arsort($futuras);

            $opciones = [];
            foreach (array_slice($futuras, 0, max(1, $cuantas), true) as $hora => $n) {
                $pct = self::pct($n, $total);
                $opciones[] = [
                    'hora' => (int) $hora,
                    'minuto' => $minutos[$dia][$hora] ?? 0,
                    'n' => (int) $n,
                    'pct' => $pct,
                    'confiabilidad' => ($pct !== null && $hhi !== null && $hhi > 0) ? round($pct * $hhi, 1) : null,
                ];
            }

            $principal = $opciones[0];

            return (object) [
                'hora' => $principal['hora'],
                'minuto' => $principal['minuto'],
                'n' => $principal['n'],
                'pct' => $principal['pct'],
                'confiabilidad' => $principal['confiabilidad'],
                'opciones' => $opciones,
                'dia' => $dia,
                'salto' => $salto,
                'dia_nombre' => self::$dias[$dia] ?? '',
                'relativo' => $salto === 0 ? 'hoy' : ($salto === 1 ? 'mañana' : (self::$dias[$dia] ?? '')),
                'consultado' => $ahora->format('H:i'),
                'dia_hoy' => self::$dias[$hoy] ?? '',
            ];
        }

        return null;
    }

    /** Rutas mas usadas por grupo (con nombre origen - destino). */
    private static function rutas_por_clave(string $grupo, array $claves, int $top = 1, ?string $base = null, ?array $params = null): array
    {
        if (!$claves) {
            return [];
        }

        if ($base === null) {
            [$base, $params] = self::base_sql();
        }

        $clave = self::clave_sql($grupo, 'b');

        $sql = "
        SELECT $clave AS clave, b.ruta_id AS ruta_id, COUNT(*) AS n
        FROM ($base) b
        WHERE b.ruta_id IS NOT NULL
          AND $clave IN (" . self::placeholders($claves) . ")
        GROUP BY $clave, b.ruta_id
        ORDER BY $clave, n DESC";

        $filas = DB::select($sql, array_merge($params, array_values($claves)));

        $conteo = [];
        $ids = [];
        foreach ($filas as $fila) {
            $indice = self::clave_indice($fila->clave);
            if (count($conteo[$indice] ?? []) >= $top) {
                continue;
            }
            $conteo[$indice][] = ['ruta_id' => $fila->ruta_id, 'n' => (int) $fila->n];
            $ids[$fila->ruta_id] = true;
        }

        // nombres en una sola consulta: el listado puede traer cientos de rutas
        $nombres = self::nombres_rutas(array_keys($ids));

        $salida = [];
        foreach ($conteo as $indice => $lista) {
            foreach ($lista as $item) {
                $salida[$indice][] = [
                    'ruta_id' => $item['ruta_id'],
                    'nombre' => $nombres[$item['ruta_id']] ?? (string) $item['ruta_id'],
                    'n' => $item['n'],
                ];
            }
        }

        return $salida;
    }

    /** Nombre origen - destino de las rutas pedidas. */
    private static function nombres_rutas(array $rutas): array
    {
        if (!$rutas) {
            return [];
        }

        $filas = DB::table('rutas as ru')
            ->leftJoin('locales as lo_orig', 'lo_orig.id', '=', 'ru.origen_id')
            ->leftJoin('locales as lo_dest', 'lo_dest.id', '=', 'ru.destino_id')
            ->leftJoin('ubigeo_distritos as ug_orig', 'ug_orig.ubigeo', '=', 'ru.ubigeo_origen')
            ->leftJoin('ubigeo_distritos as ug_dest', 'ug_dest.ubigeo', '=', 'ru.ubigeo_destino')
            ->whereIn('ru.id', $rutas)
            ->selectRaw("ru.id, lo_orig.nombre AS loc_origen_nombre, lo_dest.nombre AS loc_destino_nombre,
                ug_orig.distrito AS ubg_origen_nombre, ug_dest.distrito AS ubg_destino_nombre")
            ->get();

        $salida = [];
        foreach ($filas as $fila) {
            $salida[$fila->id] = DBTiemposRuta::nombre_ruta($fila);
        }

        return $salida;
    }

    /** Ultimos viajes del grupo, con los hitos a la vista. */
    private static function viajes_de_clave(string $grupo, $clave, int $limite = 25): array
    {
        [$base, $params] = self::base_con_clave($grupo, $clave);

        $sql = "
        SELECT w.ref, w.placa, w.conductor, w.trt, w.ruta_id,
               w.etapa_2, w.etapa_3, w.etapa_4, w.etapa_5, w.etapa_6,
               w.desvio, w.primera_marca, w.marca_presenta, w.ultima_marca, w.cerrado
        FROM ($base) w
        ORDER BY COALESCE(w.marca_presenta, w.ultima_marca, w.primera_marca) DESC
        LIMIT " . max(1, $limite);

        $filas = DB::select($sql, $params);

        $ids = [];
        foreach ($filas as $fila) {
            if ($fila->ruta_id !== null) {
                $ids[$fila->ruta_id] = true;
            }
        }
        $nombres = self::nombres_rutas(array_keys($ids));

        foreach ($filas as $fila) {
            $fila->ruta_nombre = $nombres[$fila->ruta_id] ?? (string) $fila->ruta_id;
        }

        return $filas;
    }

    //------------------- utilidades -------------------

    private static function placeholders(array $valores): string
    {
        return implode(',', array_fill(0, count($valores), '?'));
    }

    /** Indice del arreglo asociativo: las claves numericas de PHP no admiten el 0 de SIN CONDUCTOR. */
    private static function clave_indice($clave): string
    {
        return 'k' . (string) $clave;
    }

    private static function pct($parte, $total): ?float
    {
        $total = (int) $total;
        if ($total <= 0) {
            return null;
        }

        return round((int) $parte / $total * 100, 1);
    }

    /** Normaliza la fila del agregado y le agrega los porcentajes de cabecera. */
    private static function fila_resumen($fila): stdClass
    {
        $obj = new stdClass();
        $viajes = (int) ($fila->viajes ?? 0);

        foreach (['cerrados', 'sin_conductor', 'sin_presenta', 'rutas', 'placas', 'conductores',
                     'dias', 'puntual30', 'tarde120', 'desvio_n', 'desvio_mediana',
                     'e2', 'e3', 'e4', 'e5', 'e6', 'ciclo', 'ciclo_real', 'ciclo_real_n'] as $campo) {
            $obj->$campo = isset($fila->$campo) ? (int) $fila->$campo : null;
        }

        $obj->viajes = $viajes;
        $obj->primera = $fila->primera ?? null;
        $obj->ultima = $fila->ultima ?? null;
        // ritmo: viajes por semana entre la primera y la ultima salida
        $obj->viajes_semana = null;
        if ($obj->primera && $obj->ultima && $viajes > 0) {
            $dias = Carbon::parse($obj->primera)->startOfDay()
                ->diffInDays(Carbon::parse($obj->ultima)->startOfDay()) + 1;
            $obj->viajes_semana = round($viajes / max(1, $dias / 7), 1);
        }
        $obj->pct_cerrados = self::pct($obj->cerrados, $viajes);
        $obj->pct_sin_conductor = self::pct($obj->sin_conductor, $viajes);
        $obj->pct_sin_presenta = self::pct($obj->sin_presenta, $viajes);
        $obj->pct_puntual = self::pct($obj->puntual30, $obj->desvio_n);
        $obj->pct_tarde = self::pct($obj->tarde120, $obj->desvio_n);

        return $obj;
    }

    //------------------- presentacion -------------------

    /** "14 h" o "—" para la hora habitual. */
    public static function etiqueta_hora($hora): string
    {
        if ($hora === null || $hora === '') {
            return '—';
        }

        return str_pad((string) (int) $hora, 2, '0', STR_PAD_LEFT) . ':00';
    }

    /** Franja habitual completa: "20:00 - 22:00". */
    public static function franja_habitual($hora): string
    {
        if ($hora === null || $hora === '') {
            return '—';
        }

        $desde = (int) $hora - self::VENTANA_HABITUAL;
        $hasta = (int) $hora + self::VENTANA_HABITUAL + 1;

        return self::etiqueta_hora(($desde + 24) % 24) . ' - ' . self::etiqueta_hora($hasta % 24);
    }

    /**
     * Lista de horas alternativas ya formateada: "09:00 12% · 11:00 9%".
     */
    public static function texto_probables($top, int $desde = 0, int $cuantas = 3): string
    {
        $top = is_array($top) ? array_slice($top, $desde, $cuantas) : [];
        if (!$top) {
            return '—';
        }

        $partes = [];
        foreach ($top as $item) {
            $partes[] = self::etiqueta_hora($item['hora']) . ' ' . ($item['pct'] ?? 0) . '%';
        }

        return implode(' · ', $partes);
    }

    /**
     * Hora con el minuto promedio real: "10:38". La presentacion en carga no cae
     * en punto, asi que el veredicto muestra el minuto y no una hora redondeada.
     */
    public static function etiqueta_hora_min($hora, $minuto = 0): string
    {
        if ($hora === null || $hora === '') {
            return '—';
        }

        $minuto = max(0, min(59, (int) $minuto));

        return str_pad((string) (int) $hora, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minuto, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Opciones del veredicto ya formateadas: "10:38 15% · 14:12 12% · 20:05 8%".
     */
    public static function texto_opciones($opciones, int $desde = 0, int $cuantas = 3): string
    {
        $opciones = is_array($opciones) ? array_slice($opciones, $desde, $cuantas) : [];
        if (!$opciones) {
            return '—';
        }

        $partes = [];
        foreach ($opciones as $item) {
            $partes[] = self::etiqueta_hora_min($item['hora'] ?? null, $item['minuto'] ?? 0)
                . ' ' . ($item['pct'] ?? 0) . '%';
        }

        return implode(' · ', $partes);
    }

    /** Desvio de puntualidad con signo: negativo = llego antes del compromiso. */
    public static function texto_desvio($minutos): string
    {
        if ($minutos === null || $minutos === '') {
            return '—';
        }

        $minutos = (int) $minutos;
        $signo = $minutos > 0 ? '+' : ($minutos < 0 ? '−' : '');

        return $signo . DBTiemposRuta::formatear_minutos(abs($minutos));
    }

    /**
     * Color del desvio de puntualidad: llegar antes del compromiso no es un
     * problema, pero adelantarse mas de 4 h tampoco es normal.
     */
    public static function color_desvio($minutos): string
    {
        if ($minutos === null || $minutos === '') {
            return 'secondary';
        }

        $minutos = (int) $minutos;

        if ($minutos < -240) return 'info';
        if ($minutos <= 30) return 'success';
        if ($minutos <= 120) return 'warning';

        return 'danger';
    }

    /** Color de la regularidad horaria (porcentaje de viajes en la franja). */
    public static function color_regularidad($pct): string
    {
        if ($pct === null) return 'secondary';
        if ($pct >= 70) return 'success';
        if ($pct >= 45) return 'info';
        if ($pct >= 25) return 'warning';

        return 'danger';
    }

    /** Color del porcentaje de confiabilidad de la prediccion horaria. */
    public static function color_confiabilidad($pct): string
    {
        if ($pct === null) return 'secondary';
        if ($pct >= 25) return 'success';
        if ($pct >= 12) return 'info';
        if ($pct >= 5) return 'warning';

        return 'danger';
    }

    /** Color del cumplimiento de cierre (viajes con fin de descargue). */
    public static function color_cierre($pct): string
    {
        if ($pct === null) return 'secondary';
        if ($pct >= 90) return 'success';
        if ($pct >= 70) return 'info';
        if ($pct >= 50) return 'warning';

        return 'danger';
    }

    /** Color del porcentaje de viajes donde figura un conductor. */
    public static function color_conductor($pct): string
    {
        if ($pct === null) return 'secondary';
        if ($pct >= 95) return 'success';
        if ($pct >= 80) return 'info';
        if ($pct >= 60) return 'warning';

        return 'danger';
    }

    /** Fondo de una celda del mapa de calor hora x dia. */
    public static function color_celda($viajes, $maximo): string
    {
        if (!$maximo || !$viajes) {
            return 'transparent';
        }

        $ratio = min(1, $viajes / $maximo);

        if ($ratio <= 0.2) return 'rgba(13, 110, 253, .25)';
        if ($ratio <= 0.4) return 'rgba(13, 110, 253, .45)';
        if ($ratio <= 0.6) return 'rgba(25, 135, 84, .55)';
        if ($ratio <= 0.8) return 'rgba(255, 193, 7, .65)';

        return 'rgba(220, 53, 69, .75)';
    }
}
