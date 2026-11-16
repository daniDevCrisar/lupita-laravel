<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Prediccion\Servicio;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Matriz día x hora de los promedios compilados (`/lupita/tiempos/promedio/horario/ver`).
 *
 * El gráfico "Promedio por hora" mostraba 24 barras idénticas: los acumuladores
 * se creaban con `array_fill(0, 24, (object) [...])`, que deja las 24 posiciones
 * apuntando al MISMO objeto. Cada celda sumaba sobre él y las 24 terminaban con
 * el total. Acá se fija que cada hora y cada día sean su propio acumulador.
 */
class TiemposPromedioHorarioTest extends TestCase
{
    public function test_cada_hora_y_cada_dia_tienen_su_propio_acumulador(): void
    {
        $datos = \App\Database\DBTiemposPromedioCompilado::horario([]);

        if ($datos->resumen->celdas === 0) {
            $this->markTestSkipped('la base no tiene la matriz día/hora compilada');
        }

        $this->assertCount(24, $datos->por_hora);
        $this->assertCount(7, $datos->por_dia);

        // objetos distintos, no 24 alias del mismo
        $this->assertNotSame($datos->por_hora[0], $datos->por_hora[1]);
        $this->assertNotSame($datos->por_dia[0], $datos->por_dia[1]);

        $viajesPorHora = array_map(static fn ($hora) => (int) $hora->viajes, $datos->por_hora);
        $viajesPorDia = array_map(static fn ($dia) => (int) $dia->viajes, $datos->por_dia);

        // ninguna hora puede tener el total (salvo que todo caiga en una sola)
        $this->assertNotSame(array_fill(0, 24, $datos->resumen->viajes), $viajesPorHora,
            'las 24 horas no pueden tener el total: es el bug de los objetos compartidos');

        // las horas y los días suman exactamente el total de la corrida
        $this->assertSame($datos->resumen->viajes, array_sum($viajesPorHora));
        $this->assertSame($datos->resumen->viajes, array_sum($viajesPorDia));
    }

    public function test_el_promedio_por_hora_es_la_media_ponderada_de_sus_celdas(): void
    {
        $datos = \App\Database\DBTiemposPromedioCompilado::horario([]);

        if ($datos->resumen->celdas === 0) {
            $this->markTestSkipped('la base no tiene la matriz día/hora compilada');
        }

        // por_hora agrega TODAS las celdas del filtro (las celdas de la tabla se
        // cortan por `limit`), así que su ponderado tiene que dar el del resumen
        $suma = 0.0;
        $peso = 0;
        foreach ($datos->por_hora as $hora) {
            $suma += (float) $hora->prom_min * (int) $hora->viajes;
            $peso += (int) $hora->viajes;
        }

        $this->assertGreaterThan(0, $peso);
        $this->assertSame($datos->resumen->viajes, $peso);

        $esperado = round($suma / $peso, 1);
        $this->assertEqualsWithDelta($esperado, (float) $datos->resumen->prom_ponderado, 0.2);
    }

    public function test_el_filtro_por_dia_deja_el_resto_en_cero(): void
    {
        $sin = \App\Database\DBTiemposPromedioCompilado::horario([]);
        if ($sin->resumen->celdas === 0) {
            $this->markTestSkipped('la base no tiene la matriz día/hora compilada');
        }

        $lunes = \App\Database\DBTiemposPromedioCompilado::horario(['dia' => 0]);

        $this->assertGreaterThan(0, $lunes->resumen->viajes);
        $this->assertLessThan($sin->resumen->viajes, $lunes->resumen->viajes);

        $porDia = array_map(static fn ($dia) => (int) $dia->viajes, $lunes->por_dia);
        $this->assertSame(0, $porDia[1], 'el martes no puede tener viajes cuando se filtró el lunes');
        $this->assertSame(0, $porDia[6], 'el domingo no puede tener viajes cuando se filtró el lunes');
        $this->assertSame($lunes->resumen->viajes, $porDia[0]);
    }
}
