<?php
namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LlamadasJsonApiController {
    public function index(Request $request)
    {
        // lower(a.audio_link) as audio_link,
        $sql= "
        select
                a.created_at,
                e.id as etapa_id,
                a.ref, a.origen, a.destino, a.placa, d.titulo_viaje, d.ruta_id,
                f.ubigeo_origen, f.ubigeo_destino,
                b.nombres as conductor, COALESCE(c.nombres, '') AS trt ,a.telefono,
                a.analisis_audio,
                a.ia_result_comments_text,
                a.conductor_confirma, a.llamada_exitosa ,
                COALESCE(
                    d.fin_descargue,
                    d.inicio_descargue,
                    d.qr_llegada_destino,
                    d.inicio_ruta,
                    d.fin_de_carga,
                    d.inicio_de_carga,
                    d.presenta_para_carga
                ) AS ultimo_evento_fecha,
                CASE
                    WHEN d.fin_descargue IS NOT NULL THEN 7
                    WHEN d.inicio_descargue IS NOT NULL THEN 6
                    WHEN d.qr_llegada_destino IS NOT NULL THEN 5
                    WHEN d.inicio_ruta IS NOT NULL THEN 4
                    WHEN d.fin_de_carga IS NOT NULL THEN 3
                    WHEN d.inicio_de_carga IS NOT NULL THEN 2
                    WHEN d.presenta_para_carga IS NOT NULL THEN 1
                    ELSE NULL
                END AS ultimo_evento_id

        from `llamadas` as `a` inner join `conductores` as `b` on `b`.`id` = `a`.`conductor_id`
            left join `trts` as `c` on `c`.`id` = `a`.`trt_id`
            left join referencias as d on d.ref = a.ref
            inner join tipos_llamada as e on e.id = a.llamada_tipo_id
            left join rutas as f on f.id = d.ruta_id
        where ((a.error_origen = 0 and a.buzon_de_voz=0 and a.conductor_contesta_pero_no_habla=0 and
              a.conductor_no_escucha=0 and a.conductor_mala_senal=0 and
              a.confusion_en_llamada=0 and a.numero_equivocado=0 and
              a.error_tecnico_llamada=0 and a.error_audio=0 and a.conductor_no_contesta=0 and
              a.razon_finalizacion_id in (1,2)  and
              !((a.conductor_confirma+ a.conductor_da_motivos + a.conversacion_fluida + a.llamada_interesante =0) and a.conductor_cuelga and !a.llamada_exitosa)
              )
              or a.conductor_confirma =1 or a.llamada_exitosa =1 )
        ";
        $sql_count = "
        select count(*) as total, SUM(IF(llamada_exitosa, 1, 0)) AS total_exitosas,
            SUM(IF(llamada_tipo_id = 1 AND llamada_exitosa, 1, 0)) AS `etapa_1`,
            SUM(IF(llamada_tipo_id = 2 AND llamada_exitosa, 1, 0)) AS `etapa_2`,
            SUM(IF(llamada_tipo_id = 3 AND llamada_exitosa, 1, 0)) AS `etapa_3`,
            SUM(IF(llamada_tipo_id = 4 AND llamada_exitosa, 1, 0)) AS `etapa_4`,
            SUM(IF(llamada_tipo_id = 5 AND llamada_exitosa, 1, 0)) AS `etapa_5`,
            SUM(IF(llamada_tipo_id = 6 AND llamada_exitosa, 1, 0)) AS `etapa_6`
        from `llamadas` as `a`

        where ( (a.error_origen = 0 and a.buzon_de_voz=0 and a.conductor_contesta_pero_no_habla=0 and
              a.conductor_no_escucha=0 and a.conductor_mala_senal=0 and
              a.confusion_en_llamada=0 and a.numero_equivocado=0 and
              a.error_tecnico_llamada=0 and a.error_audio=0 and a.conductor_no_contesta=0 and
              a.razon_finalizacion_id in (1,2)  and
              !((a.conductor_confirma+ a.conductor_da_motivos + a.conversacion_fluida + a.llamada_interesante =0) and a.conductor_cuelga and !a.llamada_exitosa)
              )
              or a.conductor_confirma =1 or a.llamada_exitosa =1)
        ";

        $limit = $request->input('limit', 100);
        $limit = max(1, min(100, $limit));
        $offset = $request->input('offset', 0);
        $offset = max(0, $offset);

        $sql_2="
        order by `a`.`created_at` desc limit ? offset ?
        ";

        //-------UBIGEOS LIMA 15 Y CALLAO 07
        $filtro = self::rango_fecha($request->startdate, $request->enddate);

        if ($filtro!==false){
//            dd($filtro);
            $data_total = DB::select($sql_count.$filtro[0], $filtro[1]);
            $data = DB::select($sql.$filtro[0].$sql_2, [...$filtro[1], (int)$limit, (int)$offset]);
        }
        else {
            $data_total = DB::select($sql_count)[0]->total;
            $data = DB::select($sql.$sql_2, [(int)$limit, (int)$offset]);
        }

        $json = [
            'startdate' => $request->startdate,
            'enddate' => $request->enddate,
            'total' => (int) $data_total[0]->total,
            'total_exitosas' => (int) $data_total[0]->total_exitosas,
            'etapas_exitosas' => [
                'etapa_1' => (int) ($data_total[0]->etapa_1 ?? 0),
                'etapa_2' => (int) ($data_total[0]->etapa_2 ?? 0),
                'etapa_3' => (int) ($data_total[0]->etapa_3 ?? 0),
                'etapa_5' => (int) ($data_total[0]->etapa_5 ?? 0),
                'etapa_6' => (int) ($data_total[0]->etapa_6 ?? 0)
            ],
            'count' => count($data),
            'offset' => (int) $offset,
            'limit' => (int) $limit,
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
