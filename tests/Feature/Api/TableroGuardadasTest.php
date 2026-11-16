<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tablero de "Predicciones guardadas": los KPIs de las tres varas.
 *
 * Lo que se prueba acá es que los números de la pantalla salgan de la base y no
 * de una cuenta inventada:
 *  - el acierto del monitorista (`compromiso_carga` contra la presentación real)
 *    tiene que coincidir con la misma cuenta hecha en SQL;
 *  - un compromiso con fecha imposible no puede arrastrar el MAE;
 *  - la pantalla tiene que dibujar los cinco bloques.
 *
 * El rango es fijo y viejo a propósito: ahí el compromiso está cargado en el
 * 86 % de las referencias (en los últimos días de noviembre casi no se carga).
 */
class TableroGuardadasTest extends TestCase
{
    private const DESDE = '2026-09-01';
    private const HASTA = '2026-10-10';

    private function vista(string $desde = self::DESDE, string $hasta = self::HASTA): array
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/guardadas', 'GET', [
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'limite' => '50',
        ]);
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = \App\Http\Controllers\PrediccionController::guardadas($peticion);

        return [$respuesta, $respuesta->getData()['tablero']];
    }

    public function test_la_pantalla_muestra_las_tres_varas_y_los_parametros(): void
    {
        [$respuesta] = $this->vista();

        $html = (string) $respuesta->render();

        $this->assertStringContainsString('Producción — lo guardado', $html);
        $this->assertStringContainsString('Motor — acierto medido', $html);
        $this->assertStringContainsString('Monitorista —', $html);
        $this->assertStringContainsString('Cara a cara sobre las mismas referencias', $html);
        $this->assertStringContainsString('Parámetros con los que está corriendo', $html);

        // los desgloses que antes no existían
        $this->assertStringContainsString('Por evidencia', $html);
        $this->assertStringContainsString('Por horizonte', $html);
        $this->assertStringContainsString('Por confianza declarada', $html);
    }

    public function test_el_acierto_del_monitorista_coincide_con_la_cuenta_en_sql(): void
    {
        [, $tablero] = $this->vista();
        $monitorista = $tablero['monitorista'];

        $esperado = DB::selectOne(
            "SELECT COUNT(*) AS n,
                    SUM(TIMESTAMPDIFF(MINUTE, compromiso_carga, presenta_para_carga) <= 0) AS cumple,
                    ROUND(AVG(ABS(TIMESTAMPDIFF(MINUTE, compromiso_carga, presenta_para_carga)))) AS mae
             FROM referencias
             WHERE compromiso_carga >= '2000-01-01'
               AND presenta_para_carga BETWEEN ? AND ?
               AND ABS(TIMESTAMPDIFF(MINUTE, compromiso_carga, presenta_para_carga)) <= ?",
            [self::DESDE . ' 00:00:00', self::HASTA . ' 23:59:59', \App\Prediccion\Tablero::TOPE_COMPROMISO_MIN]
        );

        if ((int) $esperado->n === 0) {
            $this->markTestSkipped('la base no tiene compromisos en ese rango');
        }

        $this->assertSame((int) $esperado->n, $monitorista['n'], 'el n del monitorista sale de la base');
        $this->assertSame((int) $esperado->cumple, $monitorista['cumple']);
        $this->assertEqualsWithDelta((int) $esperado->mae, $monitorista['mae'], 1,
            'el MAE del compromiso tiene que ser la media de |real − compromiso|');

        // el porcentaje y la antelación salen de esos mismos números
        $this->assertSame((int) round(100 * (int) $esperado->cumple / (int) $esperado->n),
            $monitorista['pct_cumple']);
        $this->assertNotNull($monitorista['antelacion_min']);
    }

    public function test_un_compromiso_imposible_no_arrastra_el_mae(): void
    {
        [, $tablero] = $this->vista();

        $monitorista = $tablero['monitorista'];
        if ($monitorista['n'] === 0) {
            $this->markTestSkipped('la base no tiene compromisos en ese rango');
        }

        // sin el filtro, un compromiso cargado con año 0 o con meses de diferencia
        // manda el MAE a decenas de miles de minutos
        $this->assertLessThan(\App\Prediccion\Tablero::TOPE_COMPROMISO_MIN, $monitorista['mae']);
        $this->assertGreaterThanOrEqual(0, $monitorista['descartados']);
    }

    public function test_el_motor_se_mide_sobre_referencias_reales_y_no_sobre_lo_guardado(): void
    {
        [, $tablero] = $this->vista();

        $this->assertNotNull($tablero['motor'], 'el motor tiene que correr en el rango');
        $this->assertGreaterThan(0, $tablero['motor']['n']);
        $this->assertNotNull($tablero['motor']['resumen']->mae_min);
        $this->assertNotNull($tablero['motor']['horizonte_min']);

        // producción es otra vara: sale de prediccion_resultados, no del backtest
        $this->assertArrayHasKey('errores', $tablero['produccion']);
        $this->assertArrayHasKey('por_evidencia', $tablero['produccion']);
    }

    public function test_sin_compromisos_no_inventa_porcentajes(): void
    {
        // 1999: antes de cualquier dato de la operación
        [, $tablero] = $this->vista('1999-01-01', '1999-12-31');

        $monitorista = $tablero['monitorista'];
        $this->assertSame(0, $monitorista['n']);
        $this->assertNull($monitorista['pct_cumple']);
        $this->assertSame(0, $tablero['cara_a_cara']['n']);
        $this->assertNull($tablero['cara_a_cara']['mae_motor']);
    }

    public function test_el_corte_del_rango_se_respeta_en_los_dos_extremos(): void
    {
        [, $tablero] = $this->vista();

        $this->assertSame(Carbon::parse(self::DESDE)->toDateString(), $tablero['desde']);
        $this->assertSame(Carbon::parse(self::HASTA)->toDateString(), $tablero['hasta']);
        $this->assertSame(\App\Database\DBPrediccionResultados::TOLERANCIA_ACEPTADA, $tablero['tolerancia']);
    }

    public function test_las_dos_varas_traen_la_escalera_de_tolerancias(): void
    {
        [$vista, $tablero] = $this->vista();

        foreach (\App\Prediccion\Aciertos::TOLERANCIAS as $minutos) {
            $this->assertArrayHasKey('pct_' . $minutos, $tablero['produccion']['errores']);
        }

        if ($tablero['monitorista']['n'] > 0) {
            $this->assertArrayHasKey('pct_180', $tablero['monitorista']);
            $this->assertNotNull($tablero['monitorista']['pct_180']);
        }

        $html = (string) $vista->render();
        $this->assertStringContainsString('Dentro de tolerancias:', $html);
        $this->assertStringContainsString('Dentro de tolerancias (real contra compromiso):', $html);
    }

    public function test_la_produccion_mide_el_horizonte_desde_el_ultimo_evento_de_la_placa(): void
    {
        [$vista, $tablero] = $this->vista();

        if (($tablero['produccion']['horizonte_n'] ?? 0) === 0) {
            $this->markTestSkipped('no hay filas comparables con ancla en ese rango');
        }

        $this->assertNotNull($tablero['produccion']['horizonte_mediano']);
        $this->assertGreaterThan(0, $tablero['produccion']['horizonte_mediano']);
        $this->assertLessThanOrEqual($tablero['produccion']['horizonte_n'],
            $tablero['produccion']['horizonte_corto'] + $tablero['produccion']['horizonte_largo']);

        $html = (string) $vista->render();
        $this->assertStringContainsString('Horizonte desde el ancla', $html);
        $this->assertStringContainsString('dentro de 6 h', $html);
    }
}
