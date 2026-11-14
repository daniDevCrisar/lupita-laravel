<?php

namespace Tests\Feature\Api;

use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B2: la ventana rápido/tardado se mide con los RESIDUOS reales del backtest en
 * vez de los cuantiles del hueco (que contienen el valor real ~35 % de las veces).
 */
class VentanaResiduosTest extends TestCase
{
    /** Claves que estos tests escriben en `prediccion_config`. */
    private const CLAVES = [
        'ventana_residuos', 'ventana_adelanto_min', 'ventana_atraso_min',
        'ventana_adelanto_cadena', 'ventana_atraso_cadena',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        try {
            DB::table('prediccion_config')->whereIn('clave', self::CLAVES)->delete();
        } catch (\Throwable $e) {
            // sin tabla se trabaja con los defaults
        }
        Parametros::olvidar();
    }

    public function test_mide_el_radio_con_los_residuos_del_backtest(): void
    {
        $v = Servicio::ventana_residuos(['limite' => 40], 0.70, 5);

        if (!$v['ok'] && str_contains((string) $v['error'], 'filas')) {
            $this->markTestSkipped('la base no tiene refs suficientes en la ventana por defecto');
        }

        $this->assertTrue($v['ok'], (string) $v['error']);
        $this->assertGreaterThan(0, $v['radio']);
        // simétrica y con la cobertura que declara
        $this->assertSame($v['radio'], $v['adelanto']);
        $this->assertSame($v['radio'], $v['atraso']);
        $this->assertGreaterThan(0, $v['n']);
        $this->assertNotNull($v['cobertura_lograda']);
        $this->assertGreaterThanOrEqual(1, (int) $v['cobertura_lograda']);
        $this->assertLessThanOrEqual(100, (int) $v['cobertura_lograda']);
        $this->assertSame(0.70, $v['cobertura']);
    }

    public function test_con_pocas_filas_no_se_mide(): void
    {
        $v = Servicio::ventana_residuos(['limite' => 10], 0.70, 100000);

        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('mínimo', (string) $v['error']);
    }

    public function test_la_ventana_medida_entra_en_la_prediccion(): void
    {
        Parametros::guardar([
            'ventana_residuos' => 1,
            'ventana_adelanto_min' => 600,
            'ventana_atraso_min' => 900,
            // sin banda propia de cadena: este test mide la banda general
            'ventana_adelanto_cadena' => 0,
            'ventana_atraso_cadena' => 0,
        ]);
        Parametros::olvidar();

        $r = Servicio::predecir($this->refMedible(), $this->opciones());

        if ($r->presenta === null) {
            $this->markTestSkipped('la ref elegida no se puede predecir en ese corte');
        }

        $this->assertSame('residuos', $r->ventana_fuente);
        // el rápido se abre hasta 600' antes, sin pasar el traslado ni `ahora`
        $this->assertLessThanOrEqual($r->presenta->getTimestamp(), $r->presenta_rapido->getTimestamp());
        $this->assertGreaterThanOrEqual(
            $r->presenta->copy()->subMinutes(600)->getTimestamp(),
            $r->presenta_rapido->getTimestamp(),
            'el rápido no puede quedar antes de la hora publicada menos el adelanto medido'
        );
        // el tardado abre el atraso medido; una franja inactiva puede correr ambos
        // extremos hasta `max_desplazamiento_min` (600)
        $desvio = (int) round(($r->presenta_tardado->getTimestamp() - $r->presenta->getTimestamp()) / 60);
        $this->assertGreaterThanOrEqual(300, $desvio);
        $this->assertLessThanOrEqual(900 + 600, $desvio);
        // la ventana publicada es la misma banda
        $this->assertSame($r->presenta_rapido->format('Y-m-d H:i'), $r->ventana['desde']->format('Y-m-d H:i'));
        $this->assertSame($r->presenta_tardado->format('Y-m-d H:i'), $r->ventana['hasta']->format('Y-m-d H:i'));
    }

    public function test_con_la_ventana_apagada_la_fuente_es_el_hueco(): void
    {
        Parametros::guardar(['ventana_residuos' => 0]);
        Parametros::olvidar();

        $r = Servicio::predecir($this->refMedible(), $this->opciones());

        if ($r->presenta === null) {
            $this->markTestSkipped('la ref elegida no se puede predecir en ese corte');
        }

        $this->assertSame('p25_p75_hueco', $r->ventana_fuente);
    }

    /**
     * Opciones del corte: se declara la ubicación del camión (distrito) para que
     * la predicción exista aunque el último hito del camión sea viejo.
     */
    private function opciones(): array
    {
        $ref = $this->refMedible();

        return [
            'ahora' => $this->ahora($ref),
            'ubicacion_tipo' => 'ubigeo',
            'ubicacion_id' => '140101',
        ];
    }

    private function refMedible(): string
    {
        $fila = DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-15')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        return (string) $fila->ref;
    }

    private function ahora(string $ref): string
    {
        $fila = DB::table('referencias')->where('ref', $ref)->first(['presenta_para_carga']);

        return Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');
    }
}
