<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\LinkGoogleMaps;
use Tests\TestCase;

/**
 * Parser de links de Google Maps.
 *
 * No hay red aca: el link corto se prueba inyectando el resolvedor.
 */
class LinkGoogleMapsTest extends TestCase
{
    public function test_saca_las_coordenadas_del_data_block(): void
    {
        $link = 'https://www.google.com/maps/place/PLANTA+CARAL/data=!4m6!3m5!1s0x0:0x0!8m2!3d-11.1063!4d-77.4512!16z?hl=es';
        $r = LinkGoogleMaps::extraer($link);

        $this->assertTrue($r['ok']);
        $this->assertSame(-11.1063, $r['lat']);
        $this->assertSame(-77.4512, $r['lng']);
        $this->assertSame('data', $r['fuente']);
        $this->assertSame('PLANTA CARAL', $r['nombre']);
    }

    public function test_saca_las_coordenadas_del_arroba_y_el_zoom(): void
    {
        $r = LinkGoogleMaps::extraer('https://www.google.com/maps/place/PLANTA+CARAL/@-11.106312,-77.451234,17z');

        $this->assertTrue($r['ok']);
        $this->assertSame(-11.106312, $r['lat']);
        $this->assertSame(-77.451234, $r['lng']);
        $this->assertSame(17, $r['zoom']);
        $this->assertSame('arroba', $r['fuente']);
    }

    public function test_el_data_block_gana_sobre_el_arroba(): void
    {
        // el @ es el centro del viewport; el !3d/!4d es el pin
        $link = 'https://www.google.com/maps/@-11.2,-77.5,15z/data=!3d-11.1063!4d-77.4512';
        $r = LinkGoogleMaps::extraer($link);

        $this->assertSame(-11.1063, $r['lat']);
        $this->assertSame(-77.4512, $r['lng']);
        $this->assertSame('data', $r['fuente']);
    }

    public function test_saca_las_coordenadas_de_los_query_params(): void
    {
        foreach (['q', 'll', 'daddr', 'center'] as $param) {
            $r = LinkGoogleMaps::extraer('https://maps.google.com/maps?' . $param . '=-11.1063,-77.4512');

            $this->assertTrue($r['ok'], 'no parseo ?' . $param);
            $this->assertSame(-11.1063, $r['lat']);
            $this->assertSame('query', $r['fuente']);
        }
    }

    public function test_resuelve_un_link_corto_con_el_resolvedor_inyectado(): void
    {
        $resolvedor = function (string $url): ?string {
            $this->assertSame('https://maps.app.goo.gl/abc123', $url);

            return 'https://www.google.com/maps/place/PLANTA+CARAL/@-11.1063,-77.4512,17z';
        };

        $r = LinkGoogleMaps::extraer('https://maps.app.goo.gl/abc123', $resolvedor);

        $this->assertTrue($r['ok']);
        $this->assertSame(-11.1063, $r['lat']);
    }

    public function test_rechaza_un_host_que_no_es_de_google(): void
    {
        $r = LinkGoogleMaps::extraer('https://evil.example.com/maps/@-11.1,-77.4,17z');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Google', $r['error']);
    }

    public function test_rechaza_coordenadas_fuera_de_rango(): void
    {
        $r = LinkGoogleMaps::extraer('https://www.google.com/maps/@-111.1,-277.4,17z');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('fuera de rango', $r['error']);
    }

    public function test_rechaza_el_cero_cero(): void
    {
        $r = LinkGoogleMaps::extraer('https://www.google.com/maps?q=0,0');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('0,0', $r['error']);
    }

    public function test_avisa_cuando_el_link_no_trae_coordenadas(): void
    {
        $r = LinkGoogleMaps::extraer('https://www.google.com/maps/place/PLANTA+CARAL/');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('coordenadas', $r['error']);
        $this->assertSame('PLANTA CARAL', $r['nombre']);
    }

    public function test_un_link_vacio_no_revienta(): void
    {
        $this->assertFalse(LinkGoogleMaps::extraer('')['ok']);
        $this->assertFalse(LinkGoogleMaps::extraer('   ')['ok']);
        $this->assertFalse(LinkGoogleMaps::extraer('cualquier cosa')['ok']);
    }

    public function test_el_nombre_del_lugar_decodifica_el_mas_y_los_acentos(): void
    {
        $r = LinkGoogleMaps::extraer('https://www.google.com/maps/place/PTA+CA%C3%91ETE/@-11.1,-77.4,17z');

        $this->assertSame('PTA CAÑETE', $r['nombre']);
    }
}
