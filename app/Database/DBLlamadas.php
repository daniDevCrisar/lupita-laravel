<?php

namespace App\Database;

use App\Tools\BuscarEnArray;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use stdClass;

class DBLlamadas {
    public static $lista = [];
    public static $razones_finalizacion = [];
    public static $tipos_llamada=[];
    public static $error_origen=[];

    //---------$etiquetas_icon_bi-----------------------
    // 0 = icono
    // 1 = texto
    // 2 = etiqueta: 1 positivo , 0 negativo
    // 3 = puntaje
    // 4 = grupo
    public static $etiquetas_icon_bi=[
        'conductor_confirma' => ['bi bi-check-circle-fill text-success', 'Confirma',1,10,1],
        'buzon_de_voz' => ['bi bi-voicemail text-warning','Buzon de voz',0,-10,2],
        'conductor_contesta_pero_no_habla' => ['bi bi-mic-mute-fill text-danger','No habla',0,-10,2],
        'conductor_no_escucha' => ['bi bi-ear-fill text-danger','No escucha',0,0,2],
        'conductor_da_motivos' => ['bi bi-chat-left-text-fill text-info','Da motivos',1,20,1],
        'conductor_mala_senal' => ['bi bi-wifi-off text-danger','Mala señal',0,-5,2],
        'confusion_en_llamada' => ['bi bi-question-circle-fill text-warning','Confusion en llamada',0,-5,2],
        'contesta_otra_persona' => ['bi bi-people-fill text-info','Contesta otra persona',0,-5,2],
        'numero_equivocado' => ['bi bi-telephone-x-fill text-danger','Numero equivocado',0,-20,2],
        'conversacion_fluida' => ['bi bi-chat-dots-fill text-success','Conversacion Fluida',1,25,1],
        'llamada_interesante' => ['bi bi-star-fill text-warning','Llamada Interesante',1,30,1],

        'ia_se_confunde' => ['bi bi-cpu-fill text-warning','IA se confunde',0,0,3],
        'ia_no_escucha' => ['bi bi-mic-mute-fill text-danger','IA no escucha',0,0,3],
        'ia_cambio_de_datos' => ['bi bi-arrow-left-right text-info','IA actualiza datos',1,0,0],
        'ia_error_interpretacion' => ['bi bi-exclamation-triangle-fill text-danger','IA error de interpretacion',0,0,3],
        'ia_dice_variable' => ['bi bi-braces text-warning','IA dice variable',0,0,3],
        'ia_mala_pronunciacion' => ['bi bi-volume-down-fill text-warning','IA mala pronunciacion',0,0,3],

        'conductor_cuelga' => ['bi bi-telephone-minus-fill text-danger','Cuelga',0,-5,0],
        'conductor_no_contesta' => ['bi bi-telephone-x-fill text-danger','No contesta',0,-10,0],
        'conductor_conducta_inapropiada' => ['bi bi-person-x-fill text-danger','Conducta Inapropiada',0,-30,2],

        'error_tecnico_llamada' => ['bi bi-gear-fill text-danger','Error tecnico en llamada',0,0,3],
        'error_audio' => ['bi bi-volume-mute-fill text-danger','Error de audio',0,0,3],
        // no exito solo en llamadas fallidas exitosa=0 y confirmacion=0
        'confirmacion_parcial' => ['bi bi-check2-square text-info','Confirmacion parcial',0,5,0],
        'conductor_ocupado' => ['bi bi-hourglass text-warning','Conductor Ocupado',0,-5,0],
    ];
    public static $filtro;

    public function __construct() {
        self::$razones_finalizacion = DB::select('SELECT * FROM razones_finalizacion');
        self::$tipos_llamada = DB::select('SELECT * FROM tipos_llamada');
        self::$error_origen = DB::select('SELECT * FROM error_origen');
    }

    public static function set_filtro($request): void
    {
        self::$filtro= new stdClass();
        self::$filtro->fecha_inicio=$request->fecha_inicio;
        self::$filtro->fecha_fin=$request->fecha_fin;
        self::$filtro->llamada_tipo_id=$request->llamada_tipo_id;
        self::$filtro->conductor= $request->conductor;
        self::$filtro->trt= $request->trt;
        self::$filtro->exitosa= $request->exitosa;
    }


    public static function listar_principal($mostrar){
        $fecha_i =self::$filtro->fecha_inicio??'';
        $fecha_f= self::$filtro->fecha_fin??'';
        $tipo_id= self::$filtro->llamada_tipo_id??'';
        $conductor= strtoupper(self::$filtro->conductor??'');
        $trt= strtoupper(self::$filtro->trt)??'';
        $exitosa=self::$filtro->exitosa??'';

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
        ->selectRaw("COALESCE(a.trt_id, 0) AS trt_id,
        COALESCE(c.nombres, 'SIN TRT') AS trt")
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
            $query->where('llamada_tipo_id', '=', $tipo_id);
        })
        ->when($conductor !='', function ($query) use($conductor) {
            if ( is_numeric($conductor) )
                $query->where('b.id', '=',$conductor);
            else
                $query->where('b.nombres', 'like','%'. $conductor. '%');
        })
        ->when($trt !='', function ($query) use($trt) {
            if ( is_numeric($trt) )
                $query->whereRaw('COALESCE(a.trt_id, 0)= ?', [$trt]);
            else
                $query->whereRaw("COALESCE(c.nombres, 'SIN TRT') like ?", ['%'. $trt . '%']);
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
            2=> 'bi bi-truck-flatbed',
            3=> 'bi bi-building',
            5=> 'bi bi-truck',
            6=> 'fa-solid fa-truck-ramp-box'
        ];

        if ($campo == 'icon') return $iconos[$id];

        //------------provisional para no usar collect--------------
        if ($id > 3)return self::$tipos_llamada[$id-1]->$campo;
        else
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

    public static function etiquetas_icon_bi($item , $size='',$solo='todo',$mostrar_cantidad=false, $otros=false){
        $iconos= self::$etiquetas_icon_bi;
        $lista_e='';
        $count=0;
        foreach ($iconos as $key => $value) { //buscar por clave de icono
            if ($item->$key??false){ //si existe y es uno etiquetar
                if($solo==='todo' or $solo===$value[2]){ //value[2] es igual a 0 y 1 positivo y negativo
                    $lista_e.= "<i class='". $value[0] ." ".$size."'></i> ".$value[1];

                    if ($mostrar_cantidad) {
                        $lista_e.= '('. $item->$key .')';
                        if ($otros) $count+= $item->$key;
                    }
                    $lista_e.="<br>";
                }
            }
        }
        if ($otros and $otros-$count>0 and $mostrar_cantidad) $lista_e.= "Otros (".($otros-$count).')<br>';
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
            SUM(audio_duracion) as audio_duracion_total,
            SUM(audio_duracion * llamada_exitosa) as audio_duracion_exitosas,
            SUM(audio_duracion * (llamada_exitosa=0)) as audio_duracion_fallidas,

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
            $sql .= " and a.created_at >= ? AND a.created_at < ? ";
            $params[] = $fecha_i;
            $params[] = $fecha_f;
        }
        if ($tipo!='') $sql .= " and a.llamada_tipo_id=". $tipo.' ';

        return [$sql, $params];
    }

    public static function top_peores_conductores($limit=5){
        $sql= "
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

                SUM(a.conductor_cuelga * (a.llamada_exitosa = 0))  AS conductor_cuelga,
                SUM(a.buzon_de_voz * (a.llamada_exitosa = 0))  AS buzon_de_voz,
                SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0)) AS conductor_contesta_pero_no_habla,
                SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0)) AS conductor_no_escucha,
                SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0)) AS conductor_mala_senal,
                SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0)) AS confusion_en_llamada,
                SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0)) AS contesta_otra_persona,
                SUM(a.numero_equivocado * (a.llamada_exitosa = 0)) AS numero_equivocado,
                SUM(a.conductor_cuelga * (a.llamada_exitosa = 0)) AS conductor_cuelga,
                SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0)) AS conductor_no_contesta,
                SUM(a.conductor_confirma * (a.llamada_exitosa = 0))  AS confirmacion_parcial,
                SUM(a.conductor_conducta_inapropiada * (a.llamada_exitosa = 0))  AS conductor_conducta_inapropiada
            FROM llamadas a
            INNER JOIN conductores b ON b.id = a.conductor_id
            LEFT JOIN trts c ON c.id = a.trt_id
            WHERE a.error_origen = 0
            ";
        $sql_2="
        GROUP BY a.conductor_id, a.trt_id
        ORDER BY diferencia DESC , exitosas asc
        limit ?;";

        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_mejores_conductores($limit= 5){
        $sql= "
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

                SUM(a.conductor_confirma * a.llamada_exitosa)  AS conductor_confirma,
                SUM(a.conductor_da_motivos * a.llamada_exitosa ) AS conductor_da_motivos,
                SUM(a.conversacion_fluida * a.llamada_exitosa) AS conversacion_fluida,
                SUM(a.llamada_interesante * a.llamada_exitosa ) AS llamada_interesante,
                SUM(a.conductor_da_motivos * a.llamada_exitosa ) + SUM(a.conversacion_fluida * a.llamada_exitosa)+
                SUM(a.llamada_interesante * a.llamada_exitosa) as etiqueta_positiva,

                 SUBSTRING_INDEX(
                     MAX(CASE
                             WHEN a.llamada_exitosa = 1
                             THEN CONCAT(
                                 LPAD(
                                     a.conductor_da_motivos +
                                     a.conversacion_fluida +
                                     a.llamada_interesante , 2, '0'
                                 ),'|',a.audio_link)
                         END
                     ),'|',-1) AS mejor_audio

            FROM llamadas a
            INNER JOIN conductores b ON b.id = a.conductor_id
            LEFT JOIN trts c ON c.id = a.trt_id
            WHERE a.error_origen = 0
        ";
        $sql_2="
        GROUP BY a.conductor_id, a.trt_id
        ORDER BY diferencia DESC , exitosas desc , etiqueta_positiva desc
        limit ?;";
        $filtro= self::aplicar_filtro_sqltext();
        $filtro[1][]=$limit;
        return  DB::select($sql . $filtro[0] . $sql_2, $filtro[1]);
    }

    public static function top_mejores_trts($limit= 5){
        $sql= "
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
            if ($count >= 9 and is_numeric($value) and $key !='etiqueta_positiva') $eti[$key] = $value;
            $count++;
        }
        arsort($eti); //ordenar de mayor a menor
        $eti= (object) array_slice($eti, 0, 4, true); //solo 3 etiquetas
        //----------------------------------------
        $iconos= self::$etiquetas_icon_bi; //iconos bootstrap
        $lista_e=''; // listar las etiquetas con mayor aparicion
        $sumar_e=0;
        //if ($item->conductor_id==32) dd($item);
        foreach ($iconos as $key => $value) {
            if ($eti->$key??0){
                $lista_e.= "<i class='". $value[0] ." ".$size."'></i> ".$value[1]."(".$eti->$key."),";
                $sumar_e+=$eti->$key;
            }
        }
        //sio las etiquetas no sumaron el total poner otros
        if ($item->fallidas > $sumar_e) $lista_e .= "Otros(". ($item->fallidas - $sumar_e) .")";

        return trim($lista_e,',');
    }

    public static function color_porcentaje($num){
        if ($num >= 75) return 'success';
        if ($num >= 50) return 'info';
        if ($num >= 25) return 'warning';
        return 'danger';
    }

    public static function puntaje_conductor($item)
    {
        $iconos= self::$etiquetas_icon_bi;
        $count=0;
        $fallidas= $item->fallidas-$item->total_error??0;
        foreach ($iconos as $key => $value) { //buscar por clave de icono
            if ($item->$key??false) $count+= ($value[3] * $item->$key);
        }
        $count+=($item->exitosas*15) - ($fallidas*10);
        return $count;
    }

    public static function audio_duracion_format($segundos){
        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $seg = $segundos % 60;

        $tiempo = '';
        if ($horas > 0) $tiempo .= $horas . ' H, ';
        if ($minutos > 0) $tiempo .= $minutos . ' Min y ';
        if ($seg > 0) $tiempo .= $seg . ' Seg';

        return trim($tiempo);
    }

    public static function buscar_razon_finalizacion($id)
    {
        $item=BuscarEnArray::cualquiera_std($id,'id',self::$razones_finalizacion);
        if ($item== false) return 0; //sin conflictos para mysql
        return $item;
    }

    public static function grafico_semana_query()
    {
        $tipo=self::$filtro->llamada_tipo_id;

        $sql="
         SELECT
             total,
             exitosas,
             fallidas,
             total_errores,
             DATE_FORMAT(t.fecha, '%W %d/%m/%y') as fecha_text
         FROM (
                  SELECT
                      COUNT(*) AS total,
                      COALESCE(SUM(a.llamada_exitosa=1),0) AS exitosas,
                      COALESCE(SUM(a.llamada_exitosa=0),0) AS fallidas,
                      COALESCE(SUM((a.llamada_exitosa=0) AND (a.error_origen!=0)),0) as total_errores,
                      DATE(a.created_at) as fecha
                  FROM llamadas a
                  WHERE a.created_at >= DATE(?) - INTERVAL 7 DAY
                    AND a.created_at < DATE(?) + INTERVAL 1 DAY ";
        $sql_where=" AND a.llamada_tipo_id=? ";
        $sql_2=" GROUP BY DATE(a.created_at)
              ) t;
        ";

        if((string) $tipo !== '')
            return DB::select($sql . $sql_where . $sql_2 ,[self::$filtro->fecha_inicio,self::$filtro->fecha_inicio,$tipo]);

        return DB::select($sql . $sql_2 ,[self::$filtro->fecha_inicio,self::$filtro->fecha_inicio]);
    }




}
