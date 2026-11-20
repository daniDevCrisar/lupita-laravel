<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad de la llamada por plataforma (modulo Eleven Labs).
 *
 * QUE HACE
 *  - `el_id`: el `conversation_id` de ElevenLabs. Es el dato nuevo que pedia el
 *    requerimiento para comparar llamadas entre plataformas.
 *  - `id_plataforma`: 1 = VAPI, 2 = ElevenLabs. Filtra/segmenta sin mirar el
 *    prefijo del id.
 *  - ensancha `vapi_id` de 36 a 64.
 *
 * POR QUE `vapi_id` SIGUE GUARDANDO EL ID DE ELEVENLABS
 * `vapi_id` no es un dato de VAPI: es la llave de todo el sistema. Es PRIMARY KEY
 * de `llamadas` y de `scoring_llamadas`, y `mensajes`, `DBLlamadas` (?ids=),
 * `DBPalabrasLlamadas`, `DBConsultarReferencias` (GROUP_CONCAT para abrir el viaje
 * desde el mapa) y el chat de Etiquetar consultan por esa columna. Si las llamadas
 * de ElevenLabs entraran solo en `el_id`, quedarian invisibles para scoring, chat,
 * mapa y reportes SIN dar ningun error. Por eso se escribe el mismo valor en
 * `vapi_id` y en `el_id`; `el_id` queda como la columna semantica por plataforma.
 *
 * POR QUE 64 Y NO 36
 * Los `conversation_id` que devuelve la API hoy miden 33 chars (y 35 el ejemplo de
 * la documentacion): entran en 36 por un caracter. 64 deja aire si ElevenLabs
 * cambia el formato y no obliga a otra migracion.
 *
 * `el_id` NO es unique: las 42.238 filas historicas de VAPI quedan en NULL y MySQL
 * permite muchos NULL en un indice unico, pero se usa indice normal porque la
 * consulta real es por igualdad exacta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llamadas', function (Blueprint $table) {
            $table->string('vapi_id', 64)->change();

            $table->string('el_id', 64)->nullable()->after('vapi_id')
                ->comment('conversation_id de ElevenLabs; NULL en las llamadas de VAPI');

            $table->unsignedTinyInteger('id_plataforma')->default(1)->after('el_id')
                ->comment('1 = VAPI, 2 = ElevenLabs');

            $table->index('el_id', 'idx_llamadas_el_id');
            $table->index('id_plataforma', 'idx_llamadas_id_plataforma');
        });
    }

    public function down(): void
    {
        Schema::table('llamadas', function (Blueprint $table) {
            $table->dropIndex('idx_llamadas_el_id');
            $table->dropIndex('idx_llamadas_id_plataforma');
            $table->dropColumn(['el_id', 'id_plataforma']);
            $table->string('vapi_id', 36)->change();
        });
    }
};
