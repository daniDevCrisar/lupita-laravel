<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de predicciones (pantalla y API).
     *
     * Guarda las TRES fechas (rápido / normal / tardado) y con qué evidencia se
     * dijeron. Sirve para responder "¿qué dijimos de esta ref y cuándo?" y para
     * medir el acierto real: cuando la referencia consigue su presentación, se
     * compara contra lo guardado (ver App\Database\DBPrediccionResultados).
     */
    public function up(): void
    {
        Schema::create('prediccion_resultados', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 10);
            $table->string('placa', 10)->nullable();
            $table->string('ruta_id', 100)->nullable();
            $table->timestamp('ahora');
            $table->timestamp('presenta')->nullable();
            $table->timestamp('presenta_rapido')->nullable();
            $table->timestamp('presenta_tardado')->nullable();
            $table->timestamp('inicio_carga')->nullable();
            $table->string('evidencia', 20)->default('sin_datos');
            $table->smallInteger('confianza')->default(0);
            $table->string('origen', 10)->default('api');
            $table->json('desglose')->nullable();
            $table->json('advertencias')->nullable();
            $table->timestamp('pedido_en')->useCurrent();

            $table->index(['ref', 'pedido_en'], 'idx_predres_ref');
            $table->index('pedido_en', 'idx_predres_pedido');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediccion_resultados');
    }
};
