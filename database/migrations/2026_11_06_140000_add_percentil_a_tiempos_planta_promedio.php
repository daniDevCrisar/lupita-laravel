<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Percentil con el que quedo calibrado el tope recomendado de cada planta.
 * Puede ser mayor que 82 cuando el descarte conjunto de fuera + dentro pasaba
 * del 18 % de los lados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiempos_planta_promedio', function (Blueprint $table) {
            $table->unsignedSmallInteger('percentil')->nullable()->after('prom_espera_min');
        });
    }

    public function down(): void
    {
        Schema::table('tiempos_planta_promedio', function (Blueprint $table) {
            $table->dropColumn('percentil');
        });
    }
};
