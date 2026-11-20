<?php

namespace App\Database\Tmp;

use Illuminate\Support\Facades\DB;
use App\Tools\BuscarEnArray;
use App\Database\DBReferencias;
use App\Database\DBScoringLlamadas;
use stdClass;use DateTime;

class DBTmpLlamadas {
    public static $razones_finalizacion = [];
    public static $log;

    /**
     * Cache de plataforma por lote: la cabecera no cambia durante un procesamiento
     * y `plataformaDelLote()` se llama una vez por llamada del lote.
     *
     * @var array<string, int>
     */
    private static array $plataformaPorLote = [];

    public function __construct() {
        self::$razones_finalizacion = DB::select("select * from razones_finalizacion");

        self::$log = new stdClass();
        self::$log->duplicados = [];
        self::$log->total_duplicados = 0;
        self::$log->total_llamadas = 0;
        self::$log->sin_conductor = 0;
    }

    public static function existe($id) {
        self::$log->total_llamadas++;
        $result= DB::select('SELECT vapi_id FROM `llamadas` where vapi_id=?', [$id]);
        if (count($result) == 1) {
            self::$log->duplicados[]= $id;
            self::$log->total_duplicados++;
            return true;
        }
        return false;
    }

    /**
     * Plataforma de origen del lote: 1 = Excel/VAPI, 2 = API de ElevenLabs.
     *
     * Se lee de `tmp_lotes.tipo`, la cabecera del lote (decision §12 del plan del
     * modulo): asi el pipeline sabe de donde vino cada llamada sin agregar
     * columnas al detalle. Si el lote no existe se asume VAPI, que es el caso de
     * todo el historico.
     */
    public static function plataformaDelLote($id_lote): int {
        $clave = (string) $id_lote;

        if (! array_key_exists($clave, self::$plataformaPorLote)) {
            $tipo = DB::table('tmp_lotes')->where('lote_id', $id_lote)->value('tipo');
            self::$plataformaPorLote[$clave] = ((int) $tipo) === 2 ? 2 : 1;
        }

        return self::$plataformaPorLote[$clave];
    }

    /**
     * ¿La llamada entro (la llamo el conductor) o salio (la llamo la IA)?
     *
     * `tmp_lotes_det.type` guarda el tipo de llamada del contrato legacy, en
     * mayusculas y sin separadores: `INBOUNDPHONECALL` / `OUTBOUNDPHONECALL`.
     * Se compara en mayusculas por si algun lote trae otra grafia.
     */
    public static function esEntrante($tipo): int {
        $tipo = strtoupper(trim((string) $tipo));

        return in_array($tipo, ['INBOUNDPHONECALL', 'INBOUND'], true) ? 1 : 0;
    }

    public static function importar_llamadas_de_tmp_al_sistema($id_trt,$id_conductor,$id_lote,$item) {
        if (self::existe($item->vapi_id)) return false; //obtener log , no continuar si no existe
        $llamada = new stdClass();
        $llamada->vapi_id = $item->vapi_id;
        $llamada->lote_id = $id_lote;
        $llamada->conductor_id = $id_conductor;
        if ($id_conductor ==='' or $id_conductor==null) {self::$log->sin_conductor++; return false;}

        $llamada->trt_id = ($id_trt !== 'null' && $id_trt !== '') ? $id_trt : null;
        $llamada->telefono= $item->telefono;
        $llamada->ref = $item->ref == '' ? null : $item->ref;

        //clasificar solo llamadas 0
        $tipol=$item->llamada_tipo;
        if ($tipol == 0) {
            if ($item->fecha_prometida) $tipol = 1;
            else if ($item->origen . $item->destino !='' ) $tipol = 3;
            else  $tipol = 2;
        }
        $llamada->llamada_tipo_id = $tipol;

        // Plataforma del lote (Etapa 6): 1 = Excel/VAPI, 2 = API de ElevenLabs.
        // Sale de `tmp_lotes.tipo`, que el modulo de ElevenLabs crea en 2.
        $llamada->id_plataforma = self::plataformaDelLote($id_lote);

        // El id explicito por plataforma: en ElevenLabs es su `conversation_id`
        // (el mismo valor de `vapi_id`, que es la llave de todo el sistema). En
        // VAPI se deja NULL: ahi `vapi_id` ya es el id nativo.
        $llamada->el_id = $llamada->id_plataforma === 2 ? $item->vapi_id : null;

        // Entrante o saliente segun `type` del detalle ("INBOUNDPHONECALL" en las
        // que llama el conductor, "OUTBOUNDPHONECALL" en las que llama la IA).
        // Antes estaba fijo en 0 y por eso todas las llamadas quedaban salientes.
        $llamada->es_entrante = self::esEntrante($item->type ?? '');
        $llamada->razon_finalizacion_id =self::obtener_id_razon($item->razon_finalizacion);

        if ($llamada->razon_finalizacion_id==4) $item->error_origen=2;
        else if ($llamada->razon_finalizacion_id==7 || $llamada->razon_finalizacion_id==9 || $llamada->razon_finalizacion_id==10)
            $item->error_origen=3;

        if ($item->analisis_transcripcion=='' && $item->analisis_audio=='' && self::etiqueta_valor($item->llamada_exitosa)==0 ) {
            if ($llamada->razon_finalizacion_id==0) $item->error_origen=-1;
        }


        $llamada->entro_llamada = $item->entro_llamada ? $item->entro_llamada : 0;

        $llamada->exitosa_segun_ia = $item->exitosa_segun_ia=='TRUE' ? 1 : 0;
        $llamada->llamada_exitosa = self::etiqueta_valor($item->llamada_exitosa);
        $llamada->audio_link = $item->audio;
        $llamada->audio_duracion = $item->audio_duracion ? $item->audio_duracion : 0 ;
        $llamada->analisis_transcripcion = $item->analisis_transcripcion;
        $llamada->analisis_audio = $item->analisis_audio;

        //ultimo agregado---------
        $llamada->costo = ((float) $item->costo) ?:0 ; //formatear decimal
        $llamada->ia_result_delay_reason_desc = $item->ia_result_delay_reason_desc;
        $llamada->ia_result_comments_text = $item->ia_result_comments_text;
        //------------------

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
        $llamada->ia_cuelga_en_plena_llamada = self::etiqueta_valor($item->ia_cuelga_en_plena_llamada);


        $llamada->conductor_cuelga = self::etiqueta_valor($item->conductor_cuelga);
        $llamada->conductor_no_contesta = self::etiqueta_valor($item->conductor_no_contesta);
        $llamada->conductor_conducta_inapropiada = self::etiqueta_valor($item->conductor_conducta_inapropiada);

        $llamada->error_tecnico_llamada = self::etiqueta_valor($item->error_tecnico_llamada);
        $llamada->error_audio = self::etiqueta_valor($item->error_audio);
        $llamada->error_origen = self::validar_error_origen($item->error_origen);

        $timestamp = (int) $item->created_at;
        $llamada->created_at = $timestamp /1000; //dejarlo en horario peruano utc-5

        $llamada->procesado= 1; //indica si la llamada fue etiquetada por un humano
        $llamada->fecha_prometida = self::fecha_string_o_numero($item->fecha_prometida);
        $llamada->origen= $item->origen;
        $llamada->destino = $item->destino;

        $llamada->placa = DBReferencias::verificar_placa($item->placa);
        $se_inserto=self::guardar_llamada($llamada);

        if ($se_inserto){
            self::guardar_mensajes($item->vapi_id,$item->mensajes_conten);

            // Puntaje del modelo para esa llamada. Ya tiene mensajes y tipo, asi
            // el veredicto aparece al abrirla en Etiquetar sin esperar el batch.
            // Si el scoring falla, la importacion no se frena.
            try {
                DBScoringLlamadas::puntuar_llamada($item->vapi_id);
            } catch (\Throwable $e) {
                // secundario: la llamada ya quedo importada
            }
        }

        return $se_inserto;
    }



    public static function fecha_string_o_numero($fecha) {
        // Si es null o vacío
        if ($fecha === null || $fecha === '' || $fecha === 'null') return null;
        // Caso 1: Es número de Excel (timestamp de Excel)
        if (is_numeric($fecha)) return DBReferencias::excel_time_a_timestamp($fecha);
        // Intentar diferentes formatos
        $formatos = [
            'd/m/Y H:i',       // 18/02/2026 12:00
            'd/m/Y H:i:s',     // 18/02/2026 12:00:00
        ];

        foreach ($formatos as $formato) {
            $date = DateTime::createFromFormat($formato, $fecha);
            if ($date){
                $date->modify('+5 hours');
                return $date->getTimestamp();
            }
        }
        return null;
    }

    public static function obtener_id_razon($name){
        $id=BuscarEnArray::cualquiera_std($name,'name',self::$razones_finalizacion);
        if ($id== false) return 0; //sin conflictos para mysql
        return $id->id;
    }

    /**
     * Normaliza el valor de una columna de etiqueta a 1 / 0.
     *
     * POR QUE NO ES UN CAST SIMPLE
     * En PHP el string "false" es VERDADERO: `if ("false")` entra. Con el codigo
     * anterior, un `tmp_lotes_det.buzon_de_voz = "false"` se guardaba como 1, o sea
     * exactamente lo contrario de lo que decia el dato. Ahora los textos "false",
     * "no" y "0" (en cualquier caja) se leen como 0 y "true"/"si"/"1" como 1.
     *
     * Cualquier otro texto no vacio sigue contando como 1, que es el
     * comportamiento historico del flujo de VAPI.
     */
    public static function etiqueta_valor($valor){
        if (is_string($valor)) {
            $texto = mb_strtolower(trim($valor));

            if ($texto === '' || $texto === '0' || $texto === 'false' || $texto === 'no') {
                return 0;
            }

            if ($texto === '1' || $texto === 'true' || $texto === 'si' || $texto === 'sí' || $texto === 'yes') {
                return 1;
            }
        }

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

            // Limite 2: el texto del turno puede tener dos puntos ("a las 3:53",
            // hora, etc.). Sin el limite, `explode(':')` devolvia 3+ partes y el
            // turno se descartaba entero, perdiendo el mensaje en silencio.
            $msg= explode(':', $mensaje, 2);
            if (count($msg) != 2) continue;
            $msg[0] = trim($msg[0]);
            $msg[1] = trim($msg[1]);

            if ($msg[0] != 'BOT' and $msg[0] != 'USER' ) continue;

            // MySQL convierte el UUID directamente con UUID_TO_BIN()
            DB::insert(
                "INSERT INTO mensajes (vapi_id, orden, tipo, mensaje)
                VALUES (?, ?, ?, ?)",
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
            ia_cuelga_en_plena_llamada,
            conductor_cuelga,
            conductor_no_contesta,
            conductor_conducta_inapropiada,
            error_tecnico_llamada,
            error_audio,
            error_origen,
            procesado,
            origen,
            destino,
            placa,

            costo,
            ia_result_delay_reason_desc,
            ia_result_comments_text,

            el_id,
            id_plataforma,
            fecha_prometida,
            created_at
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
            ?,?,
            FROM_UNIXTIME(?),
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
            $llamada->ia_cuelga_en_plena_llamada,
            $llamada->conductor_cuelga,
            $llamada->conductor_no_contesta,
            $llamada->conductor_conducta_inapropiada,
            $llamada->error_tecnico_llamada,
            $llamada->error_audio,
            $llamada->error_origen,
            $llamada->procesado,
            $llamada->origen,
            $llamada->destino,
            $llamada->placa,

            $llamada->costo,
            $llamada->ia_result_delay_reason_desc,
            $llamada->ia_result_comments_text,

            $llamada->el_id,
            $llamada->id_plataforma,
            $llamada->fecha_prometida,
            $llamada->created_at,



        ];

        return DB::insert($sql, $params);
    }

}
