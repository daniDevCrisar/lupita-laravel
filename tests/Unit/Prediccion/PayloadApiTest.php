<?php

namespace Tests\Unit\Prediccion;

use App\Http\Controllers\Api\PrediccionApiController;
use Tests\TestCase;

/**
 * Qué refs se escriben cuando el POST trae `guardar: true`.
 *
 * Este mapeo se sacó a un método puro justamente porque el bug era silencioso:
 * el payload del viaje ancla se copiaba sobre la ref a predecir y le reescribía
 * los hitos con fechas de otro viaje.
 */
class PayloadApiTest extends TestCase
{
    public function test_no_copia_los_hitos_del_viaje_ancla_en_la_ref_a_predecir(): void
    {
        $refs = PrediccionApiController::refs_del_payload([
            'ref' => 'R1',
            'viaje' => ['ref' => 'ANCLA1', 'presenta_para_carga' => '01/11/2026 08:00'],
            'historia' => [],
        ]);

        $this->assertArrayNotHasKey('R1', $refs);
        $this->assertArrayHasKey('ANCLA1', $refs);
    }

    public function test_incluye_la_ref_a_predecir_solo_si_viene_con_sus_propios_hitos(): void
    {
        $refs = PrediccionApiController::refs_del_payload([
            'ref' => 'R1',
            'viaje' => ['ref' => 'R1', 'presenta_para_carga' => '01/11/2026 08:00'],
        ]);

        $this->assertArrayHasKey('R1', $refs);
        $this->assertSame('01/11/2026 08:00', $refs['R1']['presenta_para_carga']);
    }

    public function test_acepta_historia_como_lista_y_como_mapa(): void
    {
        $lista = PrediccionApiController::refs_del_payload([
            'ref' => 'R1',
            'historia' => [['ref' => 'H1', 'presenta_para_carga' => '01/11/2026 08:00']],
        ]);
        $mapa = PrediccionApiController::refs_del_payload([
            'ref' => 'R1',
            'historia' => ['H2' => ['presenta_para_carga' => '02/11/2026 08:00']],
        ]);

        $this->assertArrayHasKey('H1', $lista);
        $this->assertArrayHasKey('H2', $mapa);
        $this->assertSame('02/11/2026 08:00', $mapa['H2']['presenta_para_carga']);
    }

    public function test_ignora_items_sin_ref_o_que_no_son_arreglos(): void
    {
        $refs = PrediccionApiController::refs_del_payload([
            'ref' => 'R1',
            'viaje' => 'no soy un arreglo',
            'historia' => [['sin_ref' => true], 'texto', ['ref' => '']],
        ]);

        $this->assertSame([], $refs);
    }
}
