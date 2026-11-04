<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Http\Request;
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

    /**
     * Espera promedio por ubicacion (minutos) para el dashboard, medida con
     * los hitos de `referencias` y el mismo saneo del modulo de tiempos
     * (etapa dentro de [0, tope]). El rango se ancla a `fin_descargue`: entran
     * todos los viajes CERRADOS dentro del periodo (cohortes de cierre).
     *   carga     = E2, presentarse para cargar -> inicio de carga, por ORIGEN
     *   descarga  = E5, llegada a destino -> inicio de descargue, por DESTINO
     *   ruta      = E4, inicio de ruta -> llegada a destino (recorrido), por RUTA
     * Si el extremo de la ruta no es un local conocido se muestra el ubigeo de
     * la ruta con su distrito (clave UBG:<ubigeo>, mismo contrato que /api/tiempos).
     */
    public static function espera_por_ubicacion(stdClass $periodo, int $limit = 10): stdClass
    {
        $salida = new stdClass();
        $salida->carga = self::espera_ubicacion_sql('carga', $periodo, $limit);
        $salida->descarga = self::espera_ubicacion_sql('descarga', $periodo, $limit);
        $salida->ruta = self::ruta_promedio_sql($periodo, $limit);

        return $salida;
    }

    private static function espera_ubicacion_sql(string $lado, stdClass $periodo, int $limit): array
    {
        // hito que inicia la etapa + columnas del extremo segun el lado
        [$hito_ini, $hito_fin, $col_local, $col_ubg] = $lado === 'carga'
            ? ['r.presenta_para_carga', 'r.inicio_de_carga', 'ru.origen_id', 'ru.ubigeo_origen']
            : ['r.qr_llegada_destino', 'r.inicio_descargue', 'ru.destino_id', 'ru.ubigeo_destino'];

        $etapa = "TIMESTAMPDIFF(MINUTE, $hito_ini, $hito_fin)";

        $sql = "SELECT
                COALESCE(lo.id, CONCAT('UBG:', $col_ubg)) AS clave,
                COALESCE(lo.nombre, CONCAT($col_ubg, ' · ', IFNULL(ud.distrito, 'sin distrito'))) AS ubicacion,
                COUNT(*) AS viajes,
                ROUND(AVG($etapa), 1) AS espera_min
            FROM referencias r
            INNER JOIN rutas ru ON ru.id = r.ruta_id
            LEFT JOIN locales lo ON lo.id = $col_local
            LEFT JOIN ubigeo_distritos ud ON ud.ubigeo = $col_ubg
            WHERE $hito_ini IS NOT NULL AND $hito_fin IS NOT NULL AND r.fin_descargue IS NOT NULL
              AND $etapa BETWEEN 0 AND ?
              AND r.fin_descargue >= ? AND r.fin_descargue < ?";
        $params = [DBTiemposPromedio::TOPE_DEFECTO, $periodo->inicio->toDateTimeString(), $periodo->fin_exclusivo->toDateTimeString()];

        // el filtro de transportista del dashboard tambien aplica a los viajes
        if (self::$filtro !== null && self::$filtro->trt_id !== null) {
            $sql .= ' AND r.trt_id = ? ';
            $params[] = self::$filtro->trt_id;
        }

        $sql .= ' GROUP BY clave, ubicacion ORDER BY viajes DESC LIMIT ' . max(1, $limit);

        return DB::select($sql, $params);
    }

    /**
     * Tiempo promedio de ruta (minutos): el recorrido origen -> destino
     * (etapa 4, `inicio_ruta -> qr_llegada_destino`), agrupado por ruta y con
     * el mismo saneo [0, tope] del modulo de tiempos. Cada extremo se nombra
     * con su local o, si no existe, con el ubigeo y distrito de la ruta.
     */
    private static function ruta_promedio_sql(stdClass $periodo, int $limit): array
    {
        $etapa = 'TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino)';

        $sql = "SELECT
                CONCAT(
                    COALESCE(lo_o.nombre, CONCAT(ru.ubigeo_origen, ' · ', IFNULL(ud_o.distrito, 'sin distrito'))),
                    ' → ',
                    COALESCE(lo_d.nombre, CONCAT(ru.ubigeo_destino, ' · ', IFNULL(ud_d.distrito, 'sin distrito')))
                ) AS recorrido,
                COUNT(*) AS viajes,
                ROUND(AVG($etapa), 1) AS ruta_min
            FROM referencias r
            INNER JOIN rutas ru ON ru.id = r.ruta_id
            LEFT JOIN locales lo_o ON lo_o.id = ru.origen_id
            LEFT JOIN locales lo_d ON lo_d.id = ru.destino_id
            LEFT JOIN ubigeo_distritos ud_o ON ud_o.ubigeo = ru.ubigeo_origen
            LEFT JOIN ubigeo_distritos ud_d ON ud_d.ubigeo = ru.ubigeo_destino
            WHERE r.inicio_ruta IS NOT NULL AND r.qr_llegada_destino IS NOT NULL AND r.fin_descargue IS NOT NULL
              AND $etapa BETWEEN 0 AND ?
              AND r.fin_descargue >= ? AND r.fin_descargue < ?";
        $params = [DBTiemposPromedio::TOPE_DEFECTO, $periodo->inicio->toDateTimeString(), $periodo->fin_exclusivo->toDateTimeString()];

        // el filtro de transportista del dashboard tambien aplica a los viajes
        if (self::$filtro !== null && self::$filtro->trt_id !== null) {
            $sql .= ' AND r.trt_id = ? ';
            $params[] = self::$filtro->trt_id;
        }

        $sql .= ' GROUP BY recorrido ORDER BY viajes DESC LIMIT ' . max(1, $limit);

        return DB::select($sql, $params);
    }

    /**
     * Minutos a formato hh:mm (p. ej. 363.4 -> 06:03). Las horas pueden superar
     * 24 si el promedio de una etapa es mayor a un dia; no se modulan.
     */
    public static function min_a_hhmm($min): string
    {
        $total = (int) round((float) $min);
        if ($total < 0) $total = 0;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Mapa por volumen de viajes (origen/destino por departamento). Reutiliza la
     * misma funcion de lupita/referencias (DBConsultarReferencias::mapa): el rango
     * se ancla a `fin_descargue` (viajes cerrados del periodo) y viaja el filtro
     * de transportista del reporte.
     */
    public static function mapa_volumen(stdClass $periodo): array
    {
        $params = [
            'fecha_inicio' => $periodo->desde,
            'fecha_fin' => $periodo->hasta,
            'etapa' => 'fin_descargue',
        ];
        if (self::$filtro !== null && self::$filtro->trt_id !== null) {
            $params['trt_id'] = self::$filtro->trt_id;
        }

        return DBConsultarReferencias::mapa(Request::create('/', 'GET', $params));
    }

    /**
     * Clasificacion EXCLUSIVA de las fallidas por etiquetas actuales (mismo
     * criterio y formula que DBLlamadas::clasificacion_fallos, pero con el
     * filtro del reporte VAPI: rango + tipo + transportista). Cada fallida cae
     * en UN solo cubo por prioridad: contacto > no coopera > tecnico > otros,
     * asi la suma de los 4 cubos SIEMPRE cuadra contra `fallidas`.
     * Los sub-contadores son crudos: una llamada puede sumar en dos de ellos.
     */
    public static function clasificacion_fallos(): stdClass
    {
        $p = self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        $contacto = '(IFNULL(a.buzon_de_voz,0)=1 OR IFNULL(a.conductor_no_contesta,0)=1 OR IFNULL(a.conductor_cuelga,0)=1)';
        $nocoop = '(IFNULL(a.conductor_contesta_pero_no_habla,0)=1 OR IFNULL(a.conductor_no_escucha,0)=1 OR IFNULL(a.conductor_conducta_inapropiada,0)=1)';
        $tec = '(IFNULL(a.error_origen,0) IN (-1,1,2,3) OR IFNULL(a.error_tecnico_llamada,0)=1 OR IFNULL(a.error_audio,0)=1)';

        $sql = "SELECT
            SUM(a.llamada_exitosa = 0) AS fallidas,
            SUM(a.llamada_exitosa = 0 AND $contacto) AS f_contacto,
            SUM(a.llamada_exitosa = 0 AND NOT $contacto AND $nocoop) AS f_no_coopera,
            SUM(a.llamada_exitosa = 0 AND NOT $contacto AND NOT $nocoop AND $tec) AS f_tecnicos,
            SUM(a.llamada_exitosa = 0 AND NOT $contacto AND NOT $nocoop AND NOT $tec) AS f_otros,

            SUM(IFNULL(a.buzon_de_voz,0)=1 AND a.llamada_exitosa=0) AS buzon_f,
            SUM(IFNULL(a.conductor_no_contesta,0)=1 AND a.llamada_exitosa=0) AS no_contesta_f,
            SUM(IFNULL(a.conductor_cuelga,0)=1 AND a.llamada_exitosa=0) AS cuelga_f,
            SUM(a.razon_finalizacion_id=5 AND a.llamada_exitosa=0) AS ocupado_f,

            SUM(IFNULL(a.conductor_contesta_pero_no_habla,0)=1 AND a.llamada_exitosa=0) AS no_habla_f,
            SUM(IFNULL(a.conductor_no_escucha,0)=1 AND a.llamada_exitosa=0) AS no_escucha_f,
            SUM(IFNULL(a.conductor_conducta_inapropiada,0)=1 AND a.llamada_exitosa=0) AS inapropiada_f,

            SUM(a.error_origen=1 AND a.llamada_exitosa=0) AS err_ia_f,
            SUM(a.error_origen=2 AND a.llamada_exitosa=0) AS err_red_f,
            SUM(a.error_origen=3 AND a.llamada_exitosa=0) AS err_sistema_f,
            SUM(a.error_origen=-1 AND a.llamada_exitosa=0) AS err_desconocido_f,

            SUM(IFNULL(a.conductor_mala_senal,0)=1 AND a.llamada_exitosa=0) AS senal_f,
            SUM(IFNULL(a.confusion_en_llamada,0)=1 AND a.llamada_exitosa=0) AS confusion_f,
            SUM(IFNULL(a.contesta_otra_persona,0)=1 AND a.llamada_exitosa=0) AS otra_persona_f,
            SUM(IFNULL(a.numero_equivocado,0)=1 AND a.llamada_exitosa=0) AS numero_equivocado_f,
            SUM(IFNULL(a.conductor_da_motivos,0)=1 AND a.llamada_exitosa=0) AS motivos_f,
            SUM(IFNULL(a.conductor_confirma,0)=1 AND a.llamada_exitosa=0 AND IFNULL(a.error_origen,0)=0) AS parcial_f,

            SUM(a.llamada_exitosa=0 AND NOT $contacto AND NOT $nocoop AND NOT $tec
                AND IFNULL(a.conductor_mala_senal,0)=0 AND IFNULL(a.confusion_en_llamada,0)=0 AND
                IFNULL(a.contesta_otra_persona,0)=0 AND IFNULL(a.numero_equivocado,0)=0 AND
                IFNULL(a.conductor_da_motivos,0)=0 AND IFNULL(a.conductor_confirma,0)=0) AS sin_etiqueta_f

            FROM llamadas a
            WHERE 1 = 1 " . $where;

        $f = DB::select($sql, $params)[0];
        foreach (get_object_vars($f) as $k => $v) {
            $f->$k = (int) $v;
        }

        return $f;
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

    /** Etiquetas cortas de mes para las filas de la progresion mensual. */
    public const MESES_CORTOS = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * Rango del agrupamiento mensual: el periodo elegido, pero nunca menos de
     * $meses meses hacia atras (anclado al primer dia del mes del ultimo dia
     * del rango). Un rango de 30 dias seguiria mostrando 6 cubos mensuales.
     */
    public static function periodo_meses(int $meses = 6): stdClass
    {
        $p = self::periodo();

        $min = $p->fin->copy()->startOfMonth()->subMonths($meses - 1)->startOfDay();
        if ($p->inicio->gt($min)) {
            $p->inicio = $min;
            $p->desde = $min->toDateString();
        }

        return $p;
    }

    /**
     * Progresion del periodo agrupada por semana o por mes.
     *
     * Diferencia con la serie del dashboard (`serie_agrupada`, que parte de
     * `por_dia()` y suma en PHP): aqui el GROUP BY se corre en SQL, asi el
     * conteo de conductores es DISTINCT dentro de cada cubo y no la suma de
     * conductores diarios (que duplicaria al conductor que aparece varios dias).
     *
     * Semana = YEARWEEK modo 1 (ISO, lunes a domingo). Mes = anio-calendario.
     * Cada fila lleva tasa, tasa de contestacion, duracion y costo por
     * confirmacion, mas lo que se calcula en PHP (`enriquecer_progreso`):
     * etiqueta, variacion contra el cubo anterior y acumulado del rango.
     */
    public static function progreso(string $granularidad = 'semana'): array
    {
        $mes = $granularidad === 'mes';

        // la vista mensual nunca muestra menos de 6 meses hacia atras
        $p = $mes ? self::periodo_meses() : self::periodo();
        [$where, $params] = self::where('a', $p->inicio, $p->fin_exclusivo);

        // la expresion de agrupamiento es fija del codigo, nunca viene del usuario
        $expr = $mes ? "DATE_FORMAT(a.created_at, '%Y-%m')" : 'YEARWEEK(a.created_at, 1)';

        $sql = "SELECT
            $expr AS periodo,
            MIN(DATE(a.created_at)) AS inicio,
            MAX(DATE(a.created_at)) AS fin,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.llamada_exitosa = 0) AS fallidas,
            SUM(a.llamada_exitosa = -1) AS indeterminadas,
            COUNT(DISTINCT a.conductor_id) AS conductores,
            COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL)) AS conductores_exitosos,
            SUM(a.entro_llamada) - SUM(a.buzon_de_voz) AS contestadas,
            SUM(a.buzon_de_voz) AS buzon_de_voz,
            SUM(a.error_origen = -1) AS error_desconocido,
            SUM(a.error_origen = 1) AS error_ia,
            SUM(a.error_origen = 2) AS error_red,
            SUM(a.error_origen = 3) AS error_sistema,
            SUM(a.audio_duracion) AS duracion,
            SUM(a.costo) AS costo
        FROM llamadas a
        WHERE 1 = 1 " . $where . "
        GROUP BY periodo
        ORDER BY periodo";

        $filas = [];
        foreach (DB::select($sql, $params) as $f) {
            $o = new stdClass();
            $o->clave = (string) $f->periodo;
            $o->inicio = (string) $f->inicio;
            $o->fin = (string) $f->fin;
            $o->total = (int) $f->total;
            $o->exitosas = (int) $f->exitosas;
            $o->fallidas = (int) $f->fallidas;
            $o->indeterminadas = (int) $f->indeterminadas;
            $o->conductores = (int) $f->conductores;
            $o->conductores_exitosos = (int) $f->conductores_exitosos;
            $o->contestadas = (int) $f->contestadas;
            $o->buzon_de_voz = (int) $f->buzon_de_voz;
            // los 4 tipos de error tecnico (error_origen); el 0 es humano y no cuenta
            $o->error_desconocido = (int) $f->error_desconocido;
            $o->error_ia = (int) $f->error_ia;
            $o->error_red = (int) $f->error_red;
            $o->error_sistema = (int) $f->error_sistema;
            $o->errores_tecnicos = $o->error_desconocido + $o->error_ia + $o->error_red + $o->error_sistema;
            $o->duracion = (int) $f->duracion;
            $o->costo = (float) $f->costo;

            $o->tasa_exito = $o->total ? round($o->exitosas / $o->total * 100, 1) : 0;
            $o->tasa_contestacion = $o->total ? round($o->contestadas / $o->total * 100, 1) : 0;
            $o->duracion_prom = $o->total ? (int) round($o->duracion / $o->total) : 0;
            $o->costo_confirmacion = $o->exitosas ? round($o->costo / $o->exitosas, 3) : 0;
            $o->carbon = Carbon::parse($o->inicio);

            $filas[] = $o;
        }

        self::enriquecer_progreso($filas, $mes);

        return $filas;
    }

    /**
     * Derivados de la progresion que necesitan el cubo anterior: etiqueta
     * legible, variacion de llamadas (%) y de tasa de exito (puntos), y el
     * acumulado de llamadas y exitosas a lo largo del rango. Se recorre en
     * orden cronologico, el primer cubo no tiene variacion (null).
     */
    private static function enriquecer_progreso(array &$filas, bool $mes): void
    {
        $acum_total = 0;
        $acum_exitosas = 0;
        $prev = null;

        foreach ($filas as $o) {
            if ($mes) {
                $o->etiqueta = self::MESES_CORTOS[(int) $o->carbon->format('n')]
                    . ' ' . $o->carbon->format('Y');
            } else {
                $lunes = $o->carbon->copy()->startOfWeek(Carbon::MONDAY);
                $o->etiqueta = 'Sem ' . $lunes->format('d/m');
            }
            $o->rango = $o->carbon->format('d/m') . ' - '
                . Carbon::parse($o->fin)->format('d/m');

            $o->var_total = ($prev && $prev->total) ? round((($o->total - $prev->total) / $prev->total) * 100, 1) : null;
            $o->var_tasa = $prev ? round($o->tasa_exito - $prev->tasa_exito, 1) : null;

            $acum_total += $o->total;
            $acum_exitosas += $o->exitosas;
            $o->acum_total = $acum_total;
            $o->acum_exitosas = $acum_exitosas;

            $prev = $o;
        }
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
