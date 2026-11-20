<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalencias de traduccion ElevenLabs -> contrato del lote (`tmp_lotes_det`).
 *
 * POR QUE EXISTEN ESTAS TABLAS
 * La traduccion del JSON de ElevenLabs al shape que espera `tmp_lotes_det`
 * (el mismo que llenaba el Excel de VAPI) tiene 51 columnas. Meter las rutas en
 * el codigo obliga a un deploy cada vez que ElevenLabs renombra o agrega una
 * variable del negocio (`carrier_name`, `trip_code`, `origen`...). Con estas dos
 * tablas, eso se cambia desde la pantalla de Equivalencias.
 *
 * QUE NO SE CONFIGURA
 * 7 columnas quedan como `tipo_origen = calculado` porque no son una lectura de
 * campo sino un calculo: `lote_id` (se genera), `vapi_id` (es el conversation_id),
 * `llamada_tipo` (sale del catalogo de asistentes), `mensajes_conten` (se arma
 * recorriendo el transcript), `audio` (ruta propia del MP3),
 * `analisis_transcripcion` y `conductor_contesta_pero_no_habla` (analizador de
 * transcripcion). Esas filas existen para que la pantalla muestre el contrato
 * completo, pero se guardan bloqueadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Pieza A: columna destino <- ruta del JSON (o constante) ----------
        Schema::create('elevenlabs_mapeos_campo', function (Blueprint $table) {
            $table->id();

            $table->string('campo_destino', 64)->unique()
                ->comment('columna de tmp_lotes_det segun DBColumns::tmpLotesDet()');

            $table->enum('tipo_origen', ['campo', 'constante', 'regla', 'calculado'])
                ->default('campo')
                ->comment('campo = leer una ruta | constante = valor fijo | regla = lo resuelve elevenlabs_mapeos_regla | calculado = codigo');

            $table->string('ruta_origen', 200)->nullable()
                ->comment('ruta con puntos dentro del JSON de detalle, ej. metadata.call_duration_secs');

            $table->string('valor_constante', 255)->nullable()
                ->comment('valor fijo cuando tipo_origen = constante');

            $table->string('transformacion', 30)->default('ninguna')
                ->comment('ninguna|texto|numero|telefono|segundos_a_ms|mayusculas|minusculas|truncar_255|json|fecha_texto');

            $table->string('valor_por_defecto', 255)->nullable()
                ->comment('se usa cuando la ruta no existe o viene vacia');

            $table->unsignedSmallInteger('orden')->default(0)
                ->comment('orden de presentacion; replica el orden de DBColumns::tmpLotesDet()');

            $table->string('descripcion', 255)->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        // ---- Pieza B: reglas disparador -> efecto -----------------------------
        Schema::create('elevenlabs_mapeos_regla', function (Blueprint $table) {
            $table->id();

            $table->string('ruta_origen', 200)
                ->comment('ruta del valor disparador, ej. metadata.termination_reason');

            $table->enum('comparacion', ['igual', 'contiene', 'empieza_por', 'vacio', 'no_vacio'])
                ->default('igual');

            $table->string('valor_origen', 255)->nullable()
                ->comment('NULL en vacio / no_vacio');

            $table->string('campo_destino', 64)
                ->comment('columna de tmp_lotes_det que se escribe');

            $table->string('valor_destino', 255)
                ->comment('valor a escribir; en razon_finalizacion debe ser un name exacto de razones_finalizacion');

            $table->integer('prioridad')->default(100)
                ->comment('menor = se evalua antes; para la misma columna gana la primera que cumple');

            $table->string('descripcion', 255)->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index(['campo_destino', 'prioridad'], 'idx_el_reglas_campo_prioridad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevenlabs_mapeos_regla');
        Schema::dropIfExists('elevenlabs_mapeos_campo');
    }
};
