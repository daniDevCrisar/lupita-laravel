<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\PrediccionController;
use App\Prediccion\Parametros;
use App\Prediccion\Sesgo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Medir y aplicar" de las tres calibraciones.
 *
 * Los botones de medir NO guardan nada: la recomendación se mira antes de
 * dejarla puesta. Estos tests fijan las dos mitades de esa regla:
 *  - sin `aplicar`, la configuración queda igual;
 *  - con `aplicar=1`, se guardan las claves de esa calibración en un solo paso.
 *
 * Importa para el motor: la cuarta fecha (`presenta_probable`) sólo se diferencia
 * de la normal si `sesgo_clase_activo` está prendido, y eso es justo lo que hace
 * "Medir y aplicar".
 */
class CalibrarYAplicarTest extends TestCase
{
    private const VENTANA = [
        'ventana_residuos', 'ventana_adelanto_min', 'ventana_atraso_min',
        'ventana_adelanto_cadena', 'ventana_atraso_cadena',
    ];

    /** @var array<string, string|null> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_merge(['cruce_pares_k', 'sesgo_clase_activo'], self::VENTANA, array_keys(Sesgo::claves())) as $clave) {
            $fila = DB::table('prediccion_config')->where('clave', $clave)->first();
            $this->original[$clave] = $fila !== null ? (string) $fila->valor : null;
        }

        Parametros::olvidar();
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $clave => $valor) {
            DB::table('prediccion_config')->where('clave', $clave)->delete();
            if ($valor !== null) {
                DB::table('prediccion_config')->insert(['clave' => $clave, 'valor' => $valor, 'updated_at' => now()]);
            }
        }

        Parametros::olvidar();
        parent::tearDown();
    }

    /** Borra las claves de una calibración para ver si el botón las vuelve a poner. */
    private function limpiar(array $claves): void
    {
        DB::table('prediccion_config')->whereIn('clave', $claves)->delete();
        Parametros::olvidar();
    }

    private function peticion(string $url, array $datos): Request
    {
        $peticion = Request::create($url, 'POST', $datos);
        $peticion->setLaravelSession(app('session.store'));

        return $peticion;
    }

    public function test_la_pantalla_de_parametros_trae_los_tres_botones_de_aplicar(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(\App\Models\User::query()->firstOrFail());

        $html = (string) $this->get(route('lupita.prediccion.parametros.ver'))->assertOk()->getContent();

        // el botón tiene que existir en el HTML: si la vista y el controlador se
        // desincronizan (o el parcial deja de incluirse) el usuario ve un medir
        // que no aplica nada y vuelve a quedar la cuarta fecha igual a la normal.
        $this->assertSame(1, substr_count($html, 'Calibrar y aplicar'));
        $this->assertSame(3, substr_count($html, 'name="aplicar" value="1"'),
            'deben ser tres: cruce, ventana y sesgos');
        // 2 etiquetas + la confirmación del cruce, que también dice "Medir y aplicar"
        $this->assertSame(3, substr_count($html, '¿Medir y aplicar'));
    }

    public function test_medir_sin_aplicar_no_guarda_nada(): void
    {
        $this->limpiar(['cruce_pares_k']);

        PrediccionController::calibrar_cruce(
            $this->peticion('/lupita/prediccion/calibrar', ['dias' => '30']));

        $this->assertSame(0, DB::table('prediccion_config')->where('clave', 'cruce_pares_k')->count(),
            'calibrar sin aplicar no puede escribir la configuración');
        $this->assertNull(session('estado'));
    }

    public function test_calibrar_y_aplicar_guarda_el_cruce(): void
    {
        $this->limpiar(['cruce_pares_k']);

        $respuesta = PrediccionController::calibrar_cruce(
            $this->peticion('/lupita/prediccion/calibrar', ['dias' => '30', 'aplicar' => '1']));

        $medicion = session('calibracion_cruce');
        if (!($medicion['ok'] ?? false)) {
            $this->markTestSkipped('no se pudo medir el cruce: ' . ($medicion['error'] ?? ''));
        }

        $esperado = (int) $medicion['recomendacion'][0]['recomendado'];

        $this->assertStringContainsString('cruce_pares_k = ' . $esperado, (string) session('estado'));
        $this->assertStringContainsString('/lupita/prediccion/', $respuesta->getTargetUrl());

        Parametros::olvidar();
        $this->assertSame($esperado, (int) Parametros::cargar()->cruce_pares_k);
    }

    public function test_medir_y_aplicar_guarda_la_ventana(): void
    {
        $this->limpiar(self::VENTANA);

        PrediccionController::calibrar_ventana(
            $this->peticion('/lupita/prediccion/ventana', ['cobertura' => '0.7', 'limite' => '200', 'aplicar' => '1']));

        $medicion = session('ventana_medida');
        if (!($medicion['ok'] ?? false)) {
            $this->markTestSkipped('no se pudo medir la ventana: ' . ($medicion['error'] ?? ''));
        }

        Parametros::olvidar();
        $enUso = Parametros::cargar();

        // `ventana_residuos = 1` es lo que activa la banda; sin él los radios no sirven.
        $this->assertSame(1, (int) $enUso->ventana_residuos,
            'aplicar la ventana tiene que prender ventana_residuos');
        $this->assertSame((int) $medicion['adelanto'], (int) $enUso->ventana_adelanto_min);
        $this->assertSame((int) $medicion['atraso'], (int) $enUso->ventana_atraso_min);
        $this->assertSame((int) ($medicion['cadena_radio'] ?? 0), (int) $enUso->ventana_adelanto_cadena);
        $this->assertSame((int) ($medicion['cadena_radio'] ?? 0), (int) $enUso->ventana_atraso_cadena);

        $this->assertStringContainsString('Ventana aplicada', (string) session('estado'));
    }

    public function test_medir_y_aplicar_guarda_los_sesgos_y_prende_la_cuarta_fecha(): void
    {
        $this->limpiar(array_merge(['sesgo_clase_activo'], array_keys(Sesgo::claves())));

        PrediccionController::calibrar_sesgo(
            $this->peticion('/lupita/prediccion/sesgo', ['dias' => '90', 'limite' => '800', 'aplicar' => '1']));

        $medicion = session('sesgo_medido');
        if (!($medicion['ok'] ?? false)) {
            $this->markTestSkipped('no se pudo medir el sesgo: ' . ($medicion['error'] ?? ''));
        }

        $this->assertNotEmpty($medicion['aplicables'], 'la medición no trajo ninguna clase aplicable');

        Parametros::olvidar();
        $enUso = Parametros::cargar();

        // sin este interruptor la cuarta fecha es exactamente igual a la normal
        $this->assertSame(1, (int) $enUso->sesgo_clase_activo,
            'aplicar los sesgos tiene que prender la cuarta fecha');

        foreach ($medicion['aplicables'] as $clave => $valor) {
            $this->assertSame($valor, (int) $enUso->{$clave}, 'no se guardó ' . $clave);
        }
    }
}
