<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Filtro por HORIZONTE en "Predicciones guardadas".
 *
 * El horizonte es lo que pasó entre el último evento real de la placa antes del
 * corte y la presentación real. Es lo que más explica el error (hasta 6 h el
 * motor acierta; pasado un día, no), así que la pantalla deja ponerle un tope y
 * las filas que lo pasan quedan fuera de TODAS las métricas, igual que en la
 * tabla de aciertos.
 */
class FiltroHorizonteGuardadasTest extends TestCase
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
            'limite' => '2000',
        ], $extra));
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = \App\Http\Controllers\PrediccionController::guardadas($peticion);

        return [$respuesta, $respuesta->getData()];
    }

    public function test_el_filtro_por_horizonte_deja_solo_las_filas_dentro_del_tope(): void
    {
        [, $todo] = $this->vista([]);
        [, $filtrado] = $this->vista(['horizonte' => '24']);

        $sinTope = $todo['tablero']['produccion'];
        $conTope = $filtrado['tablero']['produccion'];

        if ((int) ($sinTope['horizonte_n'] ?? 0) === 0) {
            $this->markTestSkipped('el rango no tiene filas con horizonte calculable');
        }

        $this->assertSame(24, (int) $filtrado['filtros']['horizonte']);

        // las cuentas cierran: las que tenían horizonte se reparten entre dentro y fuera
        $this->assertSame(
            (int) $sinTope['horizonte_n'],
            (int) $conTope['horizonte_dentro'] + (int) $conTope['horizonte_fuera'],
            'las filas con horizonte se reparten entre las que entran y las que quedan fuera');

        // el n de producción es exactamente las que entraron
        $this->assertSame((int) $conTope['horizonte_dentro'], (int) $conTope['errores']['n']);

        if ((int) $conTope['horizonte_fuera'] > 0) {
            $this->assertLessThan((int) $sinTope['errores']['n'], (int) $conTope['errores']['n'],
                'con tope tienen que quedar menos filas');
        }

        // y la corrida del motor también va con el tope (nunca crece)
        $motorSin = (int) ($todo['tablero']['motor']['n'] ?? 0);
        $motorCon = (int) ($filtrado['tablero']['motor']['n'] ?? 0);

        if ($motorSin > 0) {
            $this->assertLessThanOrEqual($motorSin, $motorCon);
        }
    }

    public function test_la_pantalla_trae_el_select_y_lo_conserva_en_el_boton_calcular(): void
    {
        [$vista, $datos] = $this->vista(['horizonte' => '24']);

        $html = (string) $vista->render();

        $this->assertStringContainsString('name="horizonte"', $html);
        $this->assertStringContainsString('hasta 24 h', $html);
        $this->assertStringContainsString('sin tope', $html);

        // el horizonte viaja en el formulario de las fechas (botón Calcular)
        $this->assertStringContainsString('name="horizonte" value="24"', $html);

        // y la pantalla dice cuántas quedaron fuera
        $this->assertStringContainsString('horizonte <strong>≤ 24 h</strong>', $html);

        $this->assertSame(24, (int) $datos['filtros']['horizonte']);
    }

    public function test_sin_tope_no_se_filtra_nada(): void
    {
        [, $todo] = $this->vista([]);
        [, $cero] = $this->vista(['horizonte' => '0']);

        $this->assertSame(
            (int) $todo['tablero']['produccion']['errores']['n'],
            (int) $cero['tablero']['produccion']['errores']['n']);
        $this->assertSame(0, (int) $cero['tablero']['produccion']['horizonte_fuera']);
    }
}
