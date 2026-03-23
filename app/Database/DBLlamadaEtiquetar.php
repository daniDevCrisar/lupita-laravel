<?php
namespace App\Database;
use Illuminate\Support\Facades\DB;
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
        $data=[];
        $sql="update llamadas set ";
        foreach (self::$etiquetas_icon_bi as $key => $item) {
            if($item[4]){
                $campo_rq= "e_" . $key;
                $sql.= $key . "= ? , ";
                $data[] = self::$rq->$campo_rq;
            }
        }
        $sql=trim($sql,",");
        $sql.=" where vapi_id=? ";
        $data[]=self::$rq->vapi_id;
        dd($sql);
    }

}
