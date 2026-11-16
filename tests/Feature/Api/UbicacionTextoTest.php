<?php

namespace Tests\Feature\Api;

use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ubicación escrita como TEXTO LIBRE: se resuelve contra el catálogo (ubigeos y
 * locales) y, si no está, se geocodifica con Geoapify para rutear ala planta.
 */
class UbicacionTextoTest extends TestCase
{
    private function refMedible(?string $ahora): array
    {
        $fila = DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-01')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref', 'presenta_para_carga']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        return [
            (string) $fila->ref,
            $ahora ?? Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i'),
        ];
    }

    public function test_un_distrito_se_resuelve_por_nombre(): void
    {
        $resolucion = Servicio::resolver_ubicacion_texto('CHICLAYO');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertFalse($resolucion['ambigua']);
        $this->assertSame('ubigeo', $resolucion['tipo']);
        $this->assertSame('140101', $resolucion['id']);
        $this->assertStringContainsString('CHICLAYO', mb_strtoupper((string) $resolucion['texto']));
    }

    public function test_un_local_se_resuelve_por_su_nombre(): void
    {
        $resolucion = Servicio::resolver_ubicacion_texto('BABEL MAXO');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertSame('local', $resolucion['tipo']);
        $this->assertSame('LOC_BABEL_MAXO', $resolucion['id']);
    }

    public function test_el_nombre_corto_de_un_local_tambien_resuelve(): void
    {
        // "CARAL" no es un distrito: es la palabra clave de PLANTA CARAL
        $resolucion = Servicio::resolver_ubicacion_texto('CARAL');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertSame('local', $resolucion['tipo']);
        $this->assertSame('LOC_CARAL', $resolucion['id']);
    }

    public function test_un_nombre_ambiguo_devuelve_candidatos(): void
    {
        // hay distritos "SAN JOSE" en Puno, Lambayeque y La Libertad
        $resolucion = Servicio::resolver_ubicacion_texto('SAN JOSE');

        $this->assertFalse($resolucion['encontrada']);
        $this->assertTrue($resolucion['ambigua']);
        $this->assertGreaterThan(1, count($resolucion['candidatos']));

        $ids = array_column($resolucion['candidatos'], 'id');
        $this->assertContains('140311', $ids);
    }

    public function test_si_no_esta_en_el_catalogo_se_geocodifica(): void
    {
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        \App\Prediccion\Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [[
                    'properties' => ['lat' => -6.7714, 'lon' => -79.8409, 'formatted' => 'Chiclayo, Lambayeque, Perú'],
                ]],
            ], 200),
        ]);

        $resolucion = Servicio::resolver_ubicacion_texto('DISTRITO QUE NO EXISTE 123');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertSame('geopunto', $resolucion['tipo']);
        $this->assertSame('geoapify', $resolucion['fuente']);
        $this->assertEqualsWithDelta(-6.7714, $resolucion['lat'], 0.0001);
        $this->assertGreaterThan(0, \App\Prediccion\Geoapify::llamadas());
    }

    public function test_un_lugar_con_varios_homologos_pide_precision(): void
    {
        // "PASAMAYO" existe en Aucallama (Lima) y en Yantalo (San Martín): el
        // motor no elige el primero a ciegas
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        \App\Prediccion\Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [
                    ['properties' => ['lat' => -11.6063, 'lon' => -77.2178,
                                      'formatted' => 'Pasamayo, Aucallama, LIM, Perú']],
                    ['properties' => ['lat' => -5.9653, 'lon' => -77.0655,
                                      'formatted' => 'Pasamayo, Yantalo, SAM, Perú']],
                ],
            ], 200),
        ]);

        $resolucion = Servicio::resolver_ubicacion_texto('PASAMAYO');

        $this->assertFalse($resolucion['encontrada']);
        $this->assertTrue($resolucion['ambigua']);
        $this->assertSame('geoapify', $resolucion['fuente']);
        $this->assertCount(2, $resolucion['candidatos']);
        $this->assertStringContainsString('Aucallama', $resolucion['candidatos'][0]['texto']);
        $this->assertStringContainsString('Yantalo', $resolucion['candidatos'][1]['texto']);
    }

    public function test_dos_variantes_del_mismo_lugar_no_son_ambiguas(): void
    {
        // Geoapify devuelve la carretera y el centro poblado del MISMO Pasamayo
        // (a pocos km): es un solo lugar, se toma el primero
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        \App\Prediccion\Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [
                    ['properties' => ['lat' => -11.6063, 'lon' => -77.2178, 'name' => 'Pasamayo',
                                      'formatted' => 'Pasamayo, Carretera Panamericana Norte, Aucallama 15125, Perú']],
                    ['properties' => ['lat' => -11.6100, 'lon' => -77.2200, 'name' => 'Pasamayo',
                                      'formatted' => 'Pasamayo, Aucallama, LIM, Perú']],
                ],
            ], 200),
        ]);

        $resolucion = Servicio::resolver_ubicacion_texto('PASAMAYO');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertFalse($resolucion['ambigua']);
        $this->assertSame('geopunto', $resolucion['tipo']);
        $this->assertStringContainsString('Aucallama', (string) $resolucion['texto']);
    }

    public function test_se_prioriza_el_resultado_que_contiene_lo_escrito(): void
    {
        // "PASAMAYO, HUARAL" no debe quedarse con la ciudad de Huaral
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        \App\Prediccion\Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [
                    ['properties' => ['lat' => -11.5000, 'lon' => -77.2000, 'name' => 'Huaral',
                                      'formatted' => 'Huaral, LR, Perú']],
                    ['properties' => ['lat' => -11.6063, 'lon' => -77.2178, 'name' => 'Pasamayo',
                                      'formatted' => 'Pasamayo, Aucallama, LIM, Perú']],
                ],
            ], 200),
        ]);

        $resolucion = Servicio::resolver_ubicacion_texto('PASAMAYO, HUARAL');

        $this->assertTrue($resolucion['encontrada']);
        $this->assertSame('geopunto', $resolucion['tipo']);
        $this->assertStringContainsString('Pasamayo', (string) $resolucion['texto']);
    }

    public function test_la_distancia_entre_dos_puntos_es_razonable(): void
    {
        // Lima -> Pasamayo (Aucallama) ~ 60-70 km en línea recta
        $km = Servicio::distancia_km(-12.0464, -77.0428, -11.6063, -77.2178);
        $this->assertGreaterThan(40, $km);
        $this->assertLessThan(90, $km);

        // Pasamayo (Lima) -> Pasamayo (San Martín) son ~630 km
        $this->assertGreaterThan(500, Servicio::distancia_km(-11.6063, -77.2178, -5.9653, -77.0655));
    }

    public function test_sin_key_ni_catalogo_no_se_puede_ubicar(): void
    {
        config(['geoapify.key' => '']);
        \App\Prediccion\Geoapify::olvidar();
        Http::fake();

        $resolucion = Servicio::resolver_ubicacion_texto('DISTRITO QUE NO EXISTE 123');

        $this->assertFalse($resolucion['encontrada']);
        $this->assertFalse($resolucion['ambigua']);
        Http::assertNothingSent();
    }

    public function test_el_api_acepta_ubicacion_texto_por_nombre(): void
    {
        [$ref, $ahora] = $this->refMedible(null);

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $ref
            . '&ahora=' . urlencode($ahora) . '&ubicacion_texto=CHICLAYO');

        $respuesta->assertOk()->assertJsonPath('success', true);
        $this->assertSame('ubigeo', $respuesta->json('ubicacion.tipo'));
        $this->assertSame('140101', $respuesta->json('ubicacion.id'));
        $this->assertSame('catalogo', $respuesta->json('ubicacion.fuente'));
        $this->assertTrue($respuesta->json('ubicacion.declarada'));
    }

    public function test_el_api_avisa_cuando_el_texto_es_ambiguo(): void
    {
        [$ref, $ahora] = $this->refMedible(null);

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $ref
            . '&ahora=' . urlencode($ahora) . '&ubicacion_texto=' . urlencode('SAN JOSE'));

        $respuesta->assertStatus(422)->assertJsonPath('success', false);
        $respuesta->assertJsonPath('error', 'Ubicacion ambigua');
        $this->assertNotEmpty($respuesta->json('candidatos'));
    }

    public function test_el_api_usa_geoapify_cuando_el_texto_no_esta_en_el_catalogo(): void
    {
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        \App\Prediccion\Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [[
                    'properties' => ['lat' => -6.7714, 'lon' => -79.8409, 'formatted' => 'Chiclayo, Lambayeque, Perú'],
                ]],
            ], 200),
            'api.geoapify.com/v1/routing*' => Http::response([
                'features' => [['properties' => ['time' => 7200.0, 'distance' => 250000.0]]],
            ], 200),
        ]);

        [$ref, $ahora] = $this->refMedible(null);

        $respuesta = $this->getJson('/api/prediccion/presenta?ref=' . $ref
            . '&ahora=' . urlencode($ahora) . '&ubicacion_texto=' . urlencode('DISTRITO QUE NO EXISTE 123'));

        $respuesta->assertOk()->assertJsonPath('success', true);
        $this->assertSame('geopunto', $respuesta->json('ubicacion.tipo'));
        $this->assertSame('geoapify', $respuesta->json('ubicacion.fuente'));

        // El TRASLADO de vuelta es otra jerarquía: si existe una ruta medida entre
        // la planta de esta ref y el ubigeo declarado, el motor la usa aunque haya
        // Geoapify disponible (`mismo_ubigeo` / `ruta_medida`); sólo cae al
        // proveedor cuando no hay nada medido. Antes se exigía 'geoapify' a secas y
        // el test se rompía cada vez que se refrescaba la base con datos nuevos.
        $this->assertContains((string) $respuesta->json('historia.traslado_fuente'),
            ['geoapify', 'mismo_ubigeo', 'ruta_medida', 'departamentos'],
            'el traslado tiene que salir de un escalón conocido de la jerarquía');
        $this->assertGreaterThan(0, (int) $respuesta->json('geoapify.llamadas'));
    }
}
