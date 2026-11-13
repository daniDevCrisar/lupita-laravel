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

    public function test_cerca_usa_el_traslado_de_vuelta(): void
    {
        $this->assertFalse(Servicio::cerca(null));
        $this->assertTrue(Servicio::cerca((object) ['min' => 0]));       // misma planta o distrito
        $this->assertTrue(Servicio::cerca((object) ['min' => 60]));      // justo el umbral
        $this->assertFalse(Servicio::cerca((object) ['min' => 61]));

        // el umbral se puede mover desde la configuración
        $this->assertTrue(Servicio::cerca((object) ['min' => 120], (object) ['cerca_min' => 150]));
        $this->assertFalse(Servicio::cerca((object) ['min' => 120], (object) ['cerca_min' => 90]));
    }

    public function test_es_cerca_por_local_distrito_o_ruta_corta(): void
    {
        $indice = ['LOC_A|LOC_B' => true];
        $par = fn (array $datos) => (object) $datos;

        // mismo local
        $this->assertTrue(Servicio::es_cerca($par([
            'ancla_destino' => 'LOC_A', 'carga_origen' => 'LOC_A',
        ]), $indice));

        // mismo distrito (es lo que hace el motor con ya_esta_en_planta)
        $this->assertTrue(Servicio::es_cerca($par([
            'ancla_destino' => 'LOC_X', 'carga_origen' => 'LOC_Y',
            'ancla_ubigeo' => '150132', 'carga_ubigeo' => '150132',
        ]), $indice));

        // ruta medida corta entre locales distintos
        $this->assertTrue(Servicio::es_cerca($par([
            'ancla_destino' => 'LOC_B', 'carga_origen' => 'LOC_A',
            'ancla_ubigeo' => '150101', 'carga_ubigeo' => '070101',
        ]), $indice));

        // lejos
        $this->assertFalse(Servicio::es_cerca($par([
            'ancla_destino' => 'LOC_X', 'carga_origen' => 'LOC_Y',
            'ancla_ubigeo' => '150101', 'carga_ubigeo' => '070101',
        ]), $indice));

        // sin datos del par
        $this->assertFalse(Servicio::es_cerca($par([]), $indice));
    }

    public function test_el_indice_de_cercania_usa_el_viaje_medido(): void
    {
        $rutas = [
            'R1' => (object) ['origen_id' => 'LOC_A', 'destino_id' => 'LOC_B'],
            'R2' => (object) ['origen_id' => 'LOC_A', 'destino_id' => 'LOC_C'],
        ];
        $etapas = ['R1' => ['e4' => 29], 'R2' => ['e4' => 400]];

        $indice = Servicio::indice_cerca($etapas, $rutas, 60);

        $this->assertTrue(isset($indice['LOC_A|LOC_B']));
        $this->assertTrue(isset($indice['LOC_B|LOC_A']), 'la cercanía es en los dos sentidos');
        $this->assertFalse(isset($indice['LOC_A|LOC_C']), 'e4 de 400 min no es cerca');
    }
}
