<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use stdClass;

class DBLlamadas {
    public static $lista = [];
    public static $razones_finalizacion = [];
    public static $tipos_llamada=[];
    public static $error_origen=[];
    public static $etiquetas_icon_bi=[
        'conductor_confirma' => ['bi bi-check-circle-fill text-success', 'Confirma'],
        'buzon_de_voz' => ['bi bi-voicemail text-warning','Buzon de voz'],
        'conductor_contesta_pero_no_habla' => ['bi bi-mic-mute-fill text-danger','No habla'],
        'conductor_no_escucha' => ['bi bi-ear-fill text-danger','No escucha'],
        'conductor_da_motivos' => ['bi bi-chat-left-text-fill text-info','Da motivos'],
        'conductor_mala_senal' => ['bi bi-wifi-off text-danger','Mala señal'],
        'confusion_en_llamada' => ['bi bi-question-circle-fill text-warning','Confusion en llamada'],
        'contesta_otra_persona' => ['bi bi-people-fill text-info','Contesta otra persona'],
        'numero_equivocado' => ['bi bi-telephone-x-fill text-danger','Numero equivocado'],
        'conversacion_fluida' => ['bi bi-chat-dots-fill text-success','Conversacion Fluida'],
        'llamada_interesante' => ['bi bi-star-fill text-warning','Llamada Interesante'],

        'ia_se_confunde' => ['bi bi-cpu-fill text-warning','IA se confunde'],
        'ia_no_escucha' => ['bi bi-mic-mute-fill text-danger','IA no escucha'],
        'ia_cambio_de_datos' => ['bi bi-arrow-left-right text-info','IA actualiza datos'],
        'ia_error_interpretacion' => ['bi bi-exclamation-triangle-fill text-danger','IA error de interpretacion'],
        'ia_dice_variable' => ['bi bi-braces text-warning','IA dice variable'],
        'ia_mala_pronunciacion' => ['bi bi-volume-down-fill text-warning','IA mala pronunciacion'],

        'conductor_cuelga' => ['bi bi-telephone-minus-fill text-danger','Cuelga'],
        'conductor_no_contesta' => ['bi bi-telephone-x-fill text-danger','No contesta'],
        'conductor_conducta_inapropiada' => ['bi bi-person-x-fill text-danger','Conducta Inapropiada'],

        'error_tecnico_llamada' => ['bi bi-gear-fill text-danger','Error tecnico en llamada'],
        'error_audio' => ['bi bi-volume-mute-fill text-danger','Error de audio'],
    ];
    public static $filtro;

    public function __construct() {
        self::$razones_finalizacion = DB::select('SELECT * FROM razones_finalizacion');
        self::$tipos_llamada = DB::select('SELECT * FROM tipos_llamada');
        self::$error_origen = DB::select('SELECT * FROM error_origen');
        self::$filtro= new stdClass();
        self::$filtro->fecha_inicio='';
        self::$filtro->fecha_fin= '';
        self::$filtro->llamada_tipo_id= '';
        self::$filtro->conductor= '';
        self::$filtro->trt= '';
        self::$filtro->exitosa='';
    }

    public static function set_filtro($request){
        self::$filtro->fecha_inicio=$request->fecha_inicio;
        self::$filtro->fecha_fin=$request->fecha_fin;
        self::$filtro->llamada_tipo_id=$request->llamada_tipo_id;
        self::$filtro->conductor= $request->conductor;
        self::$filtro->trt= $request->trt;
        self::$filtro->exitosa= $request->exitosa;
    }


    public static function listar_principal($mostrar){
        $fecha_i =self::$filtro->fecha_inicio;
        $fecha_f= self::$filtro->fecha_fin;
        $tipo_id= self::$filtro->llamada_tipo_id;
        $conductor= strtoupper(self::$filtro->conductor);
        $trt= strtoupper(self::$filtro->trt);
        $exitosa=self::$filtro->exitosa;

        $llamadas = DB::table('llamadas as a')
        ->join('conductores as b', 'b.id', '=', 'a.conductor_id')
        ->leftJoin('trts as c', 'c.id', '=', 'a.trt_id')
        ->select(
            'a.vapi_id',
            'a.created_at',
            'a.llamada_tipo_id',
            'a.ref',
            'a.origen',
            'a.destino',
            'a.placa',
            'a.conductor_id',
            'b.nombres as conductor',
            'a.telefono',
            'a.trt_id',
            'c.nombres as trt',
            'a.audio_link',
            'a.audio_duracion',
            'a.analisis_transcripcion',
            'a.analisis_audio',
            'a.razon_finalizacion_id',
            'a.error_origen',
            'a.llamada_exitosa',

            'a.exitosa_segun_ia',
            'entro_llamada',

            'a.conductor_confirma',
            'a.buzon_de_voz',
            'a.conductor_contesta_pero_no_habla',
            'a.conductor_no_escucha',
            'a.conductor_da_motivos',
            'a.conductor_mala_senal',
            'a.confusion_en_llamada',
            'a.contesta_otra_persona',
            'a.numero_equivocado',
            'a.conversacion_fluida',
            'a.llamada_interesante',

            'a.ia_se_confunde',
            'a.ia_no_escucha',
            'a.ia_cambio_de_datos',
            'a.ia_error_interpretacion',
            'a.ia_dice_variable',
            'a.ia_mala_pronunciacion',

            'a.conductor_cuelga',
            'a.conductor_no_contesta',
            'a.conductor_conducta_inapropiada',

            'a.error_tecnico_llamada',
            'a.error_audio'
        )
        ->when($fecha_i && $fecha_f, function ($query) use ($fecha_i, $fecha_f) {
            $query->whereBetween('a.created_at', [
                Carbon::parse($fecha_i)->startOfDay(),
                Carbon::parse($fecha_f)->endOfDay()
            ]);
        })
        ->when((string) $tipo_id !='', function ($query) use($tipo_id) {
            $query->where('llamada_tipo_id', '=', $tipo_id);
        })
        ->when($conductor !='', function ($query) use($conductor) {
            $query->where('b.nombres', 'like','%'. $conductor. '%');
        })
        ->when($trt !='', function ($query) use($trt) {
            $query->where('c.nombres', 'like','%'. $trt . '%');
        })
        ->when($exitosa =='exito', function ($query){
            $query->where('a.llamada_exitosa', '=', 1);
        })
        ->when(is_numeric($exitosa), function ($query) use($exitosa) {
            $query->where('a.error_origen', '=', $exitosa);
            $query->where('a.llamada_exitosa', '=', 0);
        })
        ->orderBy('a.created_at', 'desc')
        ->paginate($mostrar)
        ->withQueryString();

        self::$lista = $llamadas;
        return $llamadas;
    }

    public static function razon_f($id, $campo='codigo'){
        return self::$razones_finalizacion[$id]->$campo;
    }

    public static function tipos_l($id, $campo='nombre'){
        $iconos=[
            0 => 'bi bi-question-circle',
            1=> 'bi bi-check-circle',
            2=> 'bi bi-truck',
            3=> 'bi-building',
        ];

        if ($campo == 'icon') return $iconos[$id];
        return self::$tipos_llamada[$id]->$campo;
    }

    public static function icon_exito($item , $solo_icon=false){
        $exito='bi bi-check-lg text-success';
        $iconos=[
            -1=> 'bi bi-question-circle text-danger',
            0=> 'bi bi-person-slash text-danger',
            1=> 'bi bi-robot text-danger',
            2=> 'bi bi-wifi text-danger',
            3=> 'bi bi-gear text-danger',
        ];
        if ($solo_icon) return $iconos[$item];
        if ($item->llamada_exitosa) return $exito;

        return $iconos[$item->error_origen];
    }

    public static function etiquetas_icon_bi($item , $size=''){
        $iconos= self::$etiquetas_icon_bi;
        $lista_e='';
        foreach ($iconos as $key => $value) {
            if ($item->$key)
                $lista_e.= "<i class='". $value[0] ." ".$size."'></i> ".$value[1]."<br>";
        }
        return $lista_e;
    }

    public static function format_fecha($fecha ,$format='d/m/Y H:i'){
        return Carbon::parse($fecha)->format($format);
    }

    public static function etiqueta_totales(){
        $sql="SELECT
            SUM(IF(analisis_audio LIKE '%CUELGA%', 1, 0)) AS cuelga_analisis,
            COUNT(*) AS llamadas,
            COUNT(DISTINCT conductor_id) AS conductores,
            COUNT(DISTINCT trt_id) AS trts,
            SUM(razon_finalizacion_id = 3) AS razon_3_no_contesta,
            SUM(razon_finalizacion_id = 4) AS razon_4_red,
            SUM(razon_finalizacion_id = 5) AS razon_5_ocupado,
            SUM(razon_finalizacion_id = 7) AS razon_7_sis,
            SUM(razon_finalizacion_id = 9) AS razon_9_sis,
            SUM(error_origen = -1) AS error_desconocido,
            SUM(error_origen = 0) AS error_humano,
            SUM(error_origen = 1) AS error_ia,
            SUM(error_origen = 2) AS error_red,
            SUM(error_origen = 3) AS error_sistema,
            SUM(a.llamada_exitosa = 0 and conductor_confirma) as confirmacion_parcial,

            SUM(
            conductor_confirma + buzon_de_voz + conductor_contesta_pero_no_habla +
            conductor_no_escucha + conductor_da_motivos +  conductor_mala_senal + confusion_en_llamada +
            contesta_otra_persona + numero_equivocado + conversacion_fluida + llamada_interesante +
            ia_se_confunde + ia_no_escucha + ia_cambio_de_datos +  ia_error_interpretacion + ia_dice_variable +
            ia_mala_pronunciacion +  conductor_no_contesta + conductor_conducta_inapropiada +
            error_tecnico_llamada + error_audio = 0 and conductor_cuelga= 1 and a.llamada_exitosa = 0
            ) as solo_cuelga,

            SUM(exitosa_segun_ia) AS exitosa_segun_ia,
            (SUM(entro_llamada) - SUM(buzon_de_voz)) AS contestadas,
            SUM(llamada_exitosa) AS llamada_exitosa,
            SUM(audio_duracion) as audio_duracion,

            SUM(conductor_confirma) AS conductor_confirma,
            SUM(buzon_de_voz) AS buzon_de_voz,
            SUM(conductor_contesta_pero_no_habla) AS conductor_contesta_pero_no_habla,
            SUM(conductor_no_escucha) AS conductor_no_escucha,
            SUM(conductor_da_motivos) AS conductor_da_motivos,
            SUM(conductor_mala_senal) AS conductor_mala_senal,
            SUM(confusion_en_llamada) AS confusion_en_llamada,
            SUM(contesta_otra_persona) AS contesta_otra_persona,
            SUM(numero_equivocado) AS numero_equivocado,
            SUM(conversacion_fluida) AS conversacion_fluida,
            SUM(llamada_interesante) AS llamada_interesante,

            SUM(ia_se_confunde) AS ia_se_confunde,
            SUM(ia_no_escucha) AS ia_no_escucha,
            SUM(ia_cambio_de_datos) AS ia_cambio_de_datos,
            SUM(ia_error_interpretacion) AS ia_error_interpretacion,
            SUM(ia_dice_variable) AS ia_dice_variable,
            SUM(ia_mala_pronunciacion) AS ia_mala_pronunciacion,

            SUM(conductor_cuelga) AS conductor_cuelga,
            SUM(conductor_no_contesta) AS conductor_no_contesta,
            SUM(conductor_conducta_inapropiada) AS conductor_conducta_inapropiada,

            SUM(error_tecnico_llamada) AS error_tecnico_llamada,
            SUM(error_audio) AS error_audio
            FROM llamadas a
            where 1=1
            ";
        $filtro= self::aplicar_filtro_sqltext();

        return  DB::select($sql . $filtro[0], $filtro[1]);
    }

    public static function aplicar_filtro_sqltext(){
        $fecha_i =self::$filtro->fecha_inicio;
        $fecha_f= self::$filtro->fecha_fin;
        $tipo=self::$filtro->llamada_tipo_id;
        $sql = "";
        $params = [];
        if ($fecha_i && $fecha_f) {
            $fecha_i=Carbon::parse($fecha_i)->startOfDay();
            $fecha_f=Carbon::parse($fecha_f)->addDay()->startOfDay();

            $sql .= " and a.created_at >= ? AND a.created_at < ? ";

            $params[] = $fecha_i;
            $params[] = $fecha_f;
        }
        if ($tipo!='') $sql .= " and a.llamada_tipo_id=". $tipo.' ';

        return [$sql, $params];
    }

    public static function top_peores_conductores($limit=5){
        $sql= "SELECT
            conductor_id,
            conductor,
            trt_id,
            trt,
            total,
            exitosas,
            fallidas,
            tasa_exito,
            diferencia,
            buzon_de_voz,conductor_contesta_pero_no_habla,conductor_no_escucha,conductor_mala_senal,
            confusion_en_llamada,contesta_otra_persona,numero_equivocado,conductor_cuelga,conductor_no_contesta
        FROM (
            SELECT
                a.conductor_id,
                b.nombres AS conductor,
                a.trt_id,
                COALESCE(c.nombres, 'SIN TRT') AS trt,
                COUNT(*) AS total,
                SUM(a.llamada_exitosa=1) AS exitosas,
                SUM(a.llamada_exitosa=0) AS fallidas,
                ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
                SUM(a.llamada_exitosa=0) - SUM(a.llamada_exitosa=1) AS diferencia,

                SUM(a.buzon_de_voz * (a.llamada_exitosa = 0))  AS buzon_de_voz,
                SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0)) AS conductor_contesta_pero_no_habla,
                SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0)) AS conductor_no_escucha,
                SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0)) AS conductor_mala_senal,
                SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0)) AS confusion_en_llamada,
                SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0)) AS contesta_otra_persona,
                SUM(a.numero_equivocado * (a.llamada_exitosa = 0)) AS numero_equivocado,
                SUM(a.conductor_cuelga * (a.llamada_exitosa = 0)) AS conductor_cuelga,
                SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0)) AS conductor_no_contesta
            FROM llamadas a
            INNER JOIN conductores b ON b.id = a.conductor_id
            LEFT JOIN trts c ON c.id = a.trt_id
            WHERE a.error_origen = 0
            ";
        $sql_2="
        GROUP BY a.conductor_id, a.trt_id
            ORDER BY fallidas DESC, tasa_exito DESC
        ) AS ranking
        ORDER BY diferencia DESC , exitosas asc
        limit ?;";

        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_mejores_conductores($limit= 5){
        $sql= "SELECT
            conductor_id,
            conductor,
            trt_id,
            trt,
            total,
            exitosas,
            fallidas,
            tasa_exito,
            diferencia,
            conductor_confirma,
            conductor_da_motivos,
            conversacion_fluida,
            llamada_interesante
        FROM (
            SELECT
                a.conductor_id,
                b.nombres AS conductor,
                a.trt_id,
                COALESCE(c.nombres, 'SIN TRT') AS trt,
                COUNT(*) AS total,
                SUM(a.llamada_exitosa=1) AS exitosas,
                SUM(a.llamada_exitosa=0) AS fallidas,
                ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
                SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

                SUM(a.conductor_confirma * (a.llamada_exitosa = 1))  AS conductor_confirma,
                SUM(a.conductor_da_motivos * (a.llamada_exitosa = 1)) AS conductor_da_motivos,
                SUM(a.conversacion_fluida * (a.llamada_exitosa = 1)) AS conversacion_fluida,
                SUM(a.llamada_interesante * (a.llamada_exitosa = 1)) AS llamada_interesante

            FROM llamadas a
            INNER JOIN conductores b ON b.id = a.conductor_id
            LEFT JOIN trts c ON c.id = a.trt_id
            WHERE a.error_origen = 0
        ";
        $sql_2="
        GROUP BY a.conductor_id, a.trt_id
            ORDER BY exitosas DESC, tasa_exito asc
        ) AS ranking
        ORDER BY diferencia DESC , exitosas asc
        limit ?;";
        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_mejores_trts($limit= 5){
        $sql= "SELECT
        conductores_con_exito,
            conductores,
            trt_id,
            trt,
            total,
            exitosas,
            fallidas,
            tasa_exito,
            diferencia,
            conductor_confirma,
            conductor_da_motivos,
            conversacion_fluida,
            llamada_interesante
        FROM (
            SELECT
                COUNT(DISTINCT conductor_id) AS conductores,
                COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL))  AS conductores_con_exito,
                a.trt_id,
                COALESCE(c.nombres, 'SIN TRT') AS trt,
                COUNT(*) AS total,
                SUM(a.llamada_exitosa=1) AS exitosas,
                SUM(a.llamada_exitosa=0) AS fallidas,
                ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
                SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

                SUM(a.conductor_confirma * (a.llamada_exitosa = 1))  AS conductor_confirma,
                SUM(a.conductor_da_motivos * (a.llamada_exitosa = 1)) AS conductor_da_motivos,
                SUM(a.conversacion_fluida * (a.llamada_exitosa = 1)) AS conversacion_fluida,
                SUM(a.llamada_interesante * (a.llamada_exitosa = 1)) AS llamada_interesante

            FROM llamadas a
            LEFT JOIN trts c ON c.id = a.trt_id
            WHERE a.error_origen = 0
            ";
            $sql_2="
            GROUP BY a.trt_id
            ) AS ranking
            ORDER BY
            conductores_con_exito desc,
            tasa_exito desc,
            total DESC
            limit ?;";

        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_peores_trts($limit= 5){
        $sql= "SELECT
        COUNT(DISTINCT conductor_id) AS conductores,
        COUNT(DISTINCT IF(a.llamada_exitosa = 0, a.conductor_id, NULL))  AS conductores_con_fallo,
        COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, NULL))  AS conductores_con_exito,
        a.trt_id,
        COALESCE(c.nombres, 'SIN TRT') AS trt,
        COUNT(*) AS total,
        SUM(a.llamada_exitosa=1) AS exitosas,
        SUM(a.llamada_exitosa=0) AS fallidas,
        ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,

        SUM(a.buzon_de_voz * (a.llamada_exitosa = 0))  AS buzon_de_voz,
        SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0)) AS conductor_contesta_pero_no_habla,
        SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0)) AS conductor_no_escucha,
        SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0)) AS conductor_mala_senal,
        SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0)) AS confusion_en_llamada,
        SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0)) AS contesta_otra_persona,
        SUM(a.numero_equivocado * (a.llamada_exitosa = 0)) AS numero_equivocado,
        SUM(a.conductor_cuelga * (a.llamada_exitosa = 0)) AS conductor_cuelga,
        SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0)) AS conductor_no_contesta

        FROM llamadas a
        LEFT JOIN trts c ON c.id = a.trt_id
        WHERE a.error_origen = 0
        ";
        $sql_2="
        GROUP BY a.trt_id
        ORDER BY
        conductores_con_fallo desc,
        tasa_exito asc,
        total DESC
        limit ?;";
        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_peores_ordenar_etiquetas($item, $size=''){
        $count= 0;
        $eti=[];
        //obtener etiquetas relevantes
        foreach ($item as $key => $value) {
            if ($count >= 9) $eti[$key] = $value;
            $count++;
        }
        arsort($eti); //ordenar de mayor a menor
        $eti= (object) array_slice($eti, 0, 2, true); //solo 3 etiquetas
        //----------------------------------------
        $iconos= self::$etiquetas_icon_bi; //iconos bootstrap
        $lista_e=''; // listar las etiquetas con mayor aparicion
        $sumar_e=0;
        foreach ($iconos as $key => $value) {
            if ($eti->$key??0){
                $lista_e.= "<i class='". $value[0] ." ".$size."'></i> ".$value[1]."(".$eti->$key."),";
                $sumar_e+=$eti->$key;
            }
        }
        //sio las etiquetas no sumaron el total poner otros
        if ($item->fallidas > $sumar_e) $lista_e .= "Otros(". ($item->total - $sumar_e) .")";

        return trim($lista_e,',');
    }

    public static function color_porcentaje($num){
        if ($num >= 75) return 'success';
        if ($num >= 50) return 'info';
        if ($num >= 25) return 'warning';
        return 'danger';
    }


}
