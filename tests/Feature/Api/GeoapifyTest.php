<?php

namespace Tests\Feature\Api;

use App\Prediccion\Geoapify;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Geoapify con la API simulada: se usa sólo como respaldo cuando no hay tránsito
 * medido, y las coordenadas se guardan para no geocodificar en cada request.
 */
class GeoapifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Geoapify::olvidar();
        DB::table('geo_coordenadas')->whereIn('ubigeo', ['140101', '150601', '220901', '130101'])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('geo_coordenadas')->whereIn('ubigeo', ['140101', '150601', '220901', '130101'])->delete();
        Geoapify::olvidar();
        parent::tearDown();
    }

    private function conKey(): void
    {
        config(['geoapify.key' => 'test-key']);
    }

    public function test_sin_key_no_hay_servicio(): void
    {
        config(['geoapify.key' => '']);
        Http::fake();

        $this->assertFalse(Geoapify::disponible());
        $this->assertNull(Geoapify::ruta('140101', '150601'));
        Http::assertNothingSent();
    }

    public function test_geocodifica_una_vez_y_guarda_las_coordenadas(): void
    {
        $this->conKey();
        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [[
                    'properties' => ['lat' => -6.7714, 'lon' => -79.8409, 'formatted' => 'Chiclayo, Lambayeque, Perú'],
                ]],
            ], 200),
        ]);

        $primera = Geoapify::coord_ubigeo('140101');
        $segunda = Geoapify::coord_ubigeo('140101');

        $this->assertEqualsWithDelta(-6.7714, $primera->lat, 0.0001);
        $this->assertEqualsWithDelta(-79.8409, $primera->lng, 0.0001);
        $this->assertStringContainsString('Chiclayo', $primera->texto);
        $this->assertSame($primera->lat, $segunda->lat);
        Http::assertSentCount(1);

        $this->assertDatabaseHas('geo_coordenadas', ['ubigeo' => '140101', 'fuente' => 'geoapify']);
    }

    public function test_ruta_devuelve_minutos_y_km(): void
    {
        $this->conKey();
        DB::table('geo_coordenadas')->insert([
            ['ubigeo' => '150601', 'lat' => -10.8928, 'lng' => -77.5204, 'texto' => 'Supe, Barranca, Lima'],
            ['ubigeo' => '140101', 'lat' => -6.7714, 'lng' => -79.8409, 'texto' => 'Chiclayo, Chiclayo, Lambayeque'],
        ]);
        Http::fake([
            'api.geoapify.com/v1/routing*' => Http::response([
                'features' => [[
                    'properties' => ['time' => 32849.707, 'distance' => 624006.0],
                ]],
            ], 200),
        ]);

        $ruta = Geoapify::ruta('150601', '140101');

        $this->assertSame(547, $ruta->min);
        $this->assertEqualsWithDelta(624.0, $ruta->km, 0.1);
        $this->assertStringContainsString('Supe', $ruta->desde);
        $this->assertStringContainsString('Chiclayo', $ruta->hasta);
    }

    public function test_si_la_api_falla_devuelve_null_y_no_rompe(): void
    {
        $this->conKey();
        DB::table('geo_coordenadas')->insert([
            ['ubigeo' => '150601', 'lat' => -10.8928, 'lng' => -77.5204, 'texto' => 'Supe'],
            ['ubigeo' => '140101', 'lat' => -6.7714, 'lng' => -79.8409, 'texto' => 'Chiclayo'],
        ]);
        Http::fake(['api.geoapify.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertNull(Geoapify::ruta('150601', '140101'));
    }

    public function test_ubigeo_inexistente_no_busca(): void
    {
        $this->conKey();
        Http::fake();

        $this->assertNull(Geoapify::coord_ubigeo('nada'));
        $this->assertNull(Geoapify::coord_ubigeo('999999'));
        Http::assertNothingSent();
    }

    /** Coordenadas ya guardadas: así estos tests sólo hablan de routing. */
    private function coordsGuardadas(): void
    {
        DB::table('geo_coordenadas')->insert([
            ['ubigeo' => '150601', 'lat' => -10.8928, 'lng' => -77.5204, 'texto' => 'Supe, Barranca, Lima'],
            ['ubigeo' => '140101', 'lat' => -6.7714, 'lng' => -79.8409, 'texto' => 'Chiclayo, Chiclayo, Lambayeque'],
            ['ubigeo' => '130101', 'lat' => -8.1119, 'lng' => -79.0287, 'texto' => 'Trujillo, Trujillo, La Libertad'],
        ]);
    }

    /** Respuesta de routing simulada (Http::response() devuelve una promesa). */
    private function respuestaRuta(int $segundos, int $metros): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'features' => [['properties' => ['time' => (float) $segundos, 'distance' => (float) $metros]]],
        ], 200);
    }

    public function test_en_hora_pico_de_la_capital_se_pide_camion_con_trafico(): void
    {
        // capital (1506xx) y 08:00, dentro de 06:30-09:30: el ruteo va con
        // tráfico aproximado (Serpentín de Pasamayo: 134' con tráfico contra 51'
        // sin tráfico y 30' como auto)
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(8056, 34284)]);

        $ruta = Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00'));

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), '/v1/routing')
            && str_contains($peticion->url(), 'mode=truck')
            && str_contains($peticion->url(), 'traffic=approximated'));
        $this->assertSame('truck', Geoapify::modo_ruta_usado());
        $this->assertSame('approximated', Geoapify::trafico_usado());
        $this->assertSame(134, $ruta->min);
        $this->assertSame('approximated', $ruta->trafico);
    }

    public function test_de_madrugada_se_pide_via_libre(): void
    {
        // a las 03:00 el tráfico aproximado es casi vía libre: no se gasta
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(1800, 32000)]);

        Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 03:00'));

        Http::assertSent(fn ($peticion) => !str_contains($peticion->url(), 'traffic='));
        $this->assertNull(Geoapify::trafico_usado());
        Http::assertSentCount(1);
    }

    public function test_cada_zona_tiene_su_reloj(): void
    {
        $this->conKey();
        $this->coordsGuardadas();

        // 06:15: en provincia (06:00-09:00) ya es pico, en la capital no
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(1800, 30000)]);
        Geoapify::ruta('130101', '140101', Carbon::parse('2026-11-14 06:15'));
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'traffic=approximated'));

        // 09:15: en la capital (hasta 09:30) sigue el pico, en provincia ya no
        Geoapify::olvidar();
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(1800, 30000)]);
        Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 09:15'));
        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'traffic=approximated'));

        Geoapify::olvidar();
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(1800, 30000)]);
        Geoapify::ruta('130101', '140101', Carbon::parse('2026-11-14 09:15'));
        Http::assertSent(fn ($peticion) => !str_contains($peticion->url(), 'traffic='));
    }

    public function test_sin_hora_de_salida_no_se_adivina_el_trafico(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(1800, 32000)]);

        Geoapify::ruta('150601', '140101');

        Http::assertSent(fn ($peticion) => !str_contains($peticion->url(), 'traffic='));
    }

    public function test_el_trafico_siempre_ignora_la_hora(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        config(['geoapify.trafico_siempre' => true]);
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(8056, 34284)]);

        Geoapify::ruta('130101', '140101', Carbon::parse('2026-11-14 03:00'));

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'traffic=approximated'));
    }

    public function test_si_el_plan_no_tiene_trafico_se_pide_via_libre(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake([
            'api.geoapify.com/v1/routing*' => Http::sequence()
                ->push(['error' => 'traffic is not supported in your plan'], 400)
                ->push(['features' => [['properties' => ['time' => 3075.9, 'distance' => 36045.0]]]], 200),
        ]);

        $ruta = Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00'));

        $this->assertNotNull($ruta);
        $this->assertSame(51, $ruta->min);
        // sigue siendo camión, pero sin tráfico
        $this->assertSame('truck', Geoapify::modo_ruta_usado());
        $this->assertNull(Geoapify::trafico_usado());
        Http::assertSentCount(2);
    }

    public function test_si_el_plan_no_tiene_camion_se_cae_a_drive(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake([
            'api.geoapify.com/v1/routing*' => Http::sequence()
                ->push(['error' => 'nope'], 400)   // truck + tráfico
                ->push(['error' => 'nope'], 400)   // truck
                ->push(['features' => [['properties' => ['time' => 1774.9, 'distance' => 32588.0]]]], 200),
        ]);

        $ruta = Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00'));

        $this->assertNotNull($ruta);
        $this->assertSame(30, $ruta->min);
        // el escalón que anduvo queda para el resto del proceso
        $this->assertSame('drive', Geoapify::modo_ruta_usado());
        $this->assertNull(Geoapify::trafico_usado());
        Http::assertSentCount(3);
    }

    public function test_el_trafico_se_puede_apagar(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        config(['geoapify.trafico' => '']);
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(3075, 36045)]);

        Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00'));

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'mode=truck')
            && !str_contains($peticion->url(), 'traffic='));
        $this->assertNull(Geoapify::trafico_usado());
        Http::assertSentCount(1);
    }

    public function test_el_modo_de_ruteo_es_configurable(): void
    {
        $this->conKey();
        $this->coordsGuardadas();
        config(['geoapify.modo_ruta' => 'heavy_truck']);
        Http::fake(['api.geoapify.com/v1/routing*' => $this->respuestaRuta(3600, 40000)]);

        Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00'));

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'mode=heavy_truck')
            && str_contains($peticion->url(), 'traffic=approximated'));
        $this->assertSame('heavy_truck', Geoapify::modo_ruta());

        // un valor desconocido no rompe: vuelve al modo camión
        config(['geoapify.modo_ruta' => 'teleport']);
        $this->assertSame('truck', Geoapify::modo_ruta());
    }

    public function test_sin_red_no_se_baja_de_escalon(): void
    {
        // una caída de red NO es un rechazo del plan: no hay que reintentar
        $this->conKey();
        $this->coordsGuardadas();
        Http::fake([
            'api.geoapify.com/v1/routing*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'),
        ]);

        $this->assertNull(Geoapify::ruta('150601', '140101', Carbon::parse('2026-11-14 08:00')));
        $this->assertSame('truck', Geoapify::modo_ruta_usado());
        $this->assertSame('approximated', Geoapify::trafico_usado());
        // un solo intento: sin respuesta no se prueba el escalón siguiente
        $this->assertSame(1, Geoapify::llamadas());
    }
}
