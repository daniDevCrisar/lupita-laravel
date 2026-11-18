<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Tools: el selector con búsqueda compartido.
 *
 * Antes cada pantalla elegía rutas y departamentos a su manera (un `buscadorDeSelect`
 * en tiempos/horario, `data-combo` en tiempos/promedio, `<select>` pelado en las
 * pantallas /ver y un texto libre en referencias). El componente vive en
 * `lupita/tools/selector.blade.php` + `public/js/lupita-selectores.js` y acá se fija
 * el contrato: el valor viaja en un input hidden con el `name` que espera el
 * formulario, así ninguna pantalla tiene que cambiar su controlador.
 */
class ToolsSelectorTest extends TestCase
{
    private function autenticar(): void
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }
    }

    public function test_la_pantalla_de_tools_muestra_los_tres_ejemplos(): void
    {
        $this->autenticar();

        $respuesta = $this->get(route('lupita.tools.selectores'));
        $respuesta->assertOk();

        $html = (string) $respuesta->getContent();
        $this->assertStringContainsString('Selector con búsqueda', $html);
        $this->assertSame(3, substr_count($html, 'data-selector="simple"'));
        $this->assertSame(1, substr_count($html, 'data-selector="multiple"'));
        $this->assertStringContainsString('lupita-selectores.js', $html);
    }

    public function test_el_selector_simple_deja_el_valor_en_un_input_hidden(): void
    {
        $html = (string) view('lupita.tools.selector', [
            'nombre' => 'ruta_id',
            'id' => 'ruta_id',
            'etiqueta' => 'Ruta',
            'opciones' => [
                ['valor' => 'RUT_A', 'texto' => 'CARAL - CHICLAYO', 'extra' => 'RUT_A'],
                ['valor' => 'RUT_B', 'texto' => 'CARAL - HUACHIPA', 'extra' => 'RUT_B'],
            ],
            'todas' => 'Todas las rutas',
            'valor' => 'RUT_B',
        ])->render();

        // el valor elegido sale en el hidden (lo que lee el formulario)
        $this->assertMatchesRegularExpression('/name="ruta_id"[^>]*value="RUT_B"/', $html);
        $this->assertStringContainsString('data-selector="simple"', $html);
        $this->assertStringContainsString('data-buscar', $html);
        // la opción "todas" va primero y con data-valor vacío
        $this->assertMatchesRegularExpression('/data-valor=""[^>]*>\s*Todas las rutas/', $html);
        // el texto del botón arranca con la opción elegida
        $this->assertStringContainsString('CARAL - HUACHIPA', $html);
    }

    public function test_el_selector_multiple_manda_un_name_por_valor(): void
    {
        $html = (string) view('lupita.tools.selector', [
            'nombre' => 'ruta_id[]',
            'id' => 'ms_rutas',
            'multiple' => true,
            'opciones' => [
                ['valor' => 'RUT_A', 'texto' => 'CARAL - CHICLAYO'],
                ['valor' => 'RUT_B', 'texto' => 'CARAL - HUACHIPA'],
                ['valor' => 'RUT_C', 'texto' => 'CARAL - SALEM'],
            ],
            'valores' => ['RUT_A', 'RUT_C'],
            'todas' => 'Todas (3)',
        ])->render();

        $this->assertStringContainsString('data-selector="multiple"', $html);
        // uno por cada valor marcado
        $this->assertSame(2, substr_count($html, 'name="ruta_id[]"'));
        $this->assertStringContainsString('value="RUT_A"', $html);
        $this->assertStringContainsString('value="RUT_C"', $html);
        // las casillas respetan lo elegido
        $this->assertMatchesRegularExpression('/value="RUT_A"[^>]*checked/s', $html);
        $this->assertSame(3, substr_count($html, 'type="checkbox"'));
        $this->assertStringContainsString('data-accion="todas"', $html);
        $this->assertStringContainsString('data-accion="ninguna"', $html);
        $this->assertStringContainsString('2 seleccionadas', $html);
    }

    public function test_las_pantallas_migradas_usan_el_componente(): void
    {
        $this->autenticar();

        // referencias NO va en esta lista: se le quitó el combo de rutas porque
        // llenarlo costaba 4 subconsultas por ruta y en el hosting la pantalla
        // moría por timeout. Volvió al campo de texto libre.
        $pantallas = [
            'lupita.tiempos.promedio' => ['ruta_id' => 3],          // ruta + 2 departamentos
            'lupita.tiempos.promedio.ver' => ['ruta_id' => 3],
            'lupita.tiempos.promedio.horario.ver' => ['ruta_id' => 1],
        ];

        foreach ($pantallas as $nombre => $esperados) {
            $html = (string) $this->get(route($nombre))->assertOk()->getContent();

            $this->assertStringContainsString('lupita-selectores.js', $html, $nombre . ' no carga el selector');
            $this->assertSame($esperados['ruta_id'] ?? $esperados['ruta'],
                substr_count($html, 'data-selector="simple"'), $nombre . ': cantidad de selectores');
            $this->assertStringNotContainsString('data-combo', $html, $nombre . ' todavía usa el combo viejo');
        }
    }

    /** La consulta de referencias volvió al filtro de ruta por texto libre. */
    public function test_referencias_usa_texto_libre_para_la_ruta(): void
    {
        $this->autenticar();

        $html = (string) $this->get(route('lupita.referencias'))->assertOk()->getContent();

        $this->assertStringContainsString('id="ruta" name="ruta"', $html, 'falta el campo de texto de ruta');
        $this->assertStringNotContainsString('data-selector="simple"', $html,
            'referencias no tiene que cargar el combo de rutas');
        $this->assertStringNotContainsString('lupita-selectores.js', $html);
        $this->assertStringNotContainsString('Todas las rutas', $html);
    }
}
