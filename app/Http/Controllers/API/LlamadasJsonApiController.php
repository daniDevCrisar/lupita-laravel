<?php
namespace App\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LlamadasJsonApiController {
    public function index(Request $request)
    {
        $sql= "
        select
                a.created_at,DATE(a.created_at) as solo_fecha , TIME(a.created_at) as solo_hora,e.nombre as etapa_nombre , e.id as etapa_id, e.color as etapa_color, e.emoji as etapa_emoji,
                a.ref, a.origen, a.destino, a.placa, d.titulo_viaje, d.ruta_id,
                f.ubigeo_origen, f.ubigeo_destino,
                b.nombres as conductor, COALESCE(c.nombres, '') AS trt ,a.telefono, lower(a.audio_link) as audio_link,
                a.analisis_transcripcion, a.analisis_audio,
                a.ia_result_delay_reason_desc, a.ia_result_comments_text,
                a.conductor_confirma, a.llamada_exitosa
        from `llamadas` as `a` inner join `conductores` as `b` on `b`.`id` = `a`.`conductor_id`
            left join `trts` as `c` on `c`.`id` = `a`.`trt_id`
            left join referencias as d on d.ref = a.ref
            inner join tipos_llamada as e on e.id = a.llamada_tipo_id
            inner join rutas as f on f.id = d.ruta_id
        where a.error_origen = 0 and a.buzon_de_voz=0 and a.conductor_contesta_pero_no_habla=0 and
              a.conductor_no_escucha=0 and a.conductor_mala_senal=0 and
              a.confusion_en_llamada=0 and a.numero_equivocado=0 and
              a.error_tecnico_llamada=0 and a.error_audio=0 and a.conductor_no_contesta=0 and
              a.razon_finalizacion_id in (1,2) and
              !((a.conductor_confirma+ a.conductor_da_motivos + a.conversacion_fluida + a.llamada_interesante =0) and a.conductor_cuelga and !a.llamada_exitosa)
        ";
        $sql_2="
        order by `a`.`created_at` desc limit 100 offset 0
        ";

        //-------UBIGEOS LIMA 15 Y CALLAO 07
        $filtro = self::rango_fecha($request->startdate, $request->enddate);

        if ($filtro!==false){
//            dd($filtro);
            $data = DB::select($sql.$filtro[0].$sql_2, $filtro[1]);
        }
        else
            $data = DB::select($sql.$sql_2);

        $json = [
            'startdate' => $request->startdate,
            'enddate' => $request->enddate,
            'total' => count($data),
            'offset' =>'0',
            'limit' => '20',
            'calls'=>(array)$data
        ];
        return response()->json($json);
    }

    public static function rango_fecha($fecha_i, $fecha_f,$letra='a'){
        if (!$fecha_i and !$fecha_f) return false;
        if (!$fecha_i and $fecha_f) return false;
        $sql='';
        $params = [];
        if ($fecha_i or $fecha_f) {
            if ($fecha_i and !$fecha_f) {
                $fecha_f=$fecha_i;
                $fecha_i=Carbon::parse($fecha_i)->startOfDay();
                $fecha_f=Carbon::parse($fecha_f)->addDay()->startOfDay();
            }
            elseif ($fecha_i and $fecha_f) {
                $fecha_i=Carbon::parse($fecha_i)->startOfDay();
                $fecha_f=Carbon::parse($fecha_f)->addDay()->startOfDay();
            }
            $sql .= " and $letra.created_at >= ? and $letra.created_at < ? ";
            $params[] = $fecha_i;
            $params[] = $fecha_f;
        }
        return [$sql, $params];
    }
}
