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
}
