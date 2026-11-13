<?php

namespace Tests\Unit\Prediccion;

use App\Database\DBPrediccion;
use PHPUnit\Framework\TestCase;

/**
 * Último hito de una ref: se resuelve por FECHA (la más actual), no por el orden
 * del ciclo, y el compromiso de carga no cuenta como evento.
 */
class UltimoHitoTest extends TestCase
{
    private function fila(array $hitos): object
    {
        $base = array_fill_keys(DBPrediccion::HITOS, null);

        return (object) array_merge($base, $hitos);
    }

    public function test_toma_el_hito_con_la_fecha_mas_actual(): void
    {
        $fila = $this->fila([
            'presenta_para_carga' => '2026-11-13 07:34:00',
            'inicio_de_carga' => '2026-11-13 08:00:00',
            'fin_de_carga' => '2026-11-13 08:50:00',
            'inicio_ruta' => '2026-11-13 09:12:00',
        ]);

        $this->assertSame('inicio_ruta', DBPrediccion::hito_mas_reciente($fila));
    }

    public function test_acepta_cualquier_etapa_del_ciclo(): void
    {
        foreach (DBPrediccion::HITOS as $i => $hito) {
            $fila = $this->fila([$hito => '2026-11-13 09:12:00']);
            $this->assertSame($hito, DBPrediccion::hito_mas_reciente($fila));
        }
    }

    public function test_el_compromiso_de_carga_no_es_el_hito_del_ancla(): void
    {
        // el compromiso (plan) quedó DESPUÉS de la presentación real: si contara,
        // el ancla del camión sería una cita, no su último evento
        $fila = $this->fila([
            'compromiso_carga' => '2026-11-14 10:00:00',
            'presenta_para_carga' => '2026-11-13 21:14:00',
            'inicio_de_carga' => '2026-11-13 22:15:00',
            'inicio_ruta' => '2026-11-13 22:23:00',
        ]);

        $this->assertSame('compromiso_carga', DBPrediccion::hito_mas_reciente($fila));
        $this->assertSame('inicio_ruta', DBPrediccion::hito_mas_reciente($fila, ['compromiso_carga']));
    }

    public function test_una_ref_con_solo_compromiso_no_tiene_hito_de_ancla(): void
    {
        $fila = $this->fila(['compromiso_carga' => '2026-11-14 10:00:00']);

        $this->assertSame('compromiso_carga', DBPrediccion::hito_mas_reciente($fila));
        $this->assertNull(DBPrediccion::hito_mas_reciente($fila, ['compromiso_carga']));
    }

    public function test_los_hitos_basura_no_cuentan(): void
    {
        $fila = $this->fila([
            'presenta_para_carga' => '1998-01-01 00:00:00',
            'inicio_ruta' => '2026-11-13 09:12:00',
        ]);

        $this->assertSame('inicio_ruta', DBPrediccion::hito_mas_reciente($fila));
    }
}
