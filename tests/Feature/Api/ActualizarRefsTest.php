<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Blindaje del endpoint que usa tceflexia: la extracción del upsert a
 * `App\Database\DBRefsActualizar` no podía cambiar ni una coma de la respuesta.
 * Estos tres caminos se resuelven sin tocar la base.
 */
class ActualizarRefsTest extends TestCase
{
    public function test_rechaza_payload_no_json(): void
    {
        $this->post('/api/refs/actualizar', ['refs' => []])
            ->assertStatus(415)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_sin_refs(): void
    {
        $this->postJson('/api/refs/actualizar', ['referencias_de_lotes' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Falta el campo "refs" (objeto {ref:{campos}} o lista [{ref:"X",...}])');
    }

    public function test_rechaza_refs_vacio(): void
    {
        $this->postJson('/api/refs/actualizar', ['refs' => []])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_refs_sin_claves_validas(): void
    {
        $this->postJson('/api/refs/actualizar', ['refs' => ['', null, 12]])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
