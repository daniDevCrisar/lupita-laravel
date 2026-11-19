<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * Las tres calibraciones (cruce, ventana y sesgo) escriben claves en
     * `prediccion_config` sin pasar por ningún formulario: la pantalla tiene que
     * mostrar qué está gobernando hoy, o el único modo de saberlo es abrir la base.
     */
    public function test_cada_calibracion_muestra_los_valores_en_uso(): void
    {
        $html = $this->html();
        $p = \App\Prediccion\Parametros::cargar();

        // un bloque por calibración: ablación, cruce, ventana y sesgos
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'En uso ahora'));

        // cruce
        $this->assertStringContainsString('<code>cruce_pares_k</code>', $html);
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('cruce_pares_k', $p->cruce_pares_k), $html);

        // ventana: las siete claves que escribe, con los minutos en h:mm
        foreach (['ventana_residuos', 'ventana_adelanto_min', 'ventana_atraso_min',
                  'ventana_adelanto_cadena', 'ventana_atraso_cadena',
                  'ventana_cobertura', 'ventana_min_filas'] as $clave) {
            $this->assertStringContainsString('<code>' . $clave . '</code>', $html, 'falta ' . $clave);
        }
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('ventana_adelanto_min', $p->ventana_adelanto_min), $html);
        // la de cadena NO termina en _min: si se muestra cruda, el operador lee 2.086
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('ventana_adelanto_cadena', $p->ventana_adelanto_cadena), $html);

        // ablación: los tres parámetros que pisa cada variante, con el modo por nombre
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('dia_modo', $p->dia_modo), $html);
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('curva_modo', $p->curva_modo), $html);
        $this->assertStringContainsString(
            \App\Prediccion\Parametros::texto('pares_recientes_max', $p->pares_recientes_max), $html);
    }

    public function test_la_grilla_muestra_las_nueve_correcciones_de_sesgo_en_uso(): void
    {
        $html = $this->html();
        $p = \App\Prediccion\Parametros::cargar();

        $this->assertStringContainsString('En uso ahora: corrección por clase', $html);

        foreach (\App\Prediccion\Sesgo::clases() as $clase) {
            $clave = \App\Prediccion\Sesgo::clave($clase);
            $this->assertStringContainsString(
                'title="' . \App\Prediccion\Parametros::texto($clave, $p->{$clave} ?? 0) . '"',
                $html, 'falta el sesgo de ' . $clase);
        }

        // y avisa si la cuarta fecha está apagada: los nueve valores no se usan
        $this->assertStringContainsString(
            (int) ($p->sesgo_clase_activo ?? 0) === 1 ? 'prendida' : 'apagada', $html);
    }

    public function test_marca_en_la_calibracion_lo_que_se_aparto_del_valor_de_fabrica(): void
    {
        $clave = 'ventana_adelanto_min';
        $fabrica = (int) \App\Prediccion\Parametros::por_defecto()->{$clave};
        $antes = DB::table('prediccion_config')->where('clave', $clave)->first();

        DB::table('prediccion_config')->updateOrInsert(
            ['clave' => $clave],
            ['valor' => (string) ($fabrica + 151), 'updated_at' => now()]);
        \App\Prediccion\Parametros::olvidar();

        try {
            $html = $this->html();

            // el valor nuevo y, al lado, el de fábrica con la marca de cambiado
            $this->assertStringContainsString(
                \App\Prediccion\Parametros::texto($clave, $fabrica + 151), $html);
            $this->assertStringContainsString(
                'fábrica ' . \App\Prediccion\Parametros::texto($clave, $fabrica), $html);
            $this->assertStringContainsString('cambiado', $html);
        } finally {
            DB::table('prediccion_config')->where('clave', $clave)->delete();
            if ($antes !== null) {
                DB::table('prediccion_config')->insert(
                    ['clave' => $clave, 'valor' => (string) $antes->valor, 'updated_at' => now()]);
            }
            \App\Prediccion\Parametros::olvidar();
        }
    }
}
