<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * El viaje compilado POR HORA de salida (`tiempos_ruta_horario`): es la misma
 * tabla que sirve `/api/tiempos/promedio/horario` y trae la demora de la hora
 * pico, que la mediana diaria no tiene.
 */
class ViajeHorarioTest extends TestCase
{
    public function test_lee_la_celda_de_dia_y_hora_de_la_ruta(): void
    {
        // martes 17/11/2026 a las 04:00 -> MySQL WEEKDAY 1, hora 4
        $celda = DBPrediccion::viaje_horario('RUT_CARAL-BABEL_MAXO', Carbon::parse('2026-11-17 04:00'), 3);

        if ($celda === null) {
            $this->markTestSkipped('la base no tiene esa celda compilada');
        }

        $this->assertSame(1, $celda->dia);
        $this->assertSame(4, $celda->hora);
        $this->assertGreaterThanOrEqual(3, $celda->viajes);
        $this->assertGreaterThan(0, $celda->min);
    }

    public function test_sin_muestra_suficiente_o_sin_ruta_no_devuelve_nada(): void
    {
        $this->assertNull(DBPrediccion::viaje_horario('RUT_QUE_NO_EXISTE', Carbon::parse('2026-11-17 04:00')));
        $this->assertNull(DBPrediccion::viaje_horario('', Carbon::parse('2026-11-17 04:00')));

        // una celda con pocos viajes no mueve nada: se exige el mínimo
        $this->assertNull(DBPrediccion::viaje_horario('RUT_CARAL-BABEL_MAXO', Carbon::parse('2026-11-17 04:00'), 9999));
    }

    public function test_el_horario_queda_en_la_prediccion_como_historia(): void
    {
        // el dato aplicado (o su ausencia) tiene que verse en el JSON: es lo que
        // permite auditar de dónde salió el viaje
        $ref = $this->refMedible();

        $r = \App\Prediccion\Servicio::predecir($ref, ['ahora' => $this->ahora($ref)]);

        $this->assertNotNull($r->historia);
        $this->assertTrue(property_exists($r->historia, 'viaje_horario'));
    }

    private function refMedible(): string
    {
        $fila = \Illuminate\Support\Facades\DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-15')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        return (string) $fila->ref;
    }

    private function ahora(string $ref): string
    {
        $fila = \Illuminate\Support\Facades\DB::table('referencias')->where('ref', $ref)->first(['presenta_para_carga']);

        return Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');
    }
}
