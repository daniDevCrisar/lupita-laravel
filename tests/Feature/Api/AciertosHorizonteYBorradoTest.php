<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccionResultados;
use App\Prediccion\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dos cosas que pidió el operador el 14/11/2026:
 *
 *  - TOPE DE HORIZONTE en la tabla de aciertos: las refs donde el camión tardó
 *    días en volver no pueden arrastrar las métricas del motor (medido: hasta 6 h
 *    de horizonte el MAE es 338 min y ±3 h 57 %; pasado 24 h, ±3 h 6 %).
 *  - BOTÓN PARA BORRAR las predicciones guardadas de un rango: el motor se
 *    recalibra seguido y lo guardado con otro modelo ensucia el acierto.
 */
class AciertosHorizonteYBorradoTest extends TestCase
{
    private const FILTROS = ['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 80];

    public function test_el_tope_de_horizonte_saca_filas_de_las_metricas_pero_las_deja_ver(): void
    {
        $sin = Servicio::backtest(self::FILTROS);
        $con = Servicio::backtest(self::FILTROS + ['horizonte_max_h' => 24]);

        if ($sin->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        // las filas de la tabla son las mismas: sólo cambia qué entra en las métricas
        $this->assertSame(count($sin->filas), count($con->filas));
        $this->assertSame(0, $sin->fuera_tope);
        $this->assertGreaterThan(0, $con->fuera_tope);
        $this->assertSame(count($sin->filas) - $con->fuera_tope, $con->resumen_motor->n);

        // cada fila dice su horizonte y si quedó fuera
        foreach ($con->filas as $fila) {
            $this->assertNotNull($fila->horizonte_min);
            $this->assertSame($fila->horizonte_min > 1440, $fila->fuera_tope);
        }
    }

    public function test_el_tope_de_horizonte_mejora_las_metricas(): void
    {
        $sin = Servicio::backtest(self::FILTROS);
        $con = Servicio::backtest(self::FILTROS + ['horizonte_max_h' => 24]);

        if ($sin->resumen_motor->mae_min === null || $con->resumen_motor->mae_min === null) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        $this->assertLessThan($sin->resumen_motor->mae_min, $con->resumen_motor->mae_min,
            'quitar de la cuenta los casos de días sin volver tiene que bajar el MAE');
    }

    public function test_el_tope_en_horas_se_pasa_al_servicio_como_horas(): void
    {
        // 6 h = 360 min: ninguna fila con horizonte > 360 puede entrar
        $con = Servicio::backtest(self::FILTROS + ['horizonte_max_h' => 6]);

        if ($con->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        foreach ($con->filas as $fila) {
            if ($fila->fuera_tope) {
                $this->assertGreaterThan(360, $fila->horizonte_min);
            } else {
                $this->assertLessThanOrEqual(360, $fila->horizonte_min);
            }
        }
        $this->assertSame(6, $con->horizonte_max_h);
    }

    public function test_todas_evalua_mas_refs_que_mil_y_respeta_el_tope_de_evaluacion(): void
    {
        // un rango de 3 meses (el de FILTROS tiene 3 semanas y no llega a 1000)
        $rango = ['desde' => '2026-08-15', 'hasta' => '2026-11-14', 'limite' => 40];

        $cuarenta = Servicio::backtest($rango);
        $todas = Servicio::backtest(['limite' => 0] + $rango);

        if ($cuarenta->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        $this->assertGreaterThan(count($cuarenta->filas), count($todas->filas),
            '"todas" tiene que evaluar más refs que el límite chico');
        $this->assertSame(Servicio::TOPE_EVALUACION, $todas->limite,
            'una corrida abierta se acota al tope de evaluación');
        $this->assertTrue($todas->truncado);
        $this->assertFalse($cuarenta->truncado);
    }

    public function test_la_tabla_muestra_como_maximo_cien_filas(): void
    {
        $this->withoutMiddleware();
        $usuario = \App\Models\User::query()->first();
        if ($usuario !== null) {
            \Illuminate\Support\Facades\Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/aciertos', 'GET', [
            'fecha_inicio' => self::FILTROS['desde'],
            'fecha_fin' => self::FILTROS['hasta'],
            'limite' => '600',
            'horizonte_max_h' => '0',
        ]);

        $vista = \App\Http\Controllers\PrediccionController::aciertos($peticion);
        $datos = $vista->getData();

        $this->assertLessThanOrEqual(
            \App\Http\Controllers\PrediccionController::FILAS_TABLA_ACIERTOS,
            count($datos['filas_tabla']),
            'la tabla no puede dibujar más de 100 filas'
        );
        $this->assertGreaterThanOrEqual(count($datos['filas_tabla']), $datos['filas_candidatas']);

        // las métricas sí se calculan sobre todas las evaluadas
        $this->assertGreaterThanOrEqual($datos['filas_candidatas'], count($datos['aciertos']->filas));
    }

    public function test_el_check_esconde_las_que_estan_fuera_del_tope(): void
    {
        $this->withoutMiddleware();
        $usuario = \App\Models\User::query()->first();
        if ($usuario !== null) {
            \Illuminate\Support\Facades\Auth::login($usuario);
        }

        $comun = [
            'fecha_inicio' => self::FILTROS['desde'],
            'fecha_fin' => self::FILTROS['hasta'],
            'limite' => '600',
            'horizonte_max_h' => '24',
        ];

        $sin = \App\Http\Controllers\PrediccionController::aciertos(Request::create('/x', 'GET', $comun))->getData();
        $con = \App\Http\Controllers\PrediccionController::aciertos(
            Request::create('/x', 'GET', $comun + ['solo_dentro_tope' => '1']))->getData();

        if ($sin['aciertos']->filas === []) {
            $this->markTestSkipped('la base no tiene pares en ese rango');
        }

        $this->assertGreaterThan(0, $sin['aciertos']->fuera_tope);
        $this->assertLessThan($sin['filas_candidatas'], $con['filas_candidatas']);

        foreach ($con['filas_tabla'] as $fila) {
            $this->assertFalse((bool) $fila->fuera_tope, 'con el check no puede quedar ninguna fuera del tope');
        }
        $this->assertTrue($con['solo_dentro_tope']);
    }

    public function test_el_boton_borra_solo_el_rango_y_el_origen_elegidos(): void
    {
        $origen = 'prueba';

        try {
            // dos filas de prueba con cortes distintos
            DB::table('prediccion_resultados')->insert([
                ['ref' => 'ZBORRAR-1', 'placa' => 'ABC', 'ruta_id' => null,
                 'ahora' => '2026-03-10 08:00:00', 'presenta' => '2026-03-10 12:00:00',
                 'presenta_rapido' => null, 'presenta_tardado' => null, 'inicio_carga' => null,
                 'evidencia' => 'cadena', 'confianza' => 30, 'origen' => $origen,
                 'desglose' => '{}', 'advertencias' => '[]', 'pedido_en' => now()],
                ['ref' => 'ZBORRAR-2', 'placa' => 'ABC', 'ruta_id' => null,
                 'ahora' => '2026-06-10 08:00:00', 'presenta' => '2026-06-10 12:00:00',
                 'presenta_rapido' => null, 'presenta_tardado' => null, 'inicio_carga' => null,
                 'evidencia' => 'cadena', 'confianza' => 30, 'origen' => $origen,
                 'desglose' => '{}', 'advertencias' => '[]', 'pedido_en' => now()],
            ]);

            $peticion = Request::create('/lupita/prediccion/guardadas/borrar', 'POST', [
                'fecha_inicio' => '2026-03-01',
                'fecha_fin' => '2026-03-31',
                'origen' => $origen,
                'limite' => '50',
            ]);
            $peticion->setLaravelSession(app('session.store'));

            $respuesta = \App\Http\Controllers\PrediccionController::borrar_guardadas($peticion);

            $this->assertStringContainsString('/lupita/prediccion/guardadas', $respuesta->getTargetUrl());
            $this->assertNotNull(session('estado'));

            $this->assertSame(0, DB::table('prediccion_resultados')
                ->where('ref', 'ZBORRAR-1')->count(), 'la del rango se borra');
            $this->assertSame(1, DB::table('prediccion_resultados')
                ->where('ref', 'ZBORRAR-2')->count(), 'la de fuera del rango no se toca');
        } finally {
            DB::table('prediccion_resultados')->where('origen', $origen)->delete();
        }
    }

    public function test_borrar_sin_datos_avisa_que_no_habia_nada(): void
    {
        $peticion = Request::create('/lupita/prediccion/guardadas/borrar', 'POST', [
            'fecha_inicio' => '1999-01-01',
            'fecha_fin' => '1999-01-02',
        ]);
        $peticion->setLaravelSession(app('session.store'));

        \App\Http\Controllers\PrediccionController::borrar_guardadas($peticion);

        $this->assertStringContainsString('No había predicciones guardadas', (string) session('estado'));
    }
}
