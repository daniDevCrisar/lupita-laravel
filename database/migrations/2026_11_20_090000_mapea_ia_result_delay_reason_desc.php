<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reactiva `ia_result_delay_reason_desc` como campo, leyendo
 * `analysis.call_summary_title`.
 *
 * POR QUE UNA MIGRACION PUNTUAL Y NO VOLVER A SEMBRAR
 * `ElevenLabsMapeoSeeder` casa las reglas sembradas por su `clave` y sobrescribe
 * sus valores. En esta base ya se editaron a mano varias de esas reglas
 * (prioridades y el texto de comparacion de `fin_remote_party`), asi que volver a
 * correr el seeder las revertiria. Esta migracion toca UNA fila y nada mas.
 *
 * OJO CON EL NOMBRE DE LA COLUMNA
 * `call_summary_title` es el titulo corto del analisis ("Voicemail Detected",
 * "Logistics Unit Availability"), no un "motivo de demora". ElevenLabs no tiene
 * equivalente de `delay_reason_desc`; se usa este porque es el dato corto mas
 * parecido disponible. Puede venir null (p. ej. en llamadas fallidas), por eso
 * lleva valor por defecto vacio.
 */
return new class extends Migration
{
    private const CAMPO = 'ia_result_delay_reason_desc';

    public function up(): void
    {
        DB::table('elevenlabs_mapeos_campo')
            ->where('campo_destino', self::CAMPO)
            ->update([
                'tipo_origen' => 'campo',
                'ruta_origen' => 'analysis.call_summary_title',
                'valor_constante' => null,
                'transformacion' => 'truncar_255',
                'valor_por_defecto' => '',
                'descripcion' => 'ElevenLabs no tiene "delay reason": se usa el título corto del análisis (call_summary_title).',
                'activo' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('elevenlabs_mapeos_campo')
            ->where('campo_destino', self::CAMPO)
            ->update([
                'tipo_origen' => 'constante',
                'ruta_origen' => null,
                'valor_constante' => '',
                'transformacion' => 'ninguna',
                'valor_por_defecto' => null,
                'descripcion' => 'Sin equivalente en ElevenLabs: se deja vacio.',
                'updated_at' => now(),
            ]);
    }
};
