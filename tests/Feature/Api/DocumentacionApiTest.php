<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * La página "API de predicción" (`/lupita/prediccion/documentacion`) es la
 * referencia que se mira para integrar el API: tiene que nombrar las seis
 * fechas publicadas. Se rompió al agregar la cuarta y el consenso (el texto
 * seguía diciendo "las tres fechas") y otra vez con la sexta.
 */
class DocumentacionApiTest extends TestCase
{
    public function test_la_documentacion_nombra_las_seis_fechas(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $html = (string) $this->get(route('lupita.prediccion.documentacion'))->assertOk()->getContent();

        // el ejemplo del JSON trae los campos de las seis fechas (Blade escapa
        // las comillas del <pre>, así que se busca el nombre pelado)
        foreach (['presenta_rapido', 'presenta', 'presenta_tardado', 'presenta_probable', 'presenta_consenso',
                  'presenta_perfecta'] as $campo) {
            $this->assertStringContainsString($campo, $html, 'falta ' . $campo . ' en el ejemplo');
        }

        // y la lista de presentaciones tiene las seis claves
        foreach (['rapido', 'normal', 'tardado', 'probable', 'consenso', 'perfecta'] as $clave) {
            $this->assertStringContainsString('clave&quot;: &quot;' . $clave, $html, 'falta la clave ' . $clave);
        }

        // el texto de la sección y la tabla de campos, al día
        $this->assertStringContainsString('Las seis fechas', $html);
        $this->assertStringContainsString('Base ajustada (4.ª)', $html);
        $this->assertStringContainsString('Consenso', $html);
        $this->assertStringContainsString('Perfecta', $html);
        $this->assertStringContainsString('presenta_consenso', $html);
        $this->assertStringContainsString('presenta_perfecta', $html);
        $this->assertStringContainsString('perfecta_tarde', $html, 'la advertencia nueva tiene que estar');
        $this->assertStringNotContainsString('las tres fechas', $html, 'quedó texto viejo de tres fechas');
        $this->assertStringNotContainsString('Las cinco fechas', $html, 'quedó el título de cinco fechas');
        $this->assertStringNotContainsString('Más probable', $html, 'la cuarta fecha se llama base ajustada');
    }

    /**
     * El orden de PRIORIDAD POR PRECISIÓN (rápido > consenso > normal > base
     * ajustada > tardado) tiene que estar en el texto: es lo que le dice al
     * integrador cuál mostrar.
     */
    public function test_la_documentacion_publica_el_orden_de_prioridad_de_las_fechas(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $html = (string) $this->get(route('lupita.prediccion.documentacion'))->assertOk()->getContent();

        $this->assertStringContainsString('en orden de prioridad por precisión', $html);
        $this->assertStringContainsString('3.085 presentaciones reales', $html);

        // el orden de los badges (la perfecta va al final, fuera del orden de
        // prioridad: es un piso, no una hora para prometer)
        $orden = ['1. Rápido', '2. Consenso', '3. Normal', '4. Base ajustada', '5. Tardado', '6. Perfecta'];
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
        // las cinco, en el orden de prioridad (la cuarta se llama base ajustada)
        $orden = ['rápido', 'consenso', 'normal', 'base ajustada', 'tardado'];
        $posiciones = array_map(fn ($texto) => stripos($html, '· ' . $texto), $orden);

        $encontrados = array_filter($posiciones, fn ($p) => $p !== false);
        $this->assertCount(5, $encontrados, 'el mockup tiene que nombrar las cinco fechas');
    }
}
