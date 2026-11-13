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
        // no todas las refs son predecibles: si el último hito de la placa es de
        // hace días, el motor contesta sin_datos a propósito. Se busca una que sí.
        $candidatas = \Illuminate\Support\Facades\DB::table('referencias')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-01')
            ->whereNotNull('ruta_id')
            ->where('ruta_id', '<>', '')
            ->orderByDesc('presenta_para_carga')
            ->limit(25)
            ->get(['ref', 'presenta_para_carga']);

        if ($candidatas->isEmpty()) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        $respuesta = null;
        $elegida = null;
        foreach ($candidatas as $fila) {
            $ahora = \Carbon\Carbon::parse($fila->presenta_para_carga)->subHours(20)->format('Y-m-d H:i');
            $intento = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref . '&ahora=' . urlencode($ahora));
            $intento->assertOk()->assertJsonPath('success', true)->assertJsonPath('ref', $fila->ref);

            if ($intento->json('presenta') !== null) {
                $respuesta = $intento;
                $elegida = $fila;
                break;
            }
        }

        if ($respuesta === null) {
            $this->markTestSkipped('ninguna de las referencias recientes tiene ancla fresca');
        }

        $respuesta->assertJsonStructure([
            'success', 'ref', 'ahora', 'placa', 'ruta', 'presenta', 'inicio_carga', 'espera_min',
            'ventana' => ['desde', 'hasta'], 'evidencia', 'confianza', 'estado', 'ancla',
            'desglose', 'supuestos', 'advertencias', 'candidatos',
        ]);

        $this->assertContains($respuesta->json('evidencia'), ['medido', 'parcial', 'cadena']);
        $this->assertNotEmpty($respuesta->json('desglose'));
        $this->assertSame($elegida->ref, $respuesta->json('ref'));
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
