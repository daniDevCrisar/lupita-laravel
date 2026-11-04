<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promedios compilados de la etapa de ruta (inicio de ruta -> llegada a destino).
     *
     * `tiempos_ruta_promedio` guarda el promedio de cada ruta en su sentido, con el
     * tope que se uso al compilar: la ventana es toda la data, asi que el numero no
     * depende de los filtros de la pantalla. `viajes` cuenta solo los que quedaron
     * dentro del tope; `descartados` dice cuantos viajes se pierden por ese tope, y
     * `prom_sin_tope_min` permite comparar ambos.
     *
     * `prom_fusion_min` no se calcula sobre los viajes: es el promedio de los dos
     * promedios ya compilados del mismo par de ubicaciones (ida y vuelta), tal como
     * se pidio, para no mezclar las dos distribuciones.
     *
     * `tiempos_ruta_horario` guarda el promedio por dia de la semana y hora de
     * inicio de ruta. Solo se compilan las rutas que aportan un promedio: una ruta
     * sin datos no se crea ni se sobrescribe.
     */
    public function up(): void
    {
        Schema::create('tiempos_ruta_promedio', function (Blueprint $table) {
            $table->string('ruta_id', 100)->primary();
            $table->string('origen_id', 50)->nullable();
            $table->string('destino_id', 50)->nullable();
            $table->string('modo', 10)->default('global');   // global | ruta
            $table->integer('tope_min');
            $table->integer('viajes_total')->default(0);     // medibles sin tope
            $table->integer('viajes')->default(0);           // dentro del tope
            $table->integer('descartados')->default(0);
            $table->decimal('prom_min', 8, 1)->nullable();
            $table->decimal('prom_sin_tope_min', 8, 1)->nullable();
            $table->integer('mediana_min')->nullable();
            $table->integer('p25_min')->nullable();
            $table->integer('p75_min')->nullable();
            $table->decimal('desv_min', 8, 1)->nullable();
            $table->decimal('prom_fusion_min', 8, 1)->nullable();
            $table->integer('viajes_fusion')->nullable();
            $table->timestamp('actualizado_en')->nullable()->useCurrent();
        });

        Schema::create('tiempos_ruta_horario', function (Blueprint $table) {
            $table->id();
            $table->string('ruta_id', 100);
            $table->unsignedTinyInteger('dia');    // WEEKDAY(): 0 = lunes
            $table->unsignedTinyInteger('hora');   // HOUR(inicio_ruta)
            $table->integer('tope_min');
            $table->integer('viajes');
            $table->decimal('prom_min', 8, 1);
            $table->unique(['ruta_id', 'dia', 'hora'], 'tiempos_ruta_horario_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiempos_ruta_horario');
        Schema::dropIfExists('tiempos_ruta_promedio');
    }
};
