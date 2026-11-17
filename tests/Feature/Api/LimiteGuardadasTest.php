<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccionResultados;
use App\Http\Controllers\PrediccionController;
use App\Models\User;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El combo de límite de "Predicciones guardadas".
 *
 * Antes la pantalla cargaba como máximo 2.000 guardadas (estaba fijo en el código)
 * y el combo sólo ofrecía 50/100/300/600. Ahora ofrece 2.000 / 5.000 / 10.000 /
 * todas, y el listado + las métricas usan ese límite.
 *
 * El MOTOR no puede seguir el combo completo: medido, 2.000 filas dejan 394 MB de
 * pico y 5.000 agotan los 512 MB del host, así que la corrida va con tope.
 */
class LimiteGuardadasTest extends TestCase
{
    private const DESDE = '2026-10-01';
    private const HASTA = '2026-11-16';

    private function vista(array $extra): array
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/guardadas', 'GET', array_merge([
            'fecha_inicio' => self::DESDE,
            'fecha_fin' => self::HASTA,
        ], $extra));
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = PrediccionController::guardadas($peticion);

        return [$respuesta, $respuesta->getData()];
    }

    public function test_el_combo_ofrece_2000_5000_10000_y_todas(): void
    {
        $this->assertContains(2000, PrediccionController::FILAS_GUARDADAS);
        $this->assertContains(5000, PrediccionController::FILAS_GUARDADAS);
        $this->assertContains(10000, PrediccionController::FILAS_GUARDADAS);
        $this->assertContains(0, PrediccionController::FILAS_GUARDADAS, '0 = todas');
        $this->assertSame(2000, PrediccionController::FILAS_GUARDADAS_VALOR, 'abre con 2.000');

        [$vista] = $this->vista([]);
        $html = (string) $vista->render();

        $this->assertStringContainsString('Límite de refs', $html);
        $this->assertStringContainsString('<option value="0"', $html);
        $this->assertStringContainsString('todas', $html);
        $this->assertStringContainsString('10.000', $html);
        // abre con 2.000 elegido
        $this->assertStringContainsString('<option value="2000" selected>', $html);
    }

    public function test_todas_las_guardadas_pasan_las_2000(): void
    {
        $hay = DB::table('prediccion_resultados')->count();
        if ($hay <= 2000) {
            $this->markTestSkipped('la base tiene ' . $hay . ' predicciones guardadas');
        }

        $dosMil = DBPrediccionResultados::resumen(
            Carbon::parse(self::DESDE), Carbon::parse(self::HASTA), 2000);
        $todas = DBPrediccionResultados::resumen(
            Carbon::parse(self::DESDE), Carbon::parse(self::HASTA), 0);

        $this->assertSame(2000, (int) $dosMil['guardadas'], 'con 2.000 se corta en 2.000');
        $this->assertGreaterThan(2000, (int) $todas['guardadas'], '"todas" trae más de 2.000');
        $this->assertLessThanOrEqual(DBPrediccionResultados::TOPE_FILAS, (int) $todas['guardadas']);
    }

    public function test_el_motor_no_pasa_del_tope_de_memoria(): void
    {
        // el tope del motor y el de la corrida son el mismo número
        $this->assertSame(Servicio::TOPE_EVALUACION, PrediccionController::FILAS_MOTOR_TOPE);

        [$vista, $datos] = $this->vista(['limite' => '10000']);

        // el listado va con 10.000; el motor, con su tope
        $this->assertSame(10000, (int) $datos['filtros']['limite']);

        if (($datos['tablero']['motor'] ?? null) === null) {
            $this->markTestSkipped('la corrida del motor no devolvió datos en ese rango');
        }

        $this->assertLessThanOrEqual(PrediccionController::FILAS_MOTOR_TOPE,
            (int) $datos['tablero']['motor']['limite']);

        $html = (string) $vista->render();
        $this->assertStringContainsString('tope de memoria', $html,
            'cuando el combo pide más de lo que el motor puede, la pantalla lo avisa');
    }

    public function test_la_tabla_dibuja_solo_las_primeras_200(): void
    {
        [$vista, $datos] = $this->vista(['limite' => '10000']);

        $this->assertLessThanOrEqual(PrediccionController::FILAS_TABLA_GUARDADAS,
            count($datos['resumen']['filas'] ?? []), 'no se dibujan 10.000 filas de HTML');

        $html = (string) $vista->render();
        $this->assertStringContainsString('se dibujan las primeras '
            . PrediccionController::FILAS_TABLA_GUARDADAS, $html);
    }
}
