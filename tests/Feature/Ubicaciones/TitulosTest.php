<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla "Títulos sin ruta": el diagnostico por titulo agrupado.
 */
class TitulosTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_un_admin_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee('Títulos sin ruta');
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertForbidden();
    }

    public function test_muestra_los_totales_del_analisis(): void
    {
        $resp = $this->actingAs($this->usuario('admin'))
            ->get(route('lupita.ubicaciones.titulos'));

        $resp->assertOk();
        $resp->assertSee('refs sin ruta');
        $resp->assertSee('títulos distintos');
    }

    public function test_el_menu_tiene_el_grupo_ubicaciones(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertSee('Ubicaciones');
    }

    /**
     * El desplegable Ubicaciones vivia anidado dentro del menu de Usuario y no
     * mostraba ningun link: Bootstrap 5.3 no soporta submenus. Ahora es de primer
     * nivel. La prueba mira la ESTRUCTURA y no los href, porque el submenu roto
     * igual emitia los <a>: lo que no hacia era dibujarlos.
     */
    public function test_ubicaciones_es_un_desplegable_de_primer_nivel(): void
    {
        $resp = $this->actingAs($this->usuario('admin'))
            ->get(route('lupita.ubicaciones.titulos'));

        // ningun submenu anidado (era lo que rompia el desplegable)
        $resp->assertDontSee('dropdown-item dropdown-toggle', false);

        // y hay un nav-link de primer nivel que se llama Ubicaciones
        $resp->assertSeeInOrder(['nav-link dropdown-toggle', 'Ubicaciones'], false);
    }
}
