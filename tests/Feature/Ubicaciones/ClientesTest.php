<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBClientes;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla "Clientes": listado, alta y edicion del catalogo del que cuelgan los locales.
 */
class ClientesTest extends TestCase
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

    public function test_un_admin_ve_el_listado_con_el_conteo_de_locales(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.clientes'))
            ->assertOk()
            ->assertSee('Clientes')
            ->assertSee('CLI_AJEPER')
            ->assertSee('local(es)');
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.clientes'))
            ->assertForbidden();
    }

    public function test_el_conteo_de_locales_por_cliente_es_el_real(): void
    {
        $clientes = collect(DBClientes::listar())->keyBy('id');

        $this->assertTrue($clientes->has('CLI_AJEPER'));

        $reales = DB::table('locales')->where('cliente_id', 'CLI_AJEPER')->count();
        $this->assertSame($reales, (int) $clientes->get('CLI_AJEPER')->locales_count);
    }

    /**
     * Regresion: con un leftJoin a `locales` para contar, el listado devolvia una fila
     * por local (CLI_BABEL repetido 11 veces) y el keyBy del test lo tapaba.
     */
    public function test_el_listado_devuelve_un_cliente_por_fila(): void
    {
        $filas = DBClientes::listar();
        $total = DB::table('clientes')->count();

        $this->assertCount($total, $filas, 'el listado trae filas de mas (join duplicando)');

        $ids = array_map(fn ($c) => $c->id, $filas);
        $this->assertSame(count($ids), count(array_unique($ids)), 'hay clientes repetidos en el listado');
    }

    public function test_crea_un_cliente(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.clientes.guardar'), [
                'id'            => 'cli_ztest',
                'nombre'        => 'CLIENTE DE PRUEBA',
                'tipo'          => 'Distribuidor',
                'descripcion'   => 'Creado por un test',
                'palabra_clave' => 'PRUEBA|ZTEST',
            ])
            ->assertRedirect(route('lupita.ubicaciones.clientes'))
            ->assertSessionHas('exito');

        // el id se guarda en mayusculas
        $this->assertDatabaseHas('clientes', ['id' => 'CLI_ZTEST', 'nombre' => 'CLIENTE DE PRUEBA']);
    }

    public function test_no_acepta_un_id_repetido(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.clientes.guardar'), [
                'id'     => 'CLI_AJEPER',
                'nombre' => 'OTRO',
                'tipo'   => 'Distribuidor',
            ])
            ->assertSessionHasErrors('id');
    }

    public function test_no_acepta_un_id_con_caracteres_raros(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.clientes.guardar'), [
                'id'     => 'CLI RARO/1',
                'nombre' => 'OTRO',
                'tipo'   => 'Distribuidor',
            ])
            ->assertSessionHasErrors('id');
    }

    public function test_el_tipo_es_obligatorio(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.clientes.guardar'), [
                'id'     => 'CLI_SINTIPO',
                'nombre' => 'SIN TIPO',
            ])
            ->assertSessionHasErrors('tipo');
    }

    public function test_edita_un_cliente_sin_tocar_el_id(): void
    {
        $original = DB::table('clientes')->where('id', 'CLI_AJEPER')->first();

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.clientes.actualizar', 'CLI_AJEPER'), [
                'id'            => 'CLI_OTRO_ID',          // se ignora: el id viene por la URL
                'nombre'        => 'AJEPER SA',
                'tipo'          => 'Principal',
                'palabra_clave' => 'AJEPER',
            ])
            ->assertRedirect(route('lupita.ubicaciones.clientes'))
            ->assertSessionHas('exito');

        $this->assertDatabaseHas('clientes', ['id' => 'CLI_AJEPER', 'nombre' => 'AJEPER SA']);
        $this->assertDatabaseMissing('clientes', ['id' => 'CLI_OTRO_ID']);

        // los locales NO quedaron apuntando a un cliente que no existe
        $this->assertSame(0, DB::table('locales')->where('cliente_id', 'CLI_OTRO_ID')->count());
        $this->assertGreaterThan(0, DB::table('locales')->where('cliente_id', 'CLI_AJEPER')->count());
        $this->assertNotNull($original);
    }

    public function test_editar_un_cliente_inexistente_da_404(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.clientes.editar', 'CLI_NO_EXISTE'))
            ->assertNotFound();
    }

    public function test_el_formulario_de_edicion_muestra_los_locales_del_cliente(): void
    {
        $locales = DBClientes::localesDe('CLI_BABEL');
        $this->assertNotEmpty($locales);

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.clientes.editar', 'CLI_BABEL'))
            ->assertOk()
            ->assertSee($locales[0])
            ->assertSee('Locales de este cliente');
    }

    public function test_el_formulario_de_alta_sugiere_los_tipos_existentes(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.clientes.crear'))
            ->assertOk()
            ->assertSee('Distribuidor')
            ->assertSee('tipos-existentes', false);
    }

    public function test_el_listado_enlaza_al_listado_de_locales_filtrado(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.clientes'))
            ->assertOk()
            ->assertSee(route('lupita.locales', ['cliente' => 'CLI_AJEPER']), false);
    }

    public function test_el_listado_de_locales_preselecciona_el_cliente_del_link(): void
    {
        $resp = $this->actingAs($this->usuario())
            ->get(route('lupita.locales', ['cliente' => 'CLI_CODISAL']))
            ->assertOk();

        $html = $resp->getContent();

        // el option de ese cliente viene con selected y el de otro no
        $this->assertMatchesRegularExpression(
            '/value="CLI_CODISAL"\s+selected/',
            $html,
            'el filtro de cliente no llego preseleccionado'
        );
    }

    public function test_un_usuario_sin_rol_no_puede_crear(): void
    {
        $this->actingAs($this->usuario(null))
            ->post(route('lupita.ubicaciones.clientes.guardar'), [
                'id'     => 'CLI_NOROL',
                'nombre' => 'NO ROL',
                'tipo'   => 'Cliente',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('clientes', ['id' => 'CLI_NOROL']);
    }

    public function test_el_menu_lleva_a_clientes(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee(route('lupita.ubicaciones.clientes'), false);
    }
}
