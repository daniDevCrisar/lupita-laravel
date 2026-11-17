<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * La página "API de predicción" (`/lupita/prediccion/documentacion`) es la
 * referencia que se mira para integrar el API: tiene que nombrar las cinco
 * fechas publicadas. Se rompió al agregar la cuarta y el consenso (el texto
 * seguía diciendo "las tres fechas").
 */
class DocumentacionApiTest extends TestCase
{
    public function test_la_documentacion_nombra_las_cinco_fechas(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $html = (string) $this->get(route('lupita.prediccion.documentacion'))->assertOk()->getContent();

        // el ejemplo del JSON trae los campos de las cinco fechas (Blade escapa
        // las comillas del <pre>, así que se busca el nombre pelado)
        foreach (['presenta_rapido', 'presenta', 'presenta_tardado', 'presenta_probable', 'presenta_consenso'] as $campo) {
            $this->assertStringContainsString($campo, $html, 'falta ' . $campo . ' en el ejemplo');
        }

        // y la lista de presentaciones tiene las cinco claves
        foreach (['rapido', 'normal', 'tardado', 'probable', 'consenso'] as $clave) {
            $this->assertStringContainsString('clave&quot;: &quot;' . $clave, $html, 'falta la clave ' . $clave);
        }

        // el texto de la sección y la tabla de campos, al día
        $this->assertStringContainsString('Las cinco fechas', $html);
        $this->assertStringContainsString('Más probable (4.ª)', $html);
        $this->assertStringContainsString('Consenso (5.ª)', $html);
        $this->assertStringContainsString('presenta_consenso', $html);
        $this->assertStringNotContainsString('las tres fechas', $html, 'quedó texto viejo de tres fechas');
    }
}
