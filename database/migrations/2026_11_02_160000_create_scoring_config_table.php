<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parametros de la sugerencia de analisis de audio, configurables desde la
     * pantalla de Pesos (antes eran constantes fijas en el codigo).
     *
     * `nota_min` es la fuerza minima de apoyo (la suma de los |peso| de las
     * palabras que dispararon) para que una nota sea siquiera sugerible: subirla
     * da sugerencias mas finas pero menos. `nota_top` es cuantas notas distintas
     * del humano entran a la co-ocurrencia.
     *
     * La clase los lee una vez por request y, si la tabla todavia no existe en el
     * servidor (aqui no hay consola y el CREATE TABLE se aplica a mano), usa los
     * mismos valores por defecto de esta migracion: calcular nunca revienta.
     */
    public function up(): void
    {
        Schema::create('scoring_config', function (Blueprint $table) {
            $table->string('clave', 32)->primary();
            $table->string('valor', 32);
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });

        DB::table('scoring_config')->insert([
            ['clave' => 'nota_min', 'valor' => '3', 'updated_at' => now()],
            ['clave' => 'nota_top', 'valor' => '200', 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_config');
    }
};
