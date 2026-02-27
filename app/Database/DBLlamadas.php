<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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

    public function __construct() {
        self::$razones_finalizacion = DB::select('SELECT * FROM razones_finalizacion');
        self::$tipos_llamada = DB::select('SELECT * FROM tipos_llamada');
        self::$error_origen = DB::select('SELECT * FROM error_origen');
    }

    public static function listar_principal($mostrar=10){
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
        ->orderBy('a.created_at', 'desc')
        ->paginate($mostrar);
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

    public static function icon_exito($item){
        $exito='bi bi-check-lg text-success';
        $iconos=[
            -1=> 'bi bi-question-circle text-danger',
            0=> 'bi bi-person-slash text-danger',
            1=> 'bi bi-cpu text-danger',
            2=> 'bi bi-wifi text-danger',
            3=> 'bi bi-gear text-danger',
        ];
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

    public static function format_fecha($fecha){
        return Carbon::parse($fecha)->format('d/m/Y H:i');
    }

    
}