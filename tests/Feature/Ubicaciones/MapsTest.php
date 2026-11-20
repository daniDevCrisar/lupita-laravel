<?php

namespace Tests\Feature\Ubicaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coordenadas de los locales: guardado desde el formulario y pantalla de Maps.
 */
class MapsTest extends TestCase
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

    private function unLocal(): object
    {
        return DB::table('locales')->orderBy('id')->first(['id', 'nombre']);
    }

    public function test_un_admin_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.maps'))
            ->assertOk()
            ->assertSee('Desde Google Maps');
    }

    public function test_un_usuario_sin_rol_no_entra(): void
    {
        $this->actingAs($this->usuario(null))
            ->get(route('lupita.ubicaciones.maps'))
            ->assertForbidden();
    }

    public function test_extrae_las_coordenadas_de_un_link(): void
    {
        $resp = $this->actingAs($this->usuario())
            ->postJson(route('lupita.ubicaciones.maps.extraer'), [
                'link' => 'https://www.google.com/maps/place/PLANTA+CARAL/@-11.1063,-77.4512,17z',
            ]);

        $resp->assertOk()
            ->assertJson(['ok' => true, 'lat' => -11.1063, 'lng' => -77.4512])
            ->assertJsonPath('nombre', 'PLANTA CARAL');
    }

    public function test_rechaza_un_link_que_no_es_de_google(): void
    {
        $this->actingAs($this->usuario())
            ->postJson(route('lupita.ubicaciones.maps.extraer'), [
                'link' => 'https://evil.example.com/maps/@-11.1,-77.4,17z',
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_asigna_las_coordenadas_a_un_local(): void
    {
        $local = $this->unLocal();

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.maps.asignar'), [
                'local_id'  => $local->id,
                'lat'       => -11.106312,
                'lng'       => -77.451234,
                'geo_texto' => 'PLANTA CARAL',
            ])
            ->assertRedirect()
            ->assertSessionHas('exito');

        $fila = DB::table('locales')->where('id', $local->id)->first();

        $this->assertSame('-11.106312', (string) $fila->lat);
        $this->assertSame('-77.451234', (string) $fila->lng);
        $this->assertSame('maps', $fila->geo_fuente);
        $this->assertSame('PLANTA CARAL', $fila->geo_texto);
        $this->assertNotNull($fila->geo_actualizado_en);
    }

    public function test_no_asigna_coordenadas_fuera_de_rango(): void
    {
        $local = $this->unLocal();

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.maps.asignar'), [
                'local_id' => $local->id,
                'lat'      => -111.1,
                'lng'      => -77.4,
            ])
            ->assertSessionHasErrors('lat');

        $this->assertNull(DB::table('locales')->where('id', $local->id)->value('lat'));
    }

    public function test_el_atajo_del_distrito_quedo_deshabilitado_explicando_por_que(): void
    {
        // geocodificar un distrito por nombre se equivoca de ciudad (Puente Piedra ->
        // Chiclayo), asi que el atajo devuelve 410 con la explicacion en vez de un
        // punto equivocado que despues usa la prediccion
        $this->actingAs($this->usuario())
            ->getJson(route('lupita.ubicaciones.maps.distrito', '150125'))
            ->assertStatus(410)
            ->assertJson(['ok' => false]);
    }

    public function test_el_listado_de_locales_muestra_la_columna_de_coordenadas(): void
    {
        $local = $this->unLocal();
        DB::table('locales')->where('id', $local->id)->update(['lat' => -11.106312, 'lng' => -77.451234]);

        $this->actingAs($this->usuario())
            ->get(route('lupita.locales'))
            ->assertOk()
            ->assertSee('Coordenadas')
            ->assertSee('https://www.google.com/maps?q=-11.106312,-77.451234', false);
    }

    public function test_el_formulario_conserva_los_combos_de_ubigeo_y_la_tarjeta_de_estado(): void
    {
        // el bloque de coordenadas se inserto en medio del formulario: esto fija que
        // los tres selects del ubigeo y la tarjeta de Estado siguen ahi y bien armados
        $this->actingAs($this->usuario())
            ->get(route('lupita.locales.editar', $this->unLocal()->id))
            ->assertOk()
            ->assertSee('id="departamento"', false)
            ->assertSee('id="provincia"', false)
            ->assertSee('id="distrito"', false)
            ->assertSee('id="distrito_ubigeo"', false)
            ->assertSee('<i class="bi bi-toggle-on"></i> Estado', false)
            ->assertSee('id="link-maps-local"', false)
            ->assertSee('id="lat"', false)
            ->assertSee('id="lng"', false);
    }

    public function test_el_formulario_de_local_guarda_las_coordenadas(): void
    {
        $local = $this->unLocal();

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', $local->id), [
                'cliente_id'      => DB::table('locales')->where('id', $local->id)->value('cliente_id'),
                'nombre'          => 'PLANTA DE PRUEBA',
                'palabra_clave'   => 'PRUEBA',
                'distrito_ubigeo' => '',
                'lat'             => '-11,106312',   // con coma: se normaliza
                'lng'             => '-77.451234',
                'geo_fuente'      => 'manual',
                'activo'          => 1,
            ])
            ->assertRedirect();

        $fila = DB::table('locales')->where('id', $local->id)->first();
        $this->assertSame('-11.106312', (string) $fila->lat);
        $this->assertSame('manual', $fila->geo_fuente);
    }

    public function test_el_formulario_no_acepta_media_coordenada(): void
    {
        $local = $this->unLocal();

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', $local->id), [
                'cliente_id'    => DB::table('locales')->where('id', $local->id)->value('cliente_id'),
                'nombre'        => 'PLANTA DE PRUEBA',
                'palabra_clave' => 'PRUEBA',
                'lat'           => '-11.106312',
                'activo'        => 1,
            ])
            ->assertSessionHasErrors('lng');
    }

    public function test_sin_coordenadas_la_metadata_geo_queda_en_null(): void
    {
        $local = $this->unLocal();

        $this->actingAs($this->usuario())
            ->post(route('lupita.locales.actualizar', $local->id), [
                'cliente_id'    => DB::table('locales')->where('id', $local->id)->value('cliente_id'),
                'nombre'        => 'PLANTA DE PRUEBA',
                'palabra_clave' => 'PRUEBA',
                'geo_fuente'    => 'maps',       // aunque venga, no hay coordenadas
                'activo'        => 1,
            ])
            ->assertRedirect();

        $fila = DB::table('locales')->where('id', $local->id)->first();
        $this->assertNull($fila->lat);
        $this->assertNull($fila->geo_fuente);
        $this->assertNull($fila->geo_actualizado_en);
    }
}
