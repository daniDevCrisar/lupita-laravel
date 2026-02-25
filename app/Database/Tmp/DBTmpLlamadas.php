<?php

namespace App\Database\Tmp;

use Illuminate\Support\Facades\DB;
use App\Tools\BuscarEnArray;
use stdClass;

class DBTmpLlamadas {
    public static $razones_finalizacion = [];
    public static $log;

    public function __construct() {
        self::$razones_finalizacion = DB::select("select * from razones_finalizacion");

        self::$log = new stdClass();
        self::$log->duplicados = [];
        self::$log->total_duplicados = 0;
        self::$log->total_llamadas = 0;
    }

    public static function existe($id) {
        self::$log->total_llamadas++;
        $result= DB::select('SELECT vapi_id FROM `llamadas` where vapi_id=UUID_TO_BIN(?)', [$id]);
        if (count($result) == 1) {
            self::$log->duplicados[]= $id;
            self::$log->total_duplicados++;
            return true;
        }
        return false;
    }

    public static function importar_llamadas_de_tmp_al_sistema($id_trt,$id_conductor,$id_lote,$item) {
        if (self::existe($item->vapi_id)) return false; //obtener log , no continuar si no existe
        $llamada = new stdClass();
        $llamada->vapi_id = $item->vapi_id;
        $llamada->lote_id = $id_lote;
        $llamada->conductor_id = $id_conductor;
        $llamada->trt_id = ($id_trt !== 'null' && $id_trt !== '') ? $id_trt : null;
        $llamada->telefono= $item->telefono;
        $llamada->ref = $item->ref == '' ? null : $item->ref;
        $llamada->llamada_tipo_id = $item->llamada_tipo;
        $llamada->es_entrante = 0; //OUTBOUNDPHONECALL
        $llamada->razon_finalizacion_id =self::obtener_id_razon($item->razon_finalizacion);
        $llamada->entro_llamada = $item->entro_llamada;
        $llamada->exitosa_segun_ia = $item->exitosa_segun_ia=='TRUE' ? 1 : 0;
        $llamada->llamada_exitosa = self::etiqueta_valor($item->llamada_exitosa);
        $llamada->audio_link = $item->audio;
        $llamada->audio_duracion = $item->audio_duracion;
        $llamada->analisis_transcripcion = $item->analisis_transcripcion;
        $llamada->analisis_audio = $item->analisis_audio;

        $llamada->conductor_confirma = self::etiqueta_valor($item->conductor_confirma);
        $llamada->buzon_de_voz = self::etiqueta_valor($item->buzon_de_voz);
        $llamada->conductor_contesta_pero_no_habla = self::etiqueta_valor($item->conductor_contesta_pero_no_habla);
        $llamada->conductor_no_escucha = self::etiqueta_valor($item->conductor_no_escucha);
        $llamada->conductor_da_motivos = self::etiqueta_valor($item->conductor_da_motivos);
        $llamada->conductor_mala_senal = self::etiqueta_valor($item->conductor_mala_senal);
        $llamada->confusion_en_llamada = self::etiqueta_valor($item->confusion_en_llamada);
        $llamada->contesta_otra_persona = self::etiqueta_valor($item->contesta_otra_persona);
        $llamada->numero_equivocado = self::etiqueta_valor($item->numero_equivocado);
        $llamada->conversacion_fluida = self::etiqueta_valor($item->conversacion_fluida);
        $llamada->llamada_interesante = self::etiqueta_valor($item->llamada_interesante);

        $llamada->ia_se_confunde = self::etiqueta_valor($item->ia_se_confunde);
        $llamada->ia_no_escucha = self::etiqueta_valor($item->ia_no_escucha);
        $llamada->ia_cambio_de_datos = self::etiqueta_valor($item->ia_cambio_de_datos);
        $llamada->ia_error_interpretacion = self::etiqueta_valor($item->ia_error_interpretacion);
        $llamada->ia_dice_variable = self::etiqueta_valor($item->ia_dice_variable);
        $llamada->ia_mala_pronunciacion = self::etiqueta_valor($item->ia_mala_pronunciacion);

        $llamada->conductor_cuelga = self::etiqueta_valor($item->conductor_cuelga);
        $llamada->conductor_no_contesta = self::etiqueta_valor($item->conductor_no_contesta);
        $llamada->conductor_conducta_inapropiada = self::etiqueta_valor($item->conductor_conducta_inapropiada);

        $llamada->error_tecnico_llamada = self::etiqueta_valor($item->error_tecnico_llamada);
        $llamada->error_audio = self::etiqueta_valor($item->error_audio);
        $llamada->error_origen = self::validar_error_origen($item->error_origen);
        self::guardar_mensajes($item->vapi_id,$item->mensajes_conten);
        $timestamp = (int) $item->created_at;
        $llamada->created_at = ($timestamp  - (5 * 60 * 60 * 1000) )  /1000; //dejarlo en horario peruano utc-5

        $llamada->procesado= 1; //indica si la llamada fue etiquetada por un humano 

        self::guardar_llamada($llamada);
    }

    public static function obtener_id_razon($name){
        $id=BuscarEnArray::cualquiera_std($name,'name',self::$razones_finalizacion);
        if ($id== false) return 0; //sin conflictos para mysql
        return $id->id;
    }

    public static function etiqueta_valor($valor){
        if ($valor) return 1;
        return 0;
    }

    private static function validar_error_origen($valor)
    {
        // Solo string vacío se convierte en 0
        if ($valor === '') return 0;
        // Si no es numérico → desconocido
        if (!is_numeric($valor)) return -1;
        
        $valor = (int)$valor;
        $permitidos = [-1, 0, 1, 2, 3];
        return in_array($valor, $permitidos, true) ? $valor : -1;
    }


    public static function guardar_mensajes($vapi_id, $conversacion_completa) {

        //$conversacion_completa = ltrim($conversacion_completa, '/');
        $mensajes = explode('//', $conversacion_completa);
        $orden = 1;
        
        foreach ($mensajes as $mensaje) {
            $mensaje = trim($mensaje);
            if ($mensaje === '') continue;

            $msg= explode(':', $mensaje);
            if (count($msg) != 2) continue;
            $msg[0] = trim($msg[0]);
            $msg[1] = trim($msg[1]);

            if ($msg[0] != 'BOT' and $msg[0] != 'USER' ) continue;

            // MySQL convierte el UUID directamente con UUID_TO_BIN()
            DB::insert(
                "INSERT INTO mensajes (vapi_id, orden, tipo, mensaje) 
                VALUES (UUID_TO_BIN(?), ?, ?, ?)",
                [$vapi_id, $orden, trim($msg[0]), trim($msg[1])]
            );
            $orden++;
        }

        return $orden - 1;
    }

    public static function guardar_llamada($llamada) {
        $sql = "INSERT INTO llamadas (
            vapi_id,
            lote_id,
            conductor_id,
            trt_id,
            telefono,
            ref,
            llamada_tipo_id,
            es_entrante,
            razon_finalizacion_id,
            entro_llamada,
            exitosa_segun_ia,
            llamada_exitosa,
            audio_link,
            audio_duracion,
            analisis_transcripcion,
            analisis_audio,
            conductor_confirma,
            buzon_de_voz,
            conductor_contesta_pero_no_habla,
            conductor_no_escucha,
            conductor_da_motivos,
            conductor_mala_senal,
            confusion_en_llamada,
            contesta_otra_persona,
            numero_equivocado,
            conversacion_fluida,
            llamada_interesante,
            ia_se_confunde,
            ia_no_escucha,
            ia_cambio_de_datos,
            ia_error_interpretacion,
            ia_dice_variable,
            ia_mala_pronunciacion,
            conductor_cuelga,
            conductor_no_contesta,
            conductor_conducta_inapropiada,
            error_tecnico_llamada,
            error_audio,
            error_origen,
            procesado,
            created_at
        ) VALUES (
            UUID_TO_BIN(?),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
            FROM_UNIXTIME(?)
        )";
        
        $params = [
            $llamada->vapi_id,
            $llamada->lote_id,
            $llamada->conductor_id,
            $llamada->trt_id,
            $llamada->telefono,
            $llamada->ref,
            $llamada->llamada_tipo_id,
            $llamada->es_entrante,
            $llamada->razon_finalizacion_id,
            $llamada->entro_llamada,
            $llamada->exitosa_segun_ia,
            $llamada->llamada_exitosa,
            $llamada->audio_link,
            $llamada->audio_duracion,
            $llamada->analisis_transcripcion,
            $llamada->analisis_audio,
            $llamada->conductor_confirma,
            $llamada->buzon_de_voz,
            $llamada->conductor_contesta_pero_no_habla,
            $llamada->conductor_no_escucha,
            $llamada->conductor_da_motivos,
            $llamada->conductor_mala_senal,
            $llamada->confusion_en_llamada,
            $llamada->contesta_otra_persona,
            $llamada->numero_equivocado,
            $llamada->conversacion_fluida,
            $llamada->llamada_interesante,
            $llamada->ia_se_confunde,
            $llamada->ia_no_escucha,
            $llamada->ia_cambio_de_datos,
            $llamada->ia_error_interpretacion,
            $llamada->ia_dice_variable,
            $llamada->ia_mala_pronunciacion,
            $llamada->conductor_cuelga,
            $llamada->conductor_no_contesta,
            $llamada->conductor_conducta_inapropiada,
            $llamada->error_tecnico_llamada,
            $llamada->error_audio,
            $llamada->error_origen,
            $llamada->procesado,
            $llamada->created_at
        ];
        
        return DB::insert($sql, $params);
    }

}