<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresion del bug reportado: en el navegador el boton "Extraer" de Google Maps no
 * funcionaba porque el layout NO tenia `<meta name="csrf-token">`, el fetch mandaba el
 * token vacio y el servidor contestaba 419. El JS no podia leer HTML como JSON, asi que
 * el usuario veia "No se pudo leer el link".
 *
 * Este test NO desactiva el CSRF a proposito: es lo unico que prueba el camino real.
 */
class MapsCsrfTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(): User
    {
        $id = DB::table('users')->insertGetId([
            'name'     => 'test-ubicaciones',
            'nombres'  => 'Test Ubicaciones',
            'email'    => 'test-ubicaciones-' . uniqid() . '@local',
            'password' => bcrypt('x'),
        ]);

        $user = User::findOrFail($id);
        $user->assignRole('admin');

        return $user;
    }

    public function test_el_layout_expone_el_meta_csrf_token(): void
    {
        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.maps'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/name="csrf-token"\s+content="[^"]+"/', $html);
    }

    public function test_extraer_funciona_con_el_token_que_trae_la_pagina(): void
    {
        $admin = $this->usuario();

        $html = $this->actingAs($admin)->get(route('lupita.ubicaciones.maps'))->getContent();
        preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m);
        $token = $m[1] ?? '';
        $this->assertNotSame('', $token, 'la pagina no trae el token: el fetch del navegador mandaria vacio');

        // el link exacto que reporto el usuario
        $this->withHeader('X-CSRF-TOKEN', $token)
            ->actingAs($admin)
            ->postJson(route('lupita.ubicaciones.maps.extraer'), [
                'link' => 'https://www.google.com/maps?q=-11.498,-77.1759&z=15',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'lat' => -11.498, 'lng' => -77.1759])
            ->assertJsonPath('fuente', 'query');
    }

    public function test_sin_token_el_endpoint_contesta_419(): void
    {
        // confirma que el test anterior si depende del token: sin el, 419
        $this->actingAs($this->usuario())
            ->postJson(route('lupita.ubicaciones.maps.extraer'), [
                'link' => 'https://www.google.com/maps?q=-11.498,-77.1759&z=15',
            ])
            ->assertStatus(419);
    }
}
