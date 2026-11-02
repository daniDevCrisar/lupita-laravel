<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Agregaciones del reporte ejecutivo VAPI (version optimizada).
 *
 * Diferencia con DBLlamadas: cada bloque del reporte se resuelve con UNA sola
 * consulta agregada sobre `llamadas` y el rango/tipo/transportista viaja en el
 * mismo WHERE. No se muta el filtro estatico entre bloques, asi que el orden de
 * las llamadas nunca altera el resultado (el helper anterior si lo mutaba para
 * recorrer los tipos de llamada).
 *
 * El objeto que devuelve kpis() mantiene las mismas claves que
 * DBLlamadas::etiqueta_totales(), de modo que los partials de correo
 * (kpi-cards, etapa-logistica, errores-tecnicos) se reutilizan sin cambios.
 */
class DBReportes
{
    /** Filtro unico de la corrida: fechas, tipo de llamada y transportista. */
    public static $filtro;

    /** Tipos que siempre aparecen en el reporte, aunque no tengan llamadas. */
    public const TIPOS_FIJOS = [1, 2, 3, 5, 6];

    /** Etiquetas de texto que agrupan los fallos (mismo criterio que todo1). */
    public const ETIQUETAS_FALLO = [
        'fallo_contacto' => ['buzon_de_voz', 'conductor_no_contesta', 'conductor_cuelga'],
        'conductor_no_coopera' => ['conductor_contesta_pero_no_habla', 'conductor_no_escucha', 'conductor_conducta_inapropiada'],
        'errores_tecnicos' => ['error_tecnico_llamada', 'error_audio', 'error_red'],
    ];

    public static function set_filtro($request): void
    {
        $tipo = $request->llamada_tipo_id ?? null;
        $trt = $request->trt ?? null;

        self::$filtro = (object) [
            'fecha_inicio' => ($request->fecha_inicio ?? null) ?: null,
            'fecha_fin' => ($request->fecha_fin ?? null) ?: null,
            'llamada_tipo_id' => ($tipo === null || $tipo === '') ? null : (int) $tipo,
            'trt_id' => ($trt === null || $trt === '') ? null : (int) $trt,
        ];
    }

    /**
     * Rango efectivo de la corrida. `fin` es el ultimo dia con datos y
     * `fin_exclusivo` el limite abierto que usan las consultas.
     */
    public static function periodo(): stdClass
    {
        $desde = self::$filtro->fecha_inicio ?? null;
        $hasta = self::$filtro->fecha_fin ?? null;

        if (!$desde && $hasta) $desde = $hasta;
        if (!$desde) $desde = date('Y-m-d');
        if (!$hasta) $hasta = $desde;

        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->startOfDay();
        // si el usuario invierte el rango, se corrige en vez de devolver vacio
        if ($fin->lt($inicio)) [$inicio, $fin] = [$fin, $inicio];

        $p = new stdClass();
        $p->inicio = $inicio;
        $p->fin = $fin;
        // diffInDays devuelve float en Carbon 3; se normaliza a int
        $p->dias = (int) $inicio->diffInDays($fin) + 1;
        $p->desde = $inicio->toDateString();
        $p->hasta = $fin->toDateString();
        $p->fin_exclusivo = $fin->copy()->addDay()->startOfDay();

        return $p;
    }

    /** Granularidad de la serie temporal segun el largo del rango. */
    public static function granularidad(int $dias): string
    {
        if ($dias <= 62) return 'dia';
        if ($dias <= 400) return 'semana';
        return 'mes';
    }

    /** Ultimo dia con llamadas registradas (para el rango por defecto). */
    public static function ultima_fecha_con_datos(): string
    {
        $ultima = DB::selectOne('SELECT DATE(MAX(created_at)) AS ultima FROM llamadas')->ultima;

        return $ultima ?: date('Y-m-d');
    }

    /** WHERE comun (rango + tipo + transportista) con sus bindings. */
    private static function where($alias, Carbon $inicio, Carbon $fin): array
    {
        $sql = " AND $alias.created_at >= ? AND $alias.created_at < ? ";
        $params = [$inicio->toDateTimeString(), $fin->toDateTimeString()];

        if (self::$filtro->llamada_tipo_id !== null) {
            $sql .= " AND $alias.llamada_tipo_id = ? ";
            $params[] = self::$filtro->llamada_tipo_id;
        }

        if (self::$filtro->trt_id !== null) {
            $sql .= " AND $alias.trt_id = ? ";
            $params[] = self::$filtro->trt_id;
        }

        return [$sql, $params];
    }

    /**
     * Totales del periodo. `llamada_exitosa` cuenta solo las marcadas 1 (las
     * -1 quedan contadas aparte en `indeterminadas`), que es lo que espera el
     * partial kpi-cards al calcular fallidas = total - exitosas.
     */
    public static function kpis($inicio = null, $fin = null): stdClass
    {
        $p = self::periodo();
        $inicio = $inicio ?: $p->inicio;
        $fin = $fin ?: $p->fin_exclusivo;
        [$where, $params] = self::where('a', $inicio, $fin);

        $sql = "SELECT
            COUNT(*) AS llamadas,
            SUM(a.llamada_exitosa = 1) AS llamada_exitosa,
            SUM(a.llamada_exitosa = 0) AS fallidas_reales,
            SUM(a.llamada_exitosa = -1) AS indeterminadas,
            COUNT(DISTINCT a.conductor_id) AS conductores,
            COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL)) AS conductores_exitosos,
            COUNT(DISTINCT a.trt_id) AS trts,
            SUM(a.razon_finalizacion_id = 3) AS razon_3_no_contesta,
            SUM(a.razon_finalizacion_id = 5) AS razon_5_ocupado,
            SUM(IF(a.entro_llamada AND a.llamada_exitosa = 0 AND a.buzon_de_voz = 0, a.audio_duracion, 0)) AS audio_duracion_fallidas_sin_buzon,
            SUM(a.error_origen = -1) AS error_desconocido,
            SUM(a.error_origen = 0) AS error_humano,
            SUM(a.error_origen = 1) AS error_ia,
            SUM(a.error_origen = 2) AS error_red,
            SUM(a.error_origen = 3) AS error_sistema,
            SUM(a.llamada_exitosa = 0 AND a.conductor_confirma AND a.error_origen = 0) AS confirmacion_parcial,
            SUM(
                a.conductor_confirma + a.buzon_de_voz + a.conductor_contesta_pero_no_habla +
                a.conductor_no_escucha + a.conductor_da_motivos + a.conductor_mala_senal + a.confusion_en_llamada +
                a.contesta_otra_persona + a.numero_equivocado + a.conversacion_fluida + a.llamada_interesante +
                a.conductor_conducta_inapropiada + a.error_tecnico_llamada + a.error_audio = 0
                AND a.conductor_cuelga AND a.llamada_exitosa = 0 AND a.error_origen = 0
            ) AS solo_cuelga,
            SUM(a.exitosa_segun_ia) AS exitosa_segun_ia,
            SUM(a.entro_llamada) - SUM(a.buzon_de_voz) AS contestadas,
            SUM(IF(a.entro_llamada AND a.llamada_exitosa = 0 AND a.buzon_de_voz = 0, 1, 0)) AS contestadas_fallidas,
            SUM(a.audio_duracion) AS audio_duracion_total,
            SUM(a.audio_duracion * (a.llamada_exitosa = 1)) AS audio_duracion_exitosas,
            SUM(a.audio_duracion * (a.llamada_exitosa = 0)) AS audio_duracion_fallidas,
            SUM(a.audio_duracion * a.buzon_de_voz) AS buzon_duracion,
            SUM(a.conductor_confirma) AS conductor_confirma,
            SUM(a.buzon_de_voz) AS buzon_de_voz,
            SUM(a.conductor_contesta_pero_no_habla AND a.llamada_exitosa = 0) AS conductor_contesta_pero_no_habla,
            SUM(a.conductor_no_escucha AND a.llamada_exitosa = 0) AS conductor_no_escucha,
            SUM(a.conductor_da_motivos) AS conductor_da_motivos,
            SUM(a.conductor_mala_senal AND a.llamada_exitosa = 0) AS conductor_mala_senal,
            SUM(a.confusion_en_llamada AND a.llamada_exitosa = 0) AS confusion_en_llamada,
            SUM(a.contesta_otra_persona AND a.llamada_exitosa = 0) AS contesta_otra_persona,
            SUM(a.numero_equivocado AND a.llamada_exitosa = 0) AS numero_equivocado,
            SUM(a.conversacion_fluida) AS conversacion_fluida,
            SUM(a.llamada_interesante) AS llamada_interesante,
            SUM(a.ia_se_confunde) AS ia_se_confunde,
            SUM(a.ia_no_escucha) AS ia_no_escucha,
            SUM(a.ia_cambio_de_datos) AS ia_cambio_de_datos,
            SUM(a.ia_error_interpretacion) AS ia_error_interpretacion,
            SUM(a.ia_dice_variable) AS ia_dice_variable,
            SUM(a.ia_mala_pronunciacion) AS ia_mala_pronunciacion,
            SUM(a.ia_cuelga_en_plena_llamada) AS ia_cuelga_en_plena_llamada,
            SUM(a.conductor_cuelga) AS conductor_cuelga,
            SUM(a.conductor_no_contesta) AS conductor_no_contesta,
            SUM(a.conductor_conducta_inapropiada) AS conductor_conducta_inapropiada,
            SUM(a.error_tecnico_llamada) AS error_tecnico_llamada,
            SUM(a.error_audio) AS error_audio,
            SUM(a.costo) AS costo,
            SUM(IF(a.llamada_exitosa = 1, a.costo, 0)) AS costo_exitosa,
            SUM(IF(a.llamada_exitosa = 0, a.costo, 0)) AS costo_fallida,
            SUM(a.costo * a.buzon_de_voz) AS buzon_costo
        FROM llamadas a
        WHERE 1 = 1 " . $where;

        $t = DB::select($sql, $params)[0];
        self::derivar_kpis($t);

        return $t;
    }

    /** Metricas derivadas que no necesitan otra consulta. */
    private static function derivar_kpis(stdClass $t): void
    {
        $t->total = (int) $t->llamadas;
        $t->exitosas = (int) $t->llamada_exitosa;
        $t->fallidas = $t->total - $t->exitosas;
        $t->tasa_exito = $t->total ? round($t->exitosas / $t->total * 100, 1) : 0;
        $t->tasa_contestacion = $t->total ? round($t->contestadas / $t->total * 100, 1) : 0;
        $t->duracion_prom_exitosa = $t->exitosas ? round($t->audio_duracion_exitosas / $t->exitosas) : 0;
        $t->duracion_prom_fallida = $t->contestadas_fallidas
            ? round($t->audio_duracion_fallidas_sin_buzon / $t->contestadas_fallidas) : 0;
        $t->costo_confirmacion = $t->exitosas ? round($t->costo / $t->exitosas, 3) : 0;
        $t->buzon_tiempo_pct = $t->audio_duracion_total
            ? round($t->buzon_duracion / $t->audio_duracion_total * 100, 1) : 0;
        $t->errores_tecnicos = (int) $t->error_desconocido + (int) $t->error_red
            + (int) $t->error_sistema + (int) $t->error_ia;
    }

    /**
     * KPIs del periodo inmediatamente anterior de igual duracion, para medir
     * la variacion. Devuelve [objeto_kpis, periodo_anterior].
     */
    public static function kpis_anterior(): array
    {
        $p = self::periodo();

        $hasta = $p->inicio->copy()->subDay()->startOfDay();
        $desde = $hasta->copy()->subDays($p->dias - 1)->startOfDay();
        $fin_exclusivo = $hasta->copy()->addDay()->startOfDay();

        $anterior = new stdClass();
        $anterior->desde = $desde->toDateString();
        $anterior->hasta = $hasta->toDateString();

        return [self::kpis($desde, $fin_exclusivo), $anterior];
    }

    /** Variacion porcentual segura entre dos valores. */
    public static function variacion($actual, $previo): ?float
    {
        if (!$previo) return null;
        return round((($actual - $previo) / $previo) * 100, 1);
    }

    /**
     * Serie por dia con los dias vacios rellenados (solo para rangos cortos,
     * para que el grafico no salte huecos).
     */
    public static function por_dia(): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            DATE(a.created_at) AS fecha,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            COUNT(DISTINCT a.conductor_id) AS conductores,
            SUM(a.audio_duracion) AS duracion,
            SUM(a.costo) AS costo
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY DATE(a.created_at)
        ORDER BY fecha";

        $filas = [];
        foreach (DB::select($sql, $params) as $fila) {
            $filas[(string) $fila->fecha] = $fila;
        }

        $salida = [];
        if ($p->dias <= 62) {
            $cursor = $p->inicio->copy();
            while ($cursor->lte($p->fin)) {
                $salida[] = self::fila_dia($cursor->toDateString(), $filas[$cursor->toDateString()] ?? null);
                $cursor->addDay();
            }
            return $salida;
        }

        foreach ($filas as $fecha => $fila) {
            $salida[] = self::fila_dia($fecha, $fila);
        }

        return $salida;
    }

    private static function fila_dia(string $fecha, $fila): stdClass
    {
        $d = new stdClass();
        $d->fecha = $fecha;
        $d->total = $fila ? (int) $fila->total : 0;
        $d->exitosas = $fila ? (int) $fila->exitosas : 0;
        $d->fallidas = $fila ? (int) $fila->fallidas : 0;
        $d->conductores = $fila ? (int) $fila->conductores : 0;
        $d->duracion = $fila ? (int) $fila->duracion : 0;
        $d->costo = $fila ? (float) $fila->costo : 0;
        $d->tasa_exito = $d->total ? round($d->exitosas / $d->total * 100, 1) : 0;
        $d->duracion_prom = $d->total ? round($d->duracion / $d->total) : 0;
        $d->costo_confirmacion = $d->exitosas ? round($d->costo / $d->exitosas, 3) : 0;
        $d->fecha_carbon = Carbon::parse($fecha);

        return $d;
    }

    /** KPIs por tipo de llamada, indexados por id. */
    public static function por_tipo(): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            a.llamada_tipo_id AS id,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            SUM(a.llamada_exitosa = -1) AS indeterminadas,
            COUNT(DISTINCT a.conductor_id) AS conductores,
            COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL)) AS conductores_exitosos,
            COUNT(DISTINCT a.trt_id) AS trts,
            SUM(a.entro_llamada) - SUM(a.buzon_de_voz) AS contestadas,
            SUM(a.buzon_de_voz) AS buzon_de_voz,
            SUM(a.razon_finalizacion_id = 3) AS razon_3_no_contesta,
            SUM(a.razon_finalizacion_id = 5) AS razon_5_ocupado,
            SUM(a.audio_duracion) AS duracion,
            SUM(a.costo) AS costo,
            SUM(a.conductor_confirma) AS conductor_confirma
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY a.llamada_tipo_id
        ORDER BY total DESC";

        $salida = [];
        foreach (DB::select($sql, $params) as $fila) {
            $fila->total = (int) $fila->total;
            $fila->exitosas = (int) $fila->exitosas;
            $fila->fallidas = (int) $fila->fallidas;
            $fila->tasa_exito = $fila->total ? round($fila->exitosas / $fila->total * 100, 1) : 0;
            $fila->costo_confirmacion = $fila->exitosas ? round($fila->costo / $fila->exitosas, 3) : 0;
            $fila->duracion_prom = $fila->total ? round($fila->duracion / $fila->total) : 0;
            $salida[(int) $fila->id] = $fila;
        }

        return $salida;
    }

    /** Distribucion por hora del dia (0..23 siempre completo). */
    public static function por_hora(): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            HOUR(a.created_at) AS hora,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            SUM(a.costo) AS costo
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY HOUR(a.created_at)
        ORDER BY hora";

        $crudo = [];
        foreach (DB::select($sql, $params) as $fila) {
            $crudo[(int) $fila->hora] = $fila;
        }

        $horas = [];
        for ($h = 0; $h < 24; $h++) {
            $fila = $crudo[$h] ?? null;
            $o = new stdClass();
            $o->hora = $h;
            $o->total = $fila ? (int) $fila->total : 0;
            $o->exitosas = $fila ? (int) $fila->exitosas : 0;
            $o->fallidas = $fila ? (int) $fila->fallidas : 0;
            $o->costo = $fila ? (float) $fila->costo : 0;
            $o->tasa_exito = $o->total ? round($o->exitosas / $o->total * 100, 1) : 0;
            $horas[$h] = $o;
        }

        return $horas;
    }

    /** Distribucion por dia de la semana (1 = domingo ... 7 = sabado). */
    public static function por_dia_semana(): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            DAYOFWEEK(a.created_at) AS dow,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY DAYOFWEEK(a.created_at)
        ORDER BY dow";

        $crudo = [];
        foreach (DB::select($sql, $params) as $fila) {
            $crudo[(int) $fila->dow] = $fila;
        }

        $nombres = [1 => 'Dom', 2 => 'Lun', 3 => 'Mar', 4 => 'Mie', 5 => 'Jue', 6 => 'Vie', 7 => 'Sab'];
        $dias = [];
        for ($d = 1; $d <= 7; $d++) {
            $fila = $crudo[$d] ?? null;
            $o = new stdClass();
            $o->dow = $d;
            $o->nombre = $nombres[$d];
            $o->total = $fila ? (int) $fila->total : 0;
            $o->exitosas = $fila ? (int) $fila->exitosas : 0;
            $o->tasa_exito = $o->total ? round($o->exitosas / $o->total * 100, 1) : 0;
            $dias[$d] = $o;
        }

        return $dias;
    }

    /**
     * Matriz dia_semana x hora con el volumen de llamadas. Es la base del mapa
     * de calor del dashboard: una sola consulta en vez de 24 SUM por fila.
     */
    public static function heatmap(): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            DAYOFWEEK(a.created_at) AS dow,
            HOUR(a.created_at) AS hora,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY DAYOFWEEK(a.created_at), HOUR(a.created_at)";

        $matriz = [];
        $maximo = 0;
        foreach (DB::select($sql, $params) as $fila) {
            $dow = (int) $fila->dow;
            $hora = (int) $fila->hora;
            $matriz[$dow][$hora] = [
                'total' => (int) $fila->total,
                'exitosas' => (int) $fila->exitosas,
            ];
            if ((int) $fila->total > $maximo) $maximo = (int) $fila->total;
        }

        return ['matriz' => $matriz, 'maximo' => $maximo];
    }

    /**
     * Ranking de conductores por diferencia exitos-fallos. El criterio
     * `min_llamadas` evita que un conductor con dos llamadas lidere la tabla.
     */
    public static function top_conductores(int $limit = 5, bool $peor = false, int $min_llamadas = 3): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            a.conductor_id,
            b.nombres AS conductor,
            a.trt_id,
            COALESCE(c.nombres, 'SIN TRT') AS trt,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            ROUND(SUM(a.llamada_exitosa = 1) / COUNT(*) * 100, 1) AS tasa_exito,
            SUM(a.llamada_exitosa = 1) - SUM(a.llamada_exitosa = 0) AS diferencia,
            SUM(a.conductor_confirma) AS conductor_confirma,
            SUM(a.conductor_da_motivos) AS conductor_da_motivos,
            SUM(a.conversacion_fluida) AS conversacion_fluida,
            SUM(a.llamada_interesante) AS llamada_interesante,
            SUM(a.buzon_de_voz) AS buzon_de_voz,
            SUM(a.conductor_cuelga) AS conductor_cuelga,
            SUM(a.conductor_no_contesta) AS conductor_no_contesta,
            SUM(a.numero_equivocado) AS numero_equivocado,
            SUM(a.conductor_conducta_inapropiada) AS conductor_conducta_inapropiada,
            SUM(a.audio_duracion) AS duracion,
            SUM(a.costo) AS costo
        FROM llamadas a
        INNER JOIN conductores b ON b.id = a.conductor_id
        LEFT JOIN trts c ON c.id = a.trt_id
        WHERE a.error_origen = 0 " . $where . "
        GROUP BY a.conductor_id, b.nombres, a.trt_id, COALESCE(c.nombres, 'SIN TRT')
        HAVING COUNT(*) >= ?
        ORDER BY " . ($peor ? 'diferencia ASC, fallidas DESC' : 'diferencia DESC, exitosas DESC') . "
        LIMIT ?";

        $params[] = $min_llamadas;
        $params[] = $limit;

        $filas = DB::select($sql, $params);
        foreach ($filas as $fila) {
            $fila->total = (int) $fila->total;
            $fila->exitosas = (int) $fila->exitosas;
            $fila->fallidas = (int) $fila->fallidas;
            $fila->duracion_prom = $fila->total ? round($fila->duracion / $fila->total) : 0;
        }

        return $filas;
    }

    /** KPIs por transportista, con el costo de cada confirmacion. */
    public static function por_trt(int $limit = 12): array
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            COALESCE(a.trt_id, 0) AS trt_id,
            COALESCE(c.nombres, 'SIN TRT') AS trt,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            COUNT(DISTINCT a.conductor_id) AS conductores,
            COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL)) AS conductores_con_exito,
            COUNT(DISTINCT IF(a.llamada_exitosa = 0, a.conductor_id, NULL)) AS conductores_con_fallo,
            SUM(a.audio_duracion) AS duracion,
            SUM(a.costo) AS costo
        FROM llamadas a
        LEFT JOIN trts c ON c.id = a.trt_id
        WHERE 1 = 1 " . $where . "
        GROUP BY COALESCE(a.trt_id, 0), c.nombres
        HAVING total > 0
        ORDER BY total DESC
        LIMIT ?";

        $params[] = $limit;

        $filas = DB::select($sql, $params);
        foreach ($filas as $fila) {
            $fila->total = (int) $fila->total;
            $fila->exitosas = (int) $fila->exitosas;
            $fila->fallidas = (int) $fila->fallidas;
            $fila->tasa_exito = $fila->total ? round($fila->exitosas / $fila->total * 100, 1) : 0;
            $fila->costo_confirmacion = $fila->exitosas ? round($fila->costo / $fila->exitosas, 3) : 0;
        }

        return $filas;
    }

    /**
     * Concordancia entre el criterio de la IA y el criterio humano. Mide que
     * tanto se parecen, no cual de los dos acierta mas.
     */
    public static function concordancia_ia(): stdClass
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $sql = "SELECT
            SUM(a.exitosa_segun_ia = 1 AND a.llamada_exitosa = 1) AS ambos_si,
            SUM(a.exitosa_segun_ia = 1 AND a.llamada_exitosa = 0) AS solo_ia,
            SUM(a.exitosa_segun_ia = 0 AND a.llamada_exitosa = 1) AS solo_humano,
            SUM(a.exitosa_segun_ia = 0 AND a.llamada_exitosa = 0) AS ambos_no,
            COUNT(*) AS evaluadas
        FROM llamadas a
        WHERE a.llamada_exitosa IN (0, 1) AND a.exitosa_segun_ia IS NOT NULL " . $where;

        $r = DB::select($sql, $params)[0];

        $o = new stdClass();
        $o->ambos_si = (int) $r->ambos_si;
        $o->solo_ia = (int) $r->solo_ia;
        $o->solo_humano = (int) $r->solo_humano;
        $o->ambos_no = (int) $r->ambos_no;
        $o->evaluadas = (int) $r->evaluadas;
        $o->acuerdo = $o->evaluadas
            ? round(($o->ambos_si + $o->ambos_no) / $o->evaluadas * 100, 1) : 0;
        $o->ia_optimista = $o->ambos_si + $o->solo_ia ? round($o->solo_ia / ($o->ambos_si + $o->solo_ia) * 100, 1) : 0;

        return $o;
    }

    /**
     * Mejor ventana de N horas consecutivas por tasa de exito, exigiendo un
     * volumen minimo. Devuelve null en `rango` si no hay datos suficientes.
     */
    public static function ventana_horaria(array $por_hora, int $ventana = 3, int $min_llamadas = 5): stdClass
    {
        $mejor = new stdClass();
        $mejor->inicio = null;
        $mejor->fin = null;
        $mejor->rango = null;
        $mejor->tasa = 0;
        $mejor->total = 0;
        $mejor->exitosas = 0;

        for ($inicio = 0; $inicio < 24; $inicio++) {
            $total = 0;
            $exitosas = 0;
            for ($i = 0; $i < $ventana; $i++) {
                $hora = $por_hora[($inicio + $i) % 24];
                $total += $hora->total;
                $exitosas += $hora->exitosas;
            }
            if ($total < $min_llamadas) continue;

            $tasa = $total ? $exitosas / $total * 100 : 0;
            // desempate por volumen: mas llamadas en la misma tasa
            if ($tasa > $mejor->tasa || ($tasa == $mejor->tasa && $total > $mejor->total)) {
                $mejor->inicio = $inicio;
                $mejor->fin = ($inicio + $ventana - 1) % 24;
                $mejor->tasa = round($tasa, 1);
                $mejor->total = $total;
                $mejor->exitosas = $exitosas;
            }
        }

        if ($mejor->inicio !== null) {
            $mejor->rango = str_pad($mejor->inicio, 2, '0', STR_PAD_LEFT) . ':00 - '
                . str_pad($mejor->fin + 1, 2, '0', STR_PAD_LEFT) . ':00';
        }

        return $mejor;
    }

    /** Etiquetas con conteo, agrupadas en positivas, negativas y de la IA. */
    public static function etiquetas(stdClass $t): array
    {
        $salida = [];
        foreach (DBLlamadas::$etiquetas_icon_bi as $clave => $meta) {
            $salida[$clave] = (object) [
                'clave' => $clave,
                'texto' => $meta[1],
                'icono' => explode(' ', $meta[0])[1] ?? '',
                'grupo' => (int) $meta[4],
                'tipo' => (int) $meta[2],
                'total' => (int) ($t->$clave ?? 0),
            ];
        }

        return $salida;
    }
}
