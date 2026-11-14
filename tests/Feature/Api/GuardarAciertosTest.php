<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccionResultados;
use App\Prediccion\Servicio;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Botón "Guardar estas predicciones" de la tabla de aciertos.
 *
 * Deja en `prediccion_resultados` lo que el motor dijo para cada ref, con el corte
 * que usa la tabla, para poder comparar después contra la presentación real. Y el
 * resumen tiene que verlo: la consulta tenía `AS real`, que es palabra reservada en
 * MySQL 8, así que fallaba en silencio y el panel siempre mostraba cero.
 */
class GuardarAciertosTest extends TestCase
{
    private const ORIGEN = 'prueba';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->delete();
        parent::tearDown();
    }

    public function test_guarda_las_predicciones_de_la_corrida_y_no_duplica(): void
    {
        $aciertos = Servicio::backtest(['desde' => '2026-11-07', 'hasta' => '2026-11-13', 'limite' => 40]);

        if ($aciertos->filas === []) {
            $this->markTestSkipped('la base no tiene filas en ese rango');
        }

        $primera = DBPrediccionResultados::guardar_filas($aciertos->filas, self::ORIGEN);
        $this->assertNull($primera['error']);
        $this->assertGreaterThan(0, $primera['guardadas']);

        // apretar el botón dos veces no duplica
        $segunda = DBPrediccionResultados::guardar_filas($aciertos->filas, self::ORIGEN);
        $this->assertSame(0, $segunda['guardadas']);
        $this->assertSame($primera['guardadas'], $segunda['saltadas']);

        $this->assertSame($primera['guardadas'],
            DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->count());
    }

    public function test_el_resumen_ve_lo_guardado_en_el_rango(): void
    {
        $desde = '2026-11-07';
        $hasta = '2026-11-13';
        $aciertos = Servicio::backtest(['desde' => $desde, 'hasta' => $hasta, 'limite' => 40]);

        if ($aciertos->filas === []) {
            $this->markTestSkipped('la base no tiene filas en ese rango');
        }

        DBPrediccionResultados::guardar_filas($aciertos->filas, self::ORIGEN);

        // la consulta del resumen tiene que correr (antes reventaba por `AS real`)
        $resumen = DBPrediccionResultados::resumen($aciertos->desde, $aciertos->hasta, 500);

        $this->assertGreaterThan(0, $resumen['guardadas']);
        $this->assertGreaterThan(0, count($resumen['filas']));
        $this->assertNotNull($resumen['filas'][0]->corte);
    }

    public function test_el_boton_guarda_y_vuelve_a_la_tabla_con_aviso(): void
    {
        $maximo = (int) DB::table('prediccion_resultados')->max('id');

        $peticion = \Illuminate\Http\Request::create('/lupita/prediccion/aciertos/guardar', 'POST', [
            'fecha_inicio' => '2026-11-07',
            'fecha_fin' => '2026-11-13',
            'limite' => '40',
        ]);
        $peticion->setLaravelSession(app('session.store'));

        try {
            $respuesta = \App\Http\Controllers\PrediccionController::guardar_aciertos($peticion);

            // vuelve a la MISMA tabla con los mismos filtros (no con back())
            $this->assertStringContainsString('/lupita/prediccion/aciertos', $respuesta->getTargetUrl());
            $this->assertStringContainsString('limite=40', $respuesta->getTargetUrl());
            $this->assertNotNull(session('estado'), 'el POST tiene que dejar un aviso visible');
        } finally {
            DB::table('prediccion_resultados')->where('origen', 'aciertos')->where('id', '>', $maximo)->delete();
        }
    }

    public function test_sin_hora_predicha_no_se_guarda_nada(): void
    {
        $filas = [
            (object) ['ref' => 'X1', 'placa' => 'ABC', 'ancla' => \Carbon\Carbon::parse('2026-11-10 08:00'),
                      'predicho' => null, 'rapido' => null, 'tardado' => null,
                      'evidencia' => 'sin_datos', 'confianza' => 0],
        ];

        $r = DBPrediccionResultados::guardar_filas($filas, self::ORIGEN);

        $this->assertSame(0, $r['guardadas']);
        $this->assertSame(1, $r['saltadas']);
        $this->assertNull($r['error']);
    }
}
