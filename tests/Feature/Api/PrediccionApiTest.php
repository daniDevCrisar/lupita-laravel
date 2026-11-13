<?php

namespace Tests\Feature\Api;

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
}
