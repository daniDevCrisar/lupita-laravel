<?php

namespace Tests\Feature\Api;

use App\Database\DBGeoapify;
use App\Models\User;
use App\Prediccion\Geoapify;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contador de llamadas a Geoapify: "cuántas veces se usó el API".
 *
 * El contador es de producción (tabla `geoapify_uso`), así que estas pruebas
 * guardan las filas de hoy al empezar y las devuelven al terminar: nunca se
 * pierde el uso real por correr la suite.
 */
class GeoapifyUsoTest extends TestCase
{
    /** @var array<int, array<string, mixed>> filas de hoy que ya existían */
    private array $previas = [];

    private const UBIGEOS = ['140101', '150601'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['geoapify.key' => 'test-key', 'geoapify.contar' => true]);
        Geoapify::olvidar();

        $hoy = now()->toDateString();
        $this->previas = DB::table('geoapify_uso')->where('fecha', $hoy)
            ->get(['fecha', 'tipo', 'llamadas', 'exitosas', 'fallidas', 'actualizado_en'])
            ->map(fn ($fila) => (array) $fila)
            ->all();

        DB::table('geoapify_uso')->where('fecha', $hoy)->delete();
        DB::table('geo_coordenadas')->whereIn('ubigeo', self::UBIGEOS)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('geoapify_uso')->where('fecha', now()->toDateString())->delete();
        if ($this->previas !== []) {
            DB::table('geoapify_uso')->insert($this->previas);
        }

        DB::table('geo_coordenadas')->whereIn('ubigeo', self::UBIGEOS)->delete();
        Geoapify::olvidar();

        parent::tearDown();
    }

    private function conCoordenadas(): void
    {
        DB::table('geo_coordenadas')->insert([
            ['ubigeo' => '150601', 'lat' => -10.8928, 'lng' => -77.5204, 'texto' => 'Supe, Barranca, Lima'],
            ['ubigeo' => '140101', 'lat' => -6.7714, 'lng' => -79.8409, 'texto' => 'Chiclayo, Chiclayo, Lambayeque'],
        ]);
    }

    public function test_una_ruta_suma_una_llamada_al_contador_del_dia(): void
    {
        $this->conCoordenadas();
        Http::fake([
            'api.geoapify.com/v1/routing*' => Http::response([
                'features' => [['properties' => ['time' => 3600.0, 'distance' => 60000.0]]],
            ], 200),
        ]);

        $ruta = Geoapify::ruta('150601', '140101');

        $this->assertNotNull($ruta);
        $this->assertSame(1, Geoapify::llamadas());
        $this->assertSame(['geocode' => 0, 'routing' => 1], Geoapify::llamadas_por_tipo());

        $uso = DBGeoapify::resumen();
        $this->assertTrue($uso->tabla);
        $this->assertSame(1, $uso->hoy);
        $this->assertSame(1, $uso->hoy_routing);
        $this->assertSame(0, $uso->hoy_geocode);
        $this->assertSame(0, $uso->hoy_fallidas);
        $this->assertSame(1, DBGeoapify::hoy());
        $this->assertSame(1, $uso->dias[0]->total);
        $this->assertSame(30, count($uso->dias));
    }

    public function test_el_geocode_se_cuenta_una_vez_y_despues_sale_de_la_tabla(): void
    {
        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [[
                    'properties' => ['lat' => -6.7714, 'lon' => -79.8409, 'formatted' => 'Chiclayo, Lambayeque'],
                ]],
            ], 200),
        ]);

        $primera = Geoapify::coord_ubigeo('140101');
        $segunda = Geoapify::coord_ubigeo('140101');

        $this->assertNotNull($primera);
        $this->assertSame($primera->lat, $segunda->lat);
        $this->assertSame(1, Geoapify::llamadas());
        $this->assertSame(1, DBGeoapify::hoy());

        $uso = DBGeoapify::resumen();
        $this->assertSame(1, $uso->hoy_geocode);
        $this->assertSame(0, $uso->hoy_routing);
        $this->assertNotNull($uso->ultima);
    }

    public function test_una_llamada_que_falla_tambien_se_cuenta(): void
    {
        $this->conCoordenadas();
        Http::fake(['api.geoapify.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertNull(Geoapify::ruta('150601', '140101'));

        $uso = DBGeoapify::resumen();
        $this->assertSame(1, $uso->hoy);
        $this->assertSame(1, $uso->hoy_fallidas);
    }

    public function test_el_reintento_del_geocode_cuenta_dos_llamadas(): void
    {
        // el primer texto (distrito, provincia, departamento) no encuentra nada y
        // se reintenta con el distrito solo: son dos peticiones, dos cobros
        Http::fake(['api.geoapify.com/v1/geocode/*' => Http::response(['features' => []], 200)]);

        $this->assertNull(Geoapify::coord_ubigeo('140101'));
        $this->assertSame(2, Geoapify::llamadas());
        $this->assertSame(2, DBGeoapify::hoy());
    }

    public function test_el_contador_se_puede_apagar(): void
    {
        config(['geoapify.contar' => false]);

        $this->assertFalse(DBGeoapify::registrar('routing'));
        $this->assertSame(0, DBGeoapify::hoy());
        $this->assertSame(0, DBGeoapify::resumen()->hoy);
    }

    public function test_el_json_del_api_trae_las_llamadas_y_el_uso_del_dia(): void
    {
        $fila = DB::table('referencias')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-01')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        // sin key no se llama a Geoapify: el contador de la predicción queda en 0
        config(['geoapify.key' => '']);
        $ahora = Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $fila->ref . '&ahora=' . urlencode($ahora));

        $respuesta->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('geoapify.usado', false)
            ->assertJsonPath('geoapify.llamadas', 0)
            ->assertJsonPath('geoapify.uso_hoy', 0);
    }

    public function test_la_pantalla_del_contador_muestra_el_uso(): void
    {
        DBGeoapify::registrar('geocode');
        DBGeoapify::registrar('routing');
        DBGeoapify::registrar('routing', false);

        $this->withoutMiddleware();
        $this->actingAs(User::query()->firstOrFail());

        $this->get(route('lupita.prediccion.geoapify'))
            ->assertOk()
            ->assertSee('Uso del API de Geoapify')
            ->assertSee('geoapify_uso')
            ->assertSee('Día por día')
            ->assertSee('3')     // hoy: 3 llamadas
            ->assertSee('1');    // fallidas: 1
    }
}
