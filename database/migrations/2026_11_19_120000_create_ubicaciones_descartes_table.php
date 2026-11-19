<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descartes de candidatos de /lupita/ubicaciones.
 *
 * Solo guarda lo que decide el humano (descartar un candidato). El analisis se
 * recalcula siempre desde `referencias` + `locales`: si se guardara "creado" o
 * "resuelto", el catalogo cambiaria y las filas quedarian mintiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicaciones_descartes', function (Blueprint $table) {
            $table->char('id', 32)->primary()->comment('md5(tipo|clave normalizada)');
            $table->string('tipo', 20)->comment('palabra | fragmento');
            $table->string('clave', 500)->comment('texto original, para leerlo en SQL');
            $table->string('motivo', 255)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones_descartes');
    }
};
