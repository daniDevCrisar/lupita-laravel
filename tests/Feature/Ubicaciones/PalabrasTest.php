<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla "Palabras repetidas": el ranking de candidatos a local, con descartes.
 */
class PalabrasTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // la suite corre con APP_ENV=local (los <env> de phpunit.xml no ganan),
        // asi que el CSRF esta activo: los POST de estos tests no llevan token
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function usuario(?string $rol = 'admin'): User
    {
        $id = DB::table('users')->insertGetId([
            'name'     => 'test-ubicaciones',
            'nombres'  => 'Test Ubicaciones',
            'email'    => 'test-ubicaciones-' . uniqid() . '@local',
            'password' => bcrypt('x'),
        ]);

        $user = User::findOrFail($id);
        if ($rol !== null) {
            $user->assignRole($rol);
        }

        return $user;
    }

    public function test_muestra_el_ranking(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.palabras'))
            ->assertOk()
            ->assertSee('Palabras repetidas')
            ->assertSee('Palabras y frases');
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.palabras'))
            ->assertForbidden();
    }

    // [ReviewFocus 5]
    public function test_descartar_y_restaurar_desde_la_pantalla(): void
    {
        $admin = $this->usuario();
        $clave = md5('MAQUILA AGUA');

        $this->actingAs($admin)->post(route('lupita.ubicaciones.palabras.descartar'), [
            'clave' => $clave,
            'texto' => 'MAQUILA AGUA',
        ])->assertRedirect();

        $this->assertDatabaseHas('ubicaciones_descartes', ['id' => $clave, 'tipo' => 'palabra']);

        $this->actingAs($admin)->post(route('lupita.ubicaciones.palabras.restaurar'), [
            'clave' => $clave,
        ])->assertRedirect();

        $this->assertDatabaseMissing('ubicaciones_descartes', ['id' => $clave]);
    }

    public function test_no_acepta_una_clave_que_no_es_md5(): void
    {
        $this->actingAs($this->usuario())->post(route('lupita.ubicaciones.palabras.descartar'), [
            'clave' => 'no-es-un-md5',
            'texto' => 'MAQUILA AGUA',
        ])->assertSessionHasErrors('clave');
    }

    public function test_exporta_csv(): void
    {
        $resp = $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.palabras.exportar'));

        $resp->assertOk();
        $this->assertStringContainsString('text/csv', $resp->headers->get('content-type'));
    }

    public function test_el_prellenado_llega_al_formulario_de_local(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.locales.crear', [
                'nombre'        => 'PLANTA MAQUILA AGUA',
                'palabra_clave' => 'MAQUILA AGUA',
            ]))
            ->assertOk()
            ->assertSee('PLANTA MAQUILA AGUA')
            ->assertSee('MAQUILA AGUA');
    }

    public function test_un_descartado_sigue_visible_pero_marcado(): void
    {
        $admin = $this->usuario();
        DB::table('ubicaciones_descartes')->insert([
            'id'         => md5('MAQUILA AGUA'),
            'tipo'       => 'palabra',
            'clave'      => 'MAQUILA AGUA',
            'created_at' => now(),
        ]);

        $resp = $this->actingAs($admin)->get(route('lupita.ubicaciones.palabras'));

        $resp->assertOk();
        // la fila se marca, no se esconde en el servidor
        $resp->assertSee('data-descartado="1"', false);
    }
}
