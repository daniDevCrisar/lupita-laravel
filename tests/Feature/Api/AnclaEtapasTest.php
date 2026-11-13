<?php

namespace Tests\Feature\Api;

use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El ancla de la predicción: qué etapa del viaje se toma y cuál no.
 *
 * La regla es "el último EVENTO del camión por fecha", con `inicio_ruta`,
 * `qr_llegada_destino` e `inicio_descargue` incluidos; el `compromiso_carga` es
 * una cita del plan y no cuenta.
 */
class AnclaEtapasTest extends TestCase
{
    public function test_el_ancla_usa_la_etapa_mas_actual_incluido_inicio_ruta(): void
    {
        $fila = DB::table('referencias')
            ->whereNotNull('inicio_ruta')
            ->whereNull('qr_llegada_destino')
            ->whereNull('inicio_descargue')
            ->whereNull('fin_descargue')
            ->whereNotNull('placa')
            ->orderByDesc('inicio_ruta')
            ->first(['ref', 'placa', 'inicio_ruta']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene un viaje detenido en inicio_ruta');
        }

        $candidatos = Servicio::candidatos_viaje((string) $fila->placa,
            Carbon::parse($fila->inicio_ruta)->addMinutes(5));

        $candidato = null;
        foreach ($candidatos as $viaje) {
            if ($viaje->ref === (string) $fila->ref) {
                $candidato = $viaje;
                break;
            }
        }

        $this->assertNotNull($candidato, 'la ref en inicio_ruta tiene que estar entre los anclas');
        $this->assertSame('inicio_ruta', $candidato->hito);
        $this->assertFalse($candidato->cerrado);
        $this->assertSame($fila->inicio_ruta, $candidato->fecha?->format('Y-m-d H:i:s'));
    }

    public function test_el_compromiso_de_carga_no_sirve_de_ancla(): void
    {
        $fila = DB::table('referencias')
            ->whereNotNull('compromiso_carga')
            ->whereNotNull('inicio_ruta')
            ->whereNull('fin_descargue')
            ->whereColumn('compromiso_carga', '>', 'inicio_ruta')
            ->orderByDesc('compromiso_carga')
            ->first(['ref', 'placa', 'compromiso_carga', 'inicio_ruta']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene compromisos posteriores a un inicio de ruta');
        }

        $candidatos = Servicio::candidatos_viaje((string) $fila->placa,
            Carbon::parse($fila->compromiso_carga)->addMinute());

        $candidato = null;
        foreach ($candidatos as $viaje) {
            if ($viaje->ref === (string) $fila->ref) {
                $candidato = $viaje;
                break;
            }
        }

        $this->assertNotNull($candidato);
        $this->assertNotSame('compromiso_carga', $candidato->hito,
            'el compromiso es una cita del plan, no el último evento del camión');
        $this->assertSame('inicio_ruta', $candidato->hito);
    }
}
