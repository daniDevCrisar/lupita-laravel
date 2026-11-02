<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Capa de datos de la vista de consulta de REFERENCIAS (viajes).
 *
 * Sigue la plantilla de tres capas del proyecto: clase estatica con set/consulta,
 * controller delgado y vista Blade. No altera DBReferencias (esa es de importacion).
 *
 * Origen/destino salen de rutas -> locales/ubigeo_distritos por ruta_id. Las etapas
 * del viaje son las 7 marcas temporales de `referencias`. El agregado de llamadas se
 * hace por subconsulta agrupada para pisar only_full_group_by.
 */
class DBConsultarReferencias
{
    /**
     * Builder base sobre referencias con todos los joins y los filtros del request.
     * Se reutiliza en listar/kpis/top_rutas/mapa. Alias de columnas pensados para
     * pasar el resultado directo a DBTiemposRuta::nombre_ruta().
     */
    private static function base(Request $request)
    {
        $q = DB::table('referencias as r')
            ->leftJoin('conductores as con', 'con.id', '=', 'r.conductor_id')
            ->leftJoin('trts as trt', 'trt.id', '=', 'r.trt_id')
            ->leftJoin('rutas as ru', 'ru.id', '=', 'r.ruta_id')
            ->leftJoin('locales as lo_orig', 'lo_orig.id', '=', 'ru.origen_id')
            ->leftJoin('locales as lo_dest', 'lo_dest.id', '=', 'ru.destino_id')
            ->leftJoin('ubigeo_distritos as ug_orig', 'ug_orig.ubigeo', '=', 'ru.ubigeo_origen')
            ->leftJoin('ubigeo_distritos as ug_dest', 'ug_dest.ubigeo', '=', 'ru.ubigeo_destino');

        return self::aplicar_filtros($q, $request);
    }

    /** Expresion 0/1 por etapa completada (no nula). */
    private const ETAPAS = [
        'r.presenta_para_carga',
        'r.inicio_de_carga',
        'r.fin_de_carga',
        'r.inicio_ruta',
        'r.qr_llegada_destino',
        'r.inicio_descargue',
        'r.fin_descargue',
    ];

    private static function aplicar_filtros($q, Request $request)
    {
        // Rango de fechas sobre las llamadas del ref (referencias no tiene created_at).
        [$fi, $ff] = self::rango($request->input('fecha_inicio'), $request->input('fecha_fin'));
        if ($fi !== null) {
            $q->whereExists(function ($sub) use ($fi, $ff) {
                $sub->select(DB::raw(1))
                    ->from('llamadas as l')
                    ->whereColumn('l.ref', 'r.ref')
                    ->where('l.created_at', '>=', $fi)
                    ->where('l.created_at', '<', $ff);
            });
        }

        if ($txt = trim((string) $request->input('q'))) {
            // Lista de refs pegada ("REF1, REF2"): se busca por ref exacta, no por LIKE.
            $lista = self::lista_de_refs($txt);
            if ($lista) {
                $q->whereIn('r.ref', $lista);
            } else {
                $like = '%' . str_replace('%', '\%', $txt) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('r.ref', 'like', $like)
                        ->orWhere('r.placa', 'like', $like)
                        ->orWhere('r.titulo_viaje', 'like', $like)
                        ->orWhere('con.nombres', 'like', $like);
                });
            }
        }

        if ($ruta = trim((string) $request->input('ruta'))) {
            $like = '%' . str_replace('%', '\%', $ruta) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('lo_orig.nombre', 'like', $like)
                    ->orWhere('lo_dest.nombre', 'like', $like)
                    ->orWhere('ug_orig.distrito', 'like', $like)
                    ->orWhere('ug_dest.distrito', 'like', $like)
                    ->orWhere('r.ruta_id', 'like', $like);
            });
        }

        if ($cid = $request->input('conductor_id')) {
            $q->where('r.conductor_id', $cid);
        }
        if ($tid = $request->input('trt_id')) {
            $q->where('r.trt_id', $tid);
        }

        // Solo viajes con ciclo no cerrado (sin marca de fin de descargue).
        if ($request->boolean('sin_fin')) {
            $q->whereNull('r.fin_descargue');
        }

        // Solo viajes completos: con las 7 fechas de etapa cargadas
        // (excluye fecha_despachador y compromiso_carga).
        if ($request->boolean('completas')) {
            foreach (self::ETAPAS as $etapa) {
                $q->whereNotNull($etapa);
            }
        }

        return $q;
    }

    /** Normaliza rango a [inicio, fin) como Carbon; si no hay rango -> [null, null]. */
    private static function rango($inicio, $fin): array
    {
        if (!$inicio) {
            return [null, null];
        }
        $fi = Carbon::parse($inicio)->startOfDay();
        $ff = $fin ? Carbon::parse($fin)->addDay()->startOfDay() : $fi->copy()->addDay()->startOfDay();
        return [$fi, $ff];
    }

    /**
     * Detecta si el texto del buscador es una LISTA de refs y devuelve sus tokens.
     *
     * Se considera lista cuando viene separada por coma o salto de linea (intencion
     * explicita) o cuando son 2+ numeros separados por espacios ("1154501 1154503").
     * Asi "Juan Perez" (nombre del conductor) se sigue buscando como texto normal.
     * Si no es lista devuelve [] y se aplica el LIKE de siempre.
     */
    public static function lista_de_refs($texto): array
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return [];
        }

        $explicita = (bool) preg_match('/[,\r\n]/', $texto);
        $tokens = preg_split('/[,\s]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return [];
        }

        $soloDigitos = true;
        foreach ($tokens as $t) {
            if (!preg_match('/^[A-Za-z0-9\-]{3,12}$/', $t)) {
                return [];
            }
            if (!ctype_digit($t)) {
                $soloDigitos = false;
            }
        }

        // sin coma/salto de linea solo se asume lista si son numeros (refs del viaje)
        if (!$explicita && (count($tokens) < 2 || !$soloDigitos)) {
            return [];
        }

        // tope defensivo: una lista pegada a mano no deberia pasar de aqui
        return array_values(array_unique(array_slice($tokens, 0, 500)));
    }

    /** Refs de la lista que NO existen en `referencias` (no son viajes registrados). */
    public static function refs_inexistentes(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map('trim', $refs), fn($r) => $r !== '')));
        if (empty($refs)) {
            return [];
        }

        $existentes = DB::table('referencias')
            ->whereIn('ref', $refs)
            ->pluck('ref')
            ->all();

        // comparacion sin distinguir mayusculas: la lista llega escrita a mano
        $normal = array_map(fn($r) => mb_strtoupper((string) $r), $existentes);

        $faltan = [];
        foreach ($refs as $r) {
            if (!in_array(mb_strtoupper($r), $normal, true)) {
                $faltan[] = $r; // se conserva el token tal como lo escribio el usuario
            }
        }

        return $faltan;
    }

    /** Listado paginado de viajes con agregado de sus llamadas. */
    public static function listar(Request $request, int $porPagina = 25)
    {
        $agg = DB::table('llamadas')
            ->selectRaw("ref,
                COUNT(*) as llamadas_total,
                SUM(llamada_exitosa = 1) as llamadas_exitosas,
                COUNT(DISTINCT llamada_tipo_id) as etapas_distintas,
                GROUP_CONCAT(vapi_id ORDER BY created_at SEPARATOR ',') as vapi_ids")
            ->whereNotNull('ref')
            ->groupBy('ref');

        $q = self::base($request)
            ->leftJoinSub($agg, 'agg', fn($j) => $j->on('agg.ref', '=', 'r.ref'))
            ->selectRaw('r.ref, r.placa, r.titulo_viaje, r.ruta_id,
                con.nombres as conductor_nombre, trt.nombres as trt_nombre,
                lo_orig.nombre as loc_origen_nombre, lo_dest.nombre as loc_destino_nombre,
                ug_orig.distrito as ubg_origen_nombre, ug_dest.distrito as ubg_destino_nombre,
                r.compromiso_carga, r.presenta_para_carga, r.inicio_de_carga, r.fin_de_carga,
                r.inicio_ruta, r.qr_llegada_destino, r.inicio_descargue, r.fin_descargue,
                COALESCE(agg.llamadas_total,0) as llamadas_total,
                COALESCE(agg.llamadas_exitosas,0) as llamadas_exitosas,
                COALESCE(agg.etapas_distintas,0) as etapas_distintas,
                agg.vapi_ids as vapi_ids,
                (  (r.presenta_para_carga IS NOT NULL)
                 + (r.inicio_de_carga   IS NOT NULL)
                 + (r.fin_de_carga      IS NOT NULL)
                 + (r.inicio_ruta       IS NOT NULL)
                 + (r.qr_llegada_destino IS NOT NULL)
                 + (r.inicio_descargue  IS NOT NULL)
                 + (r.fin_descargue     IS NOT NULL) ) as etapas_completadas,
                COALESCE(r.fin_descargue, r.inicio_descargue, r.qr_llegada_destino, r.inicio_ruta,
                         r.fin_de_carga, r.inicio_de_carga, r.presenta_para_carga, r.compromiso_carga) as ultima_fecha');

        $orden = self::mapeo_orden($request->input('ordenar_por'));
        $dir = $request->input('orden') === 'asc' ? 'asc' : 'desc';
        $q->orderByRaw("$orden $dir, r.ref asc");

        return $q->paginate($porPagina)->appends($request->query());
    }

    private static function mapeo_orden($campo): string
    {
        return match ($campo) {
            'conductor' => 'conductor_nombre',
            'ruta'      => 'loc_origen_nombre',
            'llamadas'  => 'llamadas_total',
            'exitosas'  => 'llamadas_exitosas',
            'etapas'    => 'etapas_completadas',
            default     => 'ultima_fecha',
        };
    }

    /** KPIs del conjunto filtrado. */
    public static function kpis(Request $request): stdClass
    {
        $etapasExpr = '(  (r.presenta_para_carga IS NOT NULL)
                        + (r.inicio_de_carga IS NOT NULL)
                        + (r.fin_de_carga IS NOT NULL)
                        + (r.inicio_ruta IS NOT NULL)
                        + (r.qr_llegada_destino IS NOT NULL)
                        + (r.inicio_descargue IS NOT NULL)
                        + (r.fin_descargue IS NOT NULL) )';

        $row = self::base($request)->selectRaw("
            COUNT(*) as total,
            SUM(r.ruta_id IS NOT NULL) as con_ruta,
            SUM(r.fin_descargue IS NOT NULL) as cerrados,
            COALESCE(SUM($etapasExpr),0) as etapas_sum
        ")->first();

        $k = new stdClass();
        $k->total = (int) ($row->total ?? 0);
        $k->con_ruta = (int) ($row->con_ruta ?? 0);
        $k->cerrados = (int) ($row->cerrados ?? 0);
        $k->pct_ruta = $k->total > 0 ? round($k->con_ruta / $k->total * 100, 1) : 0.0;
        $k->pct_cerrados = $k->total > 0 ? round($k->cerrados / $k->total * 100, 1) : 0.0;
        // Promedio de etapas completadas por viaje, sobre las 7 marcas.
        $k->etapas_prom = $k->total > 0 ? round($row->etapas_sum / $k->total, 1) : 0.0;
        $k->pct_etapas = $k->total > 0 ? round($row->etapas_sum / ($k->total * 7) * 100, 1) : 0.0;
        return $k;
    }

    /** Conteo de viajes por departamento (2 digitos ubigeo) para origen y destino. */
    public static function mapa(Request $request): array
    {
        $origen = self::base($request)
            ->whereNotNull('ru.ubigeo_origen')
            ->selectRaw("LEFT(ru.ubigeo_origen,2) as dep, COUNT(*) as veces")
            ->groupBy('dep')
            ->pluck('veces', 'dep')
            ->toArray();

        $destino = self::base($request)
            ->whereNotNull('ru.ubigeo_destino')
            ->selectRaw("LEFT(ru.ubigeo_destino,2) as dep, COUNT(*) as veces")
            ->groupBy('dep')
            ->pluck('veces', 'dep')
            ->toArray();

        return ['origen' => $origen, 'destino' => $destino];
    }

    /** Movimientos del tramo ruta (inicio_ruta -> qr_llegada_destino) para el mapa animado.
     *
     * Reutiliza base() (mismos filtros). Cada viaje se reduce a coordenadas x/y de los
     * departamentos de origen/destino (DBRutas::$depas) y a un par de tiempos epoch (ms,
     * tratados como hora local 'naive' para mostrar el reloj sin sorpresas de zona horaria).
     * Incluye conductor, placa y TRT (colores del mapa) y los vapi_id del ref para que
     * el clic en un camion abra sus llamadas. Se limita a $limite viajes.
     */
    public static function movimientos(Request $request, int $limite = 600): array
    {
        $filas = self::base($request)
            ->whereNotNull('r.inicio_ruta')
            ->whereNotNull('r.qr_llegada_destino')
            ->whereNotNull('ru.ubigeo_origen')
            ->whereNotNull('ru.ubigeo_destino')
            ->orderBy('r.inicio_ruta')
            ->limit($limite)
            ->selectRaw("r.ref, r.placa, r.inicio_ruta, r.qr_llegada_destino,
                con.nombres as conductor_nombre, trt.nombres as trt_nombre,
                LEFT(ru.ubigeo_origen,2) as dep0, LEFT(ru.ubigeo_destino,2) as dep1,
                COALESCE(NULLIF(lo_orig.nombre,''), ug_orig.distrito) as origen_nombre,
                COALESCE(NULLIF(lo_dest.nombre,''), ug_dest.distrito) as destino_nombre")
            ->get();

        $movs = [];
        foreach ($filas as $f) {
            // el tramo debe durar >= 0 y ambos departamentos deben tener coordenadas
            $inicio = strtotime($f->inicio_ruta . ' UTC');
            $fin = strtotime($f->qr_llegada_destino . ' UTC');
            if ($inicio === false || $fin === false || $fin < $inicio) {
                continue;
            }
            $d0 = DBRutas::$depas[$f->dep0] ?? null;
            $d1 = DBRutas::$depas[$f->dep1] ?? null;
            if (!$d0 || !$d1) {
                continue;
            }
            $movs[] = [
                'ref'     => $f->ref,
                'conductor' => $f->conductor_nombre,
                'placa'   => $f->placa,
                'trt'     => $f->trt_nombre,
                'dep0'  => $f->dep0, 'dep1' => $f->dep1,
                'x0' => $d0['x'], 'y0' => $d0['y'],
                'x1' => $d1['x'], 'y1' => $d1['y'],
                'inicio'  => $inicio * 1000,
                'fin'     => $fin * 1000,
                'origen'  => $f->origen_nombre,
                'destino' => $f->destino_nombre,
            ];
        }

        // vapi_id por ref en una sola consulta: lupita.llamadas?ids= espera ids separados
        // por salto de linea, pero se transportan con coma y el JS los convierte.
        $vids = [];
        if ($movs) {
            $filasV = DB::table('llamadas')
                ->selectRaw('ref, GROUP_CONCAT(vapi_id ORDER BY created_at SEPARATOR ",") as vids')
                ->whereIn('ref', array_column($movs, 'ref'))
                ->whereNotNull('vapi_id')
                ->groupBy('ref')
                ->get();
            foreach ($filasV as $v) {
                $vids[$v->ref] = $v->vids;
            }
        }
        foreach ($movs as &$mov) {
            $mov['vids'] = $vids[$mov['ref']] ?? '';
        }
        unset($mov);

        return $movs;
    }

    /** Top rutas (origen - destino) por numero de viajes. */
    public static function top_rutas(Request $request, int $limite = 8): array
    {
        $rows = self::base($request)
            ->whereNotNull('r.ruta_id')
            ->selectRaw("r.ruta_id,
                MAX(lo_orig.nombre) as loc_origen_nombre,
                MAX(lo_dest.nombre) as loc_destino_nombre,
                MAX(ug_orig.distrito) as ubg_origen_nombre,
                MAX(ug_dest.distrito) as ubg_destino_nombre,
                COUNT(*) as veces")
            ->groupBy('r.ruta_id')
            ->orderByDesc('veces')
            ->limit($limite)
            ->get();

        return $rows->all();
    }

    /** Llamadas de una referencia (para los endpoints JSON). */
    public static function llamadas_de_ref(string $ref): array
    {
        return DB::table('llamadas as a')
            ->leftJoin('conductores as b', 'b.id', '=', 'a.conductor_id')
            ->leftJoin('tipos_llamada as e', 'e.id', '=', 'a.llamada_tipo_id')
            ->where('a.ref', $ref)
            ->orderBy('a.created_at')
            ->selectRaw('a.vapi_id, a.created_at, a.telefono, a.llamada_tipo_id,
                e.nombre as llamada_tipo, a.origen, a.destino, a.placa,
                a.llamada_exitosa, a.exitosa_segun_ia, a.error_origen,
                a.razon_finalizacion_id, a.analisis_audio, a.analisis_transcripcion,
                a.ia_result_comments_text, a.ia_result_delay_reason_desc,
                a.audio_link, a.audio_duracion, a.costo')
            ->get()
            ->map(fn($r) => (array) $r)
            ->all();
    }

    /** Mapa ref => {resumen, llamadas_exitosas[]}, para una lista de refs.
     *
     * El resumen trae los conteos del ref (total/exitosas/fallidas) y solo se listan
     * las llamadas exitosas, con el mismo set de campos que devuelve llamadas_de_ref().
     */
    public static function llamadas_de_refs(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map('trim', $refs), fn($r) => $r !== '')));
        if (empty($refs)) {
            return [];
        }

        $agrupado = [];
        foreach ($refs as $r) {
            // cada ref presente, aunque no tenga llamadas
            $agrupado[$r] = [
                'resumen' => ['total' => 0, 'exitosas' => 0, 'fallidas' => 0],
                'llamadas_exitosas' => [],
            ];
        }

        $filas = DB::table('llamadas as a')
            ->leftJoin('tipos_llamada as e', 'e.id', '=', 'a.llamada_tipo_id')
            ->whereIn('a.ref', $refs)
            ->orderBy('a.ref')
            ->orderBy('a.created_at')
            ->selectRaw('a.ref, a.vapi_id, a.created_at, a.telefono, a.llamada_tipo_id,
                e.nombre as llamada_tipo, a.origen, a.destino, a.placa,
                a.llamada_exitosa, a.exitosa_segun_ia,
                a.analisis_audio, a.ia_result_comments_text,
                a.ia_result_delay_reason_desc, a.audio_link')
            ->get();

        foreach ($filas as $fila) {
            $esExitosa = (int) $fila->llamada_exitosa === 1;
            $agrupado[$fila->ref]['resumen']['total']++;
            if ($esExitosa) {
                $agrupado[$fila->ref]['resumen']['exitosas']++;
                // el ref ya es clave del agrupado: se retira del objeto de la llamada
                $llamada = (array) $fila;
                unset($llamada['ref']);
                $agrupado[$fila->ref]['llamadas_exitosas'][] = $llamada;
            } else {
                $agrupado[$fila->ref]['resumen']['fallidas']++;
            }
        }

        return $agrupado;
    }
}
