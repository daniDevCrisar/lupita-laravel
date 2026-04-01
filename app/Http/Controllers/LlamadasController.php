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
        if ($request->fecha_inicio and $request->fecha_fin) {

            $reporte->mapa_calor= $llamadas::mapa_calor_rango();
            $max_t=0;$max_f=0;$max_e=0; //obtener los maximos para el mapa de calor
            $t_max_t=0;$t_max_f=0;$t_max_e=0; //resumen
            $t_min_t=0;$t_min_f=0;$t_min_e=0; //resumen
            $hora_max_t=0;$hora_max_f=0;$hora_max_e=0; //resumen
            $hora_min_t=0;$hora_min_f=0;$hora_min_e=0; //resumen

            $resumen_mapa= new stdClass();

            for($i = 0; $i< 24;$i++){
                $t_total=0;$t_fallo=0;$t_exito=0;
                for($j=0; $j<count($reporte->mapa_calor); $j++){
                    $key_t='hora_'.$i;
                    $key_f='hora_'.$i . '_fallo';
                    $key_e='hora_'.$i . '_exito';
                    $v_total = $reporte->mapa_calor[$j]->$key_t;
                    $v_fallo = $reporte->mapa_calor[$j]->$key_f;
                    $v_exito = $reporte->mapa_calor[$j]->$key_e;

                    $t_total+=$v_total;
                    $t_fallo+=$v_fallo;
                    $t_exito+=$v_exito;

                    if($v_total > $max_t) $max_t = $v_total;
                    if($v_fallo > $max_f) $max_f = $v_fallo;
                    if($v_exito > $max_e) $max_e = $v_exito;
                }
                $resumen_mapa->rows[]=[
                    'total'=>$t_total,
                    'fallo'=>$t_fallo,
                    'exito'=>$t_exito
                ];
                if($t_total > $t_max_t) {
                    $t_max_t = $t_total;$hora_max_t = $i;
                }
                if($t_fallo > $t_max_f) {
                    $t_max_f = $t_fallo;$hora_max_f = $i;
                }
                if($t_exito > $t_max_e) {
                    $t_max_e = $t_exito;$hora_max_e = $i;
                }

                if($t_total < $t_min_t) {
                    $t_min_t = $t_total;$hora_min_t = $i;
                }
                if($t_fallo < $t_min_f) {
                    $t_min_f = $t_fallo;$hora_min_f = $i;
                }
                if($t_exito < $t_min_e) {
                    $t_min_e = $t_exito;$hora_min_e = $i;
                }
            }
            $resumen_mapa->max_total=$t_max_t;
            $resumen_mapa->max_fallo=$t_max_f;
            $resumen_mapa->max_exito=$t_max_e;
            $resumen_mapa->max_total_hora=$hora_max_t;
            $resumen_mapa->max_fallo_hora=$hora_max_f;
            $resumen_mapa->max_exito_hora=$hora_max_e;

            $resumen_mapa->min_total=$t_min_t;
            $resumen_mapa->min_fallo=$t_min_f;
            $resumen_mapa->min_exito=$t_min_e;
            $resumen_mapa->min_total_hora=$hora_min_t;
            $resumen_mapa->min_fallo_hora=$hora_min_f;
            $resumen_mapa->min_exito_hora=$hora_min_e;

            $reporte->mapa_calor_resumen= $resumen_mapa;

            $reporte->mapa_calor_max = [
                'total'=> $max_t,
                'fallo'=>$max_f,
                'exito'=> $max_e
            ];
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
