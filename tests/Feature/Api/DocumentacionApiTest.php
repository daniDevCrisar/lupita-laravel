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

    /**
     * El orden medido (consenso > normal > más probable > rápido > tardado) tiene
     * que estar en el texto: es lo único que le dice al integrador cuál mostrar.
     */
    public function test_la_documentacion_publica_el_orden_medido_de_las_fechas(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $html = (string) $this->get(route('lupita.prediccion.documentacion'))->assertOk()->getContent();

        $this->assertStringContainsString('Cuál usar, de más a menos probable', $html);
        $this->assertStringContainsString('3.085 presentaciones reales', $html);

        // el orden de los badges: 1 consenso, 2 normal, 3 más probable, 4 rápido, 5 tardado
        $orden = ['1. Consenso', '2. Normal', '3. Más probable', '4. Rápido', '5. Tardado'];
        $posiciones = array_map(fn ($texto) => strpos($html, $texto), $orden);

        foreach ($posiciones as $i => $posicion) {
            $this->assertNotFalse($posicion, 'falta el badge "' . $orden[$i] . '"');
        }

        for ($i = 1; $i < count($posiciones); $i++) {
            $this->assertGreaterThan($posiciones[$i - 1], $posiciones[$i],
                'el orden se rompió entre "' . $orden[$i - 1] . '" y "' . $orden[$i] . '"');
        }

        // la tabla de KPI de la sección, con sus márgenes
        foreach (['±30 min', '±1 h', '±2 h', '±3 h', '±6 h', '±12 h'] as $margen) {
            $this->assertStringContainsString($margen, $html, 'falta el margen ' . $margen);
        }

        // y la aclaración de que el MAE ordena distinto
        $this->assertStringContainsString('En MAE el orden es otro', $html);
    }

    public function test_el_ejemplo_estatico_de_la_pantalla_existe(): void
    {
        $ruta = public_path('ejemplo-fechas.html');
        $this->assertFileExists($ruta, 'falta el mockup de la pantalla de fechas');

        $html = (string) file_get_contents($ruta);
        $this->assertStringContainsString('Cómo mostrar las fechas cuando se predice', $html);
        // las cinco, en el orden medido
        $orden = ['consenso', 'normal', 'más probable', 'rápido', 'tardado'];
        $posiciones = array_map(fn ($texto) => stripos($html, '· ' . $texto), $orden);

        $encontrados = array_filter($posiciones, fn ($p) => $p !== false);
        $this->assertCount(5, $encontrados, 'el mockup tiene que nombrar las cinco fechas');
    }
}
