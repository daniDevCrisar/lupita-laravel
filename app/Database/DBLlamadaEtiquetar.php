<?php
namespace App\Database;
use Illuminate\Support\Facades\DB;
use stdClass;
class DBLlamadaEtiquetar
{
    public static $etiquetas_icon_bi=[];
    public static $rq;
    public function __construct($etiq,$request) {
        self::$etiquetas_icon_bi=$etiq;
        self::$rq=$request;
    }

    public static function etiquetar()
    {
        $json = new stdClass();
        $json->accion='error';
        $json->descripcion='vapi_id';
        $json->result=0;

        if (self::$rq->vapi_id =='' or self::$rq->vapi_id == null) return $json;

        $data=[];
        $sql="update llamadas set ";
        foreach (self::$etiquetas_icon_bi as $key => $item) {
            if($item[4]){
                $sql.= $key . "= ? , ";
                $data[] = self::$rq->$key;
            }
        }

        if (self::$rq->analisis_audio === null) self::$rq->analisis_audio= '';
        $sql.= 'analisis_audio=? ,';
        $data[] = self::$rq->analisis_audio;

        if (self::$rq->exito === 'exito') $sql.= "error_origen=0, llamada_exitosa= 1 ";
        else {
            $sql.= "error_origen=? , llamada_exitosa= 0 ";
            $data[] = self::$rq->exito;
        }

        $sql.=" where vapi_id=?";
        $data[]=trim(self::$rq->vapi_id);
        //dd($data,$sql);
        $json->accion='guardar';
        $json->descripcion=self::$rq->vapi_id;
        //$json->query=$sql;
        $json->result=DB::update($sql,$data);

        return $json;
    }

    public static function analisis_audio_mas_usado(){
        $sql="
        SELECT analisis_audio,total
        FROM (
            SELECT analisis_audio , COUNT(analisis_audio) as total FROM `llamadas` GROUP BY analisis_audio) b
        where total > 3 and analisis_audio !='' order by total desc;
        ";
        return DB::select($sql);
    }

    public static function generar_trofeos(){
        $lista="
        select
            a.conductor_id as id,
            COUNT(*) AS total,
            SUM(a.llamada_exitosa=1) AS exitosas,
            SUM(a.llamada_exitosa=0) AS fallidas,
            ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
            SUM(a.error_origen = 1) as error_ia,
            SUM(a.buzon_de_voz) AS buzon_de_voz,
            SUM(a.conductor_contesta_pero_no_habla) AS conductor_contesta_pero_no_habla,
            SUM(a.conductor_no_escucha) AS conductor_no_escucha,
            SUM(a.conductor_mala_senal) AS conductor_mala_senal,
            SUM(a.confusion_en_llamada) AS confusion_en_llamada,
            SUM(a.contesta_otra_persona) AS contesta_otra_persona,
            SUM(a.numero_equivocado) AS numero_equivocado,
            SUM(a.conductor_cuelga) AS conductor_cuelga,
            SUM(a.conductor_no_contesta) AS conductor_no_contesta,
            SUM(a.conductor_confirma * (!a.llamada_exitosa and a.error_origen=0)) AS confirmacion_parcial,
            SUM(a.conductor_conducta_inapropiada) AS conductor_conducta_inapropiada,
            SUM(a.razon_finalizacion_id = 5) AS conductor_ocupado,

            SUM(a.conductor_confirma * a.llamada_exitosa) AS conductor_confirma,
            SUM(a.conductor_da_motivos * a.llamada_exitosa) AS conductor_da_motivos,
            SUM(a.conversacion_fluida * a.llamada_exitosa) AS conversacion_fluida,
            SUM(a.llamada_interesante * a.llamada_exitosa) AS llamada_interesante

        from `llamadas` as `a`
        inner join `conductores` as `b`
        on `b`.`id` = `a`.`conductor_id`
        group by `a`.`conductor_id`;
        ";

        $conductores = DB::select($lista);
        $trofeos_100=['conductor_confirma','buzon_de_voz','conductor_contesta_pero_no_habla','conductor_cuelga','conductor_no_contesta','conductor_ocupado'];
        $solo_con_trofeos='';
        $ids_con_trofeos=[];
        foreach ($conductores as $conductor) {
            $trofeos = [];
            //-----JSON--------
            // NOMBRE_DEL_CAMPO : NIVEL   -> 1 BRONZE 2 PLATA 3 ORO
            //                              10        50       100
            foreach ($trofeos_100 as $trofeo) {
                if ($conductor->$trofeo >= 100)
                    $trofeos[$trofeo] = 3;
                elseif ($conductor->$trofeo >= 50)
                    $trofeos[$trofeo] = 2;
                elseif ($conductor->$trofeo >= 10)
                    $trofeos[$trofeo] = 1;
            }


            if (!empty($trofeos)){
                $json= addslashes(json_encode($trofeos,JSON_UNESCAPED_UNICODE));
                $solo_con_trofeos.=" WHEN {$conductor->id} THEN '{$json}' ";
                $ids_con_trofeos[]=$conductor->id;
            }
        }

        //------------CONSULTA----------------------
        if (!empty($ids_con_trofeos)){
            $ids_string = implode(',', $ids_con_trofeos);  // convertir array en array de mysql
            $sql="
            UPDATE conductores
                SET trofeos = CASE id {$solo_con_trofeos} END
            WHERE id IN ({$ids_string})
            ";
            $actualizados=DB::update($sql);
            //dd($sql,$actualizados);
            return $actualizados;
        }
        else return false;
        //--------------------------------------------------------
    }

}
