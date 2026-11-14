<?php

namespace Tests\Feature\Api;

use App\Prediccion\Servicio;
use Tests\TestCase;

/**
 * `Servicio::calibrar()`: el mismo motor que el comando `prediccion:calibrar` y
 * que el botón "Calibrar" de la pantalla de parámetros. Devuelve todo lo medido
 * (entrenamiento, validación, mejor combinación y recomendación) sin imprimir ni
 * guardar nada.
 */
class CalibrarTest extends TestCase
{
    public function test_calibra_cruce_pares_k_y_recomienda_un_valor(): void
    {
        $r = Servicio::calibrar([
            'rejilla' => ['cruce_pares_k' => [3, 8]],
            'limite' => 12,
            'n_minimo' => 1,
        ]);

        if (!$r['ok'] && str_contains((string) $r['error'], 'filas')) {
            $this->markTestSkipped('la base no tiene refs suficientes en la ventana por defecto');
        }

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame('mae', $r['metrica']);
        $this->assertSame(2, $r['combinaciones']);
        $this->assertCount(2, $r['resultados']);
        $this->assertNotNull($r['actual']['entrenamiento']);
        $this->assertNotNull($r['actual']['validacion']);
        $this->assertNotNull($r['mejor']);
        $this->assertNotEmpty($r['recomendacion']);

        $rec = $r['recomendacion'][0];
        $this->assertSame('cruce_pares_k', $rec['clave']);
        $this->assertContains($rec['recomendado'], [3, 8]);
        $this->assertArrayHasKey('difiere', $rec);

        // la recomendación es la del ganador de la validación
        $this->assertSame($r['mejor']['valores']['cruce_pares_k'], $rec['recomendado']);
    }

    public function test_una_rejilla_con_claves_que_no_existen_no_corre(): void
    {
        $r = Servicio::calibrar(['rejilla' => ['no_existe_esta_clave' => [1, 2]]]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no_existe_esta_clave', (string) $r['error']);
        $this->assertSame([], $r['resultados']);
    }

    public function test_una_ventana_vacia_se_avisa_sin_correr(): void
    {
        $r = Servicio::calibrar([
            'desde' => '2026-11-10',
            'hasta' => '2026-11-13',
            'validar_desde' => '2026-11-01',
            'rejilla' => ['cruce_pares_k' => [3]],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('vacía', (string) $r['error']);
    }
}
