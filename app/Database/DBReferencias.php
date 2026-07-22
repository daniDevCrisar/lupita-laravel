<?php

namespace App\Database;
use DateTime;
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
//        if (!property_exists($row,'id_conductor')) return false;

        if (property_exists($row,'id_conductor')){
            $id_conductor=$row->id_conductor;
            $id_trt= ($id_conductor !== 'null' && $id_conductor !== '') ? $id_conductor : null;
        }
        else
            $id_conductor= null;


        if (property_exists($row,'id_trt')){
            $id_trt=$row->id_trt;
            $id_trt= ($id_trt !== 'null' && $id_trt !== '') ? $id_trt : null;
        }
        else
            $id_trt= null;

        $sql="
        INSERT INTO referencias (
            ref, trt_id, conductor_id, fecha_despachador,
            titulo_viaje, placa, fin_descargue, inicio_descargue,
            qr_llegada_destino,
            inicio_ruta,
            fin_de_carga,
            inicio_de_carga,
            presenta_para_carga,
            compromiso_carga
        ) VALUES (
        ?, ?, ?, FROM_UNIXTIME(?),
            ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?),
            FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
            FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?)
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
            inicio_ruta = VALUES(inicio_ruta),
            fin_de_carga = VALUES(fin_de_carga),
            inicio_de_carga = VALUES(inicio_de_carga),
            presenta_para_carga = VALUES(presenta_para_carga),
            compromiso_carga = VALUES(compromiso_carga);
        ";

//        ?, ?, ?, FROM_UNIXTIME(?),
//            ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?),
//            FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
//            FROM_UNIXTIME(?), FROM_UNIXTIME(?)
//        dd( self::excel_time_a_timestamp($row->fin_descargue),
//            self::excel_time_a_timestamp($row->inicio_descargue),
//            self::excel_time_a_timestamp($row->qr_llegada_destino),
//            self::excel_time_a_timestamp($row->fin_de_carga),
//            self::excel_time_a_timestamp($row->inicio_de_carga),
//            self::excel_time_a_timestamp($row->presenta_para_carga),
//            self::excel_time_a_timestamp($row->compromiso_carga) ,$row);

        $placa=self::verificar_placa($row->placa);
        if ($placa==null) return false;
        $accion=  DB::affectingStatement($sql,
            [
                $row->ref,
                $id_trt,
                $id_conductor,
                self::fecha_str_timestamp($row->fecha_despachador),
                $row->titulo_viaje,
                $placa,
                self::fecha_str_timestamp($row->fin_descargue),
                self::fecha_str_timestamp($row->inicio_descargue),
                self::fecha_str_timestamp($row->qr_llegada_destino),
                self::fecha_str_timestamp($row->inicio_ruta),
                self::fecha_str_timestamp($row->fin_de_carga),
                self::fecha_str_timestamp($row->inicio_de_carga),
                self::fecha_str_timestamp($row->presenta_para_carga),
                self::fecha_str_timestamp($row->compromiso_carga),
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

    public static function fecha_str_timestamp($fecha_str, $format='d/m/Y H:i') {
        if (empty($fecha_str)) {
            return null;
        }
        else if (is_numeric($fecha_str))
            return self::excel_time_a_timestamp($format);


        $fecha = DateTime::createFromFormat($format, $fecha_str);

        if ($fecha) {
//            $timestamp = $fecha->getTimestamp() + (5 * 3600); // Restar 5 horas (18000 segundos)
            $timestamp = $fecha->getTimestamp(); // Restar 5 horas (18000 segundos)
            return $timestamp;
        } else {
            echo $fecha_str . '<br>';
            return null;
        }
    }

    public static function actualizar_rutas($lote_id){
        $ruta_log= DB::statement("CALL sp_actualizar_rutas_lote('$lote_id');");
        return 'Se Actualizo las Rutas: ' . $ruta_log;
    }


}
