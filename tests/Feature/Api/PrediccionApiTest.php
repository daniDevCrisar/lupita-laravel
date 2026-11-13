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
        $fila = \Illuminate\Support\Facades\DB::table('referencias')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-01')
            ->whereNotNull('ruta_id')
            ->where('ruta_id', '<>', '')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        // corte 20 h antes de su presentación real: el motor tiene que predecir
        $ahora = \Carbon\Carbon::parse($fila->presenta_para_carga)->subHours(20)->format('Y-m-d H:i');
        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref . '&ahora=' . urlencode($ahora));

        $respuesta->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ref', $fila->ref)
            ->assertJsonStructure([
                'success', 'ref', 'ahora', 'placa', 'ruta', 'presenta', 'inicio_carga', 'espera_min',
                'ventana' => ['desde', 'hasta'], 'evidencia', 'confianza', 'estado', 'ancla',
                'desglose', 'supuestos', 'advertencias', 'candidatos',
            ]);

        $this->assertContains($respuesta->json('evidencia'), ['medido', 'parcial', 'cadena', 'sin_datos']);
        $this->assertNotEmpty($respuesta->json('desglose'));
    }
}
