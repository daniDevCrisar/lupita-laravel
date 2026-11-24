<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBLocales;
use App\Database\DBUbicaciones;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una `palabra_clave` con un "|" de mas crea una alternativa VACIA en el patron del SP
 * (`\b(A|B|)\b`), y eso matchea CUALQUIER titulo: el local se convierte en un comodin
 * que se lleva todas las refs que no resolvieron. Paso con LOC_SALEM_PIURA
 * ('SALEM PIURA|ALMACEN PIURA|') y la columna "Ruta que quedaría" salio toda con SALEM.
 */
class ClavePeligrosaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // OJO: el FQCN correcto lleva "Foundation"; con el otro, withoutMiddleware no
        // filtra nada y el POST contesta 419.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

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

    private function local(string $id, string $clave, string $cliente = 'CLI_AJEPER'): void
    {
        DB::table('locales')->insert([
            'id'            => $id,
            'cliente_id'    => $cliente,
            'nombre'        => $id,
            'palabra_clave' => $clave,
            'activo'        => 1,
        ]);
    }

    public function test_detecta_la_variante_vacia(): void
    {
        $this->local('ZPEL_ROTO', 'SALEM PIURA|ALMACEN PIURA|');

        $peligrosas = collect(DBLocales::palabrasClavePeligrosas())->keyBy('id');

        $this->assertTrue($peligrosas->has('ZPEL_ROTO'));
        $this->assertSame(1, $peligrosas->get('ZPEL_ROTO')['vacias']);
    }

    public function test_no_marca_las_claves_bien_escritas(): void
    {
        $this->local('ZPEL_BIEN', 'SALEM PIURA|ALMACEN PIURA');

        $ids = array_column(DBLocales::palabrasClavePeligrosas(), 'id');

        $this->assertNotContains('ZPEL_BIEN', $ids);
    }

    /**
     * El comportamiento que hace dano: con la variante vacia CUALQUIER texto coincide.
     * Se fija para que quede claro que no es un bug del analizador sino del dato
     * (MySQL hace exactamente lo mismo: `'X' REGEXP '\b(A|B|)\b'` da 1).
     */
    public function test_una_clave_con_barra_final_coincide_con_cualquier_texto(): void
    {
        $this->local('ZPEL_COMODIN', 'ZPELNOMBRE|');

        $r = DBUbicaciones::analizador()->analizar('CUALQUIER COSA - OTRA COSA');

        $this->assertNotNull($r['origen_resuelto']);
        $this->assertSame('ZPEL_COMODIN', $r['origen_resuelto']['id']);
    }

    public function test_el_formulario_rechaza_una_barra_final(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', 'LOC_CARAL'), [
                'cliente_id'    => DB::table('locales')->where('id', 'LOC_CARAL')->value('cliente_id'),
                'nombre'        => 'PLANTA CARAL',
                'palabra_clave' => 'CARAL|',
                'activo'        => 1,
            ])
            ->assertSessionHasErrors('palabra_clave');

        // no se guardo nada
        $this->assertSame('CARAL', DB::table('locales')->where('id', 'LOC_CARAL')->value('palabra_clave'));
    }

    public function test_el_formulario_rechaza_dos_barras_seguidas(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', 'LOC_CARAL'), [
                'cliente_id'    => DB::table('locales')->where('id', 'LOC_CARAL')->value('cliente_id'),
                'nombre'        => 'PLANTA CARAL',
                'palabra_clave' => 'CARAL||OTRA',
                'activo'        => 1,
            ])
            ->assertSessionHasErrors('palabra_clave');
    }

    public function test_el_formulario_limpia_los_espacios_de_las_variantes(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', 'LOC_CARAL'), [
                'cliente_id'    => DB::table('locales')->where('id', 'LOC_CARAL')->value('cliente_id'),
                'nombre'        => 'PLANTA CARAL',
                'palabra_clave' => '  CARAL  |  PLANTA CARAL  ',
                'activo'        => 1,
            ])
            ->assertRedirect();

        $this->assertSame(
            'CARAL|PLANTA CARAL',
            DB::table('locales')->where('id', 'LOC_CARAL')->value('palabra_clave')
        );
    }

    public function test_el_formulario_de_edicion_avisa_si_la_clave_guardada_esta_mal(): void
    {
        // un local que YA quedo mal guardado: al abrir el form el aviso tiene que estar
        // visible sin depender de que el usuario apriete guardar
        $this->local('ZPEL_FORM', 'UNACLAVE|', 'CLI_AJEPER');

        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', 'ZPEL_FORM'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Hay un «|» de más', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*d-none[^"]*"\s+id="aviso-palabra-clave"/',
            $html,
            'el aviso de variante vacia se renderizo oculto'
        );
    }

    public function test_el_formulario_de_edicion_no_avisa_si_la_clave_esta_bien(): void
    {
        $this->local('ZPEL_FORM2', 'UNACLAVE|OTRA', 'CLI_AJEPER');

        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', 'ZPEL_FORM2'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="[^"]*d-none[^"]*"\s+id="aviso-palabra-clave"/',
            $html,
            'el aviso deberia venir oculto cuando la clave esta bien'
        );
    }

    public function test_el_listado_de_locales_avisa_de_la_clave_peligrosa(): void
    {
        $this->local('ZPEL_AVISO', 'ZPELAVISO|');

        $this->actingAs($this->usuario())
            ->get(route('lupita.locales'))
            ->assertOk()
            ->assertSee('Palabra clave peligrosa')
            ->assertSee('ZPEL_AVISO');
    }

    public function test_la_pantalla_de_titulos_avisa_de_la_clave_peligrosa(): void
    {
        $this->local('ZPEL_AVISO2', 'ZPELAVISO2|');

        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee('Palabra clave peligrosa')
            ->assertSee('ZPEL_AVISO2');
    }
}
