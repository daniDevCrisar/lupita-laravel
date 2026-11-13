<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Ventanas;
use Carbon\Carbon;
use Tests\TestCase;

class VentanasTest extends TestCase
{
    /**
     * Marcas horarias diarias del camión: "un hito a las 07:00 todos los días".
     *
     * @param  array<int, string>  $horas
     * @return array<int, Carbon>
     */
    private function hitos(array $horas, int $dias = 10): array
    {
        $salida = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = Carbon::parse('2026-10-01 00:00')->addDays($i);
            foreach ($horas as $hora) {
                $salida[] = $dia->copy()->setTimeFromTimeString($hora);
            }
        }

        return $salida;
    }

    public function test_detecta_la_noche_sin_actividad(): void
    {
        $ventanas = Ventanas::inactivas(
            $this->hitos(['07:00', '11:00', '13:00', '18:00']),
            Carbon::parse('2026-10-01 00:00'),
            Carbon::parse('2026-10-11 00:00')
        );

        $this->assertContains(['desde' => 0, 'hasta' => 420], $ventanas);      // 00:00 - 07:00
        $this->assertContains(['desde' => 1110, 'hasta' => 1440], $ventanas); // 18:30 - 24:00
    }

    public function test_detecta_el_almuerzo_sin_marcas(): void
    {
        // come entre las 11:30 y las 14:00: en esa franja no hay ni una marca
        $ventanas = Ventanas::inactivas(
            $this->hitos(['07:00', '11:00', '14:00', '18:00']),
            Carbon::parse('2026-10-01 00:00'),
            Carbon::parse('2026-10-11 00:00')
        );

        $this->assertContains(['desde' => 690, 'hasta' => 840], $ventanas);
    }

    public function test_una_franja_con_una_sola_marca_cuenta_como_muerta(): void
    {
        $hitos = array_merge(
            $this->hitos(['07:00', '18:00']),
            [Carbon::parse('2026-10-03 12:10')]   // una sola marca al mediodía
        );

        $ventanas = Ventanas::inactivas($hitos, Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00'));

        $this->assertContains(['desde' => 450, 'hasta' => 1080], $ventanas);
    }

    public function test_sin_hitos_no_inventa_ventanas(): void
    {
        $this->assertSame([], Ventanas::inactivas([], Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00')));
    }

    public function test_desplaza_una_fecha_dentro_de_la_noche(): void
    {
        $res = Ventanas::desplazar(
            Carbon::parse('2026-11-14 03:10'),
            [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 360]]
        );

        $this->assertSame('2026-11-14 06:00', $res->fecha->format('Y-m-d H:i'));
        $this->assertSame(170, $res->min);
        $this->assertNull($res->aviso);
    }

    public function test_no_mueve_una_fecha_fuera_de_las_ventanas(): void
    {
        $t = Carbon::parse('2026-11-14 10:30');
        $res = Ventanas::desplazar($t, [['desde' => 0, 'hasta' => 360]]);

        $this->assertTrue($res->fecha->equalTo($t));
        $this->assertSame(0, $res->min);
    }

    public function test_respeta_el_maximo_y_avisa(): void
    {
        $res = Ventanas::desplazar(
            Carbon::parse('2026-11-14 22:30'),
            [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 600]],
            60
        );

        $this->assertSame(60, $res->min);
        $this->assertNotNull($res->aviso);
    }
}
