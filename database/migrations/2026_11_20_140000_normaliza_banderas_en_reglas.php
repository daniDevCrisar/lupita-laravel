<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza `error_tecnico_llamada` de "1" a "true" en las reglas.
 *
 * QUE PASABA
 * Cuando las banderas (`buzon_de_voz`, `error_tecnico_llamada`, `conductor_cuelga`,
 * `conductor_no_contesta`) se limitaron a dos opciones de texto —`true` / `false`—
 * la regla sembrada de `error_tecnico_llamada` seguia guardada con `1`. El motor
 * la aplicaba bien (`DBTmpLlamadas::etiqueta_valor()` acepta `1` y `true`), pero
 * el desplegable de la pestaña Reglas la mostraba como
 * "1 (fuera de la lista)", que parece un error y no lo es.
 *
 * Aqui se deja el valor dentro del dominio para que el desplegable lo muestre
 * normal. El comportamiento del pipeline no cambia: `etiqueta_valor()` lee las
 * dos formas igual.
 */
return new class extends Migration
{
    /** columnas de bandera cuyo dominio es true / false */
    private const BANDERAS = ['error_tecnico_llamada', 'conductor_cuelga', 'conductor_no_contesta'];

    public function up(): void
    {
        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->where('valor_destino', '1')
            ->update(['valor_destino' => 'true', 'updated_at' => now()]);

        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->where('valor_destino', '0')
            ->update(['valor_destino' => 'false', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->where('valor_destino', 'true')
            ->update(['valor_destino' => '1', 'updated_at' => now()]);

        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->where('valor_destino', 'false')
            ->update(['valor_destino' => '0', 'updated_at' => now()]);
    }
};
