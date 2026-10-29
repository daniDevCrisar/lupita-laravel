<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tiempos promedio de recorrido por ruta y por conductor.
 *
 * Los tiempos se calculan con los hitos de la tabla `referencias`:
 *   compromiso_carga -> presenta_para_carga -> inicio_de_carga -> inicio_ruta
 *   -> qr_llegada_destino -> inicio_descargue -> fin_descargue
 *
 * Todos los promedios se devuelven en MINUTOS.
 * La etapa 1 (compromiso_carga -> presenta_para_carga) queda FUERA del ciclo:
 * el ciclo completo inicia en `presenta_para_carga`.
 *
 * `ciclo_promedio` es el ciclo completo: la SUMA DE LOS PROMEDIOS de las
 * etapas E2..E6 que tienen datos (por eso la columna siempre cuadra con lo que
 * se ve en E2..E6, aunque un mismo viaje no tenga toda la cadena de hitos).
 * `ciclo_min`/`ciclo_max`/`ciclo_viajes` si exigen la cadena completa y salen
 * del `ciclo_total` observado viaje por viaje.
 * `ruta_promedio` es solo el recorrido origen -> destino, que corresponde
 * a la etapa 4 (inicio_ruta -> qr_llegada_destino).
 *
 * El tope de duracion (`max_etapa`) descarta el viaje completo si alguna
 * etapa registrada del ciclo cae fuera de [0, tope]: datos errados por
 * duracion excesiva o por hitos invertidos.
 */
class DBTiemposRuta
{
    public static $filtro;

    // Etapas del ciclo logistico medidas con los hitos de `referencias`
    public static $etapas = [
        1 => 'Compromiso - Presenta a carga (fuera del ciclo)',
        2 => 'Presenta - Inicio de carga',
        3 => 'Inicio de carga - Inicio de ruta',
        4 => 'Inicio de ruta - Llegada a destino',
        5 => 'Llegada a destino - Inicio de descargue',
        6 => 'Inicio de descargue - Fin de descargue',
    ];

    // Etapas sujetas al tope de duracion: la etapa 1 es solo de confirmacion
    // y no forma parte del ciclo, por eso queda excluida.
    public static $etapas_con_tope = [2, 3, 4, 5, 6];

    public static function set_filtro($request): void
    {
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio;
        self::$filtro->fecha_fin = $request->fecha_fin;
        self::$filtro->ruta_id = $request->ruta_id;
        self::$filtro->conductor = $request->conductor;
        self::$filtro->trt = $request->trt;
        self::$filtro->min_viajes = $request->min_viajes;
        self::$filtro->max_etapa = $request->max_etapa;
        self::$filtro->ordenar_por = $request->ordenar_por;
        self::$filtro->orden = $request->orden;
        self::$filtro->limit = $request->limit;
    }

    //------------------- filtros -------------------

    private static function filtro_fechas(): array
    {
        $sql = '';
        $params = [];

        if (self::$filtro->fecha_inicio ?? '') {
            $sql .= ' AND lf.created_at >= ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_inicio)->startOfDay();
        }

        if (self::$filtro->fecha_fin ?? '') {
            $sql .= ' AND lf.created_at < ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_fin)->addDay()->startOfDay();
        }

        return [$sql, $params];
    }

    private static function filtro_extra(): array
    {
        $sql = '';
        $params = [];

        $ruta = trim((string) (self::$filtro->ruta_id ?? ''));
        if ($ruta !== '') {
            $sql .= ' AND r.ruta_id = ? ';
            $params[] = $ruta;
        }

        $conductor = trim((string) (self::$filtro->conductor ?? ''));
        if ($conductor !== '') {
            if (is_numeric($conductor)) {
                $sql .= ' AND COALESCE(r.conductor_id, lr.conductor_id) = ? ';
                $params[] = (int) $conductor;
            } else {
                $sql .= ' AND co.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($conductor) . '%';
            }
        }

        $trt = trim((string) (self::$filtro->trt ?? ''));
        if ($trt !== '') {
            if (is_numeric($trt)) {
                $sql .= ' AND COALESCE(r.trt_id, lr.trt_id) = ? ';
                $params[] = (int) $trt;
            } else {
                $sql .= ' AND t.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($trt) . '%';
            }
        }

        return [$sql, $params];
    }

    /**
     * Tope de duracion por etapa en minutos. `null` = filtro desactivado.
     */
    public static function max_etapa(): ?int
    {
        $tope = (int) (self::$filtro->max_etapa ?? 0);

        return $tope > 0 ? $tope : null;
    }

    /**
     * Expresiones TIMESTAMPDIFF de cada etapa. Son la unica fuente de las
     * formulas: `base_sql` las usa para las columnas `etapa_N`, para el ciclo
     * y para el tope (ahi aun no existen los alias de columna).
     */
    private static function expresiones_etapas(): array
    {
        return [
            1 => 'TIMESTAMPDIFF(MINUTE, r.compromiso_carga, r.presenta_para_carga)',
            2 => 'TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga)',
            3 => 'TIMESTAMPDIFF(MINUTE, r.inicio_de_carga, r.inicio_ruta)',
            4 => 'TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino)',
            5 => 'TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue)',
            6 => 'TIMESTAMPDIFF(MINUTE, r.inicio_descargue, r.fin_descargue)',
        ];
    }

    /**
     * Columnas `etapa_1 ... etapa_6` de la vista base.
     */
    private static function select_etapas(): string
    {
        $columnas = [];
        foreach (self::expresiones_etapas() as $etapa => $expr) {
            $columnas[] = "$expr AS etapa_$etapa";
        }

        return implode(",\n            ", $columnas);
    }

    /**
     * Ciclo observado de un viaje: solo es calculable si ningun hito
     * intermedio falta y ninguno esta invertido. Si falta un hito, la
     * diferencia entre los extremos abarca el hueco (dias enteros) y inflaria
     * el dato, asi que queda `NULL`.
     *
     * Se usa para `ciclo_min`, `ciclo_max` y `ciclo_viajes`; el promedio se
     * arma sumando promedios de etapa (ver `ciclo_promedio_sql`).
     */
    private static function ciclo_total_sql(): string
    {
        $expresiones = self::expresiones_etapas();

        $etapas = [];
        foreach (self::$etapas_con_tope as $etapa) {
            $etapas[] = $expresiones[$etapa];
        }

        // LEAST devuelve NULL si a alguna etapa le falta el hito: el CASE cae a NULL
        return 'CASE WHEN LEAST(' . implode(', ', $etapas) . ') >= 0'
            . ' THEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue)'
            . ' END AS ciclo_total';
    }

    /**
     * Descarta el viaje completo si alguna etapa registrada del ciclo esta
     * fuera de [0, tope]: por durar mas que el maximo o por tener hitos
     * invertidos. Las etapas sin hito (`NULL`) no descartan.
     */
    private static function filtro_tope_etapa(): array
    {
        $tope = self::max_etapa();
        if ($tope === null) {
            return ['', []];
        }

        $expresiones = self::expresiones_etapas();

        $sql = '';
        $params = [];
        foreach (self::$etapas_con_tope as $etapa) {
            $expr = $expresiones[$etapa];
            $sql .= " AND ($expr IS NULL OR $expr BETWEEN 0 AND ?) ";
            $params[] = $tope;
        }

        return [$sql, $params];
    }

    /**
     * Vista base: una fila por referencia con el conductor/trt efectivo
     * y los minutos de cada etapa del ciclo.
     *
     * El filtro de fechas se aplica sobre `llamadas.created_at` (igual que
     * el resto de listados del sistema), por eso la subconsulta de llamadas.
     *
     * `$aplicar_tope = false` devuelve la base sin el tope de duracion, que es
     * lo necesario para contar cuantos viajes descarta dicho filtro.
     */
    private static function base_sql(bool $aplicar_tope = true): array
    {
        $fechas = self::filtro_fechas();
        $extra = self::filtro_extra();
        $tope = $aplicar_tope ? self::filtro_tope_etapa() : ['', []];

        $sql = "
        SELECT
            r.ref AS ref,
            r.ruta_id AS ruta_id,
            COALESCE(r.conductor_id, lr.conductor_id) AS conductor_id,
            COALESCE(r.trt_id, lr.trt_id) AS trt_id,
            co.nombres AS conductor,
            t.nombres AS trt,

            " . self::select_etapas() . ",
            -- ciclo observado: solo con toda la cadena de hitos en orden
            " . self::ciclo_total_sql() . "
        FROM referencias r
        INNER JOIN (
            SELECT lf.ref AS ref,
                   MAX(lf.conductor_id) AS conductor_id,
                   MAX(lf.trt_id) AS trt_id
            FROM llamadas lf
            WHERE lf.ref IS NOT NULL " . $fechas[0] . "
            GROUP BY lf.ref
        ) lr ON lr.ref = r.ref
        LEFT JOIN conductores co ON co.id = COALESCE(r.conductor_id, lr.conductor_id)
        LEFT JOIN trts t ON t.id = COALESCE(r.trt_id, lr.trt_id)
        WHERE r.ruta_id IS NOT NULL " . $extra[0] . $tope[0];

        return [$sql, array_merge($fechas[1], $extra[1], $tope[1])];
    }

    //------------------- columnas calculadas -------------------

    /**
     * Promedio sin redondear de una etapa, ignorando hitos invertidos.
     */
    private static function promedio_etapa_sql(int $etapa): string
    {
        return "AVG(CASE WHEN t.etapa_$etapa >= 0 THEN t.etapa_$etapa END)";
    }

    /**
     * Cuantas etapas del ciclo (E2..E6) aportan un promedio real.
     */
    private static function ciclo_etapas_con_datos_sql(): string
    {
        $partes = [];
        foreach (self::$etapas_con_tope as $etapa) {
            $partes[] = '(' . self::promedio_etapa_sql($etapa) . ' IS NOT NULL)';
        }

        return '(' . implode(' + ', $partes) . ')';
    }

    /**
     * Ciclo promedio = suma de los promedios de las etapas del ciclo que si
     * tienen datos. La columna cuadra siempre con lo que se ve en E2..E6 y no
     * depende de que un mismo viaje tenga toda la cadena de hitos registrada.
     * `NULL` solo cuando ninguna etapa tiene datos.
     */
    private static function ciclo_promedio_sql(): string
    {
        $suma = [];
        foreach (self::$etapas_con_tope as $etapa) {
            $suma[] = 'COALESCE(' . self::promedio_etapa_sql($etapa) . ', 0)';
        }

        return 'CASE WHEN ' . self::ciclo_etapas_con_datos_sql() . ' = 0 THEN NULL'
            . ' ELSE (' . implode(' + ', $suma) . ') END';
    }

    /**
     * Columnas de tiempo agrupables. Los promedios ignoran diferencias
     * negativas (hitos invertidos). El ciclo es la suma de promedios de
     * etapa; `ciclo_etapas` cuenta cuantas etapas entraron en esa suma y
     * `ciclo_viajes`/`ciclo_min`/`ciclo_max` se mantienen sobre los viajes que
     * si tienen toda la cadena de hitos (ciclos reales observados).
     */
    private static function select_tiempos(bool $detalle = true): string
    {
        $columnas = [
            'COUNT(*) AS viajes',
            // recorrido origen -> destino (etapa 4)
            'ROUND(AVG(CASE WHEN t.etapa_4 >= 0 THEN t.etapa_4 END), 1) AS ruta_promedio',
            'MIN(CASE WHEN t.etapa_4 >= 0 THEN t.etapa_4 END) AS ruta_min',
            'MAX(CASE WHEN t.etapa_4 >= 0 THEN t.etapa_4 END) AS ruta_max',
            // ciclo completo: suma de los promedios de E2..E6 con datos
            'ROUND(' . self::ciclo_promedio_sql() . ', 1) AS ciclo_promedio',
            self::ciclo_etapas_con_datos_sql() . ' AS ciclo_etapas',
            'SUM(CASE WHEN t.ciclo_total IS NOT NULL THEN 1 ELSE 0 END) AS ciclo_viajes',
            'MIN(CASE WHEN t.ciclo_total >= 0 THEN t.ciclo_total END) AS ciclo_min',
            'MAX(CASE WHEN t.ciclo_total >= 0 THEN t.ciclo_total END) AS ciclo_max',
        ];

        foreach (array_keys(self::$etapas) as $etapa) {
            if ($detalle) {
                $columnas[] = "SUM(CASE WHEN t.etapa_$etapa IS NOT NULL THEN 1 ELSE 0 END) AS etapa_{$etapa}_viajes";
            }
            $columnas[] = 'ROUND(' . self::promedio_etapa_sql($etapa) . ', 1) AS etapa_' . $etapa . '_promedio';
        }

        return implode(",\n            ", $columnas);
    }

    private static function order_by_sql(array $columnas): string
    {
        $ordenar_por = self::$filtro->ordenar_por ?? '';

        if ($ordenar_por === '' || !isset($columnas[$ordenar_por])) {
            return 'ORDER BY ruta_promedio IS NULL, ruta_promedio ASC';
        }

        $columna = $columnas[$ordenar_por];
        $direccion = (self::$filtro->orden ?? '') === '0' ? 'DESC' : 'ASC';

        return "ORDER BY $columna IS NULL, $columna $direccion";
    }

    private static function min_viajes(): int
    {
        return max(1, (int) (self::$filtro->min_viajes ?? 1));
    }

    private static function limite(): int
    {
        return max(1, min(1000, (int) (self::$filtro->limit ?? 100)));
    }

    //------------------- consultas -------------------

    public static function resumen(): stdClass
    {
        [$base, $params] = self::base_sql();

        $sql = "
        SELECT
            " . self::select_tiempos() . ",
            COUNT(DISTINCT t.ruta_id) AS rutas,
            COUNT(DISTINCT t.conductor_id) AS conductores
        FROM ($base) t";

        $result = DB::select($sql, $params);

        return $result[0] ?? (object) [
            'viajes' => 0,
            'ruta_promedio' => null,
            'ruta_min' => null,
            'ruta_max' => null,
            'ciclo_promedio' => null,
            'ciclo_etapas' => 0,
            'ciclo_viajes' => 0,
            'ciclo_min' => null,
            'ciclo_max' => null,
            'rutas' => 0,
            'conductores' => 0,
        ];
    }

    /**
     * Viajes que descarta el tope de duracion (mismos demas filtros, sin tope).
     * `null` cuando el tope esta desactivado.
     */
    public static function viajes_descartados(): ?int
    {
        $tope = self::max_etapa();
        if ($tope === null) {
            return null;
        }

        // base sin tope, para comparar contra la misma poblacion
        [$base, $params] = self::base_sql(false);

        $condiciones = [];
        foreach (self::$etapas_con_tope as $etapa) {
            $condiciones[] = "(t.etapa_$etapa IS NOT NULL AND (t.etapa_$etapa < 0 OR t.etapa_$etapa > ?))";
            $params[] = $tope;
        }

        $sql = "
        SELECT COUNT(*) AS descartados
        FROM ($base) t
        WHERE " . implode(' OR ', $condiciones);

        $result = DB::select($sql, $params);

        return (int) ($result[0]->descartados ?? 0);
    }

    public static function tiempo_promedio_por_ruta(): array
    {
        [$base, $params] = self::base_sql();

        $sql = "
        SELECT
            g.*,
            (SELECT nombre FROM locales WHERE locales.id = ru.origen_id) AS loc_origen_nombre,
            (SELECT nombre FROM locales WHERE locales.id = ru.destino_id) AS loc_destino_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = ru.ubigeo_origen) AS ubg_origen_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = ru.ubigeo_destino) AS ubg_destino_nombre
        FROM (
            SELECT
                t.ruta_id,
                COUNT(DISTINCT t.conductor_id) AS conductores,
                " . self::select_tiempos() . "
            FROM ($base) t
            GROUP BY t.ruta_id
            HAVING viajes >= ?
        ) g
        LEFT JOIN rutas ru ON ru.id = g.ruta_id
        " . self::order_by_sql([
            'ruta' => 'ruta_promedio',
            'ciclo' => 'ciclo_promedio',
            'viajes' => 'viajes',
            'conductores' => 'conductores',
        ]) . "
        LIMIT " . self::limite();

        return DB::select($sql, array_merge($params, [self::min_viajes()]));
    }

    public static function tiempo_promedio_por_conductor(): array
    {
        [$base, $params] = self::base_sql();

        $sql = "
    SELECT * FROM (
        SELECT
            t.conductor_id,
            COALESCE(MAX(t.conductor), 'SIN CONDUCTOR') AS conductor,
            MAX(t.trt) AS trt,
            COUNT(DISTINCT t.ruta_id) AS rutas,
            " . self::select_tiempos() . "
        FROM ($base) t
        GROUP BY t.conductor_id
        HAVING viajes >= ?
    ) x
    " . self::order_by_sql([
                'ruta' => 'ruta_promedio',
                'ciclo' => 'ciclo_promedio',
                'viajes' => 'viajes',
                'rutas' => 'rutas',
            ]) . "
    LIMIT " . self::limite();

        return DB::select($sql, array_merge($params, [self::min_viajes()]));
    }

    /**
     * Cruce ruta x conductor, para comparar a cada conductor en una misma ruta.
     */
    public static function matriz_ruta_conductor(): array
    {
        [$base, $params] = self::base_sql();

        $sql = "
        SELECT
            g.*,
            (SELECT nombre FROM locales WHERE locales.id = ru.origen_id) AS loc_origen_nombre,
            (SELECT nombre FROM locales WHERE locales.id = ru.destino_id) AS loc_destino_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = ru.ubigeo_origen) AS ubg_origen_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = ru.ubigeo_destino) AS ubg_destino_nombre
        FROM (
            SELECT
                t.ruta_id,
                t.conductor_id,
                COALESCE(MAX(t.conductor), 'SIN CONDUCTOR') AS conductor,
                " . self::select_tiempos(false) . "
            FROM ($base) t
            GROUP BY t.ruta_id, t.conductor_id
            HAVING viajes >= ?
        ) g
        LEFT JOIN rutas ru ON ru.id = g.ruta_id
        " . self::order_by_sql([
            'ruta' => 'ruta_promedio',
            'ciclo' => 'ciclo_promedio',
            'viajes' => 'viajes',
        ]) . "
        LIMIT " . self::limite();

        return DB::select($sql, array_merge($params, [self::min_viajes()]));
    }

    /**
     * Listado de rutas para el selector de filtros.
     */
    public static function rutas_lista(): array
    {
        return DB::select("
        SELECT r.*,
            (SELECT nombre FROM locales WHERE locales.id = r.origen_id) AS loc_origen_nombre,
            (SELECT nombre FROM locales WHERE locales.id = r.destino_id) AS loc_destino_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = r.ubigeo_origen) AS ubg_origen_nombre,
            (SELECT distrito FROM ubigeo_distritos WHERE ubigeo_distritos.ubigeo = r.ubigeo_destino) AS ubg_destino_nombre
        FROM rutas r
        ORDER BY r.id");
    }

    //------------------- presentacion -------------------

    public static function nombre_ruta($item): string
    {
        $origen = $item->loc_origen_nombre ?? $item->ubg_origen_nombre ?? '';
        $destino = $item->loc_destino_nombre ?? $item->ubg_destino_nombre ?? '';

        if ($origen === '' and $destino === '') {
            return (string) ($item->ruta_id ?? '');
        }

        return trim(trim($origen) . ' - ' . trim($destino));
    }

    public static function formatear_minutos($minutos): string
    {
        if ($minutos === null or $minutos === '') {
            return '—';
        }

        $minutos = (int) round($minutos);
        $signo = $minutos < 0 ? '-' : '';
        $minutos = abs($minutos);

        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);
        $min = $minutos % 60;

        if ($dias > 0) {
            return $signo . $dias . 'd ' . $horas . 'h';
        }
        if ($horas > 0) {
            return $signo . $horas . 'h ' . str_pad((string) $min, 2, '0', STR_PAD_LEFT) . 'm';
        }

        return $signo . $min . 'm';
    }

    /**
     * Desviacion porcentual de un tiempo contra la referencia (promedio).
     */
    public static function desviacion($minutos, $referencia): string
    {
        if (!$minutos or !$referencia) {
            return '—';
        }

        $desviacion = round((($minutos - $referencia) / $referencia) * 100, 1);

        return ($desviacion > 0 ? '+' : '') . $desviacion . '%';
    }

    /**
     * Color de bootstrap segun que tan lejos esta el tiempo de la referencia.
     */
    public static function color_por_tiempo($minutos, $referencia = null): string
    {
        if ($minutos === null or !$referencia or $referencia <= 0) {
            return 'secondary';
        }

        $ratio = $minutos / $referencia;

        if ($ratio <= 0.9) return 'success';
        if ($ratio <= 1.15) return 'info';
        if ($ratio <= 1.4) return 'warning';

        return 'danger';
    }
}
