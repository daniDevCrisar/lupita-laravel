<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBUbicaciones;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rutas que quedaron con un ubigeo en el id (el SP no encontro local) y hay que
 * reprocesar cuando se da de alta el local que faltaba.
 */
class RutasUbigeoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    public function test_detecta_las_rutas_con_ubigeo(): void
    {
        DB::table('rutas')->insert([
            ['id' => 'ZUB_ORI', 'origen_id' => '150132', 'destino_id' => 'LOC_CARAL', 'veces_usada' => 3, 'ultima_vez' => now()],
            ['id' => 'ZUB_DES', 'origen_id' => 'LOC_CARAL', 'destino_id' => '150601', 'veces_usada' => 2, 'ultima_vez' => now()],
            ['id' => 'ZUB_AMBOS', 'origen_id' => '150132', 'destino_id' => '150601', 'veces_usada' => 1, 'ultima_vez' => now()],
            ['id' => 'ZUB_NORMAL', 'origen_id' => 'LOC_CARAL', 'destino_id' => 'LOC_BABEL_MAXO', 'veces_usada' => 9, 'ultima_vez' => now()],
        ]);

        $rutas = collect(DBUbicaciones::rutasConUbigeo())->keyBy('id');

        $this->assertTrue($rutas->has('ZUB_ORI'));
        $this->assertTrue($rutas->has('ZUB_DES'));
        $this->assertTrue($rutas->has('ZUB_AMBOS'));
        $this->assertFalse($rutas->has('ZUB_NORMAL'), 'una ruta local-local no es "con ubigeo"');

        $this->assertTrue($rutas->get('ZUB_ORI')['origen_ubigeo']);
        $this->assertFalse($rutas->get('ZUB_ORI')['destino_ubigeo']);
        $this->assertTrue($rutas->get('ZUB_AMBOS')['origen_ubigeo']);
        $this->assertTrue($rutas->get('ZUB_AMBOS')['destino_ubigeo']);
    }

    public function test_cuenta_las_refs_de_cada_ruta_con_ubigeo(): void
    {
        DB::table('rutas')->insert([
            'id' => 'ZUB_REF', 'origen_id' => '150132', 'destino_id' => 'LOC_CARAL',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);
        DB::table('referencias')->insert([
            ['ref' => 'ZUB1', 'titulo_viaje' => 'ENVIO PT CARAL - ALGO', 'ruta_id' => 'ZUB_REF'],
            ['ref' => 'ZUB2', 'titulo_viaje' => 'ENVIO PT CARAL - OTRO', 'ruta_id' => 'ZUB_REF'],
        ]);

        $ruta = collect(DBUbicaciones::rutasConUbigeo())->firstWhere('id', 'ZUB_REF');

        $this->assertSame(2, $ruta['refs']);
        $this->assertArrayHasKey('ZUB1', DBUbicaciones::refsDeRutas(['ZUB_REF']));
    }

    public function test_la_pantalla_muestra_las_rutas_y_cuantas_resolverian(): void
    {
        DB::table('rutas')->insert([
            'id' => 'ZUB_PANT', 'origen_id' => '150132', 'destino_id' => 'LOC_CARAL',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);
        // el titulo resuelve local-local con el catalogo actual (CARAL y BABEL MAXO)
        DB::table('referencias')->insert([
            'ref' => 'ZUBP1', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => 'ZUB_PANT',
        ]);

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.rutas_ubigeo'))
            ->assertOk()
            ->assertSee('Rutas con ubigeo')
            ->assertSee('ZUB_PANT')
            ->assertSee('refs que ya resolverían a un local');
    }

    public function test_el_reproceso_desde_ubigeo_resuelve_las_refs_mejorables(): void
    {
        config(['ubicaciones.sp_forzado' => true]);

        // la base real tiene 115 rutas con ubigeo: se neutralizan para que el lote de 50
        // procese solo la ruta de este test (todo en transaccion, se revierte)
        DB::table('rutas')->whereRaw("origen_id REGEXP '^[0-9]{6}$'")->update(['origen_id' => 'LOC_CARAL']);
        DB::table('rutas')->whereRaw("destino_id REGEXP '^[0-9]{6}$'")->update(['destino_id' => 'LOC_CARAL']);

        DB::table('rutas')->insert([
            'id' => 'ZUB_REP', 'origen_id' => '150132', 'destino_id' => 'LOC_CARAL',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);
        DB::table('referencias')->insert([
            'ref' => 'ZUBR1', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => 'ZUB_REP',
        ]);

        $resp = $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['desde' => 'ubigeo']);

        $resp->assertOk()->assertSee('Reporte de reproceso');

        // el SP ahora encuentra local-local y la ref deja la ruta con ubigeo
        $ruta = (string) DB::table('referencias')->where('ref', 'ZUBR1')->value('ruta_id');
        $this->assertNotSame('ZUB_REP', $ruta);
        $this->assertStringContainsString('CARAL', $ruta);
        $this->assertStringNotContainsString('150132', $ruta);
    }

    public function test_desde_ubigeo_sin_mejorables_avisa(): void
    {
        config(['ubicaciones.sp_forzado' => true]);

        // la base real tiene rutas con ubigeo: se neutralizan para probar el caso vacio
        // (todo va en transaccion, se revierte al terminar el test)
        DB::table('rutas')->whereRaw("origen_id REGEXP '^[0-9]{6}$'")->update(['origen_id' => 'LOC_CARAL']);
        DB::table('rutas')->whereRaw("destino_id REGEXP '^[0-9]{6}$'")->update(['destino_id' => 'LOC_CARAL']);

        $resp = $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['desde' => 'ubigeo']);

        $resp->assertRedirect(route('lupita.ubicaciones.rutas_ubigeo'))
            ->assertSessionHas('error');
    }

    public function test_al_crear_un_local_avisa_cuantas_refs_con_ubigeo_lo_mencionan(): void
    {
        DB::table('rutas')->insert([
            'id' => 'ZUB_AVISO', 'origen_id' => '150132', 'destino_id' => 'LOC_CARAL',
            'veces_usada' => 1, 'ultima_vez' => now(),
        ]);
        DB::table('referencias')->insert([
            'ref' => 'ZUBA1', 'titulo_viaje' => 'ENVIO PT ZUBNUEVO - CARAL', 'ruta_id' => 'ZUB_AVISO',
        ]);

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.guardar'), [
                'id'            => 'LOC_ZUBNUEVO',
                'cliente_id'    => 'CLI_AJEPER',
                'nombre'        => 'PLANTA ZUBNUEVO',
                'palabra_clave' => 'ZUBNUEVO',
                'activo'        => 1,
            ])
            ->assertRedirect(route('lupita.locales'))
            ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'rutas con ubigeo'));

        $this->assertDatabaseHas('locales', ['id' => 'LOC_ZUBNUEVO']);
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.rutas_ubigeo'))
            ->assertForbidden();
    }

    public function test_el_menu_lleva_a_rutas_con_ubigeo(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee(route('lupita.ubicaciones.rutas_ubigeo'), false);
    }
}
