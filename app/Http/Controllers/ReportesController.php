<?php

namespace App\Http\Controllers;

use App\Database\DBLlamadas;
use App\Database\DBReportes;
use App\Database\DBRutas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte ejecutivo VAPI version 2.
 *
 * Cinco salidas sobre la misma recoleccion de datos (una consulta por bloque):
 *  - dashboard:      vista web interactiva con filtros y graficos.
 *  - dashboard_html: el mismo dashboard como archivo .html descargable.
 *  - reporte:        reporte completo con la estructura del reporte clasico
 *                    (reporte/todo/todo1) y los datos agregados de DBReportes.
 *  - reporte_html:   el reporte web como archivo .html descargable.
 *  - dia / rango:    reportes email-safe, mismos KPIs que la portada.
 */
class ReportesController extends Controller
{
    /** Color de Bootstrap (nombre de tipos_llamada) a hexadecimal para correo. */
    public const COLORES_HEX = [
        'success' => '#2e7d32',
        'danger' => '#c62828',
        'warning' => '#ff8f00',
        'primary' => '#0d6efd',
        'info' => '#0c7591',
        'secondary' => '#6c757d',
        'dark' => '#2c3e50',
    ];

    public const MESES_CORTOS = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * Recolecta todo lo que necesitan las tres vistas. $con_rutas solo se usa
     * en el reporte de un dia: el bloque de rutas es el mas costoso y no se
     * necesita en el dashboard ni en el rango.
     */
    private static function recolectar(Request $request, bool $con_rutas = false, bool $con_espera = false): array
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'llamada_tipo_id' => 'nullable|numeric',
            'trt' => 'nullable|numeric',
            'min_llamadas' => 'nullable|numeric',
        ]);

        DB::statement('SET SESSION wait_timeout = 600');

        // carga razones de finalizacion, tipos de llamada y origenes de error
        $llamadas = new DBLlamadas();
        DBReportes::set_filtro($request);

        $periodo = DBReportes::periodo();
        $kpis = DBReportes::kpis();
        [$anterior, $periodo_anterior] = DBReportes::kpis_anterior();

        $por_hora = DBReportes::por_hora();
        $por_tipo = DBReportes::por_tipo();
        $por_dia = DBReportes::por_dia();

        $min_llamadas = (int) ($request->min_llamadas ?: 3);

        $tipos = self::tipos_reporte($llamadas, $por_tipo, $periodo->dias);
        $etapas = self::etapas_logistica($por_tipo, $periodo->dias);

        $rutas = null;
        if ($con_rutas) {
            $llamadas::set_filtro($request);
            $rutas_lista = new DBRutas();
            $rutas_lista::$filtro = $llamadas::aplicar_filtro_sqltext('a');
            $rutas = new stdClass();
            $rutas->clase = $rutas_lista;
            $rutas->lista_detalle = $rutas_lista::lista_rutas_recorridas();
            $rutas->resumen = $rutas_lista::rutas_resumen();
            $rutas->depas = $rutas_lista::$depas;
        }

        return [
            'llamadas' => $llamadas,
            'periodo' => $periodo,
            'espera' => $con_espera ? DBReportes::espera_por_ubicacion($periodo) : null,
            'mapa' => $con_espera ? DBReportes::mapa_volumen($periodo) : null,
            'depas' => DBRutas::$depas,
            'filtros' => DBReportes::$filtro,
            'kpis' => $kpis,
            'anterior' => $anterior,
            'periodo_anterior' => $periodo_anterior,
            'variacion' => (object) [
                'total' => DBReportes::variacion($kpis->total, $anterior->total),
                'exitosas' => DBReportes::variacion($kpis->exitosas, $anterior->exitosas),
                'tasa_exito' => DBReportes::variacion($kpis->tasa_exito, $anterior->tasa_exito),
                'costo' => DBReportes::variacion($kpis->costo, $anterior->costo),
                'costo_confirmacion' => DBReportes::variacion($kpis->costo_confirmacion, $anterior->costo_confirmacion),
            ],
            'tipos' => $tipos,
            'etapas' => $etapas,
            'por_hora' => $por_hora,
            'por_dia' => $por_dia,
            'serie' => self::serie_agrupada($por_dia, $periodo),
            'por_dia_semana' => DBReportes::por_dia_semana(),
            'heatmap' => DBReportes::heatmap(),
            'mejores' => DBReportes::top_conductores(5, false, $min_llamadas),
            'peores' => DBReportes::top_conductores(5, true, $min_llamadas),
            'trts' => DBReportes::por_trt(12),
            'ia' => DBReportes::concordancia_ia(),
            'etiquetas' => DBReportes::etiquetas($kpis),
            'fallos' => DBReportes::clasificacion_fallos(),
            'ventana' => DBReportes::ventana_horaria($por_hora, 3, 5),
            'rutas' => $rutas,
            'min_llamadas' => $min_llamadas,
            'catalogo_tipos' => self::catalogo_tipos($llamadas),
            'catalogo_trts' => DB::table('trts')->orderBy('nombres')->get(['id', 'nombres']),
        ];
    }

    /** Tipos con los 5 fijos garantizados + los que tengan datos en el rango. */
    private static function tipos_reporte(DBLlamadas $llamadas, array $por_tipo, int $dias): array
    {
        $catalogo = [];
        foreach ($llamadas::$tipos_llamada as $tipo) {
            $catalogo[(int) $tipo->id] = $tipo;
        }

        $ids = DBReportes::TIPOS_FIJOS;
        foreach ($por_tipo as $id => $fila) {
            if ($fila->total > 0 && !in_array((int) $id, $ids, true)) $ids[] = (int) $id;
        }

        $salida = [];
        foreach ($ids as $id) {
            $fila = $por_tipo[$id] ?? null;
            $o = new stdClass();
            $o->id = $id;
            $o->nombre = $catalogo[$id]->nombre ?? ('Tipo ' . $id);
            $o->emoji = $catalogo[$id]->emoji ?? '';
            $o->color = $catalogo[$id]->color ?? 'secondary';
            $o->hex = self::COLORES_HEX[$o->color] ?? '#6c757d';
            $o->total = $fila->total ?? 0;
            $o->exitosas = $fila->exitosas ?? 0;
            $o->fallidas = $fila->fallidas ?? 0;
            $o->tasa_exito = $fila->tasa_exito ?? 0;
            $o->conductores = $fila->conductores ?? 0;
            $o->conductores_exitosos = $fila->conductores_exitosos ?? 0;
            $o->trts = $fila->trts ?? 0;
            $o->contestadas = $fila->contestadas ?? 0;
            $o->buzon_de_voz = $fila->buzon_de_voz ?? 0;
            $o->razon_3_no_contesta = $fila->razon_3_no_contesta ?? 0;
            $o->razon_5_ocupado = $fila->razon_5_ocupado ?? 0;
            $o->duracion = $fila->duracion ?? 0;
            $o->duracion_prom = $fila->duracion_prom ?? 0;
            $o->costo = $fila->costo ?? 0;
            $o->costo_confirmacion = $fila->costo_confirmacion ?? 0;
            $o->dias = $dias;
            $salida[] = $o;
        }

        return $salida;
    }

    /** Formato que espera el partial etapa-logistica (indexado 1..6). */
    private static function etapas_logistica(array $por_tipo, int $dias): array
    {
        $etapas = [];
        for ($i = 1; $i <= 6; $i++) {
            $fila = $por_tipo[$i] ?? null;
            $e = new stdClass();
            $e->tipo = $i;
            $e->dias = $fila && $fila->total ? $dias : 0;
            $e->total = $fila->total ?? 0;
            $e->exitosas = $fila->exitosas ?? 0;
            $e->porcentaje = $e->total ? round($e->exitosas / $e->total * 100) : 0;
            $etapas[$i] = $e;
        }

        return $etapas;
    }

    /** Tipos de llamada para los selects de filtro (sin el 4, que es "en ruta"). */
    private static function catalogo_tipos(DBLlamadas $llamadas): array
    {
        $salida = [];
        foreach ($llamadas::$tipos_llamada as $tipo) {
            if (in_array((int) $tipo->id, [0, 4], true)) continue;
            $salida[] = $tipo;
        }

        return $salida;
    }

    /**
     * Dashboard interactivo. Sin fechas abre los ultimos 7 dias que tengan
     * datos: si se usara "hoy" la vista arrancaria vacia hasta que se carguen
     * las llamadas del dia.
     */
    public static function dashboard(Request $request)
    {
        self::dias_por_defecto($request);

        return view('reporte.v2.dashboard', self::recolectar($request, false, true));
    }

    /** El mismo dashboard como archivo .html independiente. */
    public static function dashboard_html(Request $request)
    {
        self::dias_por_defecto($request);

        return self::descargar('reporte.v2.dashboard', $request, false, 'dashboard_vapi_', true);
    }

    /**
     * Reporte completo en pantalla: misma estructura y lenguaje visual que
     * reporte/todo/todo1 (lienzo claro, resumen de 8 tarjetas, embudo, mejores
     * y peores, mapa de calor, motivos de fallo y Venn IA vs humano), pero con
     * las consultas agregadas de DBReportes en lugar de las de DBLlamadas.
     */
    public static function reporte(Request $request)
    {
        self::dias_por_defecto($request);

        return view('reporte.v2.reporte', self::recolectar($request, self::es_un_dia($request), true));
    }

    /** El reporte web como archivo .html independiente. */
    public static function reporte_html(Request $request)
    {
        self::dias_por_defecto($request);

        return self::descargar('reporte.v2.reporte', $request, self::es_un_dia($request), 'reporte_vapi_', true);
    }

    /**
     * Reporte de progreso por semana y por mes.
     *
     * Mismas metricas agregadas de DBReportes que el dashboard (totales,
     * exitosas, tasa de exito, contestadas, buzon, duracion y costo por
     * confirmacion), pero presentadas como evolucion temporal: una tabla con
     * su grafico por semana y otra por mes, cada cubo con su variacion contra
     * el anterior y el acumulado del rango. Va en el formato reporte clasico
     * (reporte/todo/todo1), no como el dashboard interactivo.
     *
     * Sin fechas abre el ano en curso hasta el ultimo dia con datos: un rango
     * de 7 dias no tiene meses que mostrar y la progresion pierde el sentido.
     */
    public static function progreso(Request $request)
    {
        self::rango_progreso($request);

        $datos = self::recolectar($request, false);
        $datos['semanas'] = DBReportes::progreso('semana');
        $datos['meses'] = DBReportes::progreso('mes');

        // el pie de la tabla mensual cubre su propio rango ampliado (min. 6 meses)
        $pm = DBReportes::periodo_meses();
        $datos['meses_kpis'] = DBReportes::kpis($pm->inicio, $pm->fin_exclusivo);

        return view('reporte.v2.progreso', $datos);
    }

    /** El reporte de progreso como archivo .html independiente. */
    public static function progreso_html(Request $request)
    {
        self::rango_progreso($request);

        $datos = self::recolectar($request, false);
        $datos['semanas'] = DBReportes::progreso('semana');
        $datos['meses'] = DBReportes::progreso('mes');

        // el pie de la tabla mensual cubre su propio rango ampliado (min. 6 meses)
        $pm = DBReportes::periodo_meses();
        $datos['meses_kpis'] = DBReportes::kpis($pm->inicio, $pm->fin_exclusivo);
        $datos['layout'] = 'layouts.export';

        $html = view('reporte.v2.progreso', $datos)->render();

        $nombre = 'progreso_vapi_' . $datos['periodo']->desde . '_al_' . $datos['periodo']->hasta;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '.html"',
        ]);
    }

    /** Sin fechas: ano en curso anclado al ultimo dia con llamadas. */
    private static function rango_progreso(Request $request): void
    {
        if (!$request->fecha_inicio && !$request->fecha_fin) {
            $ultima = DBReportes::ultima_fecha_con_datos();
            $request->merge([
                'fecha_inicio' => date('Y-01-01', strtotime($ultima)),
                'fecha_fin' => $ultima,
            ]);
        }
    }

    /** Sin fechas: ultimos 7 dias anclados al ultimo dia con llamadas. */
    private static function dias_por_defecto(Request $request): void
    {
        if (!$request->fecha_inicio && !$request->fecha_fin) {
            $ultima = DBReportes::ultima_fecha_con_datos();
            $request->merge([
                'fecha_inicio' => date('Y-m-d', strtotime($ultima . ' -6 days')),
                'fecha_fin' => $ultima,
            ]);
        }
    }

    /** El bloque de rutas solo aplica a un dia; ?rutas=0 lo apaga. */
    private static function es_un_dia(Request $request): bool
    {
        $desde = $request->fecha_inicio ?: $request->fecha_fin;
        $hasta = $request->fecha_fin ?: $request->fecha_inicio;

        return $desde && $hasta && $desde === $hasta && $request->rutas !== '0';
    }

    /**
     * Renderiza la vista con el layout de exportacion y la entrega como
     * descarga .html: el archivo no depende de la app, solo de los CDN.
     */
    private static function descargar(string $vista, Request $request, bool $con_rutas, string $prefijo, bool $con_espera = false)
    {
        $datos = self::recolectar($request, $con_rutas, $con_espera);
        $datos['layout'] = 'layouts.export';

        $html = view($vista, $datos)->render();

        $nombre = $prefijo . $datos['periodo']->desde;
        if ($datos['periodo']->desde !== $datos['periodo']->hasta) {
            $nombre .= '_al_' . $datos['periodo']->hasta;
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '.html"',
        ]);
    }

    /**
     * Serie del dashboard adaptada al largo del rango: dia a dia hasta 62 dias,
     * por semana hasta 400 y por mes en rangos mas largos. Los dias sin llamadas
     * devuelven null para que la linea de % de exito no caiga a 0 en falso.
     */
    private static function serie_agrupada(array $por_dia, stdClass $periodo): array
    {
        $granularidad = DBReportes::granularidad($periodo->dias);

        $salida = [
            'labels' => [],
            'total' => [],
            'exitosas' => [],
            'tasa' => [],
            'granularidad' => $granularidad,
        ];

        if ($granularidad === 'dia') {
            foreach ($por_dia as $d) {
                $salida['labels'][] = $d->fecha_carbon->format('d/m');
                $salida['total'][] = $d->total;
                $salida['exitosas'][] = $d->exitosas;
                $salida['tasa'][] = $d->total ? $d->tasa_exito : null;
            }

            return $salida;
        }

        $grupos = [];
        foreach ($por_dia as $d) {
            if ($granularidad === 'semana') {
                $carbon = $d->fecha_carbon->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                $clave = $carbon->toDateString();
                $etiqueta = 'Sem ' . $carbon->format('d/m');
            } else {
                $carbon = $d->fecha_carbon->copy()->startOfMonth();
                $clave = $carbon->format('Y-m');
                $etiqueta = self::MESES_CORTOS[(int) $carbon->format('n')] . ' ' . $carbon->format('Y');
            }

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['label' => $etiqueta, 'total' => 0, 'exitosas' => 0];
            }
            $grupos[$clave]['total'] += $d->total;
            $grupos[$clave]['exitosas'] += $d->exitosas;
        }

        foreach ($grupos as $grupo) {
            $salida['labels'][] = $grupo['label'];
            $salida['total'][] = $grupo['total'];
            $salida['exitosas'][] = $grupo['exitosas'];
            $salida['tasa'][] = $grupo['total'] ? round($grupo['exitosas'] / $grupo['total'] * 100, 1) : null;
        }

        return $salida;
    }

    /** Reporte email-safe de UN dia. Mismos KPIs que la portada, resueltos con
     * consultas agregadas; el bloque de rutas se puede apagar con ?rutas=0.
     */
    public static function dia(Request $request)
    {
        $fecha = $request->fecha_inicio ?: date('Y-m-d');
        $request->merge(['fecha_inicio' => $fecha, 'fecha_fin' => $fecha]);

        $con_rutas = $request->rutas !== '0';

        return view('reporte.v2.dia', self::recolectar($request, $con_rutas));
    }

    /** Lanzador: formulario con atajos de periodo (día, semana, mes). */
    public static function seleccion(Request $request)
    {
        $llamadas = new DBLlamadas();

        return view('reporte.v2.seleccion', [
            'catalogo_tipos' => self::catalogo_tipos($llamadas),
            'catalogo_trts' => DB::table('trts')->orderBy('nombres')->get(['id', 'nombres']),
            'fecha_inicio' => $request->fecha_inicio ?: date('Y-m-01'),
            'fecha_fin' => $request->fecha_fin ?: date('Y-m-d'),
        ]);
    }

    /** Reporte email-safe de un rango (dia, semana o mes). */
    public static function rango(Request $request)
    {
        if (!$request->fecha_inicio && !$request->fecha_fin) {
            $request->merge([
                'fecha_inicio' => date('Y-m-01'),
                'fecha_fin' => date('Y-m-d'),
            ]);
        }

        return view('reporte.v2.rango', self::recolectar($request, false));
    }
}
