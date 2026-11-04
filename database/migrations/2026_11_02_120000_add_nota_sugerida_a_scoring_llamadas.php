<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sugerencia de analisis de audio, calculada con las notas que ya escribieron
     * los humanos (`llamadas.analisis_audio`).
     *
     * Son tres columnas de SOLO LECTURA para el revisor: se guardan al lado del
     * puntaje porque nacen del mismo momento (el veredicto del modelo) y con los
     * mismos insumos (los `disparos` de esa llamada), pero NO se copian a
     * `llamadas.analisis_audio`. Quien escribe esa nota sigue siendo el humano.
     *
     * `nota_sugerida_n` es cuantas llamadas con esa nota compartieron alguna de
     * las palabras que dispararon, y `nota_sugerida_total` cuantas llamadas la
     * tienen en total: la vista los muestra juntos ("5 de 42") para que se vea la
     * fuerza de la sugerencia. Solo hay sugerencia si la llamada tuvo palabras con
     * peso que dispararon: sin texto del conductor, el modelo calla.
     */
    public function up(): void
    {
        Schema::table('scoring_llamadas', function (Blueprint $table) {
            $table->string('nota_sugerida', 120)->nullable()
                ->comment('nota humana mas parecida; sugerencia, nunca se copia a llamadas');
            $table->unsignedSmallInteger('nota_sugerida_n')->nullable()
                ->comment('llamadas con esa nota que compartieron los disparos');
            $table->unsignedSmallInteger('nota_sugerida_total')->nullable()
                ->comment('llamadas que tienen esa nota en total');
        });
    }

    public function down(): void
    {
        Schema::table('scoring_llamadas', function (Blueprint $table) {
            $table->dropColumn(['nota_sugerida', 'nota_sugerida_n', 'nota_sugerida_total']);
        });
    }
};
