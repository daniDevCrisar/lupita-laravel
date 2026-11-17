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
            'dia_modo' => 1,   // factor de día por placa con prior de flota (el de siempre)
            'curva_modo' => 0, 'curva_k' => 5,
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

    public function test_el_tramo_desde_la_ubicacion_declarada_reemplaza_la_ida(): void
    {
        // El camión va en viaje y el operador declaró dónde está: lo que le falta
        // de la ida es el ruteo desde ese punto hasta el destino (60'), no la
        // mediana de la ruta contada desde el hito. Después de llegar, la
        // descarga y la vuelta (e5+e6 = 105') se suman igual.
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 07:00')],
            'tramo_en_curso' => (object) [
                'min' => 60, 'km' => 48.3, 'resto_min' => 105,
                'desde' => 'Grifo Kio Lurín', 'hasta' => 'PLANTA CARAL', 'fuente' => 'geoapify',
            ],
        ]));

        // 10:00 + 60' de manejo + 105' de descarga y vuelta = 12:45 de llegada
        // (= t_fin); + hueco 300' = 17:45
        $this->assertSame('2026-11-14 17:45', $r->presenta->format('Y-m-d H:i'));
        // el restante se sigue contando desde el ancla: 07:00 -> 12:45 = 345'
        $restante = collect($r->desglose)->firstWhere('concepto', 'restante_viaje');
        $this->assertSame(345, $restante['min']);
        $this->assertStringContainsString('ubicación declarada', $restante['fuente']);
        // y se explica en los pasos: el manejo desde la ubicación y lo que sigue
        $paso = collect($r->pasos)->firstWhere('concepto', 'restante_viaje');
        $this->assertStringContainsString('Grifo Kio Lurín', $paso['detalle']);
        $this->assertStringContainsString('1:45', $paso['detalle']);   // descarga y vuelta
        // el paso fecha el fin del viaje (llega + descarga), no el hito
        $this->assertSame('2026-11-14 12:45:00', $paso['fecha']);
        $this->assertNotEmpty(array_filter($r->supuestos, fn ($s) => str_contains($s, 'ubicación declarada')));
    }

    public function test_si_la_mediana_ya_se_consumio_se_espera_hasta_el_p75(): void
    {
        // 6 h desde inicio_ruta y la mediana de las etapas es 285' (e4 180 +
        // e5 60 + e6 45): la mediana ya venció. Con el p75 medido (420') todavía
        // le faltarían 60', que es lo que separa "va con retraso" de "ya descargó".
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 15:00'),
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'etapas' => ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45,
                         'e4_p75' => 280, 'e5_p75' => 80, 'e6_p75' => 60],
        ]));

        // 15:00 + (420 - 360) = 16:00 de fin de viaje, + hueco 300' = 21:00
        $this->assertSame('2026-11-14 21:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertContains('etapa_vencida', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
        // el desglose sigue contando desde el ancla: 09:00 -> 16:00 = 420'
        $this->assertSame(420, collect($r->desglose)->firstWhere('concepto', 'restante_viaje')['min']);
    }

    public function test_sin_el_descuento_queda_el_comportamiento_viejo(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 15:00'),
            'parametros' => $this->parametros(['descontar_transcurrido' => 0]),
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'etapas' => ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45,
                         'e4_p75' => 280, 'e5_p75' => 80, 'e6_p75' => 60],
        ]));

        // viejo: etapa vencida -> se cuenta desde ahora (15:00) + hueco 300'
        $this->assertSame('2026-11-14 20:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(0, collect($r->desglose)->firstWhere('concepto', 'restante_viaje')['min']);
    }

    public function test_si_falta_una_etapa_se_suma_lo_que_hay(): void
    {
        // ruta sin E5 (la cola en el destino): antes el motor asumía "libre ahora"
        // y perdía E4 (180) y E6 (45); ahora suma lo que tiene y lo avisa
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'etapas' => ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => null, 'e6' => 45],
        ]));

        // 09:00 + (180 + 45) = 12:45 de fin de viaje, + hueco 300 = 17:45
        $this->assertSame('2026-11-14 17:45', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(225, collect($r->desglose)->firstWhere('concepto', 'restante_viaje')['min']);
        $aviso = implode(' | ', $r->advertencias);
        $this->assertStringContainsString('etapas_incompletas', $aviso);
        $this->assertStringContainsString('E5', $aviso);

        // y lo que dice la función, sin pasar por el motor
        $parcial = MotorPresenta::faltantes_parcial('inicio_ruta',
            ['e4' => 180, 'e5' => null, 'e6' => 45]);
        $this->assertSame(225, $parcial['min']);
        $this->assertSame(['E5'], $parcial['faltan']);

        // sin ninguna etapa no se inventa nada
        $this->assertNull(MotorPresenta::faltantes_parcial('inicio_ruta', []));
        $this->assertNull(MotorPresenta::faltantes_parcial('fin_descargue', ['e4' => 180]));
    }

    public function test_la_ventana_medida_manda_sobre_los_cuantiles_del_hueco(): void
    {
        // residuos medidos: ±10 min de adelanto y +15 h de atraso (el hueco es 300')
        $r = MotorPresenta::predecir($this->entrada([
            'ventana_residuos' => (object) ['adelanto' => 600, 'atraso' => 900, 'cobertura' => 0.7],
        ]));

        // presenta 09:00 + 300' = 14:00
        $this->assertSame('2026-11-14 14:00', $r->presenta->format('Y-m-d H:i'));
        // OJO: la banda medida se centra en la HORA PUBLICADA, no en el hueco.
        // Rápido pediría 14:00 - 600' = 04:00, que ya pasó (ahora es 10:00), así
        // que se recorta al corte. Antes se recortaba contra el traslado (180' ->
        // 12:00), y eso dejaba la banda asimétrica y la cobertura en la mitad.
        $this->assertSame('2026-11-14 10:00', $r->presenta_rapido->format('Y-m-d H:i'));
        // tardado = 14:00 + 900' = 05:00 del día siguiente
        $this->assertSame('2026-11-15 05:00', $r->presenta_tardado->format('Y-m-d H:i'));
        // la ventana publicada es la misma banda, y se dice de dónde salió
        $this->assertSame('residuos', $r->ventana_fuente);
        $this->assertSame('2026-11-14 10:00', $r->ventana['desde']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-15 05:00', $r->ventana['hasta']->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(0.7, (float) $r->ventana_cobertura, 0.001);
        $this->assertStringContainsString('error real', $r->presentaciones[0]['descripcion']);
    }

    public function test_la_ventana_medida_queda_simetrica_alrededor_de_la_hora_publicada(): void
    {
        // con el corte temprano no hay recorte: la banda tiene que ser exacta.
        // Hueco de 1.200' para que el "rápido" (20 h antes) no caiga antes del corte.
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 09:00'),
            'calibrador' => (object) ['gap_min' => 1200, 'p25_min' => 900, 'p75_min' => 1500,
                                      'base_min' => 1200, 'residual_min' => 0, 'n' => 6, 'nivel' => 'medido'],
            'ventana_residuos' => (object) ['adelanto' => 600, 'atraso' => 600, 'cobertura' => 0.7],
        ]));

        $this->assertSame('2026-11-15 05:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 19:00', $r->presenta_rapido->format('Y-m-d H:i'));
        $this->assertSame('2026-11-15 15:00', $r->presenta_tardado->format('Y-m-d H:i'));

        $izquierda = (int) round(($r->presenta->getTimestamp() - $r->presenta_rapido->getTimestamp()) / 60);
        $derecha = (int) round(($r->presenta_tardado->getTimestamp() - $r->presenta->getTimestamp()) / 60);
        $this->assertSame(600, $izquierda, 'el adelanto medido tiene que llegar completo');
        $this->assertSame(600, $derecha, 'el atraso medido tiene que llegar completo');
    }

    public function test_la_cadena_usa_su_propia_banda_cuando_esta_medida(): void
    {
        // sin pares medidos (calibrador null) el motor arma la cadena; la banda de
        // la cadena es mucho más ancha que la general, así que tiene que usarla
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => null,
            'ventana_residuos' => (object) [
                'adelanto' => 600, 'atraso' => 600,
                'adelanto_cadena' => 3000, 'atraso_cadena' => 3000,
                'cobertura' => 0.7,
            ],
        ]));

        $this->assertSame('cadena', $r->evidencia);
        // cadena: t_fin 09:00 (ancla cerrada) + traslado 180 + muerto 60 = 13:00
        $this->assertSame('2026-11-14 13:00', $r->presenta->format('Y-m-d H:i'));
        // tardado = 240' + 3000' = 3240' desde las 09:00 -> 16/11 15:00
        $this->assertSame('2026-11-16 15:00', $r->presenta_tardado->format('Y-m-d H:i'));
    }

    public function test_sin_ventana_medida_siguen_los_cuantiles_del_hueco(): void
    {
        $r = MotorPresenta::predecir($this->entrada());

        $this->assertSame('p25_p75_hueco', $r->ventana_fuente);
        // el calibrador del test trae p25=240 y p75=420
        $this->assertSame('2026-11-14 13:00', $r->presenta_rapido->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 16:00', $r->presenta_tardado->format('Y-m-d H:i'));
    }

    public function test_el_p75_de_las_etapas_es_el_techo_cuando_ya_existe(): void    {
        $etapas = ['e4' => 180, 'e5' => 60, 'e6' => 45, 'e4_p75' => 280, 'e5_p75' => 80, 'e6_p75' => 60];

        $this->assertSame(420, MotorPresenta::faltantes_p75('inicio_ruta', $etapas));
        // sin p75 compilado cae a la mediana, no inventa
        $this->assertSame(285, MotorPresenta::faltantes_p75('inicio_ruta', ['e4' => 180, 'e5' => 60, 'e6' => 45]));
        // hitos sin etapas
        $this->assertNull(MotorPresenta::faltantes_p75('fin_descargue', $etapas));
        $this->assertNull(MotorPresenta::faltantes_p75(null, $etapas));
    }

    public function test_lo_que_sigue_al_viaje_son_solo_las_etapas_posteriores(): void    {
        $etapas = $this->entrada()->etapas;   // e2 90, e3 60, e4 180, e5 60, e6 45

        // desde inicio_ruta falta la ida (e4) + descarga; sin la ida quedan 105'
        $this->assertSame(285, MotorPresenta::faltantes('inicio_ruta', $etapas));
        $this->assertSame(105, MotorPresenta::faltantes_sin_viaje('inicio_ruta', $etapas));
        // desde el fin de carga todavía tiene que hacer la ida entera: quedan
        // e3 (salida de planta) + e5 + e6 = 165'
        $this->assertSame(165, MotorPresenta::faltantes_sin_viaje('fin_de_carga', $etapas));
        // desde la presentación todavía falta cargar: e2 + e3 + e5 + e6 = 255'
        $this->assertSame(255, MotorPresenta::faltantes_sin_viaje('presenta_para_carga', $etapas));
        // ya llegó al destino: no hay ida que reemplazar
        $this->assertSame(105, MotorPresenta::faltantes_sin_viaje('qr_llegada_destino', $etapas));
        // sin una etapa, o con un hito que no tiene etapas, no se inventa nada
        $this->assertNull(MotorPresenta::faltantes_sin_viaje('inicio_ruta', ['e4' => 180, 'e5' => 60]));
        $this->assertNull(MotorPresenta::faltantes_sin_viaje('fin_descargue', $etapas));
        $this->assertNull(MotorPresenta::faltantes_sin_viaje(null, $etapas));
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

    public function test_ancla_declarada_arma_la_cadena_desde_ahora(): void
    {
        // sin viaje en curso utilizable pero con la ubicación declarada: el
        // cálculo arranca en el corte y sólo suma el traslado hasta la planta
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-14 10:00'),
            'ancla' => (object) ['hito' => 'ubicacion_declarada', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 10:00')],
            'calibrador' => null,
            'traslado' => (object) ['min' => 300, 'fuente' => 'ruta_medida'],
        ]));

        // 10:00 + traslado 300' + muerto por defecto 60' = 16:00
        $this->assertSame('2026-11-14 16:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('cadena', $r->evidencia);
        $this->assertSame('en_posicion_declarada', $r->estado->clave);

        $suma = 0;
        foreach ($r->desglose as $fila) {
            if (in_array($fila['concepto'], ['restante_viaje', 'hueco_total', 'espera_ventana', 'ajuste'], true)) {
                $suma += $fila['min'];
            }
        }
        $this->assertSame(360, $suma);
    }

    public function test_la_linea_de_tiempo_cierra_siempre(): void
    {
        $casos = [
            'normal' => [],
            'atrasado' => [
                'ahora' => Carbon::parse('2026-11-16 10:00'),
                'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                     'fecha' => Carbon::parse('2026-11-14 09:00')],
            ],
            'etapa vencida' => [
                'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                     'fecha' => Carbon::parse('2026-11-13 09:00')],
            ],
        ];

        foreach ($casos as $nombre => $over) {
            $r = MotorPresenta::predecir($this->entrada($over));
            $ancla = $r->ancla->fecha;
            $presenta = null;
            $inicio = null;

            foreach ($r->pasos as $paso) {
                // cada paso trae su fecha y su acumulado: la línea de tiempo se
                // arma sin recalcular y no puede "fallar en las fechas"
                $this->assertNotNull($paso['fecha'], $nombre . ': ' . $paso['concepto'] . ' sin fecha');

                $fecha = Carbon::parse($paso['fecha']);
                $this->assertSame(
                    (int) round(($fecha->getTimestamp() - $ancla->getTimestamp()) / 60),
                    $paso['acumulado_min'],
                    $nombre . ': acumulado de ' . $paso['concepto']
                );

                if ($paso['concepto'] === 'presenta') {
                    $presenta = $fecha;
                }
                if ($paso['concepto'] === 'inicio_carga') {
                    $inicio = $fecha;
                }
            }

            $this->assertSame($r->presenta->format('Y-m-d H:i'), $presenta?->format('Y-m-d H:i'), $nombre);
            $this->assertSame($r->inicio_carga->format('Y-m-d H:i'), $inicio?->format('Y-m-d H:i'), $nombre);

            // la suma de las duraciones (con el ajuste, si hubo recorte) = presenta - ancla
            $suma = 0;
            foreach ($r->pasos as $paso) {
                if (in_array($paso['concepto'], ['ancla', 'presenta', 'espera_carga', 'inicio_carga'], true)) {
                    continue;
                }
                $suma += $paso['min'];
            }
            $this->assertSame(
                (int) round(($r->presenta->getTimestamp() - $ancla->getTimestamp()) / 60),
                $suma,
                $nombre . ': los pasos tienen que sumar hasta presenta'
            );
        }
    }

    public function test_los_pasos_explican_la_prediccion_con_nombres_y_hhmm(): void    {
        $r = MotorPresenta::predecir($this->entrada([
            'planta_nombre' => 'PLANTA CARAL',
            'ancla_texto' => 'Fin de descargue del viaje 1180352',
            'calibrador' => null,
            'traslado' => (object) [
                'min' => 547, 'fuente' => 'geoapify', 'km' => 624.0,
                'texto' => 'Supe, Barranca, Lima → Chiclayo, Chiclayo, Lambayeque',
            ],
        ]));

        $this->assertIsArray($r->pasos);
        $conceptos = array_column($r->pasos, 'concepto');
        foreach (['ancla', 'traslado', 'tiempo_muerto', 'presenta', 'espera_carga', 'inicio_carga'] as $concepto) {
            $this->assertContains($concepto, $conceptos, 'falta el paso ' . $concepto);
        }

        $traslado = null;
        foreach ($r->pasos as $paso) {
            if ($paso['concepto'] === 'traslado') {
                $traslado = $paso;
            }
        }

        $this->assertSame(547, $traslado['min']);
        $this->assertSame('9:07', $traslado['duracion']);
        $this->assertStringContainsString('Chiclayo', $traslado['detalle']);
        $this->assertStringContainsString('PLANTA CARAL', $traslado['titulo']);

        // ancla 09:00 + traslado 547 + muerto 60 = 19:07
        $this->assertSame('10:07', $r->duracion_total);
        $this->assertSame('2026-11-14 19:07', $r->presenta->format('Y-m-d H:i'));

        $espera = null;
        foreach ($r->pasos as $paso) {
            if ($paso['concepto'] === 'espera_carga') {
                $espera = $paso;
            }
        }
        $this->assertSame('1:40', $espera['duracion']);
    }

    public function test_devuelve_las_cinco_fechas_incluidas_la_probable_y_el_consenso(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            // hueco medido 300 con p25 240 y p75 420
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
        ]));

        $this->assertSame('2026-11-14 13:00', $r->presenta_rapido->format('Y-m-d H:i'));   // 09:00 + 240
        $this->assertSame('2026-11-14 14:00', $r->presenta->format('Y-m-d H:i'));          // 09:00 + 300
        $this->assertSame('2026-11-14 16:00', $r->presenta_tardado->format('Y-m-d H:i'));  // 09:00 + 420

        // sin sesgo aplicado, la cuarta fecha es igual a la normal
        $this->assertSame('2026-11-14 14:00', $r->presenta_probable->format('Y-m-d H:i'));
        $this->assertSame(0, $r->sesgo_min);

        // el consenso: promedio de rápido (13:00), normal (14:00) y cuarta (14:00) = 13:40
        $this->assertSame('2026-11-14 13:40', $r->presenta_consenso->format('Y-m-d H:i'));

        $this->assertCount(5, $r->presentaciones);
        $this->assertSame(['rapido', 'normal', 'tardado', 'probable', 'consenso'],
            array_column($r->presentaciones, 'clave'));
        $this->assertSame('3:00', $r->presentaciones[0]['desde_ahora']);   // ahora 10:00 -> 13:00
        $this->assertSame('4:00', $r->presentaciones[1]['desde_ahora']);
        $this->assertSame('6:00', $r->presentaciones[2]['desde_ahora']);
        $this->assertSame('4:00', $r->presentaciones[3]['desde_ahora']);
        $this->assertSame('3:40', $r->presentaciones[4]['desde_ahora']);

        // y cada fecha viaja con SU construcción, no sólo la normal
        $porClave = array_column($r->presentaciones, null, 'clave');
        $this->assertSame(-60, $porClave['rapido']['delta_normal_min']);
        $this->assertSame(0, $porClave['normal']['delta_normal_min']);
        $this->assertSame(120, $porClave['tardado']['delta_normal_min']);
        $this->assertSame(-20, $porClave['consenso']['delta_normal_min']);

        $this->assertStringContainsString('normal − 1:00', $porClave['rapido']['construccion']);
        $this->assertStringContainsString('normal + 2:00', $porClave['tardado']['construccion']);
        $this->assertStringContainsString('promedio de rápido', $porClave['consenso']['construccion']);
        $this->assertStringContainsString('la suma de los pasos', $porClave['normal']['construccion']);
    }

    public function test_el_consenso_promedia_en_minutos_desde_el_corte(): void
    {
        $ahora = Carbon::parse('2026-11-14 10:00');

        $consenso = MotorPresenta::consenso(
            $ahora,
            Carbon::parse('2026-11-14 08:00'),
            Carbon::parse('2026-11-14 11:00'),
            Carbon::parse('2026-11-14 14:00'),
        );

        // 08:00 + 11:00 + 14:00 = 33:00 sobre 10:00 -> 11:00
        $this->assertSame('2026-11-14 11:00', $consenso->format('Y-m-d H:i'));
        // y cae siempre entre la mínima y la máxima
        $this->assertTrue($consenso->greaterThanOrEqualTo(Carbon::parse('2026-11-14 08:00')));
        $this->assertTrue($consenso->lessThanOrEqualTo(Carbon::parse('2026-11-14 14:00')));
    }

    public function test_la_cuarta_fecha_usa_el_sesgo_de_su_clase(): void
    {
        // sin pares (cadena) el hueco es traslado 180 + muerto 60 = 240 min, así que
        // el horizonte predicho cae en el tramo "corto". Sesgo -3.886: el motor venía
        // publicando 3.886 min antes, así que la cuarta fecha se corre hacia adelante.
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => null,   // sin pares: cadena
            'parametros' => $this->parametros([
                'sesgo_clase_activo' => 1,
                'sesgo_clase_cadena_corto' => -3886,
            ]),
        ]));

        $this->assertSame('cadena', $r->evidencia);
        $this->assertSame('cadena|corto', $r->sesgo_clase);
        $this->assertSame(-3886, $r->sesgo_min);

        $esperado = $r->presenta->copy()->addMinutes(3886);
        $this->assertSame($esperado->format('Y-m-d H:i'), $r->presenta_probable->format('Y-m-d H:i'));
    }

    public function test_la_banda_se_dibuja_sobre_la_cuarta_fecha_cuando_el_sesgo_esta_aplicado(): void
    {
        // La ventana medida sale del residuo contra la fecha que se usa. Si el
        // sesgo está aplicado y la banda se dibuja sobre la NORMAL, queda corrida:
        // medido con la banda asimétrica, cubría 52 % en vez del 70 % medido.
        $banda = (object) [
            'adelanto' => 120, 'atraso' => 200,
            'adelanto_cadena' => 120, 'atraso_cadena' => 200,
            'cobertura' => 0.7,
        ];

        // sin sesgo: la banda va sobre la hora publicada (13:00)
        $sin = MotorPresenta::predecir($this->entrada([
            'calibrador' => null,
            'ventana_residuos' => $banda,
        ]));

        $this->assertSame(0, $sin->sesgo_min);
        $this->assertSame('2026-11-14 11:00', $sin->presenta_rapido->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 16:20', $sin->presenta_tardado->format('Y-m-d H:i'));

        // con sesgo −200: la misma banda, corrida a la cuarta fecha (16:20)
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => null,
            'ventana_residuos' => $banda,
            'parametros' => $this->parametros([
                'sesgo_clase_activo' => 1,
                'sesgo_clase_cadena_corto' => -200,
            ]),
        ]));

        $this->assertSame('cadena|corto', $r->sesgo_clase);
        $this->assertSame('2026-11-14 16:20', $r->presenta_probable->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 14:20', $r->presenta_rapido->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 19:40', $r->presenta_tardado->format('Y-m-d H:i'));

        // el tardado nunca queda antes de la hora publicada
        $this->assertTrue($r->presenta_tardado->greaterThanOrEqualTo($r->presenta));
        $this->assertTrue($r->presenta_rapido->lessThanOrEqualTo($r->presenta_probable));
    }

    public function test_sin_pares_las_tres_fechas_se_abren_por_porcentaje(): void
    {
        // cadena: traslado 180 + muerto 60 = 240; el spread es 30 %, pero el
        // rápido no puede bajar del traslado (180), no de 168
        $r = MotorPresenta::predecir($this->entrada(['calibrador' => null]));

        $this->assertSame('2026-11-14 12:00', $r->presenta_rapido->format('Y-m-d H:i'));  // 09:00 + 180
        $this->assertSame('2026-11-14 13:00', $r->presenta->format('Y-m-d H:i'));         // 09:00 + 240
        $this->assertSame('2026-11-14 14:12', $r->presenta_tardado->format('Y-m-d H:i')); // 09:00 + 312
    }

    public function test_el_rapido_nunca_es_menor_que_el_traslado(): void
    {
        // p25 de 60 min pero el traslado medido son 180: no puede llegar antes de
        // lo que tarda el viaje
        $r = MotorPresenta::predecir($this->entrada([
            'calibrador' => (object) ['gap_min' => 300, 'p25_min' => 60, 'p75_min' => 420,
                                      'base_min' => 300, 'residual_min' => 0, 'n' => 6, 'nivel' => 'medido'],
        ]));

        $this->assertSame('2026-11-14 12:00', $r->presenta_rapido->format('Y-m-d H:i'));  // 09:00 + 180
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
