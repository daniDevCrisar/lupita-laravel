<?php

namespace Tests\Feature\Api;

use App\Prediccion\Geoapify;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La ubicación declarada del camión (`ubicacion_texto`) y qué hace el motor con
 * ella. Dos situaciones distintas, y la respuesta del API tiene que decir cuál:
 *
 *  - el camión va en medio de un viaje: desde esa posición hasta el DESTINO de
 *    ese viaje se rutea el tramo que le falta;
 *  - no tiene viaje en curso: desde esa posición hasta la PLANTA DE CARGA.
 *
 * Las refs son sintéticas (placa ZZTEST) porque el dato real no tiene pares
 * "camión en tránsito -> siguiente ref" en la ventana (un camión en tránsito es,
 * por definición, el último viaje de su placa). Se borran al terminar.
 */
class TramoDeclaradoTest extends TestCase
{
    private const PLACA = 'ZZTEST';

    private const REF_VIAJE = 'ZZTEST001';     // ancla con el camión en ruta

    private const REF_CERRADA = 'ZZTEST002';   // ancla que ya terminó de descargar

    private const REF_META = 'ZZTEST003';      // la ref a predecir (sin hitos)

    /** Ruta real, con proyección de ubigeos (050101 Barranca -> LOC_CARAL). */
    private const RUTA = 'RUT_050101-CARAL';

    private const AHORA = '2027-01-03 14:30';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('referencias')->where('placa', self::PLACA)->delete();

        // una por una: cada ref tiene un juego distinto de hitos
        $filas = [
            [
                // camión en ruta: salió del carguío hace 3 h y todavía no llegó
                'ref' => self::REF_VIAJE, 'placa' => self::PLACA, 'ruta_id' => self::RUTA,
                'presenta_para_carga' => '2027-01-03 06:00',
                'inicio_de_carga' => '2027-01-03 07:30',
                'fin_de_carga' => '2027-01-03 09:00',
                'inicio_ruta' => '2027-01-03 11:30',
            ],
            [
                // ya descargó: el camión está libre y tiene que volver a cargar
                'ref' => self::REF_CERRADA, 'placa' => self::PLACA, 'ruta_id' => self::RUTA,
                'presenta_para_carga' => '2027-01-01 06:00',
                'inicio_de_carga' => '2027-01-01 07:30',
                'fin_de_carga' => '2027-01-01 09:00',
                'inicio_ruta' => '2027-01-01 09:30',
                'qr_llegada_destino' => '2027-01-02 05:00',
                'inicio_descargue' => '2027-01-02 07:00',
                'fin_descargue' => '2027-01-02 09:00',
            ],
            // la ref que se predice: sin hitos, con ruta
            ['ref' => self::REF_META, 'placa' => self::PLACA, 'ruta_id' => self::RUTA],
        ];

        foreach ($filas as $fila) {
            DB::table('referencias')->insert($fila);
        }
    }

    protected function tearDown(): void
    {
        DB::table('referencias')->where('placa', self::PLACA)->delete();
        Geoapify::olvidar();

        parent::tearDown();
    }

    /** Geoapify responde: geocodifica cualquier texto y rutea 2 h / 250 km. */
    private function geoapify(bool $rutea = true, int $segundos = 7200): void
    {
        config(['geoapify.key' => 'test-key', 'geoapify.contar' => false]);
        Geoapify::olvidar();

        Http::fake([
            'api.geoapify.com/v1/geocode/*' => Http::response([
                'features' => [[
                    'properties' => ['lat' => -12.2528, 'lon' => -76.9283,
                                      'formatted' => 'Grifo Kio Petroperu, Lurín, Perú'],
                ]],
            ], 200),
            'api.geoapify.com/v1/routing*' => $rutea
                ? Http::response(['features' => [['properties' => [
                    'time' => (float) $segundos, 'distance' => 250000.0]]]], 200)
                : Http::response(['features' => []], 200),
        ]);
    }

    private function predecir(string $ancla, string $extra = '')
    {
        return $this->getJson('/api/prediccion/presenta?ref=' . self::REF_META
            . '&ahora=' . urlencode(self::AHORA) . '&ancla=' . $ancla . $extra);
    }

    public function test_sin_ubicacion_la_respuesta_dice_de_donde_salio_la_posicion(): void
    {
        $this->geoapify();

        $r = $this->predecir(self::REF_VIAJE);

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertSame('inicio_ruta', $r->json('ancla.hito'));
        $this->assertSame('deducida', $r->json('ubicacion.modo'));
        $this->assertFalse($r->json('ubicacion.usada'));
        $this->assertNull($r->json('ubicacion.pedida'));
        $this->assertNull($r->json('ubicacion.tramo'));
        // el estado del camión sigue saliendo de su último hito
        $this->assertSame(self::REF_VIAJE, $r->json('ancla.ref'));
    }

    public function test_con_viaje_en_curso_la_ubicacion_declarada_calcula_el_tramo_hasta_el_destino(): void
    {
        $this->geoapify();

        $sinUbicacion = $this->predecir(self::REF_VIAJE);
        $sinUbicacion->assertOk();

        $conUbicacion = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=CHICLAYO');
        $conUbicacion->assertOk()->assertJsonPath('success', true);

        // la respuesta dice, sin ambigüedad, que se usó lo que se mandó
        $this->assertSame('CHICLAYO', $conUbicacion->json('ubicacion.pedida'));
        $this->assertTrue($conUbicacion->json('ubicacion.usada'));
        $this->assertSame('hacia_destino', $conUbicacion->json('ubicacion.modo'));
        $this->assertStringContainsString('sigue en viaje', (string) $conUbicacion->json('ubicacion.detalle'));

        // y el tramo: 2 h de ruteo desde la ubicación hasta el destino del viaje
        $this->assertSame(120, $conUbicacion->json('ubicacion.tramo.min'));
        $this->assertSame(250.0, (float) $conUbicacion->json('ubicacion.tramo.km'));
        $this->assertSame('geoapify', $conUbicacion->json('ubicacion.tramo.fuente'));
        $this->assertNotEmpty($conUbicacion->json('ubicacion.tramo.hasta'));
        $this->assertTrue((bool) $conUbicacion->json('geoapify.usado'));
        $this->assertStringContainsString('tramo', (string) $conUbicacion->json('geoapify.para'));

        // el tramo reemplaza la ida completa de la ruta: se presenta antes
        $normal = Carbon::parse((string) $sinUbicacion->json('presenta'));
        $conTramo = Carbon::parse((string) $conUbicacion->json('presenta'));
        $this->assertTrue($conTramo->lessThan($normal),
            'con el tramo desde la ubicación declarada tiene que presentarse antes');

        $restanteSin = collect($sinUbicacion->json('desglose'))->firstWhere('concepto', 'restante_viaje');
        $restanteCon = collect($conUbicacion->json('desglose'))->firstWhere('concepto', 'restante_viaje');
        $this->assertLessThan($restanteSin['min'], $restanteCon['min']);
        $this->assertStringContainsString('ubicación declarada', (string) $restanteCon['fuente']);

        // se explica en los pasos y en los supuestos
        $paso = collect($conUbicacion->json('pasos'))->firstWhere('concepto', 'restante_viaje');
        $this->assertStringContainsString('manejo desde', (string) $paso['detalle']);
        $this->assertNotEmpty(array_filter($conUbicacion->json('supuestos') ?? [],
            fn ($s) => str_contains((string) $s, 'ubicación declarada')));
    }

    public function test_un_texto_libre_geocodificado_tambien_sirve_para_el_tramo(): void
    {
        $this->geoapify();

        // "GRIFO KIO LURIN" no es distrito ni local del catálogo: se geocodifica
        $r = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=' . urlencode('GRIFO QUE NO EXISTE 123'));

        $r->assertOk();
        $this->assertSame('geopunto', $r->json('ubicacion.tipo'));
        $this->assertSame('geoapify', $r->json('ubicacion.fuente'));
        $this->assertSame('hacia_destino', $r->json('ubicacion.modo'));
        $this->assertSame(120, $r->json('ubicacion.tramo.min'));
        $this->assertEqualsWithDelta(-12.2528, (float) $r->json('ubicacion.lat'), 0.0001);
        $this->assertGreaterThan(0, (int) $r->json('geoapify.llamadas'));
        // los tiempos de manejo se piden como camión, no como auto
        $this->assertSame('truck', $r->json('geoapify.modo_ruta'));
    }

    public function test_sin_viaje_en_curso_la_ubicacion_es_el_punto_de_partida_del_regreso(): void
    {
        $this->geoapify();

        $r = $this->predecir(self::REF_CERRADA, '&ubicacion_texto=CHICLAYO');

        $r->assertOk();
        $this->assertSame('fin_descargue', $r->json('ancla.hito'));
        $this->assertTrue($r->json('ubicacion.usada'));
        $this->assertSame('hacia_planta', $r->json('ubicacion.modo'));
        $this->assertStringContainsString('planta de carga', (string) $r->json('ubicacion.detalle'));
        // ya no hay ida que acortar: el tramo es el regreso a la planta
        $this->assertNull($r->json('ubicacion.tramo'));
        $this->assertSame('geoapify', $r->json('historia.traslado_fuente'));
    }

    /**
     * Si el pedido trae ubicación y NO trae ancla, el motor NO deduce el último
     * viaje de la placa: arranca en la posición declarada. Caso real (ref 1195347):
     * deducía un fin de descargue de 144,9 h y publicaba `presenta = ahora` con
     * 137 h de "ajuste".
     */
    public function test_con_ubicacion_y_sin_ancla_no_se_deduce_el_ultimo_viaje(): void
    {
        $this->geoapify();

        // la ref a predecir tiene un viaje deducible como ancla (REF_VIAJE, abierto),
        // pero como se manda ubicación y no se manda `ancla`, no se usa
        $r = $this->getJson('/api/prediccion/presenta?ref=' . self::REF_META
            . '&ahora=' . urlencode(self::AHORA)
            . '&ubicacion_texto=' . urlencode('PLANTA CHICLAYO'));

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertSame('ubicacion_declarada', $r->json('ancla.hito'));
        $this->assertSame('hacia_planta', $r->json('ubicacion.modo'));
        $this->assertFalse((bool) $r->json('atrasado'), 'arranca en ahora: no puede estar atrasado');

        $advertencias = array_map(fn ($a) => explode(':', (string) $a)[0], (array) $r->json('advertencias'));
        $this->assertContains('ancla_declarada', $advertencias);
        $this->assertStringContainsString('no trae ancla',
            implode(' ', array_map(fn ($a) => (string) $a, (array) $r->json('advertencias'))));

        // y con `ancla` explícita SÍ se usa el viaje (el comportamiento de siempre)
        $conAncla = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=' . urlencode('PLANTA CHICLAYO'));
        $this->assertSame(self::REF_VIAJE, $conAncla->json('ancla.ref'));
        $this->assertSame('inicio_ruta', $conAncla->json('ancla.hito'));
        $this->assertSame('hacia_destino', $conAncla->json('ubicacion.modo'));
    }

    /**
     * La unidad está declarada EN la planta de carga: no hay traslado ni viaje que
     * esperar, así que se presenta ahora y sólo queda la espera en planta.
     * Caso real (ref 1196252): con el viaje en curso el motor publicaba la
     * presentación 41 h después y un traslado de 39:50.
     */
    public function test_si_la_unidad_esta_en_la_planta_no_hay_traslado_ni_espera_de_viaje(): void
    {
        $this->geoapify();

        // la planta de la ruta del ref es el ubigeo 050101: se declara esa posición
        // CON el viaje en curso como ancla (el peor caso: el ancla dice otra cosa)
        $r = $this->predecir(self::REF_VIAJE, '&ubicacion_tipo=ubigeo&ubicacion_id=050101');

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertSame('ubicacion_declarada', $r->json('ancla.hito'), 'la planta manda sobre el viaje');
        $this->assertSame('hacia_planta', $r->json('ubicacion.modo'));
        $this->assertSame('ya_esta_en_planta', $r->json('historia.traslado_fuente'));
        $this->assertSame(0, (int) $r->json('desglose.1.min'), 'el traslado de regreso es 0');

        // se presenta ahora (el corte) y la carga empieza después de la espera
        $presenta = (string) $r->json('presenta');
        $this->assertStringStartsWith('2027-01-03 14:30', $presenta, 'se presenta en el corte, no 41 h después');

        $inicio = \Carbon\Carbon::parse((string) $r->json('inicio_carga'));
        $this->assertSame((int) $r->json('espera_min'),
            (int) round(\Carbon\Carbon::parse($presenta)->diffInMinutes($inicio)));

        // las cinco fechas colapsan en la hora del corte
        foreach ((array) $r->json('presentaciones') as $presentacion) {
            $this->assertSame(0, (int) $presentacion['delta_normal_min'], $presentacion['clave']);
        }

        $this->assertContains('en_planta', array_map(fn ($a) => explode(':', (string) $a)[0],
            (array) $r->json('advertencias')));
    }

    public function test_sin_geoapify_la_ubicacion_declarada_no_rompe_la_prediccion(): void
    {
        // sin key (el servidor de producción hoy): el texto tiene que seguir
        // resolviéndose por catálogo y la predicción no puede caerse
        config(['geoapify.key' => '']);
        Geoapify::olvidar();
        Http::fake();

        $r = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=CHICLAYO');

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertSame('CHICLAYO', $r->json('ubicacion.pedida'));
        $this->assertSame('140101', $r->json('ubicacion.id'));
        $this->assertFalse((bool) $r->json('geoapify.usado'));
        $this->assertFalse((bool) $r->json('historia.geoapify_disponible'));
        $this->assertContains($r->json('ubicacion.modo'), ['hacia_destino', 'no_usada']);
        Http::assertNothingSent();
    }

    /**
     * La ubicación declarada se respeta SIEMPRE: con el ruteo caído el tramo sale de
     * las coordenadas del local declarado (no queda `ubicacion_no_usada` ni se usa el
     * destino del viaje como si el camión no se hubiera movido).
     */
    public function test_un_local_sin_ruteo_igual_usa_sus_coordenadas(): void
    {
        config(['geoapify.key' => '']);
        Geoapify::olvidar();
        Http::fake();

        $r = $this->predecir(self::REF_VIAJE, '&ubicacion_tipo=local&ubicacion_id=LOC_CHICLAYO');

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertTrue((bool) $r->json('ubicacion.usada'), 'la ubicación declarada se usa siempre');
        $this->assertSame('hacia_destino', $r->json('ubicacion.modo'));
        $this->assertSame('coordenadas', $r->json('ubicacion.tramo.fuente'));
        $this->assertGreaterThan(0, (int) $r->json('ubicacion.tramo.min'));

        $advertencias = array_map(fn ($a) => explode(':', (string) $a)[0], (array) $r->json('advertencias'));
        $this->assertNotContains('ubicacion_no_usada', $advertencias);
    }

    /**
     * Sin ruteo, el tramo declarado sale de las COORDENADAS de los dos extremos con
     * la velocidad de camión del tramo PARCIAL (manejo sin paradas), no con el
     * ajuste del viaje completo (que incluye descansos y sumaba doble).
     */
    public function test_sin_ruteo_el_tramo_declarado_sale_de_las_coordenadas(): void
    {
        config(['geoapify.key' => '']);
        Geoapify::olvidar();
        Http::fake();

        $r = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=' . urlencode('PLANTA CHICLAYO'));

        $r->assertOk()->assertJsonPath('success', true);
        $this->assertSame('hacia_destino', $r->json('ubicacion.modo'));
        $this->assertSame('LOC_CHICLAYO', $r->json('ubicacion.id'));
        $this->assertSame('coordenadas', $r->json('ubicacion.tramo.fuente'));
        $this->assertGreaterThan(0, (int) $r->json('ubicacion.tramo.min'));
        $this->assertGreaterThan(0, (float) $r->json('ubicacion.tramo.km'));
        // las coordenadas de los dos extremos están en el catálogo: no hubo llamadas
        Http::assertNothingSent();
    }

    /**
     * Un ubigeo que no está en `geo_coordenadas` y que Geoapify no puede resolver
     * (sin key) no puede terminar en una hora inventada: la API corta con error.
     */
    public function test_un_ubigeo_que_geoapify_no_encuentra_corta_con_error(): void
    {
        config(['geoapify.key' => '']);
        Geoapify::olvidar();
        Http::fake();

        // por texto libre: ni catálogo ni geocodificación
        $porTexto = $this->predecir(self::REF_VIAJE, '&ubicacion_texto=999999');
        $porTexto->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('No se pudo consultar el API de prediccion',
            (string) $porTexto->json('error'));

        // y declarando el ubigeo directo (sin pasar por el catálogo de texto) el
        // corte lo hace el motor, no el request
        $directo = $this->predecir(self::REF_VIAJE, '&ubicacion_tipo=ubigeo&ubicacion_id=999999');
        $directo->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('No se pudo consultar el API de prediccion',
            (string) $directo->json('error'));
        $this->assertNull($directo->json('presenta'));
    }
}
