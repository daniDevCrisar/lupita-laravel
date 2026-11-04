<?php

namespace App\Http\Controllers;

use App\Database\DBRutas;
use App\Database\DBTrts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Database\DBLlamadas;
use App\Database\DBLlamadaEtiquetar;
use App\Database\DBConductores;
use stdClass;

class LlamadasController extends Controller
{
    public static function listar_llamadas(Request $request){
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|string',
            'exitosa'          => 'nullable|string',
            'e_operador' => 'nullable|numeric:',
            'etiquetas' => 'nullable|array',
            'etiquetas.*' => 'string|max:50',
            'ids'              => 'nullable|string',
        ]);

        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        $llamadas::listar_principal(30);

        return view('lupita.lista_llamadas', [
            'llamadas' => $llamadas
        ]);
    }

    public static function reporte_todo(Request $request){
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|string',
            'exitosa'          => 'nullable|string',
            'top_peores'       => 'nullable|numeric',
        ]);

        $llamadas= new DBLlamadas();
        //-------------EVITAR DESBORDAMIENTO POR TIEMPO DE ESPERA
        DB::statement('SET SESSION wait_timeout = 600');
        //------------------------
        $llamadas::set_filtro($request);
        //$llamadas::listar_principal(30);

        $reporte = new stdClass();
        $reporte->titulo = 'Reporte de llamadas totales VAPI';
        $reporte->total= $llamadas::etiqueta_totales();
        if ($reporte->total[0]->llamadas==0) return '<h1 style="color: red">No hay llamadas</h1>';

        if ($request->llamada_tipo_id == 1) $reporte->titulo = 'Reporte de Confirmaciones VAPI';
        elseif ($request->llamada_tipo_id == 2) $reporte->titulo = 'Reporte de Espera fuera de planta para carga VAPI';
        elseif ($request->llamada_tipo_id == 3) $reporte->titulo = 'Reporte de Espera dentro de planta para carga VAPI';
        elseif ($request->llamada_tipo_id == 5) $reporte->titulo = 'Reporte de Espera fuera de planta para descarga VAPI';
        elseif ($request->llamada_tipo_id == 6) $reporte->titulo = 'Reporte de Espera dentro de planta para descarga VAPI';


        //--------------------peores personalizado---------------------
        $top_peor_c = 5;
//        dd($request->llamada_tipo_id,$request?->top_peores);
        if (!$request->llamada_tipo_id??0 and $request?->top_peores??0){
            $top_peor_c= $request->top_peores;
        }

        $reporte->total= $reporte->total[0];
        // clasificacion exclusiva de fallidas por etiquetas actuales (todo1)
        $reporte->fallos= $llamadas::clasificacion_fallos();
        $reporte->top_peores= $top_peor_c;
        $reporte->peores= $llamadas::top_peores_conductores($top_peor_c);
        $reporte->mejores= $llamadas::top_mejores_conductores();
        $reporte->mejores_trts= $llamadas::top_mejores_trts();
        $reporte->peores_trts= $llamadas::top_peores_trts();
        $reporte->grafico_semana= $llamadas::grafico_semana_query();
        $reporte->diagrama_venn_ia_persona = $llamadas::diagrama_venn_ia_persona()[0];


        //-----------PRUEBA--------------
        if (!$request->llamada_tipo_id)
            $reporte->etapa_logistica=$llamadas::resumen_por_etapa_logistica();

        if ($request->fecha_inicio and $request->fecha_fin) {
            $result=$llamadas::mapa_calor_rango();
            $reporte->mapa_calor =$result['mapa_calor'];
            $reporte->mapa_calor_resumen=$result['mapa_calor_resumen'];
            $reporte->mapa_calor_max=$result['mapa_calor_max'];
        }
        //-----------------------

        //------------lista de rutas--------------
        $rutas = new stdClass();
        $rutas_lista = new DBRutas();
        $rutas_lista::$filtro= $llamadas::aplicar_filtro_sqltext('d');
//        dd($rutas::$filtro);
        $rutas->clase=$rutas_lista;
        $rutas->lista = $rutas_lista::lista_principal();
        $rutas->depas = $rutas_lista::$depas;

        $rutas_lista::$filtro= $llamadas::aplicar_filtro_sqltext('a');
        $rutas->lista_detalle = $rutas_lista::lista_rutas_recorridas();
        $rutas->resumen= $rutas_lista::rutas_resumen();
        $rutas->mas_rapido= $rutas_lista::conductor_mas_rapido();
//        dd($rutas->lista_detalle);
        //-------------------------------

        return view('reporte.todo.todo1', [
            'llamadas' => $llamadas,
            'reporte' => $reporte,
            'rutas' => $rutas
        ]);
    }

    /**
     * Recolecta los datos que comparten la portada completa y la compacta.
     * $con_rutas=false omite el bloque de rutas/mapa (la parte mas costosa) para
     * la version ultra-compacta de gerencia. Muta solo el filtro estatico por tipo
     * y lo restaura al final; no modifica ninguno de los helpers de DBLlamadas.
     */
    private static function portada_datos(Request $request, $con_rutas = true){
        $request->validate([
            'fecha_inicio' => 'nullable|date',
        ]);

        DB::statement('SET SESSION wait_timeout = 600');

        $llamadas = new DBLlamadas();
        $llamadas::set_filtro($request);

        // dia seleccionado (si no viene, hoy). Se usa para el badge y la URL.
        $fecha_str = $request->fecha_inicio ?: date('Y-m-d');
        $folder = date('d-m-y', strtotime($fecha_str));

        //----------------- RESUMEN (todas las llamadas, sin tipo) -----------------
        $resumen = new stdClass();
        $resumen->total = $llamadas::etiqueta_totales()[0];
        $resumen->etapa_logistica = $llamadas::resumen_por_etapa_logistica();

        //----------------- rutas y mapa (solo del resumen, opcional) -----------------
        $rutas = null;
        if ($con_rutas) {
            $rutas = new stdClass();
            $rutas_lista = new DBRutas();
            $rutas_lista::$filtro = $llamadas::aplicar_filtro_sqltext('d');
            $rutas->clase = $rutas_lista;
            $rutas->lista = $rutas_lista::lista_principal();
            $rutas->depas = $rutas_lista::$depas;
            $rutas_lista::$filtro = $llamadas::aplicar_filtro_sqltext('a');
            $rutas->lista_detalle = $rutas_lista::lista_rutas_recorridas();
            $rutas->resumen = $rutas_lista::rutas_resumen();
        }

        //----------------- KPIs por tipo + enlace al detalle exportado -----------------
        $titulos = [
            1 => 'Total confirmaciones',
            2 => 'Total unidades en espera para carga',
            3 => 'Total unidades dentro de planta para carga',
            5 => 'Total unidades en espera para descarga',
            6 => 'Total unidades dentro de planta para descarga',
        ];
        $slugs = [
            1 => 'confirmaciones',
            2 => 'fuera-de-planta-para-carga',
            3 => 'dentro-de-planta-para-carga',
            5 => 'fuera-de-planta-para-descarga',
            6 => 'dentro-de-planta-para-descarga',
        ];
        $base = 'https://danidevcrisar.github.io/lupita-laravel/reportes/';

        $tipos = [];
        foreach (array_keys($titulos) as $id) {
            DBLlamadas::$filtro->llamada_tipo_id = $id;      // muta solo el filtro estatico
            $tipos[] = (object) [
                'id' => $id,
                'titulo' => $titulos[$id],
                'total' => $llamadas::etiqueta_totales()[0],
                'url' => $base . $folder . '/' . $id . '-' . $slugs[$id] . '.html',
            ];
        }
        DBLlamadas::$filtro->llamada_tipo_id = $request->llamada_tipo_id; // restaurar



        return [
            'llamadas' => $llamadas,
            'resumen' => $resumen,
            'rutas' => $rutas,
            'tipos' => $tipos,
            'fecha_str' => $fecha_str,
            'folder' => $folder,
            'fecha' => $request->fecha_inicio,
        ];
    }

    /**
     * Portada/hub de UN solo dia (version extendida): KPIs del resumen + etapa
     * logistica + mapa/rutas + errores tecnicos, y un bloque de KPIs por cada tipo
     * de llamada que enlaza al detalle ya exportado (GitHub Pages).
     */
    public static function reporte_portada(Request $request){
        return view('reporte.todo.portada', self::portada_datos($request, true));
    }

    /**
     * Version compacta para gerencia. 'nivel' = ultra (resumen + tabla por tipo)
     * o medio (agrega etapa logistica y Top 5 rutas).
     */
    public static function reporte_compacta(Request $request){
        $nivel = $request->nivel === 'medio' ? 'medio' : 'ultra';
        $datos = self::portada_datos($request, $nivel === 'medio'); // rutas solo si medio
        $datos['nivel'] = $nivel;
        return view('reporte.todo.portada_compacta', $datos);
    }

    /**
     * Lanzador: formulario para elegir fecha + tipo de reporte y generar el visto.
     */
    public static function reporte_seleccion(Request $request){
        return view('reporte.todo.seleccion', [
            'llamadas' => new DBLlamadas(),
            'fecha_str' => $request->fecha_inicio ?: date('Y-m-d'),
        ]);
    }

    /**
     * Destino del formulario del lanzador: decide que vista devolver segun 'tipo'.
     */
    public static function reporte_ejecutivo(Request $request){
        $tipo = $request->tipo ?: 'completa';
        if ($tipo === 'compacta_ultra') { $request->merge(['nivel' => 'ultra']); return self::reporte_compacta($request); }
        if ($tipo === 'compacta_medio') { $request->merge(['nivel' => 'medio']); return self::reporte_compacta($request); }
        return self::reporte_portada($request);
    }

    public static function listar_conductores(Request $request)
    {
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|numeric',
            'ordenar_por'        => 'nullable|string',
            'orden'        => 'nullable|numeric',
        ]);

        $llamadas= new DBLlamadas();

        $conductores = new DBConductores();
        $conductores::set_filtro($request);
        $conductores = $conductores::lista_principal();
        return view('lupita.lista_conductores', [
            'llamadas' => $llamadas,
            'conductores' => $conductores
        ]);
    }

    public static function listar_trts(Request $request)
    {
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'trt'        => 'nullable|string',
            'ordenar_por'        => 'nullable|string',
            'orden'        => 'nullable|numeric',
        ]);

        $llamadas= new DBLlamadas();

        $trts = new DBTrts();
        $trts::set_filtro($request);
        $trts = $trts::lista_principal();
        return view('lupita.lista_trts', [
            'llamadas' => $llamadas,
            'trts' => $trts
        ]);
    }

    public static function procesar_audio(Request $request){
        $analisis= new stdClass();
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|string',
            'exitosa'          => 'nullable|string',
            'ids'              => 'nullable|string',
        ]);

        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        $llamadas::listar_principal(100);

        $analisis->texto_mas_usado= DBLlamadaEtiquetar::analisis_audio_mas_usado();

        return view('lupita.procesar_audio', [
            'llamadas' => $llamadas,
            'analisis' => $analisis
        ]);
    }

    public static function guardar_etiquetas(Request $request){
        //METODO PATCH SOLO SE PUEDE ENVIAR UN JSON
        $data= (object) $request->json()->all();

        $etiquetar= new DBLlamadaEtiquetar(DBLlamadas::$etiquetas_icon_bi,$data);
        $response=$etiquetar::etiquetar();
        return response()->json($response);
    }

    /**
     * Lista de llamadas del dia. Acepta ?fecha=YYYY-MM-DD (dia a consultar) y
     * ?enddate=YYYY-MM-DD (opcional, para abrir un rango). Sin parametro abre hoy.
     */
    public static function calls_lista_llamadas(Request $request){
        $tipos_llamada = DB::table('tipos_llamada')->select('id', 'nombre', 'emoji', 'color')
            ->where('id', '!=','0')
            ->where('id', '!=','4')
            ->get();
        return view('calls.calls_lista', [
            'tipos_llamada' => $tipos_llamada,
            'fecha'         => self::fecha_url($request->query('fecha')),
            'fecha_fin'     => self::fecha_url($request->query('enddate')),
        ]);
    }

    /**
     * Normaliza una fecha recibida por la URL y la devuelve siempre como Y-m-d
     * (formato que exige el input type=date). Acepta 2026-08-10, 10/08/2026 y
     * 10-08-2026; cualquier otra cosa devuelve null y la pantalla abre hoy, en
     * lugar de romper con un error de validacion en un GET.
     */
    private static function fecha_url($valor){
        if (!is_string($valor)) return null;
        $valor = trim($valor);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
            [$anio, $mes, $dia] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $valor, $m)) {
            [$dia, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mes, $dia, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : null;
    }

}
