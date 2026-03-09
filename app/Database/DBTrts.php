<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;

class DBTrts
{

    public static function crear($row)
    {
        return DB::table('trts')->insertGetId( [
            'sis_id' => $row->sis_id,
            'vapi_id' => $row->vapi_id,
            'nombres' => $row->conductor,
            'ruc' => $row->ruc
        ]);
    }

    public static function sp_insertar_o_obtener_trts($row){
        $accion= DB::select('CALL sp_insertar_o_obtener_trts(?, ?, ?, ?)',
        [
            $row->sis_id,
            $row->vapi_id,
            $row->nombres??$row->transportista,
            $row->ruc
        ]);
        return $accion[0];
    }

    public static function lista_pricipal($row){
        $sql="select
        COALESCE(a.trt_id, 0) AS trt_id,
        COALESCE(b.nombres, 'SIN TRT') AS trt,
        COUNT(*) AS total,
        SUM(a.llamada_exitosa=1) AS exitosas,
        SUM(a.llamada_exitosa=0) AS fallidas,
        ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
        SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

        COUNT(DISTINCT conductor_id) AS conductores,
        COUNT(DISTINCT IF(a.llamada_exitosa = 0 and error_origen=0, a.conductor_id, NULL))  AS conductores_con_fallo,
        COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL))  AS conductores_con_exito,

        SUM(error_origen = -1) as error_desconocido,
        SUM(error_origen = 1) as error_ia,
        SUM(error_origen = 2) as error_red,
        SUM(error_origen = 3) as error_sistema,
        SUM(error_origen!= 0) AS total_error,

        SUM(a.buzon_de_voz * (a.llamada_exitosa = 0 and error_origen=0)) AS buzon_de_voz,
        SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0  and error_origen=0)) AS conductor_contesta_pero_no_habla,
        SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_no_escucha,
        SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_mala_senal,
        SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0 and error_origen=0)) AS confusion_en_llamada,
        SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0 and error_origen=0)) AS contesta_otra_persona,
        SUM(a.numero_equivocado * (a.llamada_exitosa = 0 and error_origen=0)) AS numero_equivocado,
        SUM(a.conductor_cuelga * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_cuelga,
        SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_no_contesta,
        SUM(a.conductor_confirma * (a.llamada_exitosa = 0 and error_origen=0)) AS confirmacion_parcial,
        SUM(a.conductor_conducta_inapropiada * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_conducta_inapropiada,
        SUM(razon_finalizacion_id = 5) AS conductor_ocupado,
        SUM(ia_se_confunde * (a.llamada_exitosa = 0  and error_origen=0)) AS ia_se_confunde,
        SUM(ia_no_escucha * (a.llamada_exitosa = 0 and error_origen=0)) AS ia_no_escucha,
        SUM(ia_error_interpretacion * (a.llamada_exitosa = 0  and error_origen=0)) AS ia_error_interpretacion,
        SUM(ia_dice_variable * (a.llamada_exitosa = 0 and error_origen=0)) AS ia_dice_variable,
        SUM(ia_mala_pronunciacion * (a.llamada_exitosa = 0 and error_origen=0)) AS ia_mala_pronunciacion,
        SUM(a.conductor_confirma * (a.llamada_exitosa = 1)) AS conductor_confirma,
        SUM(a.conductor_da_motivos * (a.llamada_exitosa = 1)) AS conductor_da_motivos,
        SUM(a.conversacion_fluida * (a.llamada_exitosa = 1)) AS conversacion_fluida,
        SUM(a.llamada_interesante * (a.llamada_exitosa = 1)) AS llamada_interesante,
        sum(a.audio_duracion) as audio_duracion
        from `llamadas` as `a` left join `trts` as `b` on `b`.`id` = `a`.`trt_id`
        group by `a`.`trt_id`
        order by `total` asc, `b`.`id` desc;
        ";
    }

}
