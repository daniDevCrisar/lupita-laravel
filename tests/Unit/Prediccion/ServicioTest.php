<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Servicio;
use Carbon\Carbon;
use Tests\TestCase;

class ServicioTest extends TestCase
{
    public function test_clave_calibracion_respeta_el_50_por_ciento(): void
    {
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 80.0));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 50.0));
        $this->assertSame(['conductor', '7'], Servicio::clave_calibracion('ABC123', 7, 49.9));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', null, null));
        $this->assertSame(['placa', ''], Servicio::clave_calibracion(null, 7, 10.0));
    }

    public function test_pares_previos_no_deja_pasar_el_futuro(): void
    {
        $par = fn (string $presenta) => (object) ['presenta' => Carbon::parse($presenta)];
        $historial = [
            $par('2026-11-01 08:00'),
            $par('2026-11-03 08:00'),   // justo en el ancla: NO entra (no es anterior)
            $par('2026-11-02 08:00'),
            $par('2026-11-04 08:00'),   // futuro
        ];

        $previos = Servicio::pares_previos($historial, Carbon::parse('2026-11-03 08:00'), 10);

        $this->assertSame(['2026-11-02 08:00', '2026-11-01 08:00'], array_map(
            fn ($parcial) => $parcial->presenta->format('Y-m-d H:i'),
            $previos
        ));
    }

    public function test_pares_previos_corta_en_el_maximo_y_ordena_del_mas_nuevo(): void
    {
        $historial = [];
        for ($dia = 1; $dia <= 10; $dia++) {
            $historial[] = (object) ['presenta' => Carbon::parse(sprintf('2026-10-%02d 08:00', $dia))];
        }

        $previos = Servicio::pares_previos($historial, Carbon::parse('2026-11-01 00:00'), 3);

        $this->assertCount(3, $previos);
        $this->assertSame('2026-10-10 08:00', $previos[0]->presenta->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 08:00', $previos[2]->presenta->format('Y-m-d H:i'));
    }

    public function test_pct_conductor_cuenta_los_pares_del_chofer(): void
    {
        $par = fn (?int $conductor) => (object) ['conductor' => $conductor];
        $pares = [$par(7), $par(7), $par(9), $par(null)];

        // 2 de 4: justo la mitad, la placa sigue mandando
        $this->assertSame(50.0, Servicio::pct_conductor($pares, 7));
        $this->assertSame(25.0, Servicio::pct_conductor($pares, 9));
        $this->assertSame(0.0, Servicio::pct_conductor($pares, 99));

        // sin conductor o sin historia no hay porcentaje que calcular
        $this->assertNull(Servicio::pct_conductor($pares, null));
        $this->assertNull(Servicio::pct_conductor($pares, 0));
        $this->assertNull(Servicio::pct_conductor([], 7));
    }
}
