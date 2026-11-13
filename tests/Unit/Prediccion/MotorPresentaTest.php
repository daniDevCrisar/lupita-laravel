<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\MotorPresenta;
use Carbon\Carbon;
use Tests\TestCase;

class MotorPresentaTest extends TestCase
{
    private function parametros(array $over = []): object
    {
        return (object) array_merge([
            'tope_hueco_min' => 10080, 'tope_etapa_min' => 2880,
            'max_desplazamiento_min' => 600, 'traslado_defecto_min' => 240,
            'muerto_defecto_min' => 60, 'espera_carga_defecto_min' => 90,
            'bins_horarios' => 8, 'edad_ancla_max_h' => 72, 'frescura_horizonte_h' => 24,
        ], $over);
    }

    private function entrada(array $over = []): object
    {
        return (object) array_merge([
            'ahora' => Carbon::parse('2026-11-14 10:00'),
            'ref' => 'REQ1', 'planta_carga' => 'LOC_CARAL',
            'ref_presenta' => null,
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'etapas' => ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45, 'ciclo' => 435],
            'calibrador' => (object) ['gap_min' => 300, 'p25_min' => 240, 'p75_min' => 420,
                                      'base_min' => 300, 'residual_min' => 0, 'n' => 6, 'nivel' => 'medido'],
            'traslado' => (object) ['min' => 180, 'fuente' => 'ruta_inversa'],
            'ventanas' => [],
            'espera_carga_min' => 100,
            'parametros' => $this->parametros(),
        ], $over);
    }

    public function test_ancla_cerrada_mas_hueco_calibrado(): void
    {
        $r = MotorPresenta::predecir($this->entrada());

        // 09:00 + hueco 300' = 14:00 ; inicio de carga = 14:00 + espera 100' = 15:40
        $this->assertSame('2026-11-14 14:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 15:40', $r->inicio_carga->format('Y-m-d H:i'));
        $this->assertSame('medido', $r->evidencia);
        $this->assertSame(100, $r->espera_min);
        // el hueco incluye el regreso: 300 totales, 180 de traslado, 120 de espera y descanso
        $this->assertSame(300, collect($r->desglose)->firstWhere('concepto', 'hueco_total')['min']);
        $this->assertSame(180, collect($r->desglose)->firstWhere('concepto', 'traslado_regreso')['min']);
        $this->assertSame(120, collect($r->desglose)->firstWhere('concepto', 'tiempo_muerto')['min']);
    }

    public function test_ancla_a_mitad_de_viaje_suma_etapas_faltantes(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
        ]));

        // inicio_ruta -> falta e4+e5+e6 = 180+60+45 = 285; 09:00 + 285' = 13:45; + 300' = 18:45
        $this->assertSame('2026-11-14 18:45', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(285, collect($r->desglose)->firstWhere('concepto', 'restante_viaje')['min']);
    }

    public function test_sin_pares_usa_la_cadena_y_baja_la_evidencia(): void
    {
        $r = MotorPresenta::predecir($this->entrada(['calibrador' => null]));

        // sin calibracion: t_fin + traslado 180' + muerto por defecto 60' = 09:00 + 240' = 13:00
        $this->assertSame('2026-11-14 13:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('cadena', $r->evidencia);
        $this->assertLessThanOrEqual(45, $r->confianza);
        $this->assertSame(60, collect($r->desglose)->firstWhere('concepto', 'tiempo_muerto')['min']);
    }

    public function test_sin_traslado_ni_hueco_no_inventa_hora(): void
    {
        $r = MotorPresenta::predecir($this->entrada(['calibrador' => null, 'traslado' => null]));

        $this->assertNull($r->presenta);
        $this->assertSame('sin_datos', $r->evidencia);
        $this->assertNotEmpty($r->advertencias);
    }

    public function test_prediccion_en_el_pasado_se_marca_atrasada(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-16 10:00'),
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
        ]));

        $this->assertTrue($r->presenta->greaterThanOrEqualTo(Carbon::parse('2026-11-16 10:00')));
        $this->assertContains('atrasado', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
        // se conserva la hora cruda para poder decir "debería haber presentado a las X"
        $this->assertTrue($r->atrasado);
        $this->assertSame('2026-11-14 14:00', $r->presenta_crudo->format('Y-m-d H:i'));
    }

    public function test_ref_que_ya_presento(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ref_presenta' => Carbon::parse('2026-11-14 08:30'),
        ]));

        $this->assertTrue($r->ya_presento);
        $this->assertNull($r->presenta);
    }

    public function test_la_ventana_mueve_la_prediccion_nocturna(): void
    {
        // el camión queda libre a las 20:00 y el hueco lo deja a la 01:00,
        // dentro de su franja muerta: la predicción se corre a las 06:00
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 20:00'),
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 20:00')],
            'ventanas' => [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 360]],
        ]));

        $this->assertSame('2026-11-15 06:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(300, collect($r->desglose)->firstWhere('concepto', 'espera_ventana')['min']);
    }

    public function test_etapa_vencida_se_acota_a_ahora_y_advierte(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-13 09:00')],
        ]));

        // 13-11 09:00 + 285' cae antes de ahora: t_fin = ahora (14-11 10:00) + 300' = 15:00
        $this->assertSame('2026-11-14 15:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertContains('etapa_vencida', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_hueco_medido_menor_que_el_traslado_se_respeta_y_avisa(): void
    {
        // el hueco sale de 5 pares reales de la placa: manda sobre el promedio de ruta
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => (object) ['gap_min' => 120, 'p25_min' => 90, 'p75_min' => 240,
                                      'base_min' => 120, 'residual_min' => 0, 'n' => 5, 'nivel' => 'medido'],
            'traslado' => (object) ['min' => 600, 'fuente' => 'ruta_medida'],
        ]));

        $this->assertSame('2026-11-14 11:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertContains('hueco_menor_que_traslado', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_ancla_en_el_futuro_se_acota_a_ahora(): void
    {
        // un hito mal cargado (fecha posterior al corte) no puede adelantar la predicción
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 10:00'),
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-12-01 09:00')],
        ]));

        $this->assertSame('2026-11-14 15:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertContains('ancla_futura', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_ancla_mas_vieja_que_el_maximo_no_predice(): void
    {
        // 60 dias sin hitos: no se devuelve "ahora" como si fuera una predicción,
        // se dice que no hay con qué predecir
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 10:00'),
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-09-15 11:30')],
        ]));

        $this->assertNull($r->presenta);
        $this->assertSame('sin_datos', $r->evidencia);
        $this->assertContains('ancla_vieja', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_el_desglose_suma_exactamente_lo_que_hay_hasta_presenta(): void
    {
        // con ancla cerrada, la hora sale de ancla + hueco + desplazamiento:
        // si el desglose no suma eso, la explicación de la pantalla está mintiendo
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'ventanas' => [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 360]],
        ]));

        $suma = 0;
        foreach ($r->desglose as $fila) {
            if (in_array($fila['concepto'], ['restante_viaje', 'hueco_total', 'espera_ventana'], true)) {
                $suma += $fila['min'];
            }
        }

        $desdeAncla = (int) round(($r->presenta->getTimestamp() - $r->ancla->fecha->getTimestamp()) / 60);
        $this->assertSame($desdeAncla, $suma);
    }

    public function test_la_confianza_baja_con_un_horizonte_de_mas_de_24_horas(): void
    {
        // hueco de 82 h: la predicción queda a más de 3 días y la confianza tiene
        // que pagarlo (en Carbon 3 la diferencia con signo es al revés y este
        // castigo no se aplicaba nunca)
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => (object) ['gap_min' => 5000, 'p25_min' => 4000, 'p75_min' => 6000,
                                      'base_min' => 5000, 'residual_min' => 0, 'n' => 4, 'nivel' => 'medido'],
        ]));

        $this->assertGreaterThan(0, $r->presenta->getTimestamp() - $r->ahora->getTimestamp());
        $this->assertStringContainsString('horizonte', $r->confianza_motivo);
        $this->assertLessThan(75, $r->confianza);
    }

    public function test_el_desglose_cuadra_tambien_cuando_la_prediccion_se_recorta(): void
    {
        $casos = [
            'atrasado' => [
                'ahora' => '2026-11-16 10:00',
                'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                     'fecha' => Carbon::parse('2026-11-14 09:00')],
            ],
            'etapa_vencida' => [
                'ahora' => '2026-11-14 10:00',
                'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                     'fecha' => Carbon::parse('2026-11-13 09:00')],
            ],
        ];

        foreach ($casos as $caso => $datos) {
            $r = MotorPresenta::predecir($this->entrada($datos));

            // sólo las filas que componen la hora: traslado y tiempo muerto son
            // el desglose del hueco, y la espera en planta va después
            $suma = 0;
            foreach ($r->desglose as $fila) {
                if (in_array($fila['concepto'], ['restante_viaje', 'hueco_total', 'espera_ventana', 'ajuste'], true)) {
                    $suma += $fila['min'];
                }
            }

            $desdeAncla = (int) round(($r->presenta->getTimestamp() - $r->ancla->fecha->getTimestamp()) / 60);
            $this->assertSame($desdeAncla, $suma, 'el desglose no cuadra en el caso ' . $caso);
        }
    }

    public function test_confianza_castiga_dispersion_ventana_traslado_y_horizonte(): void
    {
        $limpio = (object) [
            'nivel' => 'medido', 'iqr' => 60, 'ancla_cerrada' => true, 'espera_ventana' => 0,
            'traslado_fuente' => 'ruta_medida', 'horizonte_min' => 60, 'parametros' => $this->parametros(),
        ];

        $this->assertSame(95, MotorPresenta::confianza($limpio)->pct);
        $this->assertSame(75, MotorPresenta::confianza((object) array_merge((array) $limpio, ['iqr' => 400]))->pct);
        $this->assertSame(70, MotorPresenta::confianza((object) array_merge((array) $limpio, ['espera_ventana' => 120]))->pct);
        $this->assertSame(70, MotorPresenta::confianza((object) array_merge((array) $limpio, ['nivel' => 'parcial']))->pct);
        $this->assertSame(45, MotorPresenta::confianza((object) array_merge((array) $limpio, ['nivel' => 'cadena']))->pct);

        $todoMal = (object) array_merge((array) $limpio, [
            'iqr' => 400, 'ancla_cerrada' => false, 'espera_ventana' => 120,
            'traslado_fuente' => 'defecto', 'horizonte_min' => 48 * 60,
        ]);
        $this->assertSame(5, MotorPresenta::confianza($todoMal)->pct);
        $this->assertStringContainsString('horizonte', MotorPresenta::confianza($todoMal)->motivo);
    }

    public function test_estado_del_conductor(): void
    {
        $enRuta = MotorPresenta::estado('inicio_ruta', Carbon::parse('2026-11-14 08:00'),
            Carbon::parse('2026-11-14 10:00'), [], 72);
        $this->assertSame('en_ruta', $enRuta->clave);

        $durmiendo = MotorPresenta::estado('fin_descargue', Carbon::parse('2026-11-14 01:00'),
            Carbon::parse('2026-11-14 03:30'), [['desde' => 0, 'hasta' => 360]], 72);
        $this->assertSame('descansando', $durmiendo->clave);

        $parado = MotorPresenta::estado('fin_descargue', Carbon::parse('2026-11-14 01:00'),
            Carbon::parse('2026-11-14 12:00'), [['desde' => 0, 'hasta' => 360]], 6);
        $this->assertSame('inactivo_extendido', $parado->clave);
    }

    public function test_faltantes_por_hito(): void
    {
        $etapas = ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45];

        $this->assertSame(285, MotorPresenta::faltantes('inicio_ruta', $etapas));
        $this->assertSame(105, MotorPresenta::faltantes('qr_llegada_destino', $etapas));
        $this->assertSame(45, MotorPresenta::faltantes('inicio_descargue', $etapas));
        $this->assertSame(435, MotorPresenta::faltantes('presenta_para_carga', $etapas));
        $this->assertNull(MotorPresenta::faltantes('fin_descargue', $etapas));
        $this->assertNull(MotorPresenta::faltantes('inicio_ruta', ['e4' => 180]));
    }
}
