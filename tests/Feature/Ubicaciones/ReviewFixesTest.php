<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Arreglos del review final.
 */
class ReviewFixesTest extends TestCase
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

    // [I-2 del review] "Variante" tiene que llegar con la frase ya agregada
    public function test_la_variante_llega_precargada_al_formulario_del_local(): void
    {
        $local = DB::table('locales')->orderBy('id')->first();

        $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', ['id' => $local->id, 'variante' => 'HUCHIPA']))
            ->assertOk()
            ->assertSee('HUCHIPA');
    }

    // [M-2 del review] un parametro de query array no puede tirar 500
    public function test_un_parametro_array_no_rompe_el_formulario(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.locales.crear') . '?nombre[]=x&palabra_clave[]=y')
            ->assertOk();
    }

    public function test_un_fragmento_array_no_rompe_la_pantalla_de_titulos(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.titulos') . '?fragmento[]=x')
            ->assertOk();
    }

    // [I-3 del review] un token corto no puede "parecerse" a un local y ofrecer variante
    public function test_un_token_corto_no_se_propone_como_variante(): void
    {
        $clasificador = new \App\Ubicaciones\Clasificador(
            new \App\Ubicaciones\AnalizadorTitulos(
                [['id' => 'LOC_SMI', 'palabra_clave' => 'SMI'], ['id' => 'LOC_CAQUI_INGA', 'palabra_clave' => 'CAQUI|INGA']],
                []
            ),
            ['palabras_ignoradas' => [], 'parecido_porcentaje' => 85, 'parecido_distancia' => 2]
        );

        $this->assertNull($clasificador->clasificar('SMP', ['clientes' => [], 'refs' => 11])['local_id']);
        $this->assertNull($clasificador->clasificar('INDP', ['clientes' => [], 'refs' => 12])['local_id']);
    }
}
