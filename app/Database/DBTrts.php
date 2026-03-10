<?php

namespace App\Database;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class DBTrts
{
    public static $filtro;
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

    public static function set_filtro($request): void
    {
        self::$filtro= new stdClass();
        self::$filtro->fecha_inicio=$request->fecha_inicio??'';
        self::$filtro->fecha_fin=$request->fecha_fin??'';
        self::$filtro->llamada_tipo_id=$request->llamada_tipo_id??'';
        self::$filtro->trt= $request->trt??'';
        self::$filtro->ordenar_por= $request->ordenar_por??'';
        self::$filtro->orden= $request->orden??'';
    }

    public static function lista_principal($limit=30){
        $fecha_i =self::$filtro->fecha_inicio;
        $fecha_f= self::$filtro->fecha_fin;
        $tipo_id= self::$filtro->llamada_tipo_id;
        $trt= strtoupper(self::$filtro->trt);
        $ordenar_por= self::$filtro->ordenar_por;
        $orden= self::$filtro->orden;
        $orden_txt= $orden ? 'asc':'desc';

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
        $query_lista= DB::table('llamadas as a')
        ->leftJoin('trts as b','b.id','=','a.trt_id')
        ->selectRaw("
        COALESCE(a.trt_id, 0) AS trt_id,
        COALESCE(b.nombres, 'SIN TRT') AS trt,
        COUNT(*) AS total,
        SUM(a.llamada_exitosa=1) AS exitosas,
        SUM(a.llamada_exitosa=0) AS fallidas,
        ROUND(SUM(a.llamada_exitosa=1)/(COUNT(*) - SUM(error_origen!= 0) )*100,1) AS tasa_exito,
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
        ")
        ->when($fecha_i or $fecha_f, function ($query) use ($fecha_i, $fecha_f) {
            if ($fecha_i and !$fecha_f)
                $query->whereBetween('a.created_at', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_i)->endOfDay()
                ]);
            elseif ($fecha_i and $fecha_f)
                $query->whereBetween('a.created_at', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_f)->endOfDay()
                ]);
        })
        ->when((string) $tipo_id !='', function ($query) use($tipo_id) {
            $query->where('a.llamada_tipo_id', '=', $tipo_id);
        })
        ->when($trt !='', function ($query) use($trt) {
            if ( is_numeric($trt) )
                $query->whereRaw("COALESCE(a.trt_id, 0) = ?",[$trt]);
            else
                $query->whereRaw("COALESCE(b.nombres, 'SIN TRT') like ?",['%'. $trt. '%']);
        })
        ->groupBy('a.trt_id')
        ->when($ordenar_por, function ($query) use($ordenar_por,$orden_txt) {
            if ($ordenar_por  == 'llamadas') $query->orderBy('total' , $orden_txt);
            elseif ($ordenar_por == 'exitosas') $query->orderBy('exitosas', $orden_txt);
            elseif ($ordenar_por == 'fallidas') $query->orderBy('fallidas', $orden_txt);
        })
        ->when($ordenar_por=='', function ($query) use($ordenar_por ,$orden_txt) {
            if ($orden_txt=='desc')
                $query->orderBy('conductores_con_exito', 'desc')
                ->orderBy('tasa_exito','desc');
            else
                $query->orderBy('conductores_con_fallo', 'desc')
                ->orderBy('tasa_exito','asc');

            $query->orderBy('total','desc');


        })
        ->orderBy('b.id', 'desc')
        ->paginate($limit)
        ->withQueryString();
        return $query_lista;
    }

}
