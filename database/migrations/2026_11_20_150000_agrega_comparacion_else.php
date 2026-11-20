<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega la comparacion `else` ("en otro caso") al enum de comparaciones.
 *
 * `else` cumple SIEMPRE. Como para cada columna gana la primera regla que cumple
 * (por prioridad), una regla `else` funciona como "lo que no cubrio ninguna otra"
 * siempre que se le ponga prioridad alta, o sea al final.
 *
 * Se amplia el ENUM con SQL directo por el mismo motivo que `diferente`: agregar
 * el valor solo en `MapeoTraduccion::COMPARACIONES` hace que MySQL trunque el
 * valor al insertar ("Data truncated for column 'comparacion'").
 */
return new class extends Migration
{
    private const VALORES = "'igual','diferente','contiene','empieza_por','vacio','no_vacio','else'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE elevenlabs_mapeos_regla MODIFY comparacion '
            . 'ENUM(' . self::VALORES . ") NOT NULL DEFAULT 'igual'"
        );
    }

    public function down(): void
    {
        // Primero se dejan de usar los valores que desaparecen del enum
        DB::table('elevenlabs_mapeos_regla')
            ->where('comparacion', 'else')
            ->update(['comparacion' => 'no_vacio']);

        DB::statement(
            'ALTER TABLE elevenlabs_mapeos_regla MODIFY comparacion '
            . "ENUM('igual','diferente','contiene','empieza_por','vacio','no_vacio') NOT NULL DEFAULT 'igual'"
        );
    }
};
