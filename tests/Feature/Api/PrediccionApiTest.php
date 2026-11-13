<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Models\User;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contrato de entrada de la API pública de predicción. Los caminos felices
 * necesitan MySQL (la suite corre en sqlite sin tablas), así que aquí se fija lo
 * que sí se puede probar: validación 422/415 siempre en JSON y el fallo de base
 * como 500 con success=false, nunca un stack trace.
 */
class PrediccionApiTest extends TestCase
{
    public function test_exige_ref_en_get(): void
    {
        $this->getJson('/api/prediccion/presenta')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ref_con_formato_invalido(): void
    {
        $this->getJson('/api/prediccion/presenta?ref=a')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ahora_invalido(): void
    {
        $this->getJson('/api/prediccion/presenta?ref=REQ123&ahora=manana')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ubicacion_tipo_desconocido(): void
    {
        $this->getJson('/api/prediccion/presenta?ref=REQ123&ubicacion_tipo=marte')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_post_que_no_es_json(): void
    {
        $this->post('/api/prediccion/presenta', ['ref' => 'REQ123'])
            ->assertStatus(415)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_post_sin_ref(): void
    {
        $this->postJson('/api/prediccion/presenta', ['guardar' => false])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error', 'detalle' => ['ref']]);
    }

    public function test_guardar_sin_datos_es_invalido(): void
    {
        $this->postJson('/api/prediccion/presenta', ['ref' => 'REQ123', 'guardar' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_aciertos_rechaza_fechas_invalidas(): void
    {
        $this->getJson('/api/prediccion/aciertos?desde=ayer')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_ref_inexistente_devuelve_404_json(): void
    {
        // la suite corre contra la misma base que la app (ver phpunit.xml/.env)
        $this->getJson('/api/prediccion/presenta?ref=NOPEXISTE')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Ref no encontrada');
    }

    public function test_predice_una_referencia_real_de_la_base(): void
    {
        // se busca un par real (fin_descargue del viaje anterior -> presenta del
        // siguiente) para que el ancla exista y sea fresca: así este test prueba
        // el motor de verdad y no se salta cuando el motor devuelve null
        $par = \Illuminate\Support\Facades\DB::selectOne("
            SELECT x.ref, x.fin_ancla, x.presenta_para_carga
            FROM (
                SELECT r.ref, r.presenta_para_carga,
                       LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS fin_ancla
                FROM referencias r
                WHERE r.placa <> '' AND r.presenta_para_carga IS NOT NULL
                  AND r.presenta_para_carga >= '2026-10-01' AND r.ruta_id IS NOT NULL AND r.ruta_id <> ''
            ) x
            WHERE x.fin_ancla IS NOT NULL
              AND TIMESTAMPDIFF(MINUTE, x.fin_ancla, x.presenta_para_carga) BETWEEN 60 AND 2880
            ORDER BY x.presenta_para_carga DESC
            LIMIT 1
        ");

        if ($par === null) {
            $this->markTestSkipped('la base no tiene pares recientes con ancla fresca');
        }

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $par->ref
            . '&ahora=' . urlencode($par->fin_ancla));

        $respuesta->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ref', (string) $par->ref)
            ->assertJsonStructure([
                'success', 'ref', 'ahora', 'placa', 'ruta', 'presenta', 'inicio_carga', 'espera_min',
                'ventana' => ['desde', 'hasta'], 'evidencia', 'confianza', 'estado', 'ancla',
                'desglose', 'supuestos', 'advertencias', 'candidatos',
            ]);

        $this->assertNotNull($respuesta->json('presenta'), 'con un ancla fresca el motor tiene que predecir');
        $this->assertFalse((bool) $respuesta->json('atrasado'));
        $this->assertContains($respuesta->json('evidencia'), ['medido', 'parcial', 'cadena']);
        $this->assertNotEmpty($respuesta->json('desglose'));
    }

    public function test_post_predice_sin_guardar(): void
    {
        $fila = \Illuminate\Support\Facades\DB::table('referencias')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-01')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        $ahora = \Carbon\Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');

        $this->postJson('/api/prediccion/presenta', ['ref' => $fila->ref, 'ahora' => $ahora])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ref', $fila->ref)
            ->assertJsonMissingPath('guardado');
    }

    public function test_sin_viaje_en_curso_predice_desde_la_ubicacion_declarada(): void
    {
        // un par con hueco real de más de 7 días: al corte, el ancla queda fuera
        // del tope y el motor no puede predecir hasta que se declara la ubicación
        $par = \Illuminate\Support\Facades\DB::selectOne("
            SELECT x.ref, x.presenta_para_carga FROM (
                SELECT r.ref, r.placa, r.presenta_para_carga,
                       LAG(r.fin_descargue) OVER (PARTITION BY r.placa ORDER BY r.presenta_para_carga) AS fin_ancla
                FROM referencias r
                WHERE r.placa <> '' AND r.presenta_para_carga IS NOT NULL
                  AND r.presenta_para_carga >= '2026-10-01' AND r.ruta_id IS NOT NULL AND r.ruta_id <> ''
            ) x
            WHERE x.fin_ancla IS NOT NULL
              AND TIMESTAMPDIFF(MINUTE, x.fin_ancla, x.presenta_para_carga) > 10080
            ORDER BY x.presenta_para_carga DESC
            LIMIT 1
        ");

        if ($par === null) {
            $this->markTestSkipped('la base no tiene pares con hueco de más de 7 días');
        }

        $ahora = \Carbon\Carbon::parse($par->presenta_para_carga)->subHour()->format('Y-m-d H:i');
        $url = '/api/prediccion/presenta?ref=' . $par->ref . '&ahora=' . urlencode($ahora);

        // sin ubicación declarada no hay desde dónde contar
        $this->getJson($url)->assertOk()->assertJsonPath('success', true)->assertJsonPath('presenta', null);

        // declarando el local, la predicción sale de la cadena
        $respuesta = $this->getJson($url . '&ubicacion_tipo=local&ubicacion_id=LOC_CARAL');
        $respuesta->assertOk()->assertJsonPath('success', true);

        $this->assertNotNull($respuesta->json('presenta'), 'con ubicación declarada tiene que predecir');
        $this->assertSame('cadena', $respuesta->json('evidencia'));
        $this->assertContains(
            'ancla_declarada',
            array_map(fn ($aviso) => explode(':', $aviso)[0], $respuesta->json('advertencias'))
        );
    }

    public function test_aciertos_devuelve_filas_con_el_error_bien_firmado(): void
    {
        $respuesta = $this->getJson('/api/prediccion/aciertos?desde=2026-10-01&hasta=2026-11-03&limit=8');
        $respuesta->assertOk()->assertJsonPath('success', true);

        $filas = $respuesta->json('filas');
        $this->assertNotEmpty($filas);

        $conError = 0;
        foreach ($filas as $fila) {
            if ($fila['predicho'] === null || $fila['error_min'] === null) {
                continue;
            }
            // error = predicho - real, en minutos: el signo es lo que se mira en la tabla
            $esperado = (strtotime($fila['predicho']) - strtotime($fila['real'])) / 60;
            $this->assertEqualsWithDelta($esperado, $fila['error_min'], 1.5);
            $conError++;
        }

        $this->assertGreaterThan(0, $conError, 'la corrida no produjo ninguna predicción con error');
    }

    public function test_una_ref_sin_ruta_se_rechaza_con_409_y_no_se_predice(): void
    {
        // sin ruta no hay planta de carga: devolver una hora sería inventar el tramo
        $fila = DB::table('referencias')
            ->whereNotNull('presenta_para_carga')
            ->where(fn ($q) => $q->whereNull('ruta_id')->orWhere('ruta_id', ''))
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias sin ruta');
        }

        $ahora = Carbon::parse($fila->presenta_para_carga)->subHours(8)->format('Y-m-d H:i');

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref . '&ahora=' . urlencode($ahora));

        $respuesta->assertStatus(409)->assertJsonPath('success', false);
        $this->assertStringContainsString('ruta', (string) $respuesta->json('error'));
    }

    public function test_las_refs_sin_ruta_quedan_fuera_de_la_tabla_de_aciertos(): void
    {
        $desde = '2026-10-20';
        $hasta = '2026-10-27';

        $pares = DBPrediccion::pares(null, Carbon::parse($desde)->startOfDay(),
            Carbon::parse($hasta)->endOfDay(), null, null, 80);

        if (count($pares) < 10) {
            $this->markTestSkipped('el rango no tiene pares suficientes');
        }

        $corrida = Servicio::backtest(['desde' => $desde, 'hasta' => $hasta, 'limite' => 80]);

        // cada par cae en un solo balde: fila evaluada, sin ruta o descartada
        $this->assertIsInt($corrida->sin_ruta);
        $this->assertSame(
            count($pares),
            count($corrida->filas) + $corrida->sin_ruta + $corrida->descartados
        );
    }

    public function test_un_ancla_que_no_es_viaje_de_la_placa_se_avisa(): void
    {
        $fila = DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias con ruta');
        }

        $ahora = Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref
            . '&ahora=' . urlencode($ahora) . '&ancla=NOEXISTE1');

        $respuesta->assertOk()->assertJsonPath('success', true);

        $avisos = array_map(fn ($aviso) => explode(':', (string) $aviso)[0], (array) $respuesta->json('advertencias'));
        $this->assertContains('ancla_no_encontrada', $avisos);
    }

    public function test_aciertos_publica_solo_las_metricas_del_motor(): void
    {
        $respuesta = $this->getJson('/api/prediccion/aciertos?desde=2026-10-20&hasta=2026-11-13&limit=5');
        $respuesta->assertOk()->assertJsonPath('success', true);

        $filas = $respuesta->json('filas');
        $this->assertNotEmpty($filas);

        $this->assertArrayHasKey('calibra_con', $filas[0]);
        $this->assertContains($filas[0]['calibra_con'], ['placa', 'conductor']);
        $this->assertArrayHasKey('pares_calibracion', $filas[0]);

        // la comparación contra el método anterior ya no se publica
        $this->assertArrayNotHasKey('comparacion', $respuesta->json());
        $this->assertArrayNotHasKey('resumen_metodo_actual', $respuesta->json());
        $this->assertArrayNotHasKey('metodo_actual', $filas[0]);
        $this->assertArrayHasKey('sin_ruta', $respuesta->json());
    }

    public function test_la_tabla_calibra_con_el_conductor_cuando_es_minoritario(): void
    {
        $desde = '2026-10-20';
        $hasta = '2026-10-27';

        $corrida = Servicio::backtest(['desde' => $desde, 'hasta' => $hasta, 'limite' => 60]);

        $porConductor = array_values(array_filter($corrida->filas, fn ($fila) => $fila->grupo === 'conductor'));
        if ($porConductor === []) {
            $this->markTestSkipped('el rango no tiene filas calibradas por conductor');
        }

        $fila = $porConductor[0];
        $conductor = DB::table('referencias')->where('ref', $fila->ref)->value('conductor_id');
        if ($conductor === null) {
            $this->markTestSkipped('la ref no trae conductor');
        }

        // el grupo tiene que poder recalcularse con el historial anterior al ancla
        $pares = DBPrediccion::pares(null, Carbon::parse($desde)->subDays(120),
            Carbon::parse($hasta)->endOfDay(), $fila->placa, null, 20000);
        $previos = Servicio::pares_previos($pares, $fila->ancla, 10000);
        $pct = Servicio::pct_conductor($previos, (int) $conductor);

        [$esperado] = Servicio::clave_calibracion($fila->placa, (int) $conductor, $pct);

        $this->assertSame('conductor', $esperado);
        $this->assertSame($esperado, $fila->grupo);
        $this->assertIsInt($fila->pares_calibracion);
    }

    public function test_las_pantallas_de_prediccion_traen_su_javascript(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(User::query()->firstOrFail());

        // api-ref: el botón "Generar predicción" es JS puro. Antes la vista usaba
        // @push('scripts') y el layout sólo tiene @yield('scripts'), así que el
        // script NUNCA llegaba a la página y el botón no hacía nada.
        $html = (string) $this->get(route('lupita.prediccion.api_ref', [
            'ref' => '1154197',
            'ahora' => '2026-10-10 17:49',
            'ancla' => '1153965',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('id="btn-predecir"', $html);
        $this->assertStringContainsString('pidiendo al API', $html);
        $this->assertStringContainsString('addEventListener', $html);

        // calculadora: el buscador de local/ubigeo también es JS
        $html = (string) $this->get(route('lupita.prediccion.presenta', ['placa' => 'C7B804']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function buscador(', $html);
    }

    public function test_una_ref_ya_en_curso_no_se_predice(): void
    {
        // la ref ya cargó y va camino del destino, pero le falta el presenta:
        // predecir una presentación que ya ocurrió sería inventar la hora
        $fila = DB::table('referencias')
            ->where(fn ($q) => $q->whereNull('presenta_para_carga')->orWhere('presenta_para_carga', ''))
            ->whereNotNull('inicio_ruta')
            ->orderByDesc('inicio_ruta')
            ->first(['ref']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene refs en curso sin presenta');
        }

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref);

        $respuesta->assertStatus(409)->assertJsonPath('success', false);
        $this->assertStringContainsString('presenta_para_carga', (string) $respuesta->json('error'));
    }

    public function test_la_fila_del_backtest_predice_lo_mismo_que_el_api(): void
    {
        // el botón "Predecir" de la tabla manda ref + corte + ancla: tiene que
        // devolver la MISMA hora que mostró la fila
        $dia = '2026-10-10';
        $corrida = Servicio::backtest(['desde' => $dia, 'hasta' => $dia, 'limite' => 500]);

        $fila = null;
        foreach ($corrida->filas as $candidata) {
            if ($candidata->predicho !== null) {
                $fila = $candidata;
                break;
            }
        }

        if ($fila === null) {
            $this->markTestSkipped('la corrida no produjo ninguna predicción');
        }

        $api = Servicio::predecir($fila->ref, [
            'ahora' => $fila->ancla->format('Y-m-d H:i'),
            'ancla_ref' => $fila->ancla_ref,
        ]);

        $this->assertNotNull($api->presenta);
        $this->assertSame(
            $fila->predicho->format('Y-m-d H:i'),
            $api->presenta->format('Y-m-d H:i'),
            'la fila del backtest y el API tienen que predecir la misma hora'
        );
    }

    public function test_la_tabla_manda_ref_corte_y_ancla_en_el_boton_predecir(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(User::query()->firstOrFail());

        $respuesta = $this->get(route('lupita.prediccion.aciertos', [
            'fecha_inicio' => '2026-10-20',
            'fecha_fin' => '2026-11-13',
            'limite' => 10,
        ]));

        $respuesta->assertOk();
        $html = (string) $respuesta->getContent();

        // el botón tiene que llevar la ref a predecir, el corte y la última ref
        // hecha (ancla): sin el ancla el motor elige otro viaje y el resultado
        // no es el que evaluó esta fila
        $this->assertMatchesRegularExpression('/api-ref\?ref=\d+[^"]*ancla=\d+/', $html);

        // solo las metricas del motor, con la columna de a quien se calibro
        $this->assertStringContainsString('Calibra<br>', $html);
        $this->assertStringNotContainsString('Método actual', $html);
        $this->assertStringNotContainsString('Quién gana', $html);
    }
}
