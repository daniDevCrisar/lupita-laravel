<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBReprocesar;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproceso de refs con `sp_actualizar_ruta_id`.
 *
 * Los tests NO dependen de que el procedimiento exista: se inyecta el llamador.
 * La suite corre contra la base real, asi que todo va en transaccion.
 */
class ReprocesarTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['ubicaciones.sp_forzado' => null]);
    }

    private function usuario(?string $rol = 'admin'): User
    {
        $id = DB::table('users')->insertGetId([
            'name'     => 'test-ubicaciones',
            'nombres'  => 'Test Ubicaciones',
            'email'    => 'test-ubicaciones-' . uniqid() . '@local',
            'password' => bcrypt('x'),
        ]);

        $user = User::findOrFail($id);
        if ($rol !== null) {
            $user->assignRole($rol);
        }

        return $user;
    }

    // [ReviewFocus 6] el SP sube veces_usada en cada llamada: una ref ya resuelta no se toca
    public function test_ignora_refs_que_ya_tienen_ruta(): void
    {
        DB::table('referencias')->insert([
            ['ref' => 'ZYA_TIENE', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => 'RUT_X'],
        ]);

        $llamadas = [];
        $reporte = DBReprocesar::procesar(['ZYA_TIENE'], function (string $ref) use (&$llamadas) {
            $llamadas[] = $ref;

            return ['v_ruta_id' => 'RUT_NUEVA'];
        });

        $this->assertSame([], $llamadas);
        $this->assertContains('ZYA_TIENE', $reporte['omitidas']);
        $this->assertSame(0, $reporte['ok']);
        $this->assertSame(1, $reporte['total']);
    }

    public function test_un_error_no_corta_el_lote(): void
    {
        $reporte = DBReprocesar::procesar(['ZR1', 'ZR2', 'ZR3'], function (string $ref) {
            if ($ref === 'ZR2') {
                throw new \RuntimeException('Origen no encontrado: X');
            }

            return ['v_ruta_id' => 'RUT_' . $ref];
        });

        $this->assertSame(2, $reporte['ok']);
        $this->assertSame(1, $reporte['error']);
        $this->assertSame(3, $reporte['total']);

        $fallida = collect($reporte['filas'])->firstWhere('ref', 'ZR2');
        $this->assertSame('Origen no encontrado: X', $fallida['mensaje']);
        $this->assertFalse($fallida['ok']);
    }

    public function test_reporta_la_ruta_que_devuelve_el_sp(): void
    {
        $reporte = DBReprocesar::procesar(['ZR9'], fn (string $ref) => ['v_ruta_id' => 'RUT_CARAL-BABEL_MAXO']);

        $this->assertSame('RUT_CARAL-BABEL_MAXO', $reporte['filas'][0]['ruta_id']);
        $this->assertTrue($reporte['filas'][0]['ok']);
    }

    // [ReviewFocus 1] sin SP no se intenta nada y se avisa
    public function test_si_el_sp_no_esta_el_endpoint_lo_avisa_y_no_escribe(): void
    {
        config(['ubicaciones.sp_forzado' => false]);

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['ZR1']])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('referencias', ['ref' => 'ZR1']);
    }

    public function test_estado_dice_si_el_sp_esta_disponible(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('lupita.ubicaciones.reprocesar.estado'))
            ->assertOk()
            ->assertJsonStructure(['disponible', 'pendientes', 'ya_resolverian']);
    }

    /**
     * El boton NO decide por el contenido del titulo: las refs van derecho al SP. Si la
     * palabra clave de un local hace que el SP mande un viaje a la ruta equivocada, eso
     * se arregla en el catalogo (o se ve en el reporte), no bloqueando el reproceso.
     */
    public function test_manda_las_refs_de_aceite_al_sp_sin_bloquearlas(): void
    {
        config(['ubicaciones.sp_forzado' => true]);

        DB::table('referencias')->insert([
            ['ref' => 'ZACEITE', 'titulo_viaje' => 'RECOJO ACEITE GRUPO PATRONA - PTA HUACHIPA', 'ruta_id' => null],
        ]);

        $resp = $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['ZACEITE']]);

        // devuelve el reporte (no un redirect con error)
        $resp->assertOk()->assertSee('Reporte de reproceso');

        // y el SP hizo su trabajo: la ref ya no esta pendiente
        $this->assertNotSame('', (string) DB::table('referencias')->where('ref', 'ZACEITE')->value('ruta_id'));
    }

    // [I-1 del review] dos POST simultaneos: se revalida la ref justo antes de llamar al SP
    public function test_revalida_la_ref_justo_antes_de_llamar_al_sp(): void
    {
        DB::table('referencias')->insert([
            ['ref' => 'ZDOBLE1', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => null],
            ['ref' => 'ZDOBLE2', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => null],
        ]);

        $llamadas = [];
        $reporte = DBReprocesar::procesar(['ZDOBLE1', 'ZDOBLE2'], function (string $ref) use (&$llamadas) {
            $llamadas[] = $ref;
            // simula otro proceso que resolvio ZDOBLE2 mientras se procesaba el lote
            if ($ref === 'ZDOBLE1') {
                DB::table('referencias')->where('ref', 'ZDOBLE2')->update(['ruta_id' => 'RUT_OTRO']);
            }

            return ['v_ruta_id' => 'RUT_' . $ref];
        });

        $this->assertSame(['ZDOBLE1'], $llamadas);
        $this->assertSame(1, $reporte['ok']);
        $this->assertContains('ZDOBLE2', $reporte['omitidas']);
    }

    public function test_valida_las_refs(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), [
                'refs' => ["ZR1'); DROP TABLE referencias;--"],
            ])
            ->assertSessionHasErrors('refs.0');
    }

    public function test_un_usuario_sin_rol_no_reprocesa(): void
    {
        $this->actingAs($this->usuario(null))
            ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['ZR1']])
            ->assertForbidden();
    }

    /**
     * Regresion: el boton "no hacia nada" porque la pantalla de titulos no mostraba ni
     * el flash de error ni los errores de validacion.
     */
    public function test_la_pantalla_de_titulos_muestra_el_error_del_reproceso(): void
    {
        $this->actingAs($this->usuario())
            ->withSession(['error' => 'El procedimiento no está instalado en esta base.'])
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee('No se procesó nada')
            ->assertSee('El procedimiento no está instalado en esta base.');
    }

    public function test_la_pantalla_de_titulos_muestra_los_errores_de_validacion(): void
    {
        $this->actingAs($this->usuario())
            ->withSession(['errors' => new \Illuminate\Support\MessageBag([
                'refs.0' => ['La referencia tiene caracteres que no corresponden.'],
            ])])
            ->get(route('lupita.ubicaciones.titulos'))
            ->assertOk()
            ->assertSee('Revisá lo enviado')
            ->assertSee('La referencia tiene caracteres que no corresponden.');
    }

    public function test_procesa_y_devuelve_el_reporte(): void
    {
        $resp = $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['ZR1', 'ZR2', 'ZR3']]);

        $resp->assertOk();
        $resp->assertSee('Reporte de reproceso');
        $resp->assertSee('refs elegidas');

        // el lote tiene que entrar comodo en cualquier timeout de proxy
        $this->assertSame(50, \App\Http\Controllers\Ubicaciones\ReprocesarController::POR_LOTE);
    }

    public function test_encadena_el_siguiente_lote_desde_la_sesion(): void
    {
        config(['ubicaciones.sp_forzado' => true]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        DB::table('referencias')->insert([
            ['ref' => 'ZCAD1', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => null],
            ['ref' => 'ZCAD2', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => null],
        ]);

        // primer request: deja 2 pendientes en la sesion y muestra el aviso de continuar
        $primero = $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['ZCAD1', 'ZCAD2']]);

        $primero->assertOk();
        $this->assertSame(0, $primero->viewData('quedan'), 'con 2 refs y lote de 50 no deberia quedar nada');

        // y si se fuerza un lote de 1, el segundo request procesa el resto
        $pendiente = ['pendientes' => ['ZCAD2'], 'total' => 2, 'ok' => 1, 'error' => 0,
                      'omitidas' => [], 'filas' => [['ref' => 'ZCAD1', 'ok' => true, 'ruta_id' => 'RUT_X', 'mensaje' => null]]];

        $segundo = $this->actingAs($this->usuario())
            ->withSession(['reproceso' => $pendiente])
            ->post(route('lupita.ubicaciones.reprocesar'), ['continuar' => 1]);

        $segundo->assertOk();
        $this->assertSame(0, $segundo->viewData('quedan'));
        $this->assertSame(2, $segundo->viewData('reporte')['total']);
    }

    public function test_continuar_sin_estado_en_sesion_avisa(): void
    {
        config(['ubicaciones.sp_forzado' => true]);

        $this->actingAs($this->usuario())
            ->post(route('lupita.ubicaciones.reprocesar'), ['continuar' => 1])
            ->assertRedirect(route('lupita.ubicaciones.titulos'))
            ->assertSessionHas('error');
    }
}
