<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Puntaje guardado de cada llamada, para calcularlo una sola vez.
     *
     * Llave sobre `vapi_id` con upsert: la pasada siguiente refresca la misma
     * fila en vez de acumular versiones. `pesos_version` es el md5 de la tabla de
     * pesos al momento de calcular; si el usuario edita un peso, la version cambia
     * y la vista de evaluacion avisa que los puntajes estan obsoletos en lugar de
     * recalcularlos sola.
     *
     * `humano_exitosa` es LA FOTO de `llamadas.llamada_exitosa` al calcular: sirve
     * para comparar sin que un reetiquetado posterior mueva el historial. Aqui no
     * se escribe en `llamadas`, solo se lee.
     */
    public function up(): void
    {
        Schema::create('scoring_llamadas', function (Blueprint $table) {
            $table->string('vapi_id', 36)->primary();
            $table->unsignedSmallInteger('llamada_tipo_id')->nullable();
            $table->boolean('humano_exitosa')->nullable();
            $table->decimal('base_pct', 5, 2)->nullable()->comment('base medida del tipo en el rango');
            $table->decimal('puntaje', 5, 2)->nullable()->comment('0-100, NULL cuando no aplica');
            $table->decimal('logit', 8, 4)->nullable();
            $table->json('disparos')->nullable()->comment('[{palabra, gram, peso}]');
            $table->string('motivo', 16)->default('ok')->comment('ok | buzon | sin_texto');
            $table->string('pesos_version', 32)->nullable();
            $table->string('rango', 24)->nullable()->comment('fecha_inicio_fecha_fin del calculo');
            $table->timestamps();

            $table->index('pesos_version', 'idx_scoring_version');
            $table->index(['llamada_tipo_id', 'puntaje'], 'idx_scoring_tipo');
            $table->index('motivo', 'idx_scoring_motivo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_llamadas');
    }
};
