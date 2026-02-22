<?php

namespace App\Database;

class DBColumns
{
    public static function tmpLotesDet()
    {
        return [
            'lote_id',
            'vapi_id',
            'type',
            'created_at',
            'created_at_excel',
            'llamada_tipo',
            'ref',
            'origen',
            'destino',
            'telefono',
            'conductor',
            'placa',
            'fecha_prometida',
            'mensajes_conten',
            'audio',
            'audio_duracion',
            'exitosa_segun_ia',
            'entro_llamada',
            'razon_finalizacion',
            'razon_finalizacion_espanol',
            'transportista',
            'analisis_transcripcion',
            'analisis_audio',
            'conductor_confirma',
            'buzon_de_voz',
            'conductor_contesta_pero_no_habla',
            'conductor_no_escucha',
            'conductor_da_motivos',
            'conductor_mala_senal',
            'confusion_en_llamada',
            'contesta_otra_persona',
            'numero_equivocado',
            'conversacion_fluida',
            'llamada_interesante',
            'ia_se_confunde',
            'ia_no_escucha',
            'ia_cambio_de_datos',
            'ia_error_interpretacion',
            'ia_dice_variable',
            'ia_mala_pronunciacion',
            'conductor_cuelga',
            'conductor_no_contesta',
            'conductor_conducta_inapropiada',
            'error_tecnico_llamada',
            'error_audio',
            'error_origen',
            'llamada_exitosa'
        ];
    }
    public static function tmp_lotes_ref(){
        return [
            'lote_id',
            'ref',
            'trt',
            'tlf_conductor',
            'titulo_viaje',
            'placa',
            'fin_descargue',
            'inicio_descargue',
            'qr_llegada_destino',
            'fin_de_carga',
            'inicio_de_carga',
            'presenta_para_carga'
        ];
    }

    public static function tmp_lotes_ref_compromiso(){
        return [
            'lote_id',
            'ref',
            'fecha_llamada',
            'trt',
            'tlf_conductor',
            'fecha_despachador',
            'titulo_viaje',
            'placa',
            'fin_descargue',
            'inicio_descargue',
            'qr_llegada_destino',
            'fin_de_carga',
            'inicio_de_carga',
            'presenta_para_carga'
        ];
    }
}