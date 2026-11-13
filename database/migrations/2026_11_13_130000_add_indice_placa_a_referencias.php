<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice por placa para el módulo de predicción (y para cualquier consulta
     * que mire los viajes de un camión, como la ficha de comportamiento).
     *
     * `referencias` tenía sólo PRIMARY(ref) e idx_ruta_id: `refs_por_placa()` y
     * los pares por placa hacían full scan de 32k filas cada vez, y
     * `Servicio::predecir()` medía 1,5 s por llamada. La segunda columna ordena
     * dentro de la placa, que es como se recorren los viajes.
     */
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->index(['placa', 'presenta_para_carga'], 'idx_referencias_placa_presenta');
        });
    }

    public function down(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->dropIndex('idx_referencias_placa_presenta');
        });
    }
};
