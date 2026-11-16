<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Prediccion\Ablacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Ablación del motor: la herramienta que dice qué mecanismo aporta y cuál resta.
 *
 * Nunca se prueban los VALORES (dependen de la base del día): se prueba la
 * mecánica —que corra las variantes pedidas, que la referencia sea "en uso",
 * que el delta tenga el signo correcto— y que el botón de la pantalla deje el
 * resultado donde la vista lo lee.
 */
class AblacionTest extends TestCase
{
    private const FILTROS = ['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 40];

    public function test_corre_las_variantes_pedidas_y_toma_la_primera_como_referencia(): void
    {
        $tabla = Ablacion::correr(self::FILTROS, [
            'en uso (como está)' => [],
            'sin factor de día' => ['dia_modo' => 0],
        ]);

        if ($tabla['n'] === 0) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        $this->assertCount(2, $tabla['filas']);
        $this->assertSame('en uso (como está)', $tabla['filas'][0]['nombre']);
        $this->assertSame([], $tabla['filas'][0]['cambios']);
        $this->assertSame(['dia_modo' => 0], $tabla['filas'][1]['cambios']);

        // la referencia se compara consigo misma: delta 0
        $this->assertSame(0, $tabla['filas'][0]['delta']);
        $this->assertNotNull($tabla['filas'][0]['mae']);
        $this->assertNotNull($tabla['filas'][1]['mae']);

        // el delta es "esta variante menos la referencia"
        $this->assertSame($tabla['filas'][1]['mae'] - $tabla['filas'][0]['mae'], $tabla['filas'][1]['delta']);
    }

    public function test_no_toca_los_parametros_en_uso(): void
    {
        $antes = \App\Prediccion\Parametros::cargar();

        Ablacion::correr(self::FILTROS, ['sin factor de día' => ['dia_modo' => 0]]);

        $despues = \App\Prediccion\Parametros::cargar();
        $this->assertSame($antes->dia_modo, $despues->dia_modo, 'la ablación no puede cambiar la configuración');
        $this->assertSame($antes->curva_modo, $despues->curva_modo);
    }

    public function test_el_baseline_de_la_placa_se_calcula_solo_con_informacion_pasada(): void
    {
        $base = Ablacion::baselines(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 40], 20);

        if ($base['filas'] === []) {
            $this->markTestSkipped('la base no tiene datos en ese rango');
        }

        $nombres = array_column($base['filas'], 'nombre');
        $this->assertContains('mediana de la placa', $nombres);
        $this->assertContains('motor completo', $nombres);

        foreach ($base['filas'] as $fila) {
            $this->assertNull($fila['error']);
            if ($fila['n'] > 0) {
                $this->assertNotNull($fila['mae']);
                $this->assertGreaterThanOrEqual(0, $fila['mae']);
            }
        }
    }

    public function test_el_boton_deja_la_medicion_en_la_sesion(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/ablacion', 'POST', [
            'dias' => '30',
            'limite' => '40',
        ]);
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = \App\Http\Controllers\PrediccionController::ablacion($peticion);

        $this->assertStringContainsString('/lupita/prediccion/parametros', $respuesta->getTargetUrl());
        $medicion = session('ablacion');
        $this->assertIsArray($medicion);
        $this->assertArrayHasKey('filas', $medicion);
        $this->assertSame(40, $medicion['limite']);
        $this->assertArrayHasKey('desde', $medicion);
    }

    public function test_la_pantalla_muestra_la_tabla_de_la_ablacion(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        app('session')->put('ablacion', [
            'desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 300, 'n' => 120,
            'filas' => [[
                'nombre' => 'en uso (como está)', 'cambios' => [], 'n' => 120, 'mae' => 1200,
                'pct_180' => 27, 'pct_360' => 40, 'pct_ventana' => 70, 'sesgo' => -100, 'error' => null, 'delta' => 0,
            ], [
                'nombre' => 'sin factor de día', 'cambios' => ['dia_modo' => 0], 'n' => 120, 'mae' => 1100,
                'pct_180' => 28, 'pct_360' => 41, 'pct_ventana' => 71, 'sesgo' => -90, 'error' => null, 'delta' => -100,
            ]],
        ]);

        $html = (string) \App\Http\Controllers\PrediccionController::parametros()->render();

        $this->assertStringContainsString('Medir qué aporta y qué resta (ablación)', $html);
        $this->assertStringContainsString('sin factor de día', $html);
        $this->assertStringContainsString('dia_modo=0', $html);
        $this->assertStringContainsString('-100 min', $html);
        $this->assertStringContainsString('prediccion:ablacion', $html);
    }
}
