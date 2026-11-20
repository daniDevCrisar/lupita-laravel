<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza a MAYUSCULAS los valores de la columna `type` (Etapa 6).
 *
 * POR QUE
 * El contrato legacy (`tmp_lotes_det.type`) guarda el tipo de llamada pegado,
 * sin separadores y en mayusculas: las 42.238 filas historicas de VAPI dicen
 * `OUTBOUNDPHONECALL`. Las reglas del modulo se sembraron con la forma de la API
 * de ElevenLabs (`outboundPhoneCall`), asi que quedaban dos formatos para el
 * mismo dato segun el lote.
 *
 * Ademas el pipeline (Etapa 6) ahora deriva `llamadas.es_entrante` de esta
 * columna comparando contra `INBOUNDPHONECALL`. El codigo compara en mayusculas
 * (`DBTmpLlamadas::esEntrante()`), asi que esto es consistencia de datos, no un
 * requisito duro; se hace igual para que la columna signifique lo mismo en todo
 * el historico.
 *
 * No toca prioridades, columnas destino ni ningun otro valor de las reglas.
 *
 * OJO CON LA COLACION
 * La primera version filtraba con `valor_destino <> UPPER(valor_destino)` y no
 * actualizaba nada: las columnas son `utf8mb4_*_ci`, asi que MySQL considera
 * `'outboundPhoneCall' = 'OUTBOUNDPHONECALL'` y el `<>` daba falso siempre.
 * Por eso el UPDATE no lleva filtro de diferencia: `UPPER()` sobre un valor ya
 * en mayusculas no cambia nada y el UPDATE queda idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE elevenlabs_mapeos_regla SET valor_destino = UPPER(valor_destino), updated_at = NOW() WHERE campo_destino = 'type'");
    }

    public function down(): void
    {
        // Sin vuelta atras: el valor original en camelCase no aportaba nada y no
        // hay forma de saber cuales filas edito el usuario a mano.
    }
};
