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

}
