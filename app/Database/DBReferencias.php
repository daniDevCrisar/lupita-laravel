<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;
use stdClass;

class DBReferencias {
    public static function existe($id) {
        $result= DB::select('SELECT ref FROM `referencias` where ref=?', [$id]);
        if (count($result) == 1) {
            return true;
        }
        return false;
    }

    public static function sp_insertar_o_nueva_referencia($row){
        if (!property_exists($row,'id_conductor')) return false;

        if (property_exists($row,'id_trt')){
            $id_trt=$row->id_trt;
            $id_trt= ($id_trt !== 'null' && $id_trt !== '') ? $id_trt : null;
        }
        else
            $id_trt= null;

        $sql="
        INSERT INTO referencias (
            ref,
            trt_id,
            conductor_id,
            fecha_despachador,
            titulo_viaje,
            placa,
            fin_descargue,
            inicio_descargue,
            qr_llegada_destino,
            fin_de_carga,
            inicio_de_carga,
            presenta_para_carga,
            compromiso_carga
        ) VALUES (
            ?, ?, ?, FROM_UNIXTIME(?),
            ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?),
            FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
            FROM_UNIXTIME(?), FROM_UNIXTIME(?)
        )
        ON DUPLICATE KEY UPDATE
            trt_id = VALUES(trt_id),
            conductor_id = VALUES(conductor_id),
            fecha_despachador = VALUES(fecha_despachador),
            titulo_viaje = VALUES(titulo_viaje),
            placa = VALUES(placa),
            fin_descargue = VALUES(fin_descargue),
            inicio_descargue = VALUES(inicio_descargue),
            qr_llegada_destino = VALUES(qr_llegada_destino),
            fin_de_carga = VALUES(fin_de_carga),
            inicio_de_carga = VALUES(inicio_de_carga),
            presenta_para_carga = VALUES(presenta_para_carga),
            compromiso_carga = VALUES(compromiso_carga);
        ";
        $placa=self::verificar_placa($row->placa);
        $accion=  DB::affectingStatement($sql,
            [
                $row->ref,
                $id_trt,
                $row->id_conductor,
                self::excel_time_a_timestamp($row->fecha_despachador),
                $row->titulo_viaje,
                $placa,
                self::excel_time_a_timestamp($row->fin_descargue),
                self::excel_time_a_timestamp($row->inicio_descargue),
                self::excel_time_a_timestamp($row->qr_llegada_destino),
                self::excel_time_a_timestamp($row->fin_de_carga),
                self::excel_time_a_timestamp($row->inicio_de_carga),
                self::excel_time_a_timestamp($row->presenta_para_carga),
                self::excel_time_a_timestamp($row->compromiso_carga),
            ]
        );

//        SELECT a.* FROM `referencias` a
//INNER JOIN llamadas b
//on b.ref = a.ref
//where b.lote_id='202602272121138107';


        if ($accion===1)
            $es_nuevo=true;
        else
            $es_nuevo=false;

        $result = new StdClass();
        $result->ref=$row->ref;
        $result->es_nuevo=$es_nuevo;

        return $result;
    }

    public static function excel_time_a_timestamp($excel_date) {
    // Excel cuenta desde 1900-01-01 (pero con error de año bisiesto)
    // La constante correcta es 25569 (días entre 1900-01-01 y 1970-01-01)
        if (empty($excel_date) || !is_numeric($excel_date)) {
            return null;
        }
        $unix_timestamp = ($excel_date - 25569) * 86400;
        return (int) floor($unix_timestamp);
    }

    public static function verificar_placa($placa) {
        $placa_2= $placa;
        if (strlen($placa) >10) {
            $placa_2= explode('/', $placa);

            $placa_2=$placa_2[0];

        }
        return $placa_2;
    }

}
