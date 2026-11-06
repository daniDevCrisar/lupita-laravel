<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo promedio y topes de espera por ruta en `tiempos_ruta_promedio`.
     *
     * Los dos topes de espera son independientes del tope de recorrido:
     *   - `tope_fuera_min`   espera FUERA de planta: E2 (presenta -> inicio de
     *                        carga) y E5 (llegada a destino -> inicio de
     *                        descargue). NULL = sin tope.
     *   - `tope_dentro_min`  tiempo DENTRO de planta: E3 (inicio de carga ->
     *                        inicio de ruta) y E6 (inicio de descargue -> fin
     *                        de descargue). NULL = sin tope.
     *
     * `prom_ciclo_min` es el ciclo completo (E2..E6) con los topes aplicados y
     * `prom_ciclo_sin_tope_min` el mismo ciclo sin descartar ningun viaje, para
     * comparar; `ciclo_viajes` cuenta los viajes con la cadena completa de
     * hitos que sostienen ese promedio. `descartados_fuera` y
     * `descartados_dentro` dicen cuantos viajes se pierden por cada tope de
     * espera (excluyentes: primero fuera, despues dentro, por ultimo recorrido).
     */
    public function up(): void
    {
        Schema::table('tiempos_ruta_promedio', function (Blueprint $table) {
            $table->integer('tope_fuera_min')->nullable()->after('tope_min');
            $table->integer('tope_dentro_min')->nullable()->after('tope_fuera_min');
            $table->integer('descartados_fuera')->default(0)->after('descartados');
            $table->integer('descartados_dentro')->default(0)->after('descartados_fuera');
            $table->decimal('prom_ciclo_min', 8, 1)->nullable()->after('prom_sin_tope_min');
            $table->decimal('prom_ciclo_sin_tope_min', 8, 1)->nullable()->after('prom_ciclo_min');
            $table->integer('ciclo_viajes')->default(0)->after('prom_ciclo_sin_tope_min');
        });
    }

    public function down(): void
    {
        Schema::table('tiempos_ruta_promedio', function (Blueprint $table) {
            $table->dropColumn([
                'tope_fuera_min',
                'tope_dentro_min',
                'descartados_fuera',
                'descartados_dentro',
                'prom_ciclo_min',
                'prom_ciclo_sin_tope_min',
                'ciclo_viajes',
            ]);
        });
    }
};
