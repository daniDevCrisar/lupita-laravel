<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de equivalencias agente de ElevenLabs -> tipo de llamada de Lupita.
 *
 * QUE RESUELVE
 * El flujo de VAPI traia el tipo de llamada hardcodeado en JS: `procesar_json.js`
 * comparaba `item.assistantId` contra 5 UUIDs literales (lineas 88-94). Con 11
 * agentes en el workspace de ElevenLabs y un mapa que el usuario va a querer
 * cambiar sin tocar codigo, el mapa pasa a ser data.
 *
 * DOMINIO DE `tipo_llamada_id`
 * Sale de la tabla `tipos_llamada` (la misma que usan ReportesController y
 * DBLlamadas::$tipos_llamada), NO de una lista en el codigo. Hoy tiene:
 *   1 = Confirmacion
 *   2 = Fuera de planta para carga
 *   3 = Dentro de planta para carga
 *   4 = En ruta
 *   5 = Fuera de planta para descarga
 *   6 = Dentro de planta para descarga
 *   NULL = no entra al lote (chats, hoja de vida, agentes de prueba)
 * Se guarda el entero y el nombre se resuelve contra esa tabla al pintar.
 *
 * POR QUE `incluir_en_lote` APARTE DE `tipo_llamada_id`
 * Un agente puede tener tipo asignado y aun asi quedar fuera del lote mientras el
 * usuario valida el mapeo. Son dos preguntas distintas: "que tipo es" y "entra".
 *
 * `agent_id` es UNIQUE porque la API garantiza un id por agente y es la llave con
 * la que se cruza el listado y el detalle de conversaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elevenlabs_agentes', function (Blueprint $table) {
            $table->id();

            $table->string('agent_id', 64)->unique()
                ->comment('agent_... tal como lo devuelve /v1/convai/agents');

            $table->string('nombre', 150)->default('')
                ->comment('nombre del agente, sincronizado desde la API');

            $table->unsignedTinyInteger('tipo_llamada_id')->nullable()
                ->comment('1,2,3,5,6 del enum de tipos; NULL = no entra al lote');

            $table->boolean('incluir_en_lote')->default(false)
                ->comment('entra en la importacion al lote');

            $table->boolean('activo')->default(true)
                ->comment('0 si el agente se archivo o ya no se usa');

            $table->timestamps();

            $table->index('tipo_llamada_id', 'idx_el_agentes_tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevenlabs_agentes');
    }
};
