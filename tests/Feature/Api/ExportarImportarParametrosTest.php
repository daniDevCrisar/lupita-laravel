<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\PrediccionController;
use App\Prediccion\Parametros;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exportar e importar la configuración del motor.
 *
 * La base no guarda historial de `prediccion_config`, así que el archivo exportado
 * ES el respaldo: se prueba que salga completo y con los tipos que usa el motor, y
 * que importar no pueda dejar la configuración en un estado que el motor no
 * entienda (claves inventadas, valores que no son números, negativos absurdos).
 *
 * La importación tiene las dos mitades de siempre:
 *  - `accion=ver` no guarda nada;
 *  - `accion=aplicar` guarda y deja el antes/después en pantalla.
 */
class ExportarImportarParametrosTest extends TestCase
{
    /** @var array<int, array{clave: string, valor: string}> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        // un import puede tocar cualquier clave: se respalda la tabla completa
        $this->original = DB::table('prediccion_config')->get(['clave', 'valor'])
            ->map(fn ($fila) => ['clave' => (string) $fila->clave, 'valor' => (string) $fila->valor])
            ->all();

        Parametros::olvidar();
    }

    protected function tearDown(): void
    {
        DB::table('prediccion_config')->delete();

        if ($this->original !== []) {
            $filas = array_map(fn (array $fila) => $fila + ['updated_at' => now()], $this->original);
            DB::table('prediccion_config')->insert($filas);
        }

        Parametros::olvidar();
        parent::tearDown();
    }

    /** Petición de importar; con `$contenido` viene con archivo adjunto. */
    private function importar(array $datos, ?string $contenido = null, string $nombre = 'config.json'): Request
    {
        $peticion = Request::create('/lupita/prediccion/parametros/importar', 'POST', $datos);
        $peticion->setLaravelSession(app('session.store'));

        if ($contenido !== null) {
            $peticion->files->set('archivo',
                UploadedFile::fake()->createWithContent($nombre, $contenido));
        }

        return $peticion;
    }

    private function enBase(string $clave): ?string
    {
        $fila = DB::table('prediccion_config')->where('clave', $clave)->first();

        return $fila === null ? null : (string) $fila->valor;
    }

    public function test_el_exportar_baja_todas_las_claves_con_su_tipo(): void
    {
        Parametros::guardar(['pares_recientes_max' => 7, 'ventana_cobertura' => 0.8]);
        Parametros::olvidar();

        $respuesta = PrediccionController::exportar();
        $contenido = (string) $respuesta->getContent();
        $datos = json_decode($contenido, true);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertStringContainsString('attachment;', (string) $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.json', (string) $respuesta->headers->get('Content-Disposition'));

        // sale la configuración EFECTIVA: todas las claves de config/prediccion.php
        $this->assertSame(1, $datos['formato']);
        $this->assertSame('prediccion_config', $datos['tabla']);
        $claves = Parametros::claves();
        sort($claves);
        $exportadas = array_keys($datos['parametros']);
        sort($exportadas);
        $this->assertSame($claves, $exportadas);

        // y con el tipo que usa el motor (int / float), no como texto
        $this->assertSame(7, $datos['parametros']['pares_recientes_max']);
        $this->assertSame(0.8, $datos['parametros']['ventana_cobertura']);
        $this->assertContains('pares_recientes_max', $datos['guardadas'],
            'las claves con valor propio se listan aparte');
    }

    public function test_importar_guarda_y_deja_el_antes_y_el_despues(): void
    {
        $antes = (int) (Parametros::cargar()->pares_recientes_max ?? 0);

        $respuesta = PrediccionController::importar($this->importar(
            ['accion' => 'aplicar'],
            json_encode(['parametros' => ['pares_recientes_max' => 999]])
        ));

        $this->assertSame(999, (int) $this->enBase('pares_recientes_max'));
        $this->assertSame(999, (int) Parametros::cargar()->pares_recientes_max,
            'el motor tiene que ver el valor nuevo en el mismo request');

        $resultado = session('importar_resultado');
        $this->assertTrue($resultado['aplicado']);
        $this->assertSame(1, $resultado['recibidas']);
        $this->assertSame($antes, $resultado['cambios']['pares_recientes_max']['antes']);
        $this->assertSame(999, $resultado['cambios']['pares_recientes_max']['despues']);
        $this->assertSame('config.json', $resultado['nombre']);
        $this->assertSame(302, $respuesta->getStatusCode());
    }

    public function test_la_vista_previa_no_guarda_nada(): void
    {
        $antes = $this->enBase('pares_recientes_max');

        $respuesta = PrediccionController::importar($this->importar(
            ['accion' => 'ver'],
            json_encode(['parametros' => ['pares_recientes_max' => 4242]])
        ));

        // no se escribió nada en la base
        $this->assertSame($antes, $this->enBase('pares_recientes_max'));

        // pero el diff queda listo para confirmar
        $vista = session('importar_vista');
        $this->assertSame(4242, $vista['cambios']['pares_recientes_max']['despues']);
        $this->assertSame(4242, $vista['valores']['pares_recientes_max']);
        $this->assertNull(session('importar_resultado'), 'todavía no se aplicó');
        $this->assertSame(302, $respuesta->getStatusCode());
    }

    public function test_el_boton_del_diff_importa_sin_volver_a_subir_el_archivo(): void
    {
        PrediccionController::importar($this->importar(
            ['accion' => 'ver'],
            json_encode(['parametros' => ['muerto_defecto_min' => 45]])
        ));

        // el segundo paso manda los valores como campos ocultos
        PrediccionController::importar($this->importar([
            'confirmado' => '1',
            'accion' => 'aplicar',
            'archivo_nombre' => 'config.json',
            'cambios' => ['muerto_defecto_min' => '45'],
        ]));

        $this->assertSame(45, (int) $this->enBase('muerto_defecto_min'));
        $this->assertTrue(session('importar_resultado')['aplicado']);
    }

    public function test_las_claves_que_el_motor_no_conoce_se_ignoran_y_se_informan(): void
    {
        PrediccionController::importar($this->importar(
            ['accion' => 'aplicar'],
            json_encode(['parametros' => [
                'pares_recientes_max' => 12,
                'clave_inventada' => 5,
                'otra_mas' => 'hola',
            ]])
        ));

        $resultado = session('importar_resultado');
        $this->assertSame(12, (int) $this->enBase('pares_recientes_max'));
        $this->assertNull($this->enBase('clave_inventada'));
        $this->assertSame(['clave_inventada', 'otra_mas'], $resultado['ignoradas']);

        // y lo que no es número también se rechaza (importar no puede romper el motor)
        $this->assertArrayHasKey('pares_recientes_max', $resultado['cambios']);
        $this->assertSame([], $resultado['rechazadas']);
    }

    public function test_un_valor_que_no_es_numero_se_rechaza(): void
    {
        PrediccionController::importar($this->importar(
            ['accion' => 'aplicar'],
            json_encode(['parametros' => ['pares_recientes_max' => 'muchos', 'min_hitos_bin' => [1, 2]]])
        ));

        $rechazadas = session('importar_resultado')['rechazadas'];
        $this->assertSame('no es un número', $rechazadas['pares_recientes_max']);
        $this->assertSame('no es un número', $rechazadas['min_hitos_bin']);
        $this->assertSame([], session('importar_resultado')['cambios']);
    }

    public function test_los_negativos_solo_entran_en_el_sesgo_por_clase(): void
    {
        PrediccionController::importar($this->importar(
            ['accion' => 'aplicar'],
            json_encode(['parametros' => [
                'min_pares_ruta' => -3,
                'sesgo_clase_cadena_largo' => -120,
            ]])
        ));

        $resultado = session('importar_resultado');
        $this->assertSame('no puede ser negativo', $resultado['rechazadas']['min_pares_ruta']);
        $this->assertSame(-120, (int) $this->enBase('sesgo_clase_cadena_largo'),
            'en el sesgo el signo ES el dato, así que el negativo pasa');
    }

    public function test_un_archivo_que_no_es_json_no_rompe_nada(): void
    {
        $antes = $this->enBase('pares_recientes_max');

        $respuesta = PrediccionController::importar($this->importar(
            ['accion' => 'aplicar'],
            'esto no es json'
        ));

        $this->assertSame(302, $respuesta->getStatusCode());
        $this->assertStringContainsString('no es un JSON válido', (string) session('importar_error'));
        $this->assertSame($antes, $this->enBase('pares_recientes_max'));
        $this->assertNull(session('importar_resultado'));
    }

    public function test_sin_archivo_avisa_y_no_guarda(): void
    {
        PrediccionController::importar($this->importar(['accion' => 'aplicar']));

        $this->assertStringContainsString('falta el archivo', (string) session('estado'));
        $this->assertNull(session('importar_resultado'));
    }

    public function test_un_export_se_puede_volver_a_importar(): void
    {
        Parametros::guardar(['pares_recientes_max' => 6, 'ventana_adelanto_min' => 1200]);
        Parametros::olvidar();

        // ida: exportar
        $exportado = (string) PrediccionController::exportar()->getContent();

        // alguien rompe la configuración a mano
        Parametros::guardar(['pares_recientes_max' => 50, 'ventana_adelanto_min' => 90]);
        Parametros::olvidar();
        $this->assertSame(50, (int) Parametros::cargar()->pares_recientes_max);

        // vuelta: importar el archivo y queda como estaba
        PrediccionController::importar($this->importar(['accion' => 'aplicar'], $exportado));

        Parametros::olvidar();
        $this->assertSame(6, (int) Parametros::cargar()->pares_recientes_max);
        $this->assertSame(1200, (int) Parametros::cargar()->ventana_adelanto_min);
    }

    public function test_la_descarga_funciona_por_http_y_no_solo_llamando_al_controlador(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(\App\Models\User::query()->firstOrFail());

        $respuesta = $this->get(route('lupita.prediccion.parametros.exportar'))->assertOk();

        $this->assertStringContainsString('attachment',
            (string) $respuesta->headers->get('Content-Disposition'));
        $datos = json_decode((string) $respuesta->getContent(), true);
        $this->assertArrayHasKey('parametros', $datos);
        $this->assertArrayHasKey('guardadas', $datos);
    }

    public function test_la_importacion_funciona_por_http_con_el_archivo_subido(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(\App\Models\User::query()->firstOrFail());

        $respuesta = $this->post(route('lupita.prediccion.parametros.importar'), [
            'accion' => 'aplicar',
            'archivo' => UploadedFile::fake()->createWithContent(
                'config.json',
                json_encode(['parametros' => ['espera_carga_defecto_min' => 33]])
            ),
        ]);

        $respuesta->assertRedirect(route('lupita.prediccion.parametros.ver'));
        $this->assertSame(33, (int) $this->enBase('espera_carga_defecto_min'));
    }

    public function test_la_pantalla_de_parametros_trae_la_tarjeta_de_exportar_e_importar(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(\App\Models\User::query()->firstOrFail());

        $html = (string) $this->get(route('lupita.prediccion.parametros.ver'))->assertOk()->getContent();

        $this->assertStringContainsString('Exportar / importar la configuración', $html);
        $this->assertStringContainsString(route('lupita.prediccion.parametros.exportar'), $html);
        $this->assertStringContainsString(route('lupita.prediccion.parametros.importar'), $html);
        $this->assertStringContainsString('name="archivo"', $html);
        $this->assertStringContainsString('Ver qué cambiaría', $html);
        $this->assertStringContainsString('Descargar configuración', $html);
    }

    public function test_la_vista_previa_se_dibuja_con_el_antes_y_el_despues(): void
    {
        $this->withoutMiddleware();
        $this->actingAs(\App\Models\User::query()->firstOrFail());

        $html = (string) $this->withSession(['importar_vista' => [
            'nombre' => 'config.json',
            'recibidas' => 2,
            'ignoradas' => ['clave_inventada'],
            'rechazadas' => ['min_pitos_bin' => 'no existe'],
            'cambios' => ['pares_recientes_max' => ['antes' => 10, 'despues' => 999]],
            'valores' => ['pares_recientes_max' => 999],
        ]])->get(route('lupita.prediccion.parametros.ver'))->assertOk()->getContent();

        $this->assertStringContainsString('Todavía no se guardó nada', $html);
        $this->assertStringContainsString('pares_recientes_max', $html);
        $this->assertStringContainsString('name="confirmado" value="1"', $html);
        $this->assertStringContainsString('name="cambios[pares_recientes_max]" value="999"', $html);
        $this->assertStringContainsString('clave_inventada', $html);
    }
}
