<?php

namespace App\Http\Controllers;

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
            'etiquetas.*' => 'string|max:50'
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
        ]);

        $llamadas= new DBLlamadas();
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


        $reporte->total= $reporte->total[0];
        $reporte->peores= $llamadas::top_peores_conductores();
        $reporte->mejores= $llamadas::top_mejores_conductores();
        $reporte->mejores_trts= $llamadas::top_mejores_trts();
        $reporte->peores_trts= $llamadas::top_peores_trts();
        $reporte->grafico_semana= $llamadas::grafico_semana_query();

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

        return view('reporte.todo.todo1', [
            'llamadas' => $llamadas,
            'reporte' => $reporte
        ]);
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
        $request->validate([
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|string',
            'exitosa'          => 'nullable|string',
        ]);

        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        $llamadas::listar_principal(30);

        return view('lupita.procesar_audio', [
            'llamadas' => $llamadas
        ]);
    }

    public static function guardar_etiquetas(Request $request){
        $etiquetar= new DBLlamadaEtiquetar(DBLlamadas::$etiquetas_icon_bi,$request);
        $response=$etiquetar::etiquetar();
        return response()->json($response);
    }

}
