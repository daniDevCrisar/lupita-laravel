<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Ventanas;
use Carbon\Carbon;
use Tests\TestCase;

class VentanasTest extends TestCase
{
    /** @return array<int, object> intervalos diarios "desde -> fin" durante $dias */
    private function jornada(int $dias = 10, string $desde = '06:00', string $fin = '22:00'): array
    {
        $out = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = Carbon::parse('2026-10-01 00:00')->addDays($i);
            $out[] = (object) ['desde' => $dia->copy()->setTimeFromTimeString($desde),
                               'fin' => $dia->copy()->setTimeFromTimeString($fin)];
        }

        return $out;
    }

    public function test_detecta_la_noche_sin_actividad(): void
    {
        $ventanas = Ventanas::inactivas(
            $this->jornada(),
            Carbon::parse('2026-10-01 00:00'),
            Carbon::parse('2026-10-11 00:00')
        );

        $this->assertContains(['desde' => 1320, 'hasta' => 1440], $ventanas);
        $this->assertContains(['desde' => 0, 'hasta' => 360], $ventanas);
    }

    public function test_detecta_el_almuerzo_cuando_la_jornada_se_parte(): void
    {
        // jornada completa 06-22: el mediodia esta cubierto y no hay franja
        $completa = Ventanas::inactivas($this->jornada(10), Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00'));
        $this->assertNotContains(['desde' => 720, 'hasta' => 780], $completa);

        // jornada partida 06-12 y 13-22: 12-13 queda inactivo
        $partida = array_merge($this->jornada(10, '06:00', '12:00'), $this->jornada(10, '13:00', '22:00'));
        $ventanas = Ventanas::inactivas($partida, Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00'));

        $this->assertContains(['desde' => 720, 'hasta' => 780], $ventanas);
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
