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
    private const DESDE = '2026-10-01';
    private const HASTA = '2026-10-31';

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

    /**
     * La tarjeta de producción deja ELEGIR qué fechas se comparan.
     *
     * El motivo es que la cuarta fecha no está en las guardadas viejas: comparar
     * cada fecha con las filas que le tocan hace que la que aparece en más filas
     * gane por tener más casos fáciles. Con los checks se arma una comparación
     * cara a cara y, al destildar, entran más filas.
     */
    public function test_la_produccion_deja_elegir_que_fechas_comparar(): void
    {
        [$vistaDefault, $tablero] = $this->vista();
        $this->exigirComparables($tablero);
        $fechas = $tablero['fechas'];

        // los cuatro checks, con la etiqueta y cuántas filas no tienen el dato
        $this->assertSame(\App\Prediccion\Tablero::FECHAS, array_column($fechas['opciones'], 'clave'));
        $this->assertArrayHasKey('faltan', $fechas['opciones'][0]);

        // sin elegir nada la pantalla no puede quedar vacía: si no hay filas con
        // las cuatro, se comparan las que sí tienen datos (y se avisa)
        $this->assertGreaterThan(0, $fechas['n']);

        $htmlDefault = (string) $vistaDefault->render();
        $this->assertStringContainsString('Calcular', $htmlDefault);
        foreach (\App\Prediccion\Tablero::FECHAS as $clave) {
            $this->assertStringContainsString('id="fecha-' . $clave . '"', $htmlDefault);
        }

        $publicadas = count(\App\Prediccion\Tablero::FECHAS);

        if ((int) $fechas['comparadas'] < $publicadas) {
            // las guardadas viejas no tienen la cuarta ni el consenso: se cae a
            // las fechas que sí tienen datos, y lo avisa
            $this->assertStringContainsString('se comparan las que sí tienen datos', $htmlDefault);
        } else {
            $this->assertSame($publicadas, (int) $fechas['comparadas'],
                'si todas las filas tienen las fechas publicadas, se comparan todas');
        }

        // una sola fecha: la tabla trae una fila y el n es el de las comparables
        [$vista, $soloNormal] = $this->vistaCon(['comparar' => '1', 'fechas' => ['normal']]);
        $this->assertSame(['normal'], $soloNormal['fechas']['seleccion']);
        $this->assertSame(1, $soloNormal['fechas']['comparadas']);
        $this->assertCount(1, $soloNormal['fechas']['filas']);
        $this->assertSame((int) $soloNormal['produccion']['errores']['n'], (int) $soloNormal['fechas']['n'],
            'la normal está en todas las comparables: su n tiene que ser el de producción');

        // dos fechas: la carrera se reparte entre esas dos y no entre cuatro
        [, $dos] = $this->vistaCon(['comparar' => '1', 'fechas' => ['normal', 'tardado']]);
        $this->assertCount(2, $dos['fechas']['filas']);
        $this->assertSame(
            (int) $dos['fechas']['n'],
            array_sum(array_map(fn ($f) => (int) $f['mas_cerca'], $dos['fechas']['filas'])));

        // la selección viaja en los campos ocultos del botón "Ver"
        $this->assertStringContainsString('name="fechas[]" value="normal"', (string) $vista->render());
    }

    public function test_el_boton_calcular_avisa_cuando_no_hay_ninguna_fecha_tildada(): void
    {
        [$vista, $tablero] = $this->vistaCon(['comparar' => '1']);
        $this->exigirComparables($tablero);

        $this->assertStringContainsString('No tildaste ninguna fecha', $tablero['fechas']['aviso'] ?? '');
        $this->assertGreaterThan(0, $tablero['fechas']['n'], 'igual compara, para no dejar la tabla vacía');
        $this->assertStringContainsString('No tildaste ninguna fecha', (string) $vista->render());
    }

    /**
     * El check "promediar las seleccionadas" agrega la fila del promedio, con sus
     * propios KPIs, y la mete en la misma carrera: así se ve si promediar ayuda.
     */
    public function test_la_produccion_puede_promediar_las_fechas_elegidas(): void
    {
        $fechas = ['rapido', 'normal', 'tardado'];

        [, $sin] = $this->vistaCon(['comparar' => '1', 'fechas' => $fechas]);
        $this->exigirComparables($sin);
        $this->assertFalse($sin['fechas']['promediado']);
        $this->assertCount(3, $sin['fechas']['filas']);

        [$vista, $con] = $this->vistaCon(['comparar' => '1', 'fechas' => $fechas, 'promediar' => '1']);

        $this->assertTrue($con['fechas']['promediado']);
        $this->assertCount(4, $con['fechas']['filas'], 'las tres elegidas + el promedio');
        $this->assertSame('promedio', $con['fechas']['filas'][3]['clave']);

        // el promedio se mide sobre las MISMAS filas y trae KPIs propios
        $this->assertSame((int) $con['fechas']['n'], (int) $con['fechas']['filas'][3]['n']);
        $this->assertNotNull($con['fechas']['filas'][3]['mae']);

        // la carrera se reparte entre las cuatro candidatas
        $this->assertSame(
            (int) $con['fechas']['n'],
            array_sum(array_map(fn ($f) => (int) $f['mas_cerca'], $con['fechas']['filas'])));

        $html = (string) $vista->render();
        $this->assertStringContainsString('promediar las seleccionadas', $html);
        $this->assertStringContainsString('name="promediar" value="1"', $html);
        $this->assertStringContainsString('<strong>Promedio</strong>', $html);
        $this->assertStringContainsString('+ su promedio', $html);

        // el veredicto contra la mejor de las elegidas, en las dos preguntas
        $this->assertArrayHasKey('veredicto', $con['fechas']['filas'][3]);
        $this->assertStringContainsString('contra la mejor de las fechas elegidas', $html);
        $this->assertStringContainsString('±180 min', $html);

        // y la tabla de "quién gana según el KPI": una fila por tolerancia
        $this->assertSame(\App\Prediccion\Aciertos::TOLERANCIAS, array_column($con['fechas']['kpi'], 'min'));
        $this->assertStringContainsString('Quién queda más cerca, según el KPI', $html);
        foreach (['±30m', '±1h', '±2h', '±3h', '±6h', '±12h'] as $etiqueta) {
            $this->assertStringContainsString($etiqueta, $html, 'falta el KPI ' . $etiqueta);
        }
    }

    /**
     * Los cuatro desgloses (evidencia, horizonte, confianza y origen) ahora
     * comparan las CINCO fechas DENTRO de cada tramo. Antes medían sólo la normal,
     * así que no se veía lo único que importa: que en `cadena`, con confianza baja
     * o pasando 24 h, la mejor fecha publicada es otra.
     */
    public function test_cada_desglose_compara_las_cinco_fechas_dentro_del_tramo(): void
    {
        [$vista, $tablero] = $this->vista();
        $this->exigirComparables($tablero);

        $desgloses = $tablero['produccion']['fechas_por'];
        $this->assertSame(['evidencia', 'horizonte', 'confianza', 'origen'], array_keys($desgloses));

        // la selección efectiva es la misma que la de la tabla cara a cara
        $seleccion = $tablero['fechas']['seleccion'];

        $sumas = [];
        foreach ($desgloses as $clave => $desglose) {
            $this->assertSame($clave, $desglose['clave']);
            $this->assertSame(\App\Prediccion\Tablero::dimension_titulo($clave), $desglose['titulo']);
            $this->assertSame($seleccion, array_column($desglose['fechas'], 'clave'),
                'los desgloses comparan las mismas fechas que se tildaron');
            $this->assertNotEmpty($desglose['filas'], 'el rango tiene datos: ' . $clave);

            $suma = 0;
            foreach ($desglose['filas'] as $fila) {
                $suma += (int) $fila['n'];
                $this->assertGreaterThan(0, (int) $fila['n']);
                $this->assertCount(count($seleccion), $fila['celdas']);

                // el MAE marcado es el más bajo de la fila
                $maes = array_filter(array_column($fila['celdas'], 'mae'), fn ($mae) => $mae !== null);
                $this->assertSame(min($maes), (int) $fila['celdas'][$fila['mejor']]['mae'],
                    'el verde de la fila ' . $fila['clave'] . ' tiene que ser el MAE más bajo');
            }

            $sumas[$clave] = $suma;
        }

        // las cuatro tablas se miden sobre el MISMO conjunto (filas completas)
        $this->assertCount(1, array_unique($sumas), 'los cuatro cuadros tienen que sumar el mismo n');
        $this->assertLessThanOrEqual((int) $tablero['produccion']['errores']['n'], reset($sumas),
            'el cara a cara no puede tener más filas que la producción');

        // el horizonte aclara que se mide desde el ancla, igual que el filtro de arriba
        $this->assertSame('horizonte desde el ancla (el que filtra el tope de arriba)',
            $desgloses['horizonte']['nota']);
        $this->assertNull($desgloses['evidencia']['nota']);

        $html = (string) $vista->render();
        $this->assertStringContainsString('Las cinco fechas dentro de cada segmento', $html);
        $this->assertStringContainsString('Más cerca', $html);
        $this->assertStringContainsString('el MAE más bajo de cada tramo va en verde', $html);
    }

    public function test_los_desgloses_siguen_las_fechas_tildadas(): void
    {
        [, $tablero] = $this->vistaCon(['comparar' => '1', 'fechas' => ['normal', 'tardado']]);
        $this->exigirComparables($tablero);

        foreach ($tablero['produccion']['fechas_por'] as $clave => $desglose) {
            $this->assertSame(['normal', 'tardado'], array_column($desglose['fechas'], 'clave'), $clave);
            foreach ($desglose['filas'] as $fila) {
                $this->assertCount(2, $fila['celdas'], 'con dos tildadas no puede haber cinco celdas');
            }
        }
    }

    /**
     * Sin predicciones guardadas que ya presentaron no hay qué comparar: el
     * rango de prueba se queda sin datos cuando se recarga la base.
     */
    private function exigirComparables(array $tablero): void
    {
        if ((int) ($tablero['produccion']['errores']['n'] ?? 0) === 0) {
            $this->markTestSkipped('el rango de prueba no tiene predicciones guardadas que ya presentaron');
        }
    }

    /** La pantalla de guardadas con filtros extra (p. ej. las fechas tildadas). */
    private function vistaCon(array $extra): array
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/guardadas', 'GET', array_merge([
            'fecha_inicio' => self::DESDE,
            'fecha_fin' => self::HASTA,
            'limite' => '50',
        ], $extra));
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = \App\Http\Controllers\PrediccionController::guardadas($peticion);

        return [$respuesta, $respuesta->getData()['tablero']];
    }
}
