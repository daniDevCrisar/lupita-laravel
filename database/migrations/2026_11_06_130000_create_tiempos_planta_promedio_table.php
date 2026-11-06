<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promedios de espera por PLANTA (local), en tabla aparte de los promedios
     * de ruta.
     *
     * Cada planta puede aparecer como planta de carga (origen de la ruta) y
     * como planta de descarga (destino), asi que la fila fusiona los dos roles:
     * las columnas `*_carga_min` miden E2/E3 y las `*_descarga_min` miden E5/E6,
     * mientras `prom_fuera_min` y `prom_dentro_min` son el promedio ponderado
     * por viajes de ambos roles.
     *
     * `p82_fuera_min` y `p82_dentro_min` son el tope recomendado de la planta:
     * el percentil 82 de su espera, que deja como maximo 18 % de los viajes por
     * encima. `tope_fuera_min` / `tope_dentro_min` guardan el tope con el que
     * se compilo la planta, que es el que se vuelve a aplicar al recompilar.
     */
    public function up(): void
    {
        Schema::create('tiempos_planta_promedio', function (Blueprint $table) {
            $table->string('planta_id', 50)->primary();
            $table->string('nombre', 100)->nullable();
            $table->string('palabra_clave', 100)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('departamento', 60)->nullable();
            $table->string('modo', 20)->default('recomendado'); // recomendado | global | propio
            $table->integer('rutas_carga')->default(0);
            $table->integer('rutas_descarga')->default(0);
            $table->integer('viajes_carga')->default(0);
            $table->integer('viajes_descarga')->default(0);
            $table->integer('viajes')->default(0);              // lados medibles (carga + descarga)
            $table->integer('descartados_fuera')->default(0);
            $table->integer('descartados_dentro')->default(0);
            $table->decimal('prom_fuera_carga_min', 8, 1)->nullable();     // E2
            $table->decimal('prom_dentro_carga_min', 8, 1)->nullable();    // E3
            $table->decimal('prom_fuera_descarga_min', 8, 1)->nullable();  // E5
            $table->decimal('prom_dentro_descarga_min', 8, 1)->nullable(); // E6
            $table->decimal('prom_fuera_min', 8, 1)->nullable();           // fusionado
            $table->decimal('prom_dentro_min', 8, 1)->nullable();          // fusionado
            $table->decimal('prom_espera_min', 8, 1)->nullable();          // fuera + dentro
            $table->integer('p82_fuera_min')->nullable();
            $table->integer('p82_dentro_min')->nullable();
            $table->integer('tope_fuera_min')->nullable();
            $table->integer('tope_dentro_min')->nullable();
            $table->timestamp('actualizado_en')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiempos_planta_promedio');
    }
};
