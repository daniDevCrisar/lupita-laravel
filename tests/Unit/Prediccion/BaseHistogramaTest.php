<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\BaseHistograma;
use Carbon\Carbon;
use Tests\TestCase;

class BaseHistogramaTest extends TestCase
{
    /**
     * Réplica del método actual (`DBComportamiento::veredicto_carga`): la hora
     * futura más frecuente de las presentas pasadas, con su minuto promedio.
     */
    public function test_elige_la_hora_futura_mas_frecuente_con_su_minuto(): void
    {
        // tres presentas del mismo lunes a las 08:xx y una del jueves a las 17:xx
        $presentas = [
            Carbon::parse('2026-10-12 08:30'), Carbon::parse('2026-10-19 08:40'),
            Carbon::parse('2026-10-26 08:35'), Carbon::parse('2026-10-15 17:10'),
        ];

        // 2026-11-13 es viernes: la próxima jornada con datos es el lunes 16
        $estimado = BaseHistograma::estimar($presentas, Carbon::parse('2026-11-13 07:00'));

        $this->assertSame('2026-11-16 08:35', $estimado->format('Y-m-d H:i'));
    }

    public function test_hoy_solo_cuenta_la_hora_que_no_paso(): void
    {
        // tres presentas de lunes a las 08:00 (la hora modal) y una a las 15:00
        $presentas = [
            Carbon::parse('2026-10-05 08:00'), Carbon::parse('2026-10-12 08:00'),
            Carbon::parse('2026-10-19 08:00'), Carbon::parse('2026-10-26 15:00'),
        ];

        // lunes 16 a las 10:00: la hora 8 ya paso hoy, asi que queda la 15
        $estimado = BaseHistograma::estimar($presentas, Carbon::parse('2026-11-16 10:00'));

        $this->assertSame('2026-11-16 15:00', $estimado->format('Y-m-d H:i'));
    }

    public function test_sin_presentas_devuelve_null(): void
    {
        $this->assertNull(BaseHistograma::estimar([], Carbon::parse('2026-11-13 07:00')));
    }
}
