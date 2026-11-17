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
        // la escalera existe pero vacía
        $this->assertNull($r['pct_180']);
        $this->assertNull($r['pct_30']);
    }

    public function test_la_escalera_de_tolerancias_es_la_misma_que_la_de_aciertos(): void
    {
        // -200, -30, 0, 30, 200, 500, 1000
        $r = Tablero::errores([-200, -30, 0, 30, 200, 500, 1000]);

        foreach (\App\Prediccion\Aciertos::TOLERANCIAS as $minutos) {
            $this->assertArrayHasKey('pct_' . $minutos, $r);
        }

        $this->assertSame(43, $r['pct_30']);   // −30, 0, 30 de 7 = 43 %
        $this->assertSame(43, $r['pct_60']);
        $this->assertSame(43, $r['pct_180']);  // el de ±200 todavía NO entra
        $this->assertSame(71, $r['pct_360']);  // ±200 sí = 5 de 7
        $this->assertSame(86, $r['pct_720']);  // ±500 también = 6 de 7
    }

    public function test_el_monitorista_tambien_trae_la_escalera(): void
    {
        $r = Tablero::monitorista([
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 10:20')],
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 11:30')],
            (object) ['compromiso' => Carbon::parse('2026-11-10 10:00'), 'real' => Carbon::parse('2026-11-10 14:00')],
        ]);

        $this->assertSame(3, $r['n']);
        $this->assertSame(33, $r['pct_30']);    // sólo el de 20 min
        $this->assertSame(67, $r['pct_120']);   // el de 20 y el de 90
        $this->assertSame(67, $r['pct_180']);
        $this->assertSame(100, $r['pct_360']);  // el de 4 h entra
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

    /** Fila de producción: la cuarta fecha es opcional (las guardadas viejas no la tienen). */
    private function filaFechas(?int $rapido, ?int $normal, ?int $tardado, ?int $probable): object
    {
        return (object) [
            'error_rapido_min' => $rapido,
            'error_min' => $normal,
            'error_tardado_min' => $tardado,
            'error_probable_min' => $probable,
        ];
    }

    public function test_fechas_compara_cara_a_cara_solo_las_filas_completas(): void
    {
        $filas = [
            $this->filaFechas(100, 30, 200, 20),    // completa
            $this->filaFechas(100, 90, 200, null),  // sin la cuarta
            $this->filaFechas(null, 60, null, null), // sólo la normal
        ];

        // las cuatro de siempre (el consenso es una quinta, va aparte)
        $t = Tablero::fechas($filas, 180, ['rapido', 'normal', 'tardado', 'probable']);

        // sólo la primera fila tiene las cuatro: el n es el mismo para todas
        $this->assertSame(1, $t['n']);
        $this->assertSame(2, $t['incompletas']);
        $this->assertSame(3, $t['total']);
        $this->assertSame(['rapido', 'normal', 'tardado', 'probable'], $t['seleccion']);
        $this->assertSame([1, 1, 1, 1], array_column($t['filas'], 'n'),
            'cada fecha se mide sobre las MISMAS filas, no sobre las que le tocan');

        // la cuarta queda más cerca (20 min) y se lleva la carrera
        $porClave = array_column($t['filas'], null, 'clave');
        $this->assertSame(20, $porClave['probable']['mae']);
        $this->assertSame(1, $porClave['probable']['mas_cerca']);
        $this->assertSame(100, $porClave['probable']['pct_mas_cerca']);
        $this->assertSame(0, $porClave['normal']['mas_cerca']);

        // las filas sin dato se informan por fecha, para saber qué destildar
        $faltan = array_column($t['opciones'], 'faltan', 'clave');
        $this->assertSame(1, $faltan['rapido']);     // la tercera fila no la tiene
        $this->assertSame(0, $faltan['normal']);
        $this->assertSame(2, $faltan['probable']);
    }

    public function test_fechas_con_dos_fechas_elegidas_entra_mas_gente(): void
    {
        $filas = [
            $this->filaFechas(100, 30, 200, 20),
            $this->filaFechas(100, 90, 200, null),
        ];

        $todas = Tablero::fechas($filas, 180, ['rapido', 'normal', 'tardado', 'probable']);
        $this->assertSame(1, $todas['n']);

        // sin la cuarta (que falta en una fila) entran las dos
        $sinCuarta = Tablero::fechas($filas, 180, ['rapido', 'normal', 'tardado']);
        $this->assertSame(2, $sinCuarta['n']);
        $this->assertSame(0, $sinCuarta['incompletas']);
        $this->assertSame(['rapido', 'normal', 'tardado'], $sinCuarta['seleccion']);
        $this->assertSame([2, 2, 2], array_column($sinCuarta['filas'], 'n'));
        $this->assertCount(3, $sinCuarta['filas'], 'la tabla sólo muestra las elegidas');

        // y la carrera se reparte entre las elegidas
        $suma = array_sum(array_column($sinCuarta['filas'], 'mas_cerca'));
        $this->assertSame(2, $suma);
    }

    public function test_la_seleccion_de_fechas_se_normaliza(): void
    {
        // null o vacía = todas las publicadas (hoy cinco)
        $this->assertSame(Tablero::FECHAS, Tablero::limpiar_seleccion(null));
        $this->assertSame(Tablero::FECHAS, Tablero::limpiar_seleccion([]));
        $this->assertCount(5, Tablero::FECHAS, 'rápido, normal, tardado, probable y consenso');

        // se descarta lo que no es una fecha y se respeta el orden publicado
        $this->assertSame(['rapido', 'probable'], Tablero::limpiar_seleccion(['probable', 'inventada', 'rapido']));
        $this->assertSame(['normal'], Tablero::limpiar_seleccion(['normal', 'normal']));
    }

    public function test_fechas_sin_filas_no_inventa_nada(): void
    {
        $t = Tablero::fechas([], 180);

        $this->assertSame(0, $t['n']);
        $this->assertSame(0, $t['total']);
        $this->assertNull($t['filas'][0]['mae']);
        $this->assertSame(Tablero::FECHAS, array_column($t['opciones'], 'clave'));
    }

    public function test_fechas_puede_promediar_las_elegidas(): void
    {
        $filas = [
            // rápido 100 / normal 30 -> promedio 65: gana la normal
            $this->filaFechas(100, 30, null, null),
            // rápido -100 / normal 100 -> promedio 0: gana el promedio, que cae justo
            $this->filaFechas(-100, 100, null, null),
        ];

        $sin = Tablero::fechas($filas, 180, ['rapido', 'normal']);
        $this->assertCount(2, $sin['filas']);
        $this->assertFalse($sin['promediado']);

        $con = Tablero::fechas($filas, 180, ['rapido', 'normal'], true);

        $this->assertTrue($con['promediado']);
        $this->assertCount(3, $con['filas'], 'las dos elegidas + el promedio');
        $this->assertSame(['rapido', 'normal', 'promedio'], array_column($con['filas'], 'clave'));

        // errores del promedio: 65 y 0 -> MAE 33 y mediana 33
        $promedio = $con['filas'][2];
        $this->assertSame(2, $promedio['n']);
        $this->assertSame(33, $promedio['mae']);
        $this->assertSame('Promedio', $promedio['etiqueta']);
        $this->assertStringContainsString('2 fechas', $promedio['texto']);

        // el promedio compite en la misma carrera
        $this->assertSame(1, $con['filas'][1]['mas_cerca'], 'la normal gana la primera fila');
        $this->assertSame(1, $promedio['mas_cerca'], 'el promedio gana la segunda');
        $this->assertSame(0, $con['filas'][0]['mas_cerca']);
        $this->assertSame(50, $promedio['pct_mas_cerca']);

        $suma = array_sum(array_column($con['filas'], 'mas_cerca'));
        $this->assertSame(2, $suma, 'cada fila se la lleva una sola candidata');
    }

    public function test_promediar_con_una_sola_fecha_no_agrega_fila(): void    {
        $filas = [$this->filaFechas(100, 30, 200, 20)];

        $t = Tablero::fechas($filas, 180, ['normal'], true);

        $this->assertFalse($t['promediado'], 'promediar una sola fecha sería esa fecha');
        $this->assertTrue($t['promediar_pedido'], 'la pantalla necesita saber que se pidió, para avisar');
        $this->assertCount(1, $t['filas']);
    }

    public function test_el_promedio_dice_si_le_gana_a_las_sueltas(): void
    {
        // Filas donde el promedio cae dentro de la tolerancia y las sueltas no,
        // pero la carrera de "más cerca" la sigue ganando una fecha suelta. Es
        // justo el caso que confunde: promediar mejora el acierto por tolerancia
        // y no la cantidad de veces que quedó más cerca.
        $filas = [
            (object) ['error_rapido_min' => 250, 'error_min' => -250,
                      'error_tardado_min' => null, 'error_probable_min' => null],
            (object) ['error_rapido_min' => 0, 'error_min' => 0,
                      'error_tardado_min' => null, 'error_probable_min' => null],
            (object) ['error_rapido_min' => 300, 'error_min' => 300,
                      'error_tardado_min' => null, 'error_probable_min' => null],
        ];

        $t = Tablero::fechas($filas, 180, ['rapido', 'normal'], true);
        $porClave = array_column($t['filas'], null, 'clave');

        // ±3 h: rápido 1 de 3, normal 1 de 3, promedio 2 de 3
        $this->assertSame(33, $porClave['rapido']['pct_180']);
        $this->assertSame(33, $porClave['normal']['pct_180']);
        $this->assertSame(67, $porClave['promedio']['pct_180']);

        // más cerca: gana el rápido 2 de 3 y el promedio 1
        $this->assertSame(67, $porClave['rapido']['pct_mas_cerca']);
        $this->assertSame(33, $porClave['promedio']['pct_mas_cerca']);

        $veredicto = $porClave['promedio']['veredicto'];
        $this->assertSame(-34, $veredicto['mas_cerca'], 'pierde la carrera por 34 puntos');
        $this->assertSame(34, $veredicto['tolerancia'], 'y gana el ±3 h por 34 puntos');
        $this->assertSame(180, $veredicto['tolerancia_min']);

        // las fechas sueltas no llevan veredicto: no compiten contra sí mismas
        $this->assertArrayNotHasKey('veredicto', $porClave['rapido']);
    }

    public function test_la_tabla_de_kpi_dice_quien_gana_en_cada_tolerancia(): void
    {
        // A: gana el rápido con 20 min (entra en ±30)
        // B: gana la normal con 60 min (entra desde ±1h)
        // C: gana el rápido con 100 min (entra desde ±2h)
        $filas = [
            (object) ['error_rapido_min' => 20, 'error_min' => 400,
                      'error_tardado_min' => null, 'error_probable_min' => null],
            (object) ['error_rapido_min' => 400, 'error_min' => 60,
                      'error_tardado_min' => null, 'error_probable_min' => null],
            (object) ['error_rapido_min' => 100, 'error_min' => 200,
                      'error_tardado_min' => null, 'error_probable_min' => null],
        ];

        $t = Tablero::fechas($filas, 180, ['rapido', 'normal']);

        // una fila por tolerancia, todas las publicadas
        $this->assertSame(\App\Prediccion\Aciertos::TOLERANCIAS, array_column($t['kpi'], 'min'));
        $this->assertSame(['±30m', '±1h', '±2h', '±3h', '±6h', '±12h'], array_column($t['kpi'], 'etiqueta'));

        $porMin = array_column($t['kpi'], null, 'min');

        // ±30 m: sólo la fila A entra, y la gana el rápido
        $this->assertSame(1, $porMin[30]['total']);
        $this->assertSame(100, $porMin[30]['fechas']['rapido']['pct']);
        $this->assertSame(0, $porMin[30]['fechas']['normal']['pct']);

        // ±1 h: entran A y B (50/50)
        $this->assertSame(2, $porMin[60]['total']);
        $this->assertSame(50, $porMin[60]['fechas']['rapido']['pct']);
        $this->assertSame(50, $porMin[60]['fechas']['normal']['pct']);

        // ±2 h y de ahí para arriba: entran las tres, el rápido 2 de 3
        foreach ([120, 180, 360, 720] as $minutos) {
            $this->assertSame(3, $porMin[$minutos]['total'], 'denominador de ' . $minutos);
            $this->assertSame(67, $porMin[$minutos]['fechas']['rapido']['pct']);
            $this->assertSame(33, $porMin[$minutos]['fechas']['normal']['pct']);
            $this->assertSame(2, $porMin[$minutos]['fechas']['rapido']['n']);
        }

        // la etiqueta se arma sola: minutos para abajo de 2 h, horas para arriba
        $this->assertSame('±30m', Tablero::kpi_etiqueta(30));
        $this->assertSame('±2h', Tablero::kpi_etiqueta(120));
        $this->assertSame('±12h', Tablero::kpi_etiqueta(720));
    }
}
