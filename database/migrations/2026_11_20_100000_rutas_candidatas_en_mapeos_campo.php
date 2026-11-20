<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ruta_origen` pasa de una ruta a una LISTA de rutas candidatas.
 *
 * POR QUE
 * Un mismo dato de negocio (`ref`, `conductor`, `placa`...) puede venir en
 * lugares distintos segun el asistente y segun la version del agente:
 * `carrier_name` hoy, `nombre_transportista` en el flujo de VAPI, `driver_name`
 * en otro. En vez de una configuracion por asistente, el campo guarda **varias
 * rutas, una por linea, y se prueban en orden**: la primera que trae un valor no
 * vacio gana. Las rutas que no existen en el JSON simplemente se saltan.
 *
 * `text` y no `varchar(200)`: una cadena de 5-6 rutas largas
 * (`conversation_initiation_client_data.dynamic_variables.*`) pasa de 200.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elevenlabs_mapeos_campo', function (Blueprint $table) {
            $table->text('ruta_origen')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('elevenlabs_mapeos_campo', function (Blueprint $table) {
            $table->string('ruta_origen', 200)->nullable()->change();
        });
    }
};
