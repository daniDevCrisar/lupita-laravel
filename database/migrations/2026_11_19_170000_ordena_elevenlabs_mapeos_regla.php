<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Endurece `elevenlabs_mapeos_regla`.
 *
 * QUE ARREGLA
 * 1. `clave` estable. El seeder casaba las reglas por (ruta_origen, comparacion,
 *    valor_origen, campo_destino). Eso se rompe en cuanto cambia uno de esos
 *    cuatro: en vez de actualizar, inserta un duplicado. Quedo comprobado: habia
 *    dos reglas identicas de `buzon_de_voz`. Con `clave` (solo en las sembradas,
 *    NULL en las que crea el usuario) el seeder es idempotente de verdad.
 *
 * 2. Reglas sobre columnas que ya no son de tipo regla. Cuando `exitosa_segun_ia`
 *    paso de `regla` a `campo` (el veredicto lo manda ElevenLabs en
 *    `analysis.call_successful`; solo se adapta el formato), sus dos reglas
 *    quedaron huerfanas: inertes para el resolvedor, pero visibles y confusas en
 *    la pantalla. Una regla solo tiene sentido si su columna es de tipo regla.
 *
 * 3. Deduplicacion por clave natural, conservando la de menor id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elevenlabs_mapeos_regla', function (Blueprint $table) {
            $table->string('clave', 64)->nullable()->unique()->after('id')
                ->comment('identificador estable de las reglas sembradas; NULL en las creadas por el usuario');
        });

        // ---- 1. reglas huerfanas: su columna ya no es de tipo regla
        $columnasDeRegla = DB::table('elevenlabs_mapeos_campo')
            ->where('tipo_origen', 'regla')
            ->pluck('campo_destino')
            ->all();

        if ($columnasDeRegla !== []) {
            DB::table('elevenlabs_mapeos_regla')
                ->whereNotIn('campo_destino', $columnasDeRegla)
                ->delete();
        }

        // ---- 2. duplicados por clave natural (se conserva el menor id)
        $filas = DB::table('elevenlabs_mapeos_regla')->orderBy('id')->get();
        $vistos = [];

        foreach ($filas as $fila) {
            $natural = $fila->ruta_origen . '|' . $fila->comparacion . '|'
                . ($fila->valor_origen ?? '') . '|' . $fila->campo_destino;

            if (isset($vistos[$natural])) {
                DB::table('elevenlabs_mapeos_regla')->where('id', $fila->id)->delete();

                continue;
            }

            $vistos[$natural] = true;
        }
    }

    public function down(): void
    {
        Schema::table('elevenlabs_mapeos_regla', function (Blueprint $table) {
            $table->dropUnique(['clave']);
            $table->dropColumn('clave');
        });
    }
};
