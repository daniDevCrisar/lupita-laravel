<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Tablero;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tablero del motor: las cuentas que se dibujan en "Predicciones guardadas".
 *
 * Es puro, así que se prueba con listas armadas a mano. Lo que importa:
 *  - el signo (positivo = el motor se quedó corto / presentó tarde);
 *  - que sin muestra devuelva null y no cero;
 *  - que las filas sin con qué comparar no ensucien los porcentajes.
 */
class TableroTest extends TestCase
{
    /** Fila comparable de producción. */
    private function fila(array $valores = []): object
    {
        return (object) array_merge([
            'ref' => '1',
            'error_min' => 0,
            'en_ventana' => null,
            'evidencia' => 'cadena',
            'origen' => 'api',
            'confianza' => 20,
            'corte' => Carbon::parse('2026-11-10 08:00'),
            'rapido' => null,
            'predicho' => null,
            'tardado' => null,
            'real' => null,
        ], $valores);
    }

    public function test_errores_separa_la_media_del_sesgo_y_no_pierde_el_signo(): void
    {
        $r = Tablero::errores([-60, 0, 120, 300]);

        $this->assertSame(4, $r['n']);
        $this->assertSame(120, $r['mae']);          // (60 + 0 + 120 + 300) / 4
        $this->assertSame(90, $r['sesgo']);         // (−60 + 0 + 120 + 300) / 4
        $this->assertSame(60, $r['error_mediano']); // mediana de los firmados
        $this->assertSame(246, $r['p90']);          // cuantil 0.90 de los absolutos
        $this->assertSame(300, $r['tope']);
        $this->assertSame(1, $r['adelanto']);
        $this->assertSame(2, $r['atraso']);
        $this->assertSame(75, $r['pct_tolerancia']); // 3 de 4 dentro de ±180
    }

    public function test_sin_filas_devuelve_null_y_no_cero(): void
    {
        $r = Tablero::errores([]);

        $this->assertSame(0, $r['n']);
        $this->assertNull($r['mae']);
        $this->assertNull($r['sesgo']);
        $this->assertNull($r['p90']);
        $this->assertNull($r['pct_tolerancia']);
    }

    public function test_agrupar_respeta_el_orden_y_saltea_lo_que_no_tiene_error(): void
    {
        $filas = [
            $this->fila(['evidencia' => 'cadena', 'error_min' => 120]),
            $this->fila(['evidencia' => 'medido', 'error_min' => 30]),
            $this->fila(['evidencia' => 'cadena', 'error_min' => -60]),
            $this->fila(['evidencia' => 'sin_datos', 'error_min' => null]),
        ];

        $grupos = Tablero::por_evidencia($filas);

        $this->assertSame(['medido', 'cadena'], array_column($grupos, 'clave'));
        $this->assertSame(1, $grupos[0]['n']);
        $this->assertSame(30, $grupos[0]['mae']);
        $this->assertSame(2, $grupos[1]['n']);
        $this->assertSame(90, $grupos[1]['mae']);   // (120 + 60) / 2
        $this->assertSame(30, $grupos[1]['sesgo']); // (120 − 60) / 2
    }

    public function test_el_porcentaje_de_ventana_solo_mira_las_filas_que_la_tienen(): void
    {
        $filas = [
            $this->fila(['error_min' => 10, 'en_ventana' => true]),
            $this->fila(['error_min' => 20, 'en_ventana' => false]),
            $this->fila(['error_min' => 30, 'en_ventana' => null]),
        ];

        $grupo = Tablero::por_evidencia($filas)[0];

        $this->assertSame(3, $grupo['n']);
        $this->assertSame(2, $grupo['ventana_n']);
        $this->assertSame(50, $grupo['pct_ventana']); // 1 de 2, la tercera no cuenta
    }

    public function test_por_horizonte_mide_cuanto_antes_se_dijo_la_hora(): void
    {
        $real = Carbon::parse('2026-11-10 12:00');
        $filas = [
            $this->fila(['corte' => Carbon::parse('2026-11-10 11:30'), 'real' => $real, 'error_min' => 10]),
            $this->fila(['corte' => Carbon::parse('2026-11-10 09:00'), 'real' => $real, 'error_min' => 20]),
            $this->fila(['corte' => Carbon::parse('2026-11-09 18:00'), 'real' => $real, 'error_min' => 30]),
            $this->fila(['corte' => Carbon::parse('2026-11-08 06:00'), 'real' => $real, 'error_min' => 40]),
        ];

        $grupos = Tablero::por_horizonte($filas);

        $this->assertSame(['hasta 1 h', '1 a 6 h', '6 a 24 h', 'más de 24 h'],
            array_column($grupos, 'clave'));
        $this->assertSame([1, 1, 1, 1], array_column($grupos, 'n'));
    }

    public function test_posicion_dice_de_que_lado_cayo_la_presentacion_real(): void
    {
        $filas = [];
        foreach ([
            ['09:00', 'antes_rapido'],
            ['11:00', 'primera_mitad'],
            ['12:00', 'segunda_mitad'],   // justo el normal: cuenta como segunda mitad
            ['18:00', 'segunda_mitad'],   // justo el tardado: todavía adentro
            ['19:00', 'despues_tardado'],
        ] as [$hora, $esperado]) {
            $filas[] = $this->fila([
                'rapido' => Carbon::parse('2026-11-10 10:00'),
                'predicho' => Carbon::parse('2026-11-10 12:00'),
                'tardado' => Carbon::parse('2026-11-10 18:00'),
                'real' => Carbon::parse("2026-11-10 $hora"),
            ]);
            $this->assertArrayHasKey($esperado, Tablero::posicion($filas));
        }

        $posicion = Tablero::posicion($filas);
        $this->assertSame(5, $posicion['n']);
        $this->assertSame(1, $posicion['antes_rapido']);
        $this->assertSame(1, $posicion['primera_mitad']);
        $this->assertSame(2, $posicion['segunda_mitad']);
        $this->assertSame(1, $posicion['despues_tardado']);

        // con las cuatro fechas incompletas no inventa posición
        $this->assertSame(0, Tablero::posicion([$this->fila()])['n']);
    }

    public function test_monitorista_cuenta_los_compromisos_cumplidos(): void
    {
        $pares = [
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 09:00'),
                      'despacho' => Carbon::parse('2026-11-09 08:00')],
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 10:00')],
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 13:00'),
                      'despacho' => Carbon::parse('2026-11-09 10:00')],
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => null],  // sin presentar
        ];

        $r = Tablero::monitorista($pares);

        $this->assertSame(3, $r['n']);
        $this->assertSame(2, $r['cumple']);   // el que presentó justo a la hora también cumple
        $this->assertSame(1, $r['tarde']);
        $this->assertSame(67, $r['pct_cumple']);
        $this->assertSame(0, $r['error_mediano']);      // mediana de [−60, 0, +180]
        $this->assertSame(1500, $r['antelacion_min']);  // mediana de [1560, 1440]: 25 h de antelación
    }

    public function test_monitorista_sin_compromisos_no_inventa_porcentaje(): void
    {
        $r = Tablero::monitorista([]);

        $this->assertSame(0, $r['n']);
        $this->assertNull($r['pct_cumple']);
        $this->assertNull($r['antelacion_min']);
    }

    public function test_cara_a_cara_compara_absolutos_y_solo_las_refs_en_comun(): void
    {
        $r = Tablero::cara_a_cara(
            ['A' => -100, 'B' => 300, 'C' => 50],   // C no tiene compromiso
            ['A' => -90, 'B' => 200]
        );

        $this->assertSame(2, $r['n']);
        $this->assertSame(200, $r['mae_motor']);        // (100 + 300) / 2
        $this->assertSame(145, $r['mae_monitorista']);  // (90 + 200) / 2
        $this->assertSame(0, $r['gana_motor']);
        $this->assertSame(2, $r['gana_monitorista']);
        $this->assertSame(0, $r['pct_gana_motor']);
        $this->assertSame(-55, $r['diferencia']);       // el motor pierde por 55 min
    }

    public function test_por_confianza_arma_los_estratos_y_no_deja_fuera_los_extremos(): void
    {
        $filas = [
            $this->fila(['confianza' => 0, 'error_min' => 400]),
            $this->fila(['confianza' => 20, 'error_min' => 300]),
            $this->fila(['confianza' => 41, 'error_min' => 200]),
            $this->fila(['confianza' => 95, 'error_min' => 100]),
            $this->fila(['confianza' => 100, 'error_min' => 50]),
        ];

        $grupos = Tablero::por_confianza($filas);

        $this->assertSame(['0–20', '41–70', '71–95', '96–100'], array_column($grupos, 'clave'));
        $this->assertSame([2, 1, 1, 1], array_column($grupos, 'n'));
        $this->assertSame(350, $grupos[0]['mae']);
        $this->assertSame(50, $grupos[3]['mae']);
    }

    public function test_el_backtest_usa_otro_nombre_para_el_corte_y_para_la_ventana(): void
    {
        $filas = [
            (object) ['ancla' => Carbon::parse('2026-11-10 06:00'), 'real' => Carbon::parse('2026-11-10 12:00'),
                      'error_min' => 30, 'dentro' => true, 'evidencia' => 'parcial', 'confianza' => 60],
        ];

        $horizontes = Tablero::por_horizonte($filas, 180, 'ancla', 'dentro');
        $this->assertSame('6 a 24 h', $horizontes[0]['clave']);
        $this->assertSame(100, $horizontes[0]['pct_ventana']);

        $evidencias = Tablero::por_evidencia($filas, 180);
        $this->assertSame('parcial', $evidencias[0]['clave']);
        $this->assertSame(100, $evidencias[0]['pct_ventana']);
    }
}
