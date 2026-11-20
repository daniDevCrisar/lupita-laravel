<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cadenas de rutas candidatas para los campos de negocio.
 *
 * POR QUE UNA MIGRACION PUNTUAL
 * `ElevenLabsMapeoSeeder` sobrescribe las reglas sembradas por su `clave`, y en
 * esta base ya se editaron a mano. Esta migracion toca solo estas filas de
 * `elevenlabs_mapeos_campo`.
 *
 * COMO FUNCIONA LA CADENA
 * `ruta_origen` guarda una ruta por linea. El resolvedor las prueba en orden: la
 * primera que existe, no viene vacia y pasa la transformacion gana. Las que no
 * existen en el JSON se saltan solas.
 *
 * POR QUE ESTOS NOMBRES
 * Se mezclan los nombres de ElevenLabs (`carrier_name`, `reference`,
 * `fecha_carga_texto`) con los que usaba VAPI en `procesar_json.js`
 * (`driver_name`, `nombre_transportista`, `vehicle_plate`,
 * `fecha_compromiso_carga`). Asi el mismo campo sirve aunque cada asistente
 * nombre distinto la variable, sin tener que configurar por asistente.
 *
 * `conductor` NO incluye `nombre`: en inbound `nombre` trae la empresa
 * ("LOGISTICA MASTERS SAS"), no el transportista, y usarlo como conductor seria
 * peor que dejarlo vacio.
 */
return new class extends Migration
{
    private const DV = 'conversation_initiation_client_data.dynamic_variables.';

    public function up(): void
    {
        foreach ($this->cadenas() as $campo => $rutas) {
            DB::table('elevenlabs_mapeos_campo')
                ->where('campo_destino', $campo)
                ->update([
                    'tipo_origen' => 'campo',
                    'ruta_origen' => implode("\n", $rutas),
                    'valor_constante' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Vuelve a la primera ruta de cada cadena
        foreach ($this->cadenas() as $campo => $rutas) {
            DB::table('elevenlabs_mapeos_campo')
                ->where('campo_destino', $campo)
                ->update(['ruta_origen' => $rutas[0], 'updated_at' => now()]);
        }
    }

    /** @return array<string, array<int, string>> */
    private function cadenas(): array
    {
        $dv = self::DV;

        return [
            'ref' => [
                $dv . 'reference',
                $dv . 'trip_code',
                $dv . 'referencia',
                $dv . 'ref',
            ],
            'origen' => [
                $dv . 'origen',
                $dv . 'origen_nombre',
                $dv . 'desde',
            ],
            'destino' => [
                $dv . 'destino',
                $dv . 'destino_nombre',
                $dv . 'hacia',
            ],
            'telefono' => [
                $dv . 'telefono',
                $dv . 'celular',
                $dv . 'telefono_conductor',
                'metadata.phone_call.external_number',
            ],
            'conductor' => [
                $dv . 'carrier_name',
                $dv . 'nombre_transportista',
                $dv . 'driver_name',
                $dv . 'conductor',
            ],
            'placa' => [
                $dv . 'placa',
                $dv . 'vehicle_plate',
                $dv . 'placa_vehiculo',
            ],
            'fecha_prometida' => [
                $dv . 'fecha_carga_texto',
                $dv . 'fecha_compromiso_carga',
                $dv . 'fecha_cita_carga',
            ],
        ];
    }
};
