<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use App\Ubicaciones\LinkGoogleMaps;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El formulario de local: la ubicacion guardada se muestra siempre, la cascada de
 * ubigeo no depende del bloque de coordenadas, y los links externos salen de config.
 */
class LocalesUbicacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Http\Middleware\ValidateCsrfToken::class);
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

    public function test_muestra_la_ubicacion_guardada_como_texto(): void
    {
        // LOC_CARAL tiene ubigeo 150601 = HUARAL, HUARAL, LIMA
        $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', 'LOC_CARAL'))
            ->assertOk()
            ->assertSee('Ubicación guardada')
            ->assertSee('LIMA / HUARAL / HUARAL')
            ->assertSee('150601');
    }

    public function test_el_bloque_de_coordenadas_va_despues_de_armar_la_cascada(): void
    {
        // si el bloque de coordenadas fallara antes de arrancar(), los combos de
        // ubigeo quedaban en blanco (bug reportado en produccion)
        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', 'LOC_CARAL'))
            ->assertOk()
            ->getContent();

        $arranque = strpos($html, 'function arrancar');
        $coords = strpos($html, 'Coordenadas: pegar el link de Google Maps');

        $this->assertNotFalse($arranque, 'no se encontro la cascada del ubigeo');
        $this->assertNotFalse($coords, 'no se encontro el bloque de coordenadas');
        $this->assertGreaterThan($arranque, $coords, 'el bloque de coordenadas tiene que ir DESPUES de arrancar()');
    }

    public function test_el_arbol_de_ubigeo_llega_con_el_distrito_guardado(): void
    {
        $html = $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', 'LOC_CARAL'))
            ->getContent();

        preg_match('/var UBIGEO = (\[.*?\]);/s', $html, $m);
        $this->assertNotEmpty($m[1] ?? '', 'la vista no manda el arbol de ubigeo');

        $arbol = json_decode($m[1], true);
        $this->assertIsArray($arbol);
        $this->assertNotEmpty($arbol, 'el arbol de ubigeo llego vacio');

        $ubigeos = [];
        foreach ($arbol as $dep) {
            foreach ($dep['provincias'] as $prov) {
                foreach ($prov['distritos'] as $d) {
                    $ubigeos[] = $d['ubigeo'];
                }
            }
        }

        $this->assertContains('150601', $ubigeos, 'el distrito de LOC_CARAL no viaja en el arbol');
    }

    public function test_el_link_al_mapa_sale_de_config(): void
    {
        config(['ubicaciones.mapa_url' => 'https://visor.local/punto?xy=']);

        $this->actingAs($this->usuario())
            ->get(route('lupita.locales'))->assertOk();

        $this->assertSame(
            'https://visor.local/punto?xy=-11.5,-77.2',
            LinkGoogleMaps::urlMapa(-11.5, -77.2)
        );
    }

    public function test_url_mapa_no_se_arma_con_datos_invalidos(): void
    {
        $this->assertNull(LinkGoogleMaps::urlMapa(null, null));
        $this->assertNull(LinkGoogleMaps::urlMapa('', ''));
        $this->assertNull(LinkGoogleMaps::urlMapa(-91, -77));
        $this->assertNull(LinkGoogleMaps::urlMapa(-11, 181));
        $this->assertNull(LinkGoogleMaps::urlMapa('abc', '-77.2'));
    }

    public function test_el_listado_enlaza_al_mapa_con_el_helper(): void
    {
        $local = DB::table('locales')->orderBy('id')->first();
        DB::table('locales')->where('id', $local->id)->update(['lat' => -11.106312, 'lng' => -77.451234]);

        $this->actingAs($this->usuario())
            ->get(route('lupita.locales'))
            ->assertOk()
            ->assertSee(LinkGoogleMaps::urlMapa(-11.106312, -77.451234), false);
    }
}
