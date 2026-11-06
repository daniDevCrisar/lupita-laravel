<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esperas compiladas y ciclo rapido por ruta.
     *
     * El tope de espera es UNO solo y se aplica a la espera total del viaje
     * (E2 + E3 + E5 + E6, fuera y dentro de planta, carga y descargue): su
     * valor es el p82 de la ruta, asi que descarta como maximo el 18 % de los
     * viajes con cadena completa.
     *
     *   - `tope_espera_min`      tope p82 aplicado (minutos).
     *   - `espera_descartados`   viajes que quedaron fuera por ese tope.
     *   - `prom_fuera_min`       espera media FUERA de planta (E2 + E5).
     *   - `prom_dentro_min`      espera media DENTRO de planta (E3 + E6).
     *   - `prom_ciclo_rapido_min` ciclo con el tope p50 de espera, para
     *                            comparar el escenario rapido con el promedio
     *                            (`prom_ciclo_min`, p82) y el tardado
     *                            (`prom_ciclo_sin_tope_min`).
     */
    public function up(): void
    {
        Schema::table('tiempos_ruta_promedio', function (Blueprint $table) {
            if (!Schema::hasColumn('tiempos_ruta_promedio', 'tope_espera_min')) {
                $table->integer('tope_espera_min')->nullable()->after('tope_dentro_min');
            }
            if (!Schema::hasColumn('tiempos_ruta_promedio', 'espera_descartados')) {
                $table->integer('espera_descartados')->default(0)->after('descartados_dentro');
            }
            if (!Schema::hasColumn('tiempos_ruta_promedio', 'prom_fuera_min')) {
                $table->decimal('prom_fuera_min', 8, 1)->nullable()->after('prom_ciclo_sin_tope_min');
            }
            if (!Schema::hasColumn('tiempos_ruta_promedio', 'prom_dentro_min')) {
                $table->decimal('prom_dentro_min', 8, 1)->nullable()->after('prom_fuera_min');
            }
            if (!Schema::hasColumn('tiempos_ruta_promedio', 'prom_ciclo_rapido_min')) {
                $table->decimal('prom_ciclo_rapido_min', 8, 1)->nullable()->after('prom_ciclo_min');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tiempos_ruta_promedio', function (Blueprint $table) {
            $table->dropColumn([
                'tope_espera_min',
                'espera_descartados',
                'prom_fuera_min',
                'prom_dentro_min',
                'prom_ciclo_rapido_min',
            ]);
        });
    }
};
