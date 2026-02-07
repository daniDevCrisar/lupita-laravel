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
            'error_tecnico_llamada',
            'error_audio',
            'error_origen',
            'llamada_exitosa'
        ];
    }
}
