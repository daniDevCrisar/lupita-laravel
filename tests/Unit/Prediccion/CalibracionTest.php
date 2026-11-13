<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Calibracion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Calibración: rejilla, puntaje y elección del mejor juego de parámetros.
 *
 * Es aritmética pura sobre los resúmenes que ya produce `Aciertos::resumir()`;
 * no toca base de datos ni corre predicciones.
 */
class CalibracionTest extends TestCase
{
    public function test_la_rejilla_es_el_producto_cartesiano(): void
    {
        $combos = Calibracion::combinaciones(['a' => [1, 2], 'b' => [10, 20, 30]]);

        $this->assertCount(6, $combos);
        $this->assertSame(['a' => 1, 'b' => 10], $combos[0]);
        $this->assertSame(['a' => 1, 'b' => 30], $combos[2]);
        $this->assertSame(['a' => 2, 'b' => 10], $combos[3]);
        $this->assertSame(['a' => 2, 'b' => 30], $combos[5]);
    }

    public function test_una_rejilla_vacia_da_una_sola_combinacion(): void
    {
        $this->assertSame([[]], Calibracion::combinaciones([]));
        $this->assertSame([[]], Calibracion::combinaciones(['a' => []]));
    }

    public function test_la_rejilla_no_puede_pasar_el_tope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Calibracion::combinaciones(['a' => range(1, 30), 'b' => range(1, 30)], 10);
    }

    public function test_con_copia_la_base_sin_mutarla_y_tipa_los_valores(): void
    {
        $base = (object) ['pares_recientes_max' => 20, 'spread_cadena' => 0.30];

        $copia = Calibracion::con($base, [
            'pares_recientes_max' => '12',
            'spread_cadena' => '0.5',
            'clave_que_no_existe' => 9,
        ]);

        $this->assertSame(20, $base->pares_recientes_max);
        $this->assertSame(0.30, $base->spread_cadena);

        $this->assertSame(12, $copia->pares_recientes_max);
        $this->assertSame(0.5, $copia->spread_cadena);
        $this->assertFalse(property_exists($copia, 'clave_que_no_existe'));
    }

    public function test_menor_es_mejor_para_el_mae_y_para_los_porcentajes(): void
    {
        $resumen = (object) ['mae_min' => 120, 'pct_60' => 25, 'pct_180' => 40, 'pct_ventana' => 60];

        $this->assertSame(120.0, Calibracion::puntaje($resumen, 'mae'));
        $this->assertSame(75.0, Calibracion::puntaje($resumen, 'pct_1h'));    // 100 - 25
        $this->assertSame(60.0, Calibracion::puntaje($resumen, 'pct_3h'));    // 100 - 40
        $this->assertSame(40.0, Calibracion::puntaje($resumen, 'pct_ventana'));

        $sinDatos = (object) ['mae_min' => null, 'pct_180' => null, 'pct_ventana' => null];
        $this->assertSame(INF, Calibracion::puntaje($sinDatos, 'mae'));
        $this->assertSame(INF, Calibracion::puntaje($sinDatos, 'pct_3h'));
    }

    public function test_una_metrica_desconocida_avisa(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Calibracion::puntaje((object) ['mae_min' => 1], 'mae_del_vecino');
    }

    public function test_mejor_elige_por_validacion_y_descarta_corridas_con_pocas_filas(): void
    {
        $resultados = [
            (object) [
                'valores' => ['x' => 1],
                'entrenamiento' => (object) ['n' => 100, 'mae_min' => 100],
                'validacion' => (object) ['n' => 50, 'mae_min' => 200],
            ],
            (object) [
                'valores' => ['x' => 2],
                'entrenamiento' => (object) ['n' => 100, 'mae_min' => 500],
                'validacion' => (object) ['n' => 50, 'mae_min' => 150],
            ],
            // gana en entrenamiento pero la validación tiene 5 filas: no cuenta
            (object) [
                'valores' => ['x' => 3],
                'entrenamiento' => (object) ['n' => 100, 'mae_min' => 1],
                'validacion' => (object) ['n' => 5, 'mae_min' => 10],
            ],
        ];

        $mejor = Calibracion::mejor($resultados, 'mae', true, 20);

        $this->assertNotNull($mejor);
        $this->assertSame(['x' => 2], $mejor->valores);
    }

    public function test_mejor_desempata_por_mae_cuando_la_metrica_iguala(): void
    {
        // mismo 20 % a ±3 h, pero distinto error medio: tiene que ganar el de
        // menos MAE, no el primero de la lista
        $resultados = [
            (object) [
                'valores' => ['x' => 1],
                'entrenamiento' => (object) ['n' => 200, 'mae_min' => 1500],
                'validacion' => (object) ['n' => 200, 'mae_min' => 1566, 'pct_180' => 20],
            ],
            (object) [
                'valores' => ['x' => 2],
                'entrenamiento' => (object) ['n' => 200, 'mae_min' => 1000],
                'validacion' => (object) ['n' => 200, 'mae_min' => 1547, 'pct_180' => 20],
            ],
        ];

        $mejor = Calibracion::mejor($resultados, 'pct_3h', true, 20);

        $this->assertNotNull($mejor);
        $this->assertSame(['x' => 2], $mejor->valores);
    }

    public function test_mejor_usa_el_entrenamiento_si_no_hay_validacion(): void
    {
        $resultados = [
            (object) [
                'valores' => ['x' => 1],
                'entrenamiento' => (object) ['n' => 100, 'mae_min' => 300],
                'validacion' => null,
            ],
            (object) [
                'valores' => ['x' => 2],
                'entrenamiento' => (object) ['n' => 100, 'mae_min' => 100],
                'validacion' => null,
            ],
        ];

        $mejor = Calibracion::mejor($resultados, 'mae', true, 20);

        $this->assertNotNull($mejor);
        $this->assertSame(['x' => 2], $mejor->valores);
    }

    public function test_sin_corridas_suficientes_no_elige_nada(): void
    {
        $resultados = [
            (object) [
                'valores' => ['x' => 1],
                'entrenamiento' => (object) ['n' => 3, 'mae_min' => 100],
                'validacion' => (object) ['n' => 3, 'mae_min' => 100],
            ],
        ];

        $this->assertNull(Calibracion::mejor($resultados, 'mae', true, 20));
        $this->assertNull(Calibracion::mejor([], 'mae', true, 20));
    }

    public function test_la_etiqueta_muestra_los_valores_legibles(): void
    {
        $this->assertSame(
            'spread_cadena=0.3 · bins_horarios=8',
            Calibracion::etiqueta(['spread_cadena' => 0.30, 'bins_horarios' => 8])
        );
    }
}
