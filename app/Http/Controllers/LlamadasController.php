<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Database\DBLlamadas;
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
        $llamadas::listar_principal(30);

        $reporte = new stdClass();
        $reporte->titulo = 'Reporte de llamadas VAPI';
        $reporte->total= $llamadas::etiqueta_totales();
        $reporte->total= $reporte->total[0];
        $reporte->peores= $llamadas::top_peores_conductores();
        $reporte->mejores= $llamadas::top_mejores_conductores();
        return view('reporte.todo.todo1', [
            'llamadas' => $llamadas,
            'reporte' => $reporte
        ]);
    }
}
