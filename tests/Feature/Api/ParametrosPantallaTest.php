<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Pantalla "Parámetros del motor de predicción": la explicación detallada.
 *
 * Los ocho editables se pueden tocar a mano y no hay historial de cambios, así
 * que la pantalla tiene que decir qué hace cada uno. Este test fija que exista
 * una ficha por parámetro (con ancla propia desde la tabla resumen) y que cada
 * ficha tenga las cinco partes: qué es, qué mueve, si lo subís, si lo bajás y el
 * cuidado.
 */
class ParametrosPantallaTest extends TestCase
{
    private function html(): string
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        return (string) \App\Http\Controllers\PrediccionController::parametros()->render();
    }

    public function test_cada_editable_tiene_su_ficha_con_ancla(): void
    {
        $html = $this->html();

        foreach (\App\Http\Controllers\PrediccionController::PARAMETROS_EDITABLES as $clave) {
            $this->assertStringContainsString('id="detalle-' . $clave . '"', $html,
                'falta la ficha de ' . $clave);
            $this->assertStringContainsString('href="#detalle-' . $clave . '"', $html,
                'la tabla resumen tiene que llevar a la ficha de ' . $clave);
        }
    }

    public function test_cada_ficha_explica_que_es_que_mueve_y_para_donde_moverlo(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Explicación detallada de los ocho parámetros editables', $html);

        foreach (['Qué es.', 'Qué mueve.', 'Si lo subís.', 'Si lo bajás.', 'Ojo.', 'Dónde entra:'] as $parte) {
            // una por cada uno de los ocho parámetros
            $this->assertGreaterThanOrEqual(8, substr_count($html, $parte), 'falta «' . $parte . '»');
        }
    }

    public function test_la_pantalla_distingue_el_valor_en_uso_del_de_fabrica(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('en uso', $html);
        $this->assertStringContainsString('fábrica', $html);
        $this->assertStringContainsString('prediccion_config', $html);
        $this->assertStringContainsString('config/prediccion.php', $html);
    }

    public function test_la_ficha_del_traslado_nombra_la_jerarquia_completa(): void
    {
        $html = $this->html();

        // el traslado es el que más escalones tiene: si se documenta mal, el
        // operador cree que el número por defecto es el que se usa siempre
        foreach (['mismo par de ubigeos', 'la ruta de la propia referencia', 'Geoapify',
                  'ese par de departamentos'] as $escalon) {
            $this->assertStringContainsString($escalon, $html);
        }
    }

    public function test_la_ficha_del_minimo_por_placa_avisa_que_apaga_la_calibracion_por_chofer(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('CALIBRAR POR CHOFER', $html);
        $this->assertStringContainsString('1.540', $html);   // la mejora medida con C3
    }
}
