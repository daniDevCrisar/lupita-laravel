<?php

namespace Tests\Feature\Api;

use App\Prediccion\Servicio;
use Tests\TestCase;

/**
 * Régimen "cerca" de la flota: cuando el camión descarga en el mismo local o
 * distrito de la planta donde vuelve a cargar, la flota carga mucho más seguido.
 * Es el prior que usa una placa sin muestra propia (caso AHV874 → BABEL MAXO).
 */
class CercaniaTest extends TestCase
{
    public function test_el_regimen_cerca_tiene_muestra_y_esta_bien_formado(): void
    {
        $regimen = Servicio::regimen_cerca();

        if ($regimen === null) {
            $this->markTestSkipped('la base no tiene pares "cerca"');
        }

        $this->assertGreaterThan(0, (int) $regimen->global['n']);
        $this->assertLessThanOrEqual($regimen->global['base_min'], $regimen->global['p25_min']);
        $this->assertGreaterThanOrEqual($regimen->global['base_min'], $regimen->global['p75_min']);
        $this->assertGreaterThan(0, count((array) $regimen->conductores), 'hay choferes con muestra propia');
    }

    public function test_el_resumen_cerca_fila_prioriza_al_chofer(): void
    {
        $regimen = Servicio::regimen_cerca();

        if ($regimen === null || $regimen->conductores === []) {
            $this->markTestSkipped('la base no tiene choferes con pares "cerca"');
        }

        $conductor = (int) array_key_first($regimen->conductores);

        $delChofer = Servicio::resumen_cerca_fila($conductor);
        $this->assertNotNull($delChofer);
        $this->assertSame('chofer', $delChofer['origen']);
        $this->assertSame($regimen->conductores[(string) $conductor], $delChofer['resumen']);

        $deLaFlota = Servicio::resumen_cerca_fila(null);
        $this->assertNotNull($deLaFlota);
        $this->assertSame('flota', $deLaFlota['origen']);
    }
}
