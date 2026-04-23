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


    public static $trofeos_text = ['conductor_confirma' => ['Confirmador <br> Inicial', 'Confirmador <br> Constante', 'Confirmador <br> Excelente'],
    'buzon_de_voz' => ['Aprendiz <br> del Buzon', 'Oficial del <br> Buzon', 'Maestro <br> del Buzón'],
    'conductor_contesta_pero_no_habla' => ['Silencioso', 'Modo Mute', 'Silencio <br>Absoluto'],
    'conductor_no_escucha' => ['Distraído', 'No Escucha', 'Sordo <br>Total'],
    'conductor_da_motivos' => ['Argumentador', 'Analista', 'Experto <br>en Motivos'],
    'conductor_mala_senal' => ['Señal Baja', 'Conexión Inestable', 'Señal Crítica'],
    'confusion_en_llamada' => ['Confusión Leve', 'Confusión Media', 'Confusión Total'],
    'contesta_otra_persona' => ['Tercero', 'Persona Ajena', 'Gestor de Terceros'],
    'numero_equivocado' => ['Error Leve', 'Repetitivo', 'Maestro del Error'],
    'conversacion_fluida' => ['Fluido','Carismático','Maestro de <br> la Palabra'],
    'llamada_interesante' => ['Interesante', 'Muy Interesante', 'Llamada Top'],

    'ia_se_confunde' => ['IA Dudosa', 'IA Inestable', 'IA Confundida Crítica'],
    'ia_no_escucha' => ['IA Baja Recepción', 'IA No Escucha', 'IA Sorda Nivel Dios'],
    'ia_cambio_de_datos' => ['IA Aprendiz', 'IA Inteligente', 'IA Optimizada Excelente'],
    'ia_error_interpretacion' => ['Error Leve', 'Interpretación Fallida', 'Error Crítico'],
    'ia_dice_variable' => ['Variable Simple', 'Variable Recurrente', 'Maestro de Variables'],
    'ia_mala_pronunciacion' => ['Pronunciación Baja', 'Pronunciación Mala', 'Pronunciación Crítica'],
    'ia_cuelga_en_plena_llamada' => ['Cuelga Leve', 'Cuelga Recurrente', 'Cuelga Crítico'],

    'conductor_cuelga' => ['Cuelga <br> Ocasionalmente', 'Cuelga Frecuente', 'Cuelga Siempre'],
    'conductor_no_contesta' => ['Fantasma', 'Desaparecido', 'Inubicable'],
    'conductor_conducta_inapropiada' => ['Conducta Leve', 'Conducta Inapropiada', 'Conducta Crítica'],

    'error_tecnico_llamada' => ['Error Técnico Leve', 'Error Técnico', 'Error Técnico Crítico'],
    'error_audio' => ['Audio Bajo', 'Audio Defectuoso', 'Audio Crítico'],

    'confirmacion_parcial' => ['Parcial Leve', 'Parcial Media', 'Parcial Experta'],
    'conductor_ocupado' => ['Ocupado Leve', 'Ocupado', 'Ocupado Frecuente'],];

    //---------$etiquetas_icon_bi-----------------------
    // 0 = icono
    // 1 = texto
    // 2 = etiqueta: 1 positivo , 0 negativo
    // 3 = puntaje
    // 4 = grupo
    public static $etiqueta_colores=[
        1=> ['primary ' , 'info' , 'success'],
        0=> ['dark' , 'warning' , 'danger'],
    ];
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
        'ia_cuelga_en_plena_llamada' => ['bi bi-bug-fill text-danger','IA cuelga en plena llamada',0,0,3],

        'conductor_cuelga' => ['bi bi-telephone-minus-fill text-danger','Cuelga',0,-5,0],
        'conductor_no_contesta' => ['bi bi-telephone-x-fill text-danger','No contesta',0,-10,0],
        'conductor_conducta_inapropiada' => ['bi bi-person-x-fill text-danger','Conducta Inapropiada',0,-30,2],

        'error_tecnico_llamada' => ['bi bi-gear-fill text-danger','Error tecnico en llamada',0,0,3],
        'error_audio' => ['bi bi-volume-mute-fill text-danger','Error de audio',0,0,3],
        // no exito solo en llamadas fallidas exitosa=0 y confirmacion=0
        'confirmacion_parcial' => ['bi bi-check2-square text-info','Confirmacion parcial',0,5,0],
        'conductor_ocupado' => ['bi bi-hourglass text-warning','Conductor Ocupado',0,-5,0],
    ];

    public static $iconos_exito=[
        'exito'=>'bi bi-check-lg text-success',
        -1=> 'bi bi-question-circle text-danger',
        0=> 'bi bi-person-slash text-danger',
        1=> 'bi bi-robot text-danger',
        2=> 'bi bi-wifi text-danger',
        3=> 'bi bi-gear text-danger',
    ];

    public static $filtro;

    public function __construct($cargar_tablas=true) {
        if ($cargar_tablas) {
            self::$razones_finalizacion = DB::select('SELECT * FROM razones_finalizacion');
            self::$tipos_llamada = DB::select('SELECT * FROM tipos_llamada');
            self::$error_origen = DB::select('SELECT * FROM error_origen');
        }
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
        self::$filtro->etiquetas= $request->etiquetas;
        self::$filtro->e_operador= $request->e_operador;
    }


    public static function listar_principal($mostrar){
        $fecha_i =self::$filtro->fecha_inicio??'';
        $fecha_f= self::$filtro->fecha_fin??'';
        $tipo_id= self::$filtro->llamada_tipo_id??'';
        $conductor= strtoupper(self::$filtro->conductor??'');
        $trt= strtoupper(self::$filtro->trt)??'';
        $exitosa=self::$filtro->exitosa??'';
        $etiquetas=self::$filtro->etiquetas??'';
        $e_operador=self::$filtro->e_operador??'';

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

            'a.costo',
            'a.ia_result_delay_reason_desc',
            'a.ia_result_comments_text',
            'b.trofeos',

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
            'a.ia_cuelga_en_plena_llamada',

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
        // ========== EN EL MEDIO - ETIQUETAS ==========
        ->when(!empty($etiquetas), function ($query) use ($etiquetas, $e_operador) {
            $sql_etiquetas='';
            foreach ($etiquetas as $tag)
                if (self::$etiquetas_icon_bi[$tag])
                    if ($e_operador)
                        $sql_etiquetas .= 'OR a.'.$tag.' = 1 ';
                    else
                        $sql_etiquetas .= 'AND a.'.$tag.'= 1 ';

            $sql_etiquetas = ltrim($sql_etiquetas,'OR');
            $sql_etiquetas = ltrim($sql_etiquetas,'AND');
            $query->whereRaw('('.$sql_etiquetas. ')');
            return $query;
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
        //if ($id > 3)return self::$tipos_llamada[$id-1]->$campo;
        //else
        return self::$tipos_llamada[$id]->$campo;
    }

    public static function icon_exito($item , $solo_icon=false){
        $iconos=self::$iconos_exito;
        if ($solo_icon) return $iconos[$item];
        if ($item->llamada_exitosa) return $iconos['exito'];

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

            COUNT(*) AS llamadas,
            COUNT(DISTINCT conductor_id) AS conductores,
            COUNT(DISTINCT if(llamada_exitosa,conductor_id,null)) AS conductores_exitosos,
            COUNT(DISTINCT trt_id) AS trts,

            SUM(razon_finalizacion_id = 3) AS razon_3_no_contesta,
            SUM(razon_finalizacion_id = 5) AS razon_5_ocupado,

            SUM(IF (entro_llamada and !llamada_exitosa and !buzon_de_voz, audio_duracion,0)) as audio_duracion_fallidas_sin_buzon,

            SUM(error_origen = -1) AS error_desconocido,
            SUM(error_origen = 0) AS error_humano,
            SUM(error_origen = 1) AS error_ia,
            SUM(error_origen = 2) AS error_red,
            SUM(error_origen = 3) AS error_sistema,
            SUM(!a.llamada_exitosa and conductor_confirma and error_origen=0) as confirmacion_parcial,

            SUM(
            a.conductor_confirma + a.buzon_de_voz + a.conductor_contesta_pero_no_habla +
            a.conductor_no_escucha + a.conductor_da_motivos +  a.conductor_mala_senal + a.confusion_en_llamada +
            a.contesta_otra_persona + a.numero_equivocado + a.conversacion_fluida + a.llamada_interesante +
            + a.conductor_conducta_inapropiada + a.error_tecnico_llamada + a.error_audio = 0 and a.conductor_cuelga and !a.llamada_exitosa and a.error_origen=0
            ) as conductor_cuelga

            SUM(exitosa_segun_ia) AS exitosa_segun_ia,
            (SUM(entro_llamada) - SUM(buzon_de_voz)) AS contestadas,
            SUM(if (entro_llamada and !llamada_exitosa and !buzon_de_voz,1,0)) as contestadas_fallidas,
            SUM(llamada_exitosa) AS llamada_exitosa,
            SUM(audio_duracion) as audio_duracion_total,
            SUM(audio_duracion * llamada_exitosa) as audio_duracion_exitosas,
            SUM(audio_duracion * !llamada_exitosa) as audio_duracion_fallidas,

            SUM(conductor_confirma) AS conductor_confirma,
            SUM(buzon_de_voz) AS buzon_de_voz,
            SUM(conductor_contesta_pero_no_habla and !llamada_exitosa) AS conductor_contesta_pero_no_habla,
            SUM(conductor_no_escucha and !llamada_exitosa) AS conductor_no_escucha,
            SUM(conductor_da_motivos) AS conductor_da_motivos,
            SUM(conductor_mala_senal and !llamada_exitosa) AS conductor_mala_senal,
            SUM(confusion_en_llamada and !llamada_exitosa) AS confusion_en_llamada,
            SUM(contesta_otra_persona and !llamada_exitosa) AS contesta_otra_persona,
            SUM(numero_equivocado and !llamada_exitosa) AS numero_equivocado,
            SUM(conversacion_fluida) AS conversacion_fluida,
            SUM(llamada_interesante) AS llamada_interesante,

            SUM(ia_se_confunde) AS ia_se_confunde,
            SUM(ia_no_escucha) AS ia_no_escucha,
            SUM(ia_cambio_de_datos) AS ia_cambio_de_datos,
            SUM(ia_error_interpretacion) AS ia_error_interpretacion,
            SUM(ia_dice_variable) AS ia_dice_variable,
            SUM(ia_mala_pronunciacion) AS ia_mala_pronunciacion,
            SUM(ia_cuelga_en_plena_llamada) AS ia_cuelga_en_plena_llamada,

            SUM(conductor_cuelga) AS conductor_cuelga,
            SUM(conductor_no_contesta) AS conductor_no_contesta,
            SUM(conductor_conducta_inapropiada) AS conductor_conducta_inapropiada,

            SUM(error_tecnico_llamada) AS error_tecnico_llamada,
            SUM(error_audio) AS error_audio,
            SUM(costo) as costo,
            sum(if(llamada_exitosa,costo,0) ) as costo_exitosa,
            sum(if(!llamada_exitosa,costo,0) ) as costo_fallida

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

    public static function mapa_calor_rango()
    {
        $tipo_id= self::$filtro->llamada_tipo_id??'';
        $sql="
        SELECT t.*,
        CONCAT(SUBSTRING(UPPER(DAYNAME(t.fecha)), 1, 1),  -- Primera letra
            ' ',DAYOFMONTH(t.fecha) ) as fecha_text
        FROM
        (SELECT
            DATE(a.created_at) AS fecha,
            -- Hora 0
            SUM(IF(HOUR(a.created_at) = 0 AND a.llamada_exitosa, 1, 0)) AS hora_0_exito,
            SUM(IF(HOUR(a.created_at) = 0 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_0_fallo,
            SUM(IF(HOUR(a.created_at) = 0 AND a.error_origen = 0, 1, 0)) AS hora_0,
            -- Hora 1
            SUM(IF(HOUR(a.created_at) = 1 AND a.llamada_exitosa, 1, 0)) AS hora_1_exito,
            SUM(IF(HOUR(a.created_at) = 1 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_1_fallo,
            SUM(IF(HOUR(a.created_at) = 1 AND a.error_origen = 0, 1, 0)) AS hora_1,
            -- Hora 2
            SUM(IF(HOUR(a.created_at) = 2 AND a.llamada_exitosa, 1, 0)) AS hora_2_exito,
            SUM(IF(HOUR(a.created_at) = 2 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_2_fallo,
            SUM(IF(HOUR(a.created_at) = 2 AND a.error_origen = 0, 1, 0)) AS hora_2,
            -- Hora 3
            SUM(IF(HOUR(a.created_at) = 3 AND a.llamada_exitosa, 1, 0)) AS hora_3_exito,
            SUM(IF(HOUR(a.created_at) = 3 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_3_fallo,
            SUM(IF(HOUR(a.created_at) = 3 AND a.error_origen = 0, 1, 0)) AS hora_3,
            -- Hora 4
            SUM(IF(HOUR(a.created_at) = 4 AND a.llamada_exitosa, 1, 0)) AS hora_4_exito,
            SUM(IF(HOUR(a.created_at) = 4 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_4_fallo,
            SUM(IF(HOUR(a.created_at) = 4 AND a.error_origen = 0, 1, 0)) AS hora_4,
            -- Hora 5
            SUM(IF(HOUR(a.created_at) = 5 AND a.llamada_exitosa, 1, 0)) AS hora_5_exito,
            SUM(IF(HOUR(a.created_at) = 5 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_5_fallo,
            SUM(IF(HOUR(a.created_at) = 5 AND a.error_origen = 0, 1, 0)) AS hora_5,
            -- Hora 6
            SUM(IF(HOUR(a.created_at) = 6 AND a.llamada_exitosa, 1, 0)) AS hora_6_exito,
            SUM(IF(HOUR(a.created_at) = 6 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_6_fallo,
            SUM(IF(HOUR(a.created_at) = 6 AND a.error_origen = 0, 1, 0)) AS hora_6,
            -- Hora 7
            SUM(IF(HOUR(a.created_at) = 7 AND a.llamada_exitosa, 1, 0)) AS hora_7_exito,
            SUM(IF(HOUR(a.created_at) = 7 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_7_fallo,
            SUM(IF(HOUR(a.created_at) = 7 AND a.error_origen = 0, 1, 0)) AS hora_7,
            -- Hora 8
            SUM(IF(HOUR(a.created_at) = 8 AND a.llamada_exitosa, 1, 0)) AS hora_8_exito,
            SUM(IF(HOUR(a.created_at) = 8 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_8_fallo,
            SUM(IF(HOUR(a.created_at) = 8 AND a.error_origen = 0, 1, 0)) AS hora_8,
            -- Hora 9
            SUM(IF(HOUR(a.created_at) = 9 AND a.llamada_exitosa, 1, 0)) AS hora_9_exito,
            SUM(IF(HOUR(a.created_at) = 9 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_9_fallo,
            SUM(IF(HOUR(a.created_at) = 9 AND a.error_origen = 0, 1, 0)) AS hora_9,
            -- Hora 10
            SUM(IF(HOUR(a.created_at) = 10 AND a.llamada_exitosa, 1, 0)) AS hora_10_exito,
            SUM(IF(HOUR(a.created_at) = 10 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_10_fallo,
            SUM(IF(HOUR(a.created_at) = 10 AND a.error_origen = 0, 1, 0)) AS hora_10,
            -- Hora 11
            SUM(IF(HOUR(a.created_at) = 11 AND a.llamada_exitosa, 1, 0)) AS hora_11_exito,
            SUM(IF(HOUR(a.created_at) = 11 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_11_fallo,
            SUM(IF(HOUR(a.created_at) = 11 AND a.error_origen = 0, 1, 0)) AS hora_11,
            -- Hora 12
            SUM(IF(HOUR(a.created_at) = 12 AND a.llamada_exitosa, 1, 0)) AS hora_12_exito,
            SUM(IF(HOUR(a.created_at) = 12 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_12_fallo,
            SUM(IF(HOUR(a.created_at) = 12 AND a.error_origen = 0, 1, 0)) AS hora_12,
            -- Hora 13
            SUM(IF(HOUR(a.created_at) = 13 AND a.llamada_exitosa, 1, 0)) AS hora_13_exito,
            SUM(IF(HOUR(a.created_at) = 13 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_13_fallo,
            SUM(IF(HOUR(a.created_at) = 13 AND a.error_origen = 0, 1, 0)) AS hora_13,
            -- Hora 14
            SUM(IF(HOUR(a.created_at) = 14 AND a.llamada_exitosa, 1, 0)) AS hora_14_exito,
            SUM(IF(HOUR(a.created_at) = 14 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_14_fallo,
            SUM(IF(HOUR(a.created_at) = 14 AND a.error_origen = 0, 1, 0)) AS hora_14,
            -- Hora 15
            SUM(IF(HOUR(a.created_at) = 15 AND a.llamada_exitosa, 1, 0)) AS hora_15_exito,
            SUM(IF(HOUR(a.created_at) = 15 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_15_fallo,
            SUM(IF(HOUR(a.created_at) = 15 AND a.error_origen = 0, 1, 0)) AS hora_15,
            -- Hora 16
            SUM(IF(HOUR(a.created_at) = 16 AND a.llamada_exitosa, 1, 0)) AS hora_16_exito,
            SUM(IF(HOUR(a.created_at) = 16 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_16_fallo,
            SUM(IF(HOUR(a.created_at) = 16 AND a.error_origen = 0, 1, 0)) AS hora_16,
            -- Hora 17
            SUM(IF(HOUR(a.created_at) = 17 AND a.llamada_exitosa, 1, 0)) AS hora_17_exito,
            SUM(IF(HOUR(a.created_at) = 17 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_17_fallo,
            SUM(IF(HOUR(a.created_at) = 17 AND a.error_origen = 0, 1, 0)) AS hora_17,
            -- Hora 18
            SUM(IF(HOUR(a.created_at) = 18 AND a.llamada_exitosa, 1, 0)) AS hora_18_exito,
            SUM(IF(HOUR(a.created_at) = 18 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_18_fallo,
            SUM(IF(HOUR(a.created_at) = 18 AND a.error_origen = 0, 1, 0)) AS hora_18,
            -- Hora 19
            SUM(IF(HOUR(a.created_at) = 19 AND a.llamada_exitosa, 1, 0)) AS hora_19_exito,
            SUM(IF(HOUR(a.created_at) = 19 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_19_fallo,
            SUM(IF(HOUR(a.created_at) = 19 AND a.error_origen = 0, 1, 0)) AS hora_19,
            -- Hora 20
            SUM(IF(HOUR(a.created_at) = 20 AND a.llamada_exitosa, 1, 0)) AS hora_20_exito,
            SUM(IF(HOUR(a.created_at) = 20 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_20_fallo,
            SUM(IF(HOUR(a.created_at) = 20 AND a.error_origen = 0, 1, 0)) AS hora_20,
            -- Hora 21
            SUM(IF(HOUR(a.created_at) = 21 AND a.llamada_exitosa, 1, 0)) AS hora_21_exito,
            SUM(IF(HOUR(a.created_at) = 21 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_21_fallo,
            SUM(IF(HOUR(a.created_at) = 21 AND a.error_origen = 0, 1, 0)) AS hora_21,
            -- Hora 22
            SUM(IF(HOUR(a.created_at) = 22 AND a.llamada_exitosa, 1, 0)) AS hora_22_exito,
            SUM(IF(HOUR(a.created_at) = 22 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_22_fallo,
            SUM(IF(HOUR(a.created_at) = 22 AND a.error_origen = 0, 1, 0)) AS hora_22,
            -- Hora 23
            SUM(IF(HOUR(a.created_at) = 23 AND a.llamada_exitosa, 1, 0)) AS hora_23_exito,
            SUM(IF(HOUR(a.created_at) = 23 AND NOT a.llamada_exitosa AND a.error_origen = 0, 1, 0)) AS hora_23_fallo,
            SUM(IF(HOUR(a.created_at) = 23 AND a.error_origen = 0, 1, 0)) AS hora_23,

            SUM( IF(a.llamada_exitosa,1,0) ) as total_exito,
            SUM( IF(NOT a.llamada_exitosa AND a.error_origen = 0 ,1,0)) as total_fallo,
            SUM( IF(NOT a.llamada_exitosa AND a.error_origen != 0 ,1,0)) as total_error
        FROM llamadas a
        WHERE a.created_at >= DATE(?)
          AND a.created_at < DATE(?) + INTERVAL 1 DAY ";
        $sql_where=" AND a.llamada_tipo_id=? ";
        $sql_2="
            GROUP BY DATE(a.created_at)) t;
            ";
        if ($tipo_id)
            $mapa_calor= DB::select($sql .$sql_where. $sql_2  ,[self::$filtro->fecha_inicio,self::$filtro->fecha_fin,$tipo_id]);

        $mapa_calor= DB::select($sql . $sql_2  ,[self::$filtro->fecha_inicio,self::$filtro->fecha_fin]);

        $max_t=0;$max_f=0;$max_e=0; //obtener los maximos para el mapa de calor
        $t_max_t=0;$t_max_f=0;$t_max_e=0; //resumen
        $t_min_t=0;$t_min_f=0;$t_min_e=0; //resumen
        $hora_max_t=0;$hora_max_f=0;$hora_max_e=0; //resumen
        $hora_min_t=0;$hora_min_f=0;$hora_min_e=0; //resumen

        $resumen_mapa= new stdClass();

        for($i = 0; $i< 24;$i++){
            $t_total=0;$t_fallo=0;$t_exito=0;
            for($j=0; $j<count($mapa_calor); $j++){
                $key_t='hora_'.$i;
                $key_f='hora_'.$i . '_fallo';
                $key_e='hora_'.$i . '_exito';
                $v_total = $mapa_calor[$j]->$key_t;
                $v_fallo = $mapa_calor[$j]->$key_f;
                $v_exito = $mapa_calor[$j]->$key_e;

                $t_total+=$v_total;
                $t_fallo+=$v_fallo;
                $t_exito+=$v_exito;

                if($v_total > $max_t) $max_t = $v_total;
                if($v_fallo > $max_f) $max_f = $v_fallo;
                if($v_exito > $max_e) $max_e = $v_exito;
            }

            $t_porcentaje=0;
            if($t_exito)
                $t_porcentaje= round(($t_exito/$t_total)*100);
            $resumen_mapa->rows[]=[
                'total'=>$t_total,
                'fallo'=>$t_fallo,
                'exito'=>$t_exito,
                'porcentaje'=>$t_porcentaje
            ];
            if($t_total > $t_max_t) {
                $t_max_t = $t_total;$hora_max_t = $i;
            }
            if($t_fallo > $t_max_f) {
                $t_max_f = $t_fallo;$hora_max_f = $i;
            }
            if($t_exito > $t_max_e) {
                $t_max_e = $t_exito;$hora_max_e = $i;
            }

            if($t_total < $t_min_t) {
                $t_min_t = $t_total;$hora_min_t = $i;
            }
            if($t_fallo < $t_min_f) {
                $t_min_f = $t_fallo;$hora_min_f = $i;
            }
            if($t_exito < $t_min_e) {
                $t_min_e = $t_exito;$hora_min_e = $i;
            }
        }
        $resumen_mapa->max_total=$t_max_t;
        $resumen_mapa->max_fallo=$t_max_f;
        $resumen_mapa->max_exito=$t_max_e;
        $resumen_mapa->max_total_hora=$hora_max_t;
        $resumen_mapa->max_fallo_hora=$hora_max_f;
        $resumen_mapa->max_exito_hora=$hora_max_e;

        $resumen_mapa->min_total=$t_min_t;
        $resumen_mapa->min_fallo=$t_min_f;
        $resumen_mapa->min_exito=$t_min_e;
        $resumen_mapa->min_total_hora=$hora_min_t;
        $resumen_mapa->min_fallo_hora=$hora_min_f;
        $resumen_mapa->min_exito_hora=$hora_min_e;

        $mapa_calor_max = [
            'total'=> $max_t,
            'fallo'=>$max_f,
            'exito'=> $max_e
        ];

        return ['mapa_calor'=>$mapa_calor,'mapa_calor_resumen'=>$resumen_mapa,'mapa_calor_max'=>$mapa_calor_max];
    }

    public static function resumen_por_etapa_logistica()
    {
        $sql="
        SELECT
                tipo,
                COUNT(a.dia) as dias,
               SUM(a.total) as total  ,
               SUM(a.llamada_exitosa) as exitosas,
               ROUND((SUM(a.llamada_exitosa)/ SUM(a.total) *100) ) as porcentaje
        FROM
            (SELECT date(created_at) as dia,llamada_tipo_id as tipo,COUNT(llamada_tipo_id) as total,
            SUM(llamada_exitosa) as llamada_exitosa
            FROM `llamadas`";
        $sql_where_1="
            WHERE created_at >= DATE(?)
            AND created_at < DATE(?) + INTERVAL 1 DAY";
        $sql_where_2="
            WHERE date(created_at) = DATE(?)
        ";
        $sql_2="
            GROUP BY DATE(created_at) , llamada_tipo_id ) a
        GROUP BY a.tipo;
        ";
        //dd(self::$filtro->fecha_inicio and self::$filtro->fecha_fin, self::$filtro);
        if(self::$filtro->fecha_inicio and self::$filtro->fecha_fin)
            $result= DB::select($sql . $sql_where_1 . $sql_2 ,[self::$filtro->fecha_inicio,self::$filtro->fecha_fin]);
        else
            $result= DB::select($sql . $sql_where_2 . $sql_2 ,[self::$filtro->fecha_inicio]);

        $etapas=[];
        foreach ($result as $item) $etapas[$item->tipo]=$item;



        for ($i=1;$i<7;$i++) {
            if (!($etapas[$i]??0)){
                $etapas[$i] = new stdClass();
                $etapas[$i]->dias=0;
                $etapas[$i]->total=0;
                $etapas[$i]->exitosas=0;
                $etapas[$i]->porcentaje=0;
                $etapas[$i]->tipo=$i;
            }
        }
        return $etapas;
    }

    public static function mapa_calor_color_bootstrap($valor, $maximo, $texto=false) {
        $porcentaje = ($valor / $maximo) * 100;
        $color_text='';
        if ($texto) $color_text='text-white';
        if ($porcentaje < 15) {
            return 'bg-opacity-10 '.$color_text; // Muy bajo
        } elseif ($porcentaje < 30) {
            return 'bg-opacity-25'; // Bajo
        } elseif ($porcentaje < 50) {
            return 'bg-opacity-50'; // Medio
        } elseif ($porcentaje < 75) {
            return 'bg-opacity-75 '.$color_text; // Alto
        } else {
            return $color_text; // Máximo
        }
    }

    public static function analizar_horarios($datos, $key, $horasConsecutivas = 4, $circular = true)
    {
        $mejorTotal = 0;
        $mejorInicio = 0;
        $peorTotal = PHP_INT_MAX;
        $peorInicio = 0;
        $totalHoras = 24;

        // Si es circular, evaluamos hasta 24 + horasConsecutivas
        $limite = $circular ? $totalHoras + $horasConsecutivas - 1 : $totalHoras - $horasConsecutivas;

        for ($i = 0; $i <= $limite; $i++) {
            $total = 0;
            for ($j = 0; $j < $horasConsecutivas; $j++) {
                $hora = ($i + $j) % 24;
                $total += $datos[$hora][$key] ?? 0;
            }

            // Evaluar mejor (máximo)
            if ($total > $mejorTotal) {
                $mejorTotal = $total;
                $mejorInicio = $i;
            }

            // Evaluar peor (mínimo)
            if ($total < $peorTotal) {
                $peorTotal = $total;
                $peorInicio = $i;
            }
        }

        // Formatear mejor horario
        $mejorInicioReal = $mejorInicio % 24;
        $mejorFinReal = ($mejorInicio + $horasConsecutivas - 1) % 24;

        if ($mejorInicio + $horasConsecutivas <= 24) {
            $mejorRango = sprintf('%02d:00 - %02d:00', $mejorInicioReal, $mejorFinReal + 1);
        } else {
            $mejorRango = sprintf('%02d:00 - %02d:00 (medianoche)', $mejorInicioReal, $mejorFinReal + 1);
        }

        // Formatear peor horario
        $peorInicioReal = $peorInicio % 24;
        $peorFinReal = ($peorInicio + $horasConsecutivas - 1) % 24;

        if ($peorInicio + $horasConsecutivas <= 24) {
            $peorRango = sprintf('%02d:00 - %02d:00', $peorInicioReal, $peorFinReal + 1);
        } else {
            $peorRango = sprintf('%02d:00 - %02d:00 (medianoche)', $peorInicioReal, $peorFinReal + 1);
        }

        return [
            'mejor' => [
                'inicio' => $mejorInicioReal,
                'fin' => $mejorFinReal,
                'rango' => $mejorRango,
                'total' => $mejorTotal,
                'promedio' => round($mejorTotal / $horasConsecutivas, 2),
                'cruza_medianoche' => ($mejorInicio + $horasConsecutivas) > 24
            ],
            'peor' => [
                'inicio' => $peorInicioReal,
                'fin' => $peorFinReal,
                'rango' => $peorRango,
                'total' => $peorTotal,
                'promedio' => round($peorTotal / $horasConsecutivas, 2),
                'cruza_medianoche' => ($peorInicio + $horasConsecutivas) > 24
            ]
        ];
    }

    public static function diagrama_venn_ia_persona ()
    {
        $sql="
        SELECT SUM(exitosa_segun_ia) as ia,
            SUM(llamada_exitosa) as persona,
            SUM(IF(exitosa_segun_ia and llamada_exitosa,1,0)) as 'interseccion',
            sum(if(exitosa_segun_ia and buzon_de_voz,1,0)) as ia_buzon_de_voz
        from llamadas a
        where 1=1
        ";
        $filtro= self::aplicar_filtro_sqltext();
        return DB::select($sql . $filtro[0],$filtro[1]);

    }





}
