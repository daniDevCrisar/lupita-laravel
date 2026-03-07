<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;

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

        $placa=self::verificar_placa($row->placa);
        $accion=  DB::select('CALL sp_insertar_o_nueva_referencia(?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?))',
            [
                $row->ref,
                $id_trt,
                $row->id_conductor,
                self::excel_time_a_timestamp($row->fecha_despachador),
                $row->titulo_viaje,
                $placa,
                null,//$row->fin_descargue,
                null,//$row->inicio_descargue,
                null,//$row->qr_llegada_destino,
                self::excel_time_a_timestamp($row->fin_de_carga),
                self::excel_time_a_timestamp($row->inicio_de_carga),
                self::excel_time_a_timestamp($row->presenta_para_carga)
            ]
        );
        return $accion[0];
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
