<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tools\ExcelTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LlamadasApiController extends Controller
{
    public function devolver_lista_lote_refs($lote_id){
        $lista_fechas=[];
        //------------DATOS DE LLAMADAS CON REF------------
        $sql="
        select DISTINCT(ref) from tmp_lotes_det where lote_id=? and ref !='' ;
        ";
        $refs= DB::select($sql,[$lote_id]);
        $lista=[];
        foreach($refs as $ref)
            $lista[]=$ref->ref;

        //-------------FECHAS DE LLAMADAS DE CONFIRMACION SIN REF-------------
        $sql_conf="
        select DISTINCT(a.fecha) from
             (SELECT DATE_FORMAT(FROM_UNIXTIME(CAST(created_at AS UNSIGNED) / 1000) ,'%Y-%m-%d') as fecha
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
        //-------------------SQL PARA CONFIRMACIONES-------------
        $sql_conf="
        UPDATE `tmp_lotes_det` set ref=?, transportista=? where lote_id=? and llamada_tipo='1' and placa=? and
        DATE_FORMAT(FROM_UNIXTIME(CAST(created_at AS UNSIGNED) / 1000) ,'%d/%m/%Y')=?;
        ";
        //-----------------------SQL REFS TEMPORAL-------------------
        $sql_ref_tmp ="
        INSERT INTO tmp_lotes_ref (lote_id, ref, tlf_conductor,titulo_viaje,placa,fin_descargue ,inicio_descargue,
                                   qr_llegada_destino,inicio_ruta,fin_de_carga , inicio_de_carga , presenta_para_carga ,compromiso_carga ,fecha_despachador, trt)
        VALUES (?,?,?,?,?,?,?,
        ?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            tlf_conductor = VALUES(tlf_conductor),
            titulo_viaje = VALUES(titulo_viaje),
            placa = VALUES(placa),
            fin_descargue = VALUES(fin_descargue),
            inicio_descargue = VALUES(inicio_descargue),
            qr_llegada_destino = VALUES(qr_llegada_destino),

            inicio_ruta = VALUES(inicio_ruta),
            fin_de_carga = VALUES(fin_de_carga),
            inicio_de_carga = VALUES(inicio_de_carga),
            presenta_para_carga = VALUES(presenta_para_carga),
            compromiso_carga = VALUES(compromiso_carga),
            fecha_despachador = VALUES(fecha_despachador),
            trt = VALUES(trt)
        ";

        foreach ($datos as $key => $item) {
            //normalizar texto------------------
            array_walk_recursive($item, function(&$i, $key) {
                if (is_string($i)) $i = ExcelTool::normalizarTexto(strtoupper( html_entity_decode($i, ENT_QUOTES, 'UTF-8')));
            });
            //------------------------------------------

            $update=DB::update($sql,[$item['transportista'],$lote_id,$key]);
            if ($item['confirmacion'] and !$update){
                $fecha_llamada_buscar= explode(' ',$item['fecha_llamada']);
                DB::update($sql_conf,[$key,$item['transportista'],$lote_id,$item['placa'],$fecha_llamada_buscar[0] ]);
            }
            DB::insert($sql_ref_tmp,[
                $lote_id,$key,$item['tlf_chofer'],$item['titulo_viaje'],$item['placa'],$item['fecha_fin_descarga'],$item['fecha_inicio_descarga'],
                $item['fecha_qr_descarga'],$item['fecha_inicio_ruta'],$item['fecha_fin_carga'],$item['fecha_inicio_carga'],$item['fecha_presente_carga'],$item['fecha_conpromiso'],
                $item['fecha_despachador'],$item['transportista']
            ]);

        }

        //-************************************************************************


        return response()->json([
            'success' => true,
            'data' => $datos
        ]);

    }
}
