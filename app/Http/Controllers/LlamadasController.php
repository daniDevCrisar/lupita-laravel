<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Database\DBLlamadas;
use App\Database\DBConductores;
use stdClass;

class LlamadasController extends Controller
{
    public static function listar_llamadas(Request $request){
        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        $llamadas::listar_principal(30);

        return view('lupita.lista_llamadas', [
            'llamadas' => $llamadas
        ]);
    }

    public static function reporte_todo(Request $request){
        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        //$llamadas::listar_principal(30);

        $reporte = new stdClass();
        $reporte->titulo = 'Reporte de llamadas totales VAPI';
        $reporte->total= $llamadas::etiqueta_totales();

        if ($request->llamada_tipo_id == 1) $reporte->titulo = 'Reporte de Confirmaciones VAPI';
        elseif ($request->llamada_tipo_id == 2) $reporte->titulo = 'Reporte de Espera fuera de planta VAPI';
        elseif ($request->llamada_tipo_id == 3) $reporte->titulo = 'Reporte de Espera dentro de planta VAPI';

        $reporte->total= $reporte->total[0];
        $reporte->peores= $llamadas::top_peores_conductores();
        $reporte->mejores= $llamadas::top_mejores_conductores();
        $reporte->mejores_trts= $llamadas::top_mejores_trts();
        $reporte->peores_trts= $llamadas::top_peores_trts();

        return view('reporte.todo.todo1', [
            'llamadas' => $llamadas,
            'reporte' => $reporte
        ]);
    }

    public static function listar_conductores(Request $request)
    {
        $llamadas= new DBLlamadas();
        $llamadas::set_filtro($request);
        $conductores = DBConductores::lista_principal();
        return view('lupita.lista_conductores', [
            'llamadas' => $llamadas,
            'conductores' => $conductores
        ]);
    }
}
