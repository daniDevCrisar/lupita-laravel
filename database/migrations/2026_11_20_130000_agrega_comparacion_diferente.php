<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega la comparacion `diferente` al enum de `elevenlabs_mapeos_regla`.
 *
 * `comparacion` es un ENUM, no un varchar: agregar la opcion en
 * `MapeoTraduccion::COMPARACIONES` no basta, MySQL trunca el valor al insertar
 * ("Data truncated for column 'comparacion'").
 *
 * Se usa SQL directo para que el enum quede EXACTAMENTE con el mismo orden,
 * nulabilidad y default que tenia (`MODIFY` conserva el resto del tipo).
 */
return new class extends Migration
{
    private const VALORES = "'igual','diferente','contiene','empieza_por','vacio','no_vacio'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE elevenlabs_mapeos_regla MODIFY comparacion '
            . 'ENUM(' . self::VALORES . ") NOT NULL DEFAULT 'igual'"
        );
    }

    public function down(): void
    {
        // Antes de sacar el valor del enum hay que dejar de usarlo, si no MySQL
        // trunca las filas que lo tengan.
        DB::table('elevenlabs_mapeos_regla')
            ->where('comparacion', 'diferente')
            ->update(['comparacion' => 'igual']);

        DB::statement(
            'ALTER TABLE elevenlabs_mapeos_regla MODIFY comparacion '
            . "ENUM('igual','contiene','empieza_por','vacio','no_vacio') NOT NULL DEFAULT 'igual'"
        );
    }
};
