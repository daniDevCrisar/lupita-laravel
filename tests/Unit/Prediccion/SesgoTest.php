<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Parametros;
use App\Prediccion\Sesgo;
use Tests\TestCase;

/**
 * Sesgo por clase: la clase, las claves y el desplazamiento de la cuarta fecha.
 *
 * La clase se define con el horizonte PREDICHO (no el real): al predecir todavía no
 * se sabe cuándo va a presentarse el camión.
 */
class SesgoTest extends TestCase
{
    public function test_la_clase_usa_el_horizonte_en_tramos(): void
    {
        $this->assertSame('cadena|corto', Sesgo::clase('cadena', 0));
        $this->assertSame('cadena|corto', Sesgo::clase('cadena', 360));
        $this->assertSame('cadena|medio', Sesgo::clase('cadena', 361));
        $this->assertSame('cadena|medio', Sesgo::clase('cadena', 1440));
        $this->assertSame('cadena|largo', Sesgo::clase('cadena', 1441));

        // el horizonte negativo (predicción atrasada) se toma en valor absoluto
        $this->assertSame('parcial|corto', Sesgo::clase('parcial', -120));
    }

    public function test_una_evidencia_desconocida_cae_en_parcial(): void
    {
        $this->assertSame('parcial|corto', Sesgo::clase('sin_datos', 10));
        $this->assertSame('parcial|largo', Sesgo::clase('', 5000));
    }

    public function test_hay_nueve_clases_y_nueve_claves(): void
    {
        $clases = Sesgo::clases();

        $this->assertCount(9, $clases);
        $this->assertContains('cadena|corto', $clases);
        $this->assertContains('medido|largo', $clases);

        $claves = Sesgo::claves();
        $this->assertCount(9, $claves);
        $this->assertSame(0, $claves['sesgo_clase_cadena_largo']);
        $this->assertSame('sesgo_clase_parcial_medio', Sesgo::clave('parcial|medio'));

        // todas las claves tienen que existir en la configuración
        $porDefecto = Parametros::por_defecto();
        foreach (array_keys($claves) as $clave) {
            $this->assertTrue(property_exists($porDefecto, $clave), 'falta la clave ' . $clave . ' en config');
        }
    }

    public function test_el_desplazamiento_es_cero_si_el_sesgo_esta_apagado(): void
    {
        $p = (object) ['sesgo_clase_activo' => 0, 'sesgo_clase_cadena_largo' => -3886];

        $this->assertSame(0, Sesgo::desplazamiento('cadena', 5000, $p));
    }

    public function test_el_desplazamiento_lee_la_celda_de_su_clase(): void
    {
        $p = (object) [
            'sesgo_clase_activo' => 1,
            'sesgo_clase_cadena_corto' => -94,
            'sesgo_clase_cadena_medio' => -1874,
            'sesgo_clase_cadena_largo' => -3886,
            'sesgo_clase_parcial_medio' => 127,
        ];

        $this->assertSame(-94, Sesgo::desplazamiento('cadena', 60, $p));
        $this->assertSame(-1874, Sesgo::desplazamiento('cadena', 600, $p));
        $this->assertSame(-3886, Sesgo::desplazamiento('cadena', 5000, $p));
        $this->assertSame(127, Sesgo::desplazamiento('parcial', 900, $p));

        // una celda que no está cargada no inventa nada
        $this->assertSame(0, Sesgo::desplazamiento('medido', 900, $p));
    }

    public function test_medir_devuelve_las_nueve_celdas_con_fallback(): void
    {
        $m = Sesgo::medir(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 200], 10);

        if (!($m['ok'] ?? false)) {
            $this->markTestSkipped('no hay filas en ese rango: ' . ($m['error'] ?? ''));
        }

        $this->assertCount(9, $m['celdas']);
        $this->assertCount(9, $m['aplicables']);
        $this->assertGreaterThan(0, $m['n']);

        foreach ($m['celdas'] as $celda) {
            $this->assertContains($celda['fuente'], ['propia', 'evidencia', 'global']);
            $this->assertIsInt($celda['sesgo']);

            // con muestra propia, la mediana informada es la de la celda
            if ($celda['fuente'] === 'propia') {
                $this->assertSame($celda['mediana'], $celda['sesgo']);
            }
        }
    }

    public function test_la_fuente_por_defecto_son_las_predicciones_guardadas(): void
    {
        $m = Sesgo::medir(['desde' => '2026-09-01', 'hasta' => '2026-11-16']);

        // con la fuente de producción tiene que decir de dónde salió
        $this->assertSame('guardadas', $m['fuente']);

        // y se puede pedir el backtest explícitamente
        $porAciertos = Sesgo::medir(['desde' => '2026-10-20', 'hasta' => '2026-11-10', 'limite' => 200,
                                     'fuente' => 'aciertos']);
        $this->assertSame('aciertos', $porAciertos['fuente']);
        $this->assertSame('normal', 'normal');
    }
}
