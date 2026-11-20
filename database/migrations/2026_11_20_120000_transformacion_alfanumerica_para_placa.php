<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `placa` pasa a usar la transformacion `alfanumerico`.
 *
 * POR QUE
 * La placa tiene que quedar como "ABC123": mayusculas y solo letras/numeros.
 * El flujo de VAPI solo hacia `replaceAll(',', '')`, asi que "A,B,C,1,2,3" seguia
 * con comas y "abc-123" seguia en minusculas con guion; comparar eso contra
 * `DBReferencias::verificar_placa()` o contra la placa del viaje fallaba.
 *
 * Migracion puntual y no re-siembra: `ElevenLabsMapeoSeeder` sobrescribe las
 * reglas sembradas por su `clave` y en esta base ya se editaron a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('elevenlabs_mapeos_campo')
            ->where('campo_destino', 'placa')
            ->update([
                'transformacion' => 'alfanumerico',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('elevenlabs_mapeos_campo')
            ->where('campo_destino', 'placa')
            ->update([
                'transformacion' => 'texto',
                'updated_at' => now(),
            ]);
    }
};
