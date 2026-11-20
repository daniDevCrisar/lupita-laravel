<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla "Fusionar locales": detecta, simula y aplica.
 */
class FusionLocalesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function local(string $id, string $clave): void
    {
        DB::table('locales')->insert([
            'id'            => $id,
            'cliente_id'    => 'CLI_AJEPER',
            'nombre'        => $id,
            'palabra_clave' => $clave,
            'activo'        => 1,
        ]);
    }

    public function test_un_admin_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.fusion'))
            ->assertOk()
            ->assertSee('Fusionar locales');
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.fusion'))
            ->assertForbidden();
    }

    public function test_muestra_los_pares_detectados(): void
    {
        $this->local('ZFUS_P1', 'ZFUSCOMPARTIDA');
        $this->local('ZFUS_P2', 'ZFUSCOMPARTIDA');

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.fusion'))
            ->assertOk()
            ->assertSee('ZFUSCOMPARTIDA')
            ->assertSee('ZFUS_P1')
            ->assertSee('ZFUS_P2');
    }

    public function test_la_simulacion_no_escribe_nada(): void
    {
        $this->local('ZFUS_S1', 'ZFUSSIM');
        $this->local('ZFUS_S2', 'ZFUSSIM|OTRA');

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.fusion', ['sobrevive' => 'ZFUS_S1', 'se_va' => 'ZFUS_S2']))
            ->assertOk()
            ->assertSee('Simulación')
            ->assertSee('no se escribió nada todavía')
            ->assertSee('ZFUSSIM');

        $this->assertSame(1, (int) DB::table('locales')->where('id', 'ZFUS_S2')->value('activo'));
    }

    public function test_simular_el_mismo_local_dos_veces_avisa_y_no_deja_aplicar(): void
    {
        $this->local('ZFUS_T1', 'ZFUSSELF');

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.fusion', ['sobrevive' => 'ZFUS_T1', 'se_va' => 'ZFUS_T1']))
            ->assertOk()
            ->assertSee('consigo mismo')
            ->assertDontSee('Fusionar ZFUS_T1 en ZFUS_T1');
    }

    public function test_aplicar_fusiona_y_avisa_en_el_flash(): void
    {
        $this->local('ZFUS_K1', 'ZFUSAPLICA');
        $this->local('ZFUS_G1', 'ZFUSAPLICA|EXTRA');

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.fusion.aplicar'), [
                'sobrevive' => 'ZFUS_K1',
                'se_va'     => 'ZFUS_G1',
            ])
            ->assertRedirect(route('lupita.ubicaciones.fusion'))
            ->assertSessionHas('exito');

        $keep = DB::table('locales')->where('id', 'ZFUS_K1')->first();
        $gone = DB::table('locales')->where('id', 'ZFUS_G1')->first();

        $this->assertStringContainsString('EXTRA', $keep->palabra_clave);
        $this->assertSame(0, (int) $gone->activo);
    }

    public function test_aplicar_con_un_local_inexistente_no_hace_nada(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.fusion.aplicar'), [
                'sobrevive' => 'NO_EXISTE',
                'se_va'     => 'TAMPOCO',
            ])
            ->assertSessionHasErrors('sobrevive');
    }

    public function test_aplicar_el_mismo_local_avisa_por_flash(): void
    {
        $this->local('ZFUS_T2', 'ZFUSSELF2');

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.fusion.aplicar'), [
                'sobrevive' => 'ZFUS_T2',
                'se_va'     => 'ZFUS_T2',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, (int) DB::table('locales')->where('id', 'ZFUS_T2')->value('activo'));
    }

    public function test_un_usuario_sin_rol_no_puede_aplicar(): void
    {
        $this->local('ZFUS_N1', 'ZFUSNOROL');
        $this->local('ZFUS_N2', 'ZFUSNOROL');

        $this->actingAs($this->usuario(null))
            ->post(route('lupita.ubicaciones.fusion.aplicar'), [
                'sobrevive' => 'ZFUS_N1',
                'se_va'     => 'ZFUS_N2',
            ])
            ->assertForbidden();

        $this->assertSame(1, (int) DB::table('locales')->where('id', 'ZFUS_N2')->value('activo'));
    }

    public function test_el_menu_lleva_a_fusionar(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee(route('lupita.ubicaciones.fusion'), false);
    }
}
