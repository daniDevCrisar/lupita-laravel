<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pesos de las palabras y frases que puntuan una llamada.
     *
     * `peso` vive en LOGIT, no en puntos porcentuales: el puntaje de una llamada
     * es logit(base del tipo) + suma de pesos, asi nunca se sale de 0-100 y dos
     * palabras optimistas no se saturan. 0,65 ≈ duplica la posibilidad.
     *
     * `llamada_tipo_id` NULL = regla global. Si el tipo tiene su propia fila para
     * la misma palabra, la del tipo gana (ajuste local sobre el generico).
     *
     * Ojo: en MySQL un unico con columna nullable no impide dos filas globales
     * iguales; el desdoblamiento lo evita DBScoringLlamadas::guardar_pesos().
     */
    public function up(): void
    {
        Schema::create('palabras_pesos', function (Blueprint $table) {
            $table->id();
            $table->string('palabra', 80);
            $table->unsignedTinyInteger('gram')->default(1)->comment('1 palabra suelta, 2-5 frase');
            $table->unsignedSmallInteger('llamada_tipo_id')->nullable();
            $table->decimal('peso', 6, 3)->default(0);
            $table->boolean('activa')->default(true);
            $table->string('origen', 12)->default('manual')->comment('manual | sugerida');
            $table->string('nota', 190)->nullable();
            $table->unsignedBigInteger('creada_por')->nullable();
            $table->unsignedBigInteger('actualizada_por')->nullable();
            $table->timestamps();

            $table->unique(['palabra', 'gram', 'llamada_tipo_id'], 'palabras_pesos_unico');
            $table->index(['llamada_tipo_id', 'activa'], 'idx_pesos_tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palabras_pesos');
    }
};
