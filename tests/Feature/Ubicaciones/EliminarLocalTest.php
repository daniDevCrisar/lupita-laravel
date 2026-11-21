<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBLocales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eliminar un local: solo cuando no tiene nada vinculado.
 */
class EliminarLocalTest extends TestCase
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

    private function local(string $id, string $clave = 'ZDELCLAVE'): void
    {
        DB::table('locales')->insert([
            'id'            => $id,
            'cliente_id'    => 'CLI_AJEPER',
            'nombre'        => $id,
            'palabra_clave' => $clave,
            'activo'        => 1,
        ]);
    }

    public function test_borra_un_local_sin_nada_vinculado(): void
    {
        $this->local('ZDEL_SOLO');

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.eliminar', 'ZDEL_SOLO'))
            ->assertRedirect(route('lupita.locales'))
            ->assertSessionHas('exito');

        $this->assertDatabaseMissing('locales', ['id' => 'ZDEL_SOLO']);
    }

    public function test_no_borra_un_local_que_aparece_en_una_ruta(): void
    {
        $this->local('ZDEL_ORI', 'ZDELCLAVE_A');
        $this->local('ZDEL_DES', 'ZDELCLAVE_B');

        DB::table('rutas')->insert([
            'id' => 'RUT_ZDEL_ORI-ZDEL_DES', 'origen_id' => 'ZDEL_ORI', 'destino_id' => 'ZDEL_DES',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.eliminar', 'ZDEL_DES'))
            ->assertRedirect(route('lupita.locales'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('locales', ['id' => 'ZDEL_DES']);
    }

    public function test_el_mensaje_dice_en_que_rutas_se_usa(): void
    {
        $this->local('ZDEL_O2', 'ZDELCLAVE_C');
        $this->local('ZDEL_D2', 'ZDELCLAVE_D');

        DB::table('rutas')->insert([
            'id' => 'RUT_ZDEL_O2-ZDEL_D2', 'origen_id' => 'ZDEL_O2', 'destino_id' => 'ZDEL_D2',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);

        $resultado = DBLocales::eliminar('ZDEL_O2');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('RUT_ZDEL_O2-ZDEL_D2', $resultado['error']);
    }

    public function test_no_borra_si_hay_promedios_compilados(): void
    {
        $this->local('ZDEL_P1', 'ZDELCLAVE_E');
        $this->local('ZDEL_P2', 'ZDELCLAVE_F');

        DB::table('tiempos_ruta_promedio')->insert([
            'ruta_id'    => 'RUT_ZDEL_P1-ZDEL_P2',
            'origen_id'  => 'ZDEL_P1',
            'destino_id' => 'ZDEL_P2',
            'tope_min'   => 0,
            'viajes'     => 1,
        ]);

        $resultado = DBLocales::eliminar('ZDEL_P1');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('promedio', $resultado['error']);
    }

    public function test_un_local_inexistente_avisa(): void
    {
        $resultado = DBLocales::eliminar('NO_EXISTE_ESTE');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('no existe', $resultado['error']);
    }

    public function test_el_listado_deshabilita_el_boton_cuando_hay_rutas(): void
    {
        $this->local('ZDEL_L1', 'ZDELCLAVE_G');
        $this->local('ZDEL_L2', 'ZDELCLAVE_H');

        DB::table('rutas')->insert([
            'id' => 'RUT_ZDEL_L1-ZDEL_L2', 'origen_id' => 'ZDEL_L1', 'destino_id' => 'ZDEL_L2',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);

        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.locales'))
            ->assertOk()
            ->getContent();

        // el local con rutas: boton deshabilitado con el motivo
        $this->assertStringContainsString('aparece en 1 ruta(s)', $html);

        // y el local libre tiene su formulario de borrado
        $this->local('ZDEL_LIBRE', 'ZDELCLAVE_I');
        $html = $this->actingAs($this->usuario())->get(route('lupita.locales'))->getContent();
        $this->assertStringContainsString(route('lupita.locales.eliminar', 'ZDEL_LIBRE'), $html);
    }

    public function test_el_listado_trae_el_conteo_de_rutas_por_local(): void
    {
        $local = DB::table('locales')->orderBy('id')->first();

        $filas = collect(DBLocales::listar())->keyBy('id');

        $this->assertTrue($filas->has($local->id), 'el listado perdio el id de los locales');
        $fila = $filas->get($local->id);
        $this->assertObjectHasProperty('rutas_count', $fila);
        $this->assertGreaterThanOrEqual(0, (int) $fila->rutas_count);
    }

    public function test_un_usuario_sin_rol_no_puede_borrar(): void
    {
        $this->local('ZDEL_NR');

        $this->actingAs($this->usuario(null))
            ->post(route('lupita.locales.eliminar', 'ZDEL_NR'))
            ->assertForbidden();

        $this->assertDatabaseHas('locales', ['id' => 'ZDEL_NR']);
    }
}
