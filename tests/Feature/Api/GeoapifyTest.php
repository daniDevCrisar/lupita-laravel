<?php

namespace Tests\Feature\Api;

use App\Prediccion\Geoapify;
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
        DB::table('geo_coordenadas')->whereIn('ubigeo', ['140101', '150601', '220901'])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('geo_coordenadas')->whereIn('ubigeo', ['140101', '150601', '220901'])->delete();
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
}
