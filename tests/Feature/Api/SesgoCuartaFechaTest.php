<?php

namespace Tests\Feature\Api;

use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use App\Prediccion\Sesgo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cuarta fecha (`presenta_probable`): la hora publicada corregida con la mediana del
 * error de su clase (evidencia × tramo del horizonte predicho).
 *
 * Los tests tocan `prediccion_config` (las 9 claves del sesgo), así que guardan y
 * restauran lo que había para no ensuciar al resto de la suite.
 */
class SesgoCuartaFechaTest extends TestCase
{
    /** @var array<string, string|null> claves del sesgo tal como estaban */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_merge(['sesgo_clase_activo'], array_keys(Sesgo::claves())) as $clave) {
            $fila = DB::table('prediccion_config')->where('clave', $clave)->first();
            $this->original[$clave] = $fila !== null ? (string) $fila->valor : null;
        }

        $this->olvidarParametros();
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $clave => $valor) {
            DB::table('prediccion_config')->where('clave', $clave)->delete();
            if ($valor !== null) {
                DB::table('prediccion_config')->insert(['clave' => $clave, 'valor' => $valor, 'updated_at' => now()]);
            }
        }

        $this->olvidarParametros();
        parent::tearDown();
    }

    /** `Parametros` memoiza por request: en un test hay que soltarlo. */
    private function olvidarParametros(): void
    {
        $reflexion = new \ReflectionClass(Parametros::class);
        $propiedad = $reflexion->getProperty('cache');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, null);
    }

    /** Deja aplicados unos sesgos conocidos. */
    private function aplicarSesgos(array $valores): void
    {
        Parametros::guardar(array_merge($valores, ['sesgo_clase_activo' => 1]));
        $this->olvidarParametros();
    }

    public function test_la_cuarta_fecha_se_mueve_con_el_sesgo_de_su_clase(): void
    {
        // con el sesgo apagado la cuarta fecha es la normal
        Parametros::guardar(['sesgo_clase_activo' => 0]);
        $this->olvidarParametros();
        $apagado = Servicio::backtest(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 120]);

        if ($apagado->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        $this->assertFalse($apagado->sesgo_activo);
        foreach ($apagado->filas as $fila) {
            if ($fila->probable !== null && $fila->predicho !== null) {
                $this->assertSame($fila->predicho->format('Y-m-d H:i'), $fila->probable->format('Y-m-d H:i'));
            }
        }

        // con un sesgo grande aplicado, cambia
        $this->aplicarSesgos(array_fill_keys(array_keys(Sesgo::claves()), 600));
        $encendido = Servicio::backtest(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 120]);

        $this->assertTrue($encendido->sesgo_activo);
        $movidas = 0;
        $exactas = 0;
        foreach ($encendido->filas as $fila) {
            if ($fila->probable === null || $fila->predicho === null) {
                continue;
            }
            $minutos = (int) round(($fila->probable->getTimestamp() - $fila->predicho->getTimestamp()) / 60);
            // sesgo +600 = el motor venía publicando 600 min DESPUÉS de lo real,
            // así que la cuarta fecha se corre 600 min hacia atrás. OJO: si esa
            // corrección cae antes de lo físicamente posible (el camión todavía
            // tiene que quedar libre y volver a cargar), la física manda y se
            // acota: por eso el rango es [-600, 0] y no exactamente -600.
            $this->assertLessThanOrEqual(0, $minutos, 'la cuarta fecha se corre hacia atrás');
            $this->assertGreaterThanOrEqual(-600, $minutos, 'nunca más atrás de lo que pide el sesgo');

            if ($minutos === -600) {
                $exactas++;
            }
            $movidas++;
        }
        $this->assertGreaterThan(0, $movidas);
        $this->assertGreaterThan(0, $exactas, 'la mayoría de las filas se corre los 600 min del sesgo');
    }

    public function test_el_resumen_de_la_cuarta_fecha_se_mide_aparte(): void
    {
        $this->aplicarSesgos(array_fill_keys(array_keys(Sesgo::claves()), 0));

        $corrida = Servicio::backtest(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 120]);

        if ($corrida->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        // con sesgo 0 la cuarta fecha es la normal: los dos resúmenes coinciden
        $this->assertSame($corrida->resumen_motor->n, $corrida->resumen_probable->n);
        $this->assertSame($corrida->resumen_motor->mae_min, $corrida->resumen_probable->mae_min);
    }

    public function test_la_ventana_se_mide_contra_la_cuarta_fecha_cuando_esta_aplicada(): void
    {
        Parametros::guardar(['sesgo_clase_activo' => 0]);
        $this->olvidarParametros();
        $apagada = Servicio::ventana_residuos(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 200], 0.7, 40);

        $this->aplicarSesgos(array_fill_keys(array_keys(Sesgo::claves()), 300));
        $encendida = Servicio::ventana_residuos(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 200], 0.7, 40);

        if (!($apagada['ok'] ?? false) || !($encendida['ok'] ?? false)) {
            $this->markTestSkipped('no hay filas suficientes para medir la ventana');
        }

        $this->assertSame('normal', $apagada['residuos_de']);
        $this->assertSame('probable', $encendida['residuos_de']);
    }

    public function test_el_boton_medir_deja_la_tabla_y_aplicar_guarda_las_claves(): void
    {
        $this->withoutMiddleware();
        $usuario = \App\Models\User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $medir = Request::create('/lupita/prediccion/sesgo', 'POST',
            ['dias' => '45', 'limite' => '200', 'fuente' => 'aciertos']);
        $medir->setLaravelSession(app('session.store'));
        $respuesta = \App\Http\Controllers\PrediccionController::calibrar_sesgo($medir);

        $this->assertStringContainsString('/lupita/prediccion/parametros', $respuesta->getTargetUrl());
        $medicion = session('sesgo_medido');
        $this->assertIsArray($medicion);
        $this->assertArrayHasKey('aplicables', $medicion);
        $this->assertSame('aciertos', $medicion['fuente'] ?? null);

        if (!($medicion['ok'] ?? false)) {
            $this->markTestSkipped('la base no tiene filas para medir: ' . ($medicion['error'] ?? ''));
        }

        // aplicar lo medido
        $aplicar = Request::create('/lupita/prediccion/sesgo/aplicar', 'POST',
            array_merge($medicion['aplicables'], ['sesgo_clase_activo' => '1']));
        $aplicar->setLaravelSession(app('session.store'));

        $r = \App\Http\Controllers\PrediccionController::aplicar_sesgo($aplicar);
        $this->assertStringContainsString('/lupita/prediccion/parametros', $r->getTargetUrl());
        $this->assertStringContainsString('Sesgos aplicados', (string) session('estado'));

        $this->olvidarParametros();
        $enUso = Parametros::cargar();
        $this->assertSame(1, (int) $enUso->sesgo_clase_activo);
        $this->assertSame($medicion['aplicables']['sesgo_clase_cadena_largo'],
            (int) $enUso->sesgo_clase_cadena_largo);
    }

    public function test_la_tabla_de_aciertos_muestra_la_cuarta_fecha(): void
    {
        $this->withoutMiddleware();
        $usuario = \App\Models\User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $filtros = ['fecha_inicio' => '2026-10-20', 'fecha_fin' => '2026-11-10', 'limite' => '40'];

        // apagado: la columna existe y dice que es igual a la normal
        Parametros::guardar(['sesgo_clase_activo' => 0]);
        $this->olvidarParametros();
        $apagado = (string) \App\Http\Controllers\PrediccionController::aciertos(
            Request::create('/x', 'GET', $filtros))->render();

        $this->assertStringContainsString('Más probable', $apagado);
        $this->assertStringContainsString('4.ª fecha', $apagado);
        $this->assertStringContainsString('igual a la normal', $apagado);

        // encendido con un sesgo grande: la fila muestra la corrección y su error
        $this->aplicarSesgos(array_fill_keys(array_keys(Sesgo::claves()), 240));
        $encendido = (string) \App\Http\Controllers\PrediccionController::aciertos(
            Request::create('/x', 'GET', $filtros))->render();

        $this->assertStringContainsString('−240 min', $encendido, 'la fila tiene que decir cuánto se corrió');
        $this->assertStringContainsString('más cerca:', $encendido, 'tiene que decir cuál quedó más cerca');
        $this->assertStringContainsString('más cerca de las cinco', $encendido,
            'la carrera del encabezado compara todas las fechas publicadas');
        $this->assertStringContainsString('Consenso', $encendido, 'el consenso también se mide');
        $this->assertStringNotContainsString('igual a la normal', $encendido);
    }

    /**
     * La tabla de aciertos tenía el error de la normal y el de la probable, pero
     * NO el de la rápida y la tardada: la comparación "más cerca" se quedaba con
     * dos fechas sin que se notara. Acá se fija que el backtest las traiga.
     */
    public function test_el_backtest_trae_el_error_de_las_cuatro_fechas(): void
    {
        $b = Servicio::backtest(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 40]);

        $this->assertGreaterThan(0, count($b->filas), 'la base no tiene filas en ese rango');

        $conRapida = 0;
        foreach ($b->filas as $fila) {
            $this->assertObjectHasProperty('error_rapido_min', $fila);
            $this->assertObjectHasProperty('error_tardado_min', $fila);
            // quinta fecha: el consenso, con su hora y su error
            $this->assertObjectHasProperty('consenso', $fila);
            $this->assertObjectHasProperty('error_consenso_min', $fila);

            if ($fila->rapido === null || $fila->error_rapido_min === null) {
                continue;
            }

            // convención: error = hora publicada − hora real
            $esperado = (int) round(($fila->rapido->getTimestamp() - $fila->real->getTimestamp()) / 60);
            $this->assertSame($esperado, $fila->error_rapido_min);

            if ($fila->consenso !== null) {
                $esperadoConsenso = (int) round(($fila->consenso->getTimestamp() - $fila->real->getTimestamp()) / 60);
                $this->assertSame($esperadoConsenso, $fila->error_consenso_min);
                // y cae entre la rápida y la tardada
                $this->assertGreaterThanOrEqual($fila->rapido->getTimestamp(), $fila->consenso->getTimestamp());
                $this->assertLessThanOrEqual($fila->tardado->getTimestamp(), $fila->consenso->getTimestamp());
            }

            $conRapida++;
        }

        $this->assertGreaterThan(0, $conRapida, 'ninguna fila trajo el error de la rápida');

        // la carrera cuenta todas las fechas sobre las mismas filas de las métricas
        $this->assertSame($b->resumen_motor->n, $b->carrera->n);
        $this->assertSame($b->carrera->n,
            $b->carrera->rapido + $b->carrera->normal + $b->carrera->tardado
            + $b->carrera->probable + $b->carrera->consenso);
    }

    public function test_el_api_publica_la_cuarta_fecha_y_la_clase(): void
    {
        $this->withoutMiddleware();

        // una ref cualquiera con ruta
        $fila = DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        $ahora = \Carbon\Carbon::parse($fila->presenta_para_carga)->subHours(6)->format('Y-m-d H:i');
        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref . '&ahora=' . urlencode($ahora));

        $respuesta->assertOk();
        $this->assertNotNull($respuesta->json('presenta_probable'));
        $this->assertArrayHasKey('sesgo', (array) $respuesta->json());
        $this->assertNotNull($respuesta->json('sesgo.clase'));

        // la lista de presentaciones trae las cinco fechas publicadas
        $claves = array_column((array) $respuesta->json('presentaciones'), 'clave');
        $this->assertContains('probable', $claves);
        $this->assertContains('consenso', $claves);
        $this->assertCount(5, $claves);

        // y el consenso viaja como campo propio, entre la rápida y la tardada
        $this->assertNotNull($respuesta->json('presenta_consenso'));
        $this->assertGreaterThanOrEqual(
            strtotime((string) $respuesta->json('presenta_rapido')),
            strtotime((string) $respuesta->json('presenta_consenso')));
        $this->assertLessThanOrEqual(
            strtotime((string) $respuesta->json('presenta_tardado')),
            strtotime((string) $respuesta->json('presenta_consenso')));

        // cada fecha viaja con SU construcción: no sólo la normal tiene pasos
        $presentaciones = (array) $respuesta->json('presentaciones');
        $porClave = [];

        foreach ($presentaciones as $presentacion) {
            $this->assertArrayHasKey('construccion', $presentacion, 'falta la construcción de ' . ($presentacion['clave'] ?? '?'));
            $this->assertArrayHasKey('delta_normal_min', $presentacion);
            $this->assertNotSame('', (string) $presentacion['construccion']);
            $porClave[$presentacion['clave']] = $presentacion;
        }

        // la normal es la base: delta 0 y la construcción remite a los pasos
        $this->assertSame(0, $porClave['normal']['delta_normal_min']);
        $this->assertStringContainsString('los pasos', $porClave['normal']['construccion']);

        // el rápido va antes y el tardado después: el signo del delta lo dice. La
        // excepción es la banda invertida: la predicción quedó atrasada (la normal se
        // recorta a `ahora`) y el rápido, que respeta el piso físico y la cuarta
        // fecha, cae después. El motor lo avisa en vez de publicar un rápido falso.
        $advertencias = array_map(fn ($a) => explode(':', (string) $a)[0], (array) $respuesta->json('advertencias'));

        if (in_array('banda_invertida', $advertencias, true)) {
            $this->assertContains('atrasado', $advertencias);
            $this->assertGreaterThan(0, $porClave['rapido']['delta_normal_min'],
                'con la banda invertida el rápido queda después de la normal y se avisa');
        } else {
            $this->assertLessThanOrEqual(0, $porClave['rapido']['delta_normal_min']);
        }

        $this->assertGreaterThanOrEqual(0, $porClave['tardado']['delta_normal_min']);
    }
}
