<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\HoraPico;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Horas pico: la capital no se mueve como el resto del país, y de esta decisión
 * depende que el ruteo se pida con tráfico o de vía libre.
 */
class HoraPicoTest extends TestCase
{
    private function hora(string $hora): Carbon
    {
        return Carbon::parse('2026-11-14 ' . $hora);
    }

    public function test_la_capital_son_los_ubigeos_del_departamento_de_lima(): void
    {
        $this->assertTrue(HoraPico::es_capital('150101'));    // Lima Cercado
        $this->assertTrue(HoraPico::es_capital('150117'));    // San Martín de Porres
        $this->assertTrue(HoraPico::es_capital('150601'));    // Caral (departamento de Lima)
        $this->assertFalse(HoraPico::es_capital('140101'));   // Chiclayo
        $this->assertFalse(HoraPico::es_capital('070101'));   // Callao: por defecto, reloj de provincia
        $this->assertFalse(HoraPico::es_capital('15010'));    // no es un ubigeo
        $this->assertFalse(HoraPico::es_capital(''));
        $this->assertFalse(HoraPico::es_capital(null));
    }

    public function test_el_callao_se_puede_sumar_a_la_capital_por_config(): void
    {
        config(['geoapify.departamentos_capital' => ['15', '07']]);

        $this->assertTrue(HoraPico::es_capital('070101'));
        $this->assertTrue(HoraPico::es_capital('150117'));
    }

    public function test_si_el_viaje_toca_la_capital_manda_el_reloj_de_la_capital(): void
    {
        $this->assertSame(HoraPico::ZONA_CAPITAL, HoraPico::zona('150601', '140101'));
        $this->assertSame(HoraPico::ZONA_CAPITAL, HoraPico::zona('140101', '150117'));
        // un punto geocodificado no tiene ubigeo: manda la punta conocida
        $this->assertSame(HoraPico::ZONA_CAPITAL, HoraPico::zona(null, '150117'));
        $this->assertSame(HoraPico::ZONA_PROVINCIA, HoraPico::zona('130101', '140101'));
        $this->assertSame(HoraPico::ZONA_PROVINCIA, HoraPico::zona(null, null));
    }

    public function test_las_franjas_de_la_capital(): void
    {
        // 06:30-09:30 y 17:30-21:00, con el extremo final abierto
        $this->assertFalse(HoraPico::es_pico($this->hora('06:29'), HoraPico::ZONA_CAPITAL));
        $this->assertTrue(HoraPico::es_pico($this->hora('06:30'), HoraPico::ZONA_CAPITAL));
        $this->assertTrue(HoraPico::es_pico($this->hora('09:29'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('09:30'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('14:30'), HoraPico::ZONA_CAPITAL));
        $this->assertTrue(HoraPico::es_pico($this->hora('17:30'), HoraPico::ZONA_CAPITAL));
        $this->assertTrue(HoraPico::es_pico($this->hora('20:59'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('21:00'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('03:00'), HoraPico::ZONA_CAPITAL));
    }

    public function test_las_franjas_del_interior_salen_mas_temprano(): void
    {
        // provincia: 06:00-09:00 y 17:00-20:00
        $this->assertTrue(HoraPico::es_pico($this->hora('06:15'), HoraPico::ZONA_PROVINCIA));
        $this->assertFalse(HoraPico::es_pico($this->hora('06:15'), HoraPico::ZONA_CAPITAL));
        $this->assertTrue(HoraPico::es_pico($this->hora('09:15'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('09:15'), HoraPico::ZONA_PROVINCIA));
        $this->assertTrue(HoraPico::es_pico($this->hora('17:10'), HoraPico::ZONA_PROVINCIA));
        $this->assertTrue(HoraPico::es_pico($this->hora('19:59'), HoraPico::ZONA_PROVINCIA));
        $this->assertFalse(HoraPico::es_pico($this->hora('20:00'), HoraPico::ZONA_PROVINCIA));
    }

    public function test_las_franjas_se_pueden_cambiar_por_config(): void
    {
        config(['geoapify.franjas_pico' => ['capital' => [[720, 780]]]]);   // 12:00-13:00

        $this->assertTrue(HoraPico::es_pico($this->hora('12:30'), HoraPico::ZONA_CAPITAL));
        $this->assertFalse(HoraPico::es_pico($this->hora('07:00'), HoraPico::ZONA_CAPITAL));

        // una franja mal cargada se ignora (no rompe ni se inventa el pico)
        config(['geoapify.franjas_pico' => ['capital' => [[780, 720]]]]);
        $this->assertFalse(HoraPico::es_pico($this->hora('12:30'), HoraPico::ZONA_CAPITAL));
    }

    public function test_el_texto_de_las_franjas(): void
    {
        $this->assertSame('06:30-09:30 y 17:30-21:00', HoraPico::texto(HoraPico::ZONA_CAPITAL));
        $this->assertSame('06:00-09:00 y 17:00-20:00', HoraPico::texto(HoraPico::ZONA_PROVINCIA));
    }
}
