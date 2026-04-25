<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LlamadasApiController extends Controller
{
    public function devolver_lista_lote_refs($lote_id){

        //------------DATOS DE LLAMADAS CON REF------------
        $sql="select ref from tmp_lotes_det where lote_id=?;";
        $refs= DB::select($sql,[$lote_id]);
        foreach($refs as $ref)
            $lista[]=$ref->ref;

        //-------------FECHAS DE LLAMADAS DE CONFIRMACION SIN REF-------------
        $sql_conf="
        select DISTINCT(a.fecha) from
             (SELECT DATE_FORMAT(FROM_UNIXTIME(CAST(created_at AS UNSIGNED) / 1000) ,'%d/%m/%Y') as fecha
              FROM `tmp_lotes_det` WHERE lote_id=?) as a;
        ";
        $fechas_conf= DB::select($sql_conf,[$lote_id]);
        foreach($fechas_conf as $fecha)
            $lista_fechas[]=$fecha->fecha;
        //--------------------------------------------------------------

        $json['fechas']=$lista_fechas;
        $json['refs']=$lista;

        return response()->json($json);
    }

    public function actualizar_lote_detalle(Request $request,$lote_id)
    {
        if (!$request->isJson()) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se acepta JSON'
            ], 415); // 415 Unsupported Media Type
        }

        $datos = $request->json()->all();
        // ----------------------ACTUALIZAR TRTS DEL LOTE*-------------------------
        $sql="update tmp_lotes_det set transportista=? where lote_id=? and ref=? and transportista='';
        ";
        foreach ($datos as $key => $item) {
            //normalizar texto------------------
            array_walk_recursive($item, function(&$i, $key) {
                if (is_string($i)) $i = strtoupper( html_entity_decode($i, ENT_QUOTES, 'UTF-8'));
            });
            //------------------------------------------
            DB::update($sql,[$item['transportista'],$lote_id,$key]);
        }

        //-************************************************************************


        return response()->json([
            'success' => true,
            'data' => $datos
        ]);

    }
}
