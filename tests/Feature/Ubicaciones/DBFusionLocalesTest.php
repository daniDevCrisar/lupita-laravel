<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBFusionLocales;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fusion de locales: deteccion de repetidos, simulacion y aplicacion.
 *
 * La suite corre contra la base real, asi que todo va en transaccion y trabaja con
 * locales propios (prefijo ZFUS_).
 */
class DBFusionLocalesTest extends TestCase
{
    use DatabaseTransactions;

    private function local(string $id, string $clave, array $extra = []): void
    {
        DB::table('locales')->insert(array_merge([
            'id'            => $id,
            'cliente_id'    => 'CLI_AJEPER',
            'nombre'        => $id,
            'palabra_clave' => $clave,
            'activo'        => 1,
        ], $extra));
    }

    public function test_detecta_los_pares_que_comparten_palabra_clave(): void
    {
        $this->local('ZFUS_A', 'ZFUSCLAVE|OTRA');
        $this->local('ZFUS_B', 'OTRA|zFusClave');   // misma variante, otra caja

        $pares = DBFusionLocales::pares();

        $encontrado = null;
        foreach ($pares as $p) {
            $ids = [$p['a']['id'], $p['b']['id']];
            if (in_array('ZFUS_A', $ids, true) && in_array('ZFUS_B', $ids, true)) {
                $encontrado = $p;
            }
        }

        $this->assertNotNull($encontrado, 'no detecto el par que comparte palabra clave');
        $this->assertSame('ZFUSCLAVE', $encontrado['variante']);
    }

    public function test_no_detecta_locales_sin_palabra_en_comun(): void
    {
        $this->local('ZFUS_C', 'UNACLAVE');
        $this->local('ZFUS_D', 'OTRA DISTINTA');

        foreach (DBFusionLocales::pares() as $p) {
            $ids = [$p['a']['id'], $p['b']['id']];
            $this->assertFalse(
                in_array('ZFUS_C', $ids, true) && in_array('ZFUS_D', $ids, true),
                'emparejo dos locales que no comparten nada'
            );
        }
    }

    public function test_el_plan_dice_que_va_a_pasar_sin_tocar_nada(): void
    {
        $this->local('ZFUS_E', 'ZFUSCLAVE2');
        $this->local('ZFUS_F', 'ZFUSCLAVE2|NUEVA');

        $plan = DBFusionLocales::plan('ZFUS_E', 'ZFUS_F');

        $this->assertTrue($plan['ok']);
        $this->assertSame('ZFUS_E', $plan['sobrevive']['id']);
        $this->assertSame('ZFUS_F', $plan['se_va']['id']);
        $this->assertStringContainsString('ZFUSCLAVE2', $plan['palabra_clave_resultante']);
        $this->assertStringContainsString('NUEVA', $plan['palabra_clave_resultante']);

        // no escribio nada
        $this->assertSame(1, DB::table('locales')->where('id', 'ZFUS_F')->value('activo'));
    }

    public function test_no_se_puede_fusionar_un_local_consigo_mismo(): void
    {
        $this->local('ZFUS_G', 'ZFUSCLAVE3');

        $plan = DBFusionLocales::plan('ZFUS_G', 'ZFUS_G');

        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('mismo', $plan['error']);
    }

    public function test_no_se_puede_fusionar_un_local_inexistente(): void
    {
        $this->local('ZFUS_H', 'ZFUSCLAVE4');

        $this->assertFalse(DBFusionLocales::plan('ZFUS_H', 'NO_EXISTE')['ok']);
    }

    public function test_aplicar_une_palabras_clave_y_desactiva_el_sobrante(): void
    {
        $this->local('ZFUS_KEEP', 'CLAVEKEEP');
        $this->local('ZFUS_GONE', 'CLAVEKEEP|CLAVEGONE');

        $resultado = DBFusionLocales::aplicar('ZFUS_KEEP', 'ZFUS_GONE');

        $this->assertTrue($resultado['ok']);

        $keep = DB::table('locales')->where('id', 'ZFUS_KEEP')->first();
        $gone = DB::table('locales')->where('id', 'ZFUS_GONE')->first();

        $this->assertStringContainsString('CLAVEKEEP', $keep->palabra_clave);
        $this->assertStringContainsString('CLAVEGONE', $keep->palabra_clave);
        $this->assertSame(1, (int) $keep->activo);
        $this->assertSame(0, (int) $gone->activo, 'el sobrante se desactiva, no se borra');
        $this->assertNotNull($gone, 'el sobrante no se borra');
    }

    public function test_aplicar_hereda_las_coordenadas_si_el_sobrante_las_tiene(): void
    {
        $this->local('ZFUS_SINC', 'CLAVECOORD');
        $this->local('ZFUS_CON', 'CLAVECOORD', ['lat' => -11.5, 'lng' => -77.2, 'geo_fuente' => 'maps']);

        // sobrevive el que NO tiene coordenadas
        DBFusionLocales::aplicar('ZFUS_SINC', 'ZFUS_CON');

        $fila = DB::table('locales')->where('id', 'ZFUS_SINC')->first();
        $this->assertSame('-11.500000', (string) $fila->lat);
        $this->assertSame('maps', $fila->geo_fuente);
    }

    public function test_aplicar_repunta_las_rutas_y_las_referencias(): void
    {
        $this->local('ZFUS_ORI', 'CLAVEORI');
        $this->local('ZFUS_DES', 'CLAVEDES');
        $this->local('ZFUS_DUP', 'CLAVEDES');

        // ruta ORI -> DUP (la que se va a fusionar con DES)
        DB::table('rutas')->insert([
            'id' => 'RUT_ZFUS_ORI-ZFUS_DUP', 'origen_id' => 'ZFUS_ORI', 'destino_id' => 'ZFUS_DUP',
            'veces_usada' => 3, 'ultima_vez' => now(),
        ]);
        DB::table('referencias')->insert([
            'ref' => 'ZFUSR1', 'titulo_viaje' => 'ENVIO ZFUS', 'ruta_id' => 'RUT_ZFUS_ORI-ZFUS_DUP',
        ]);

        $resultado = DBFusionLocales::aplicar('ZFUS_DES', 'ZFUS_DUP');

        $this->assertTrue($resultado['ok']);
        $this->assertDatabaseHas('rutas', ['id' => 'RUT_ZFUS_ORI-ZFUS_DES', 'destino_id' => 'ZFUS_DES']);
        $this->assertDatabaseHas('referencias', ['ref' => 'ZFUSR1', 'ruta_id' => 'RUT_ZFUS_ORI-ZFUS_DES']);
        $this->assertDatabaseMissing('rutas', ['id' => 'RUT_ZFUS_ORI-ZFUS_DUP']);
    }

    public function test_aplicar_suma_las_veces_usada_cuando_dos_rutas_colapsan(): void
    {
        $this->local('ZFUS_O2', 'CLAVEO2');
        $this->local('ZFUS_D2', 'CLAVED2');
        $this->local('ZFUS_X2', 'CLAVED2');

        DB::table('rutas')->insert([
            ['id' => 'RUT_ZFUS_O2-ZFUS_D2', 'origen_id' => 'ZFUS_O2', 'destino_id' => 'ZFUS_D2',
             'veces_usada' => 5, 'ultima_vez' => '2026-01-01 00:00:00'],
            ['id' => 'RUT_ZFUS_O2-ZFUS_X2', 'origen_id' => 'ZFUS_O2', 'destino_id' => 'ZFUS_X2',
             'veces_usada' => 7, 'ultima_vez' => '2026-02-01 00:00:00'],
        ]);

        DBFusionLocales::aplicar('ZFUS_D2', 'ZFUS_X2');

        $fila = DB::table('rutas')->where('id', 'RUT_ZFUS_O2-ZFUS_D2')->first();
        $this->assertSame(12, (int) $fila->veces_usada, 'las veces usadas se suman');
    }

    public function test_una_ruta_que_quedaria_origen_igual_a_destino_no_se_toca(): void
    {
        $this->local('ZFUS_AA', 'CLAVEAA');
        $this->local('ZFUS_BB', 'CLAVEBB');

        DB::table('rutas')->insert([
            'id' => 'RUT_ZFUS_AA-ZFUS_BB', 'origen_id' => 'ZFUS_AA', 'destino_id' => 'ZFUS_BB',
            'veces_usada' => 2, 'ultima_vez' => now(),
        ]);
        DB::table('referencias')->insert([
            'ref' => 'ZFUSR2', 'titulo_viaje' => 'ENVIO ZFUS', 'ruta_id' => 'RUT_ZFUS_AA-ZFUS_BB',
        ]);

        $plan = DBFusionLocales::plan('ZFUS_AA', 'ZFUS_BB');
        $this->assertContains('RUT_ZFUS_AA-ZFUS_BB', $plan['rutas_self']);
        $this->assertTrue($plan['ok'], 'la simulacion avisa pero no bloquea');

        DBFusionLocales::aplicar('ZFUS_AA', 'ZFUS_BB');

        // la ruta self se deja como estaba
        $this->assertDatabaseHas('rutas', ['id' => 'RUT_ZFUS_AA-ZFUS_BB', 'destino_id' => 'ZFUS_BB']);
        $this->assertDatabaseHas('referencias', ['ref' => 'ZFUSR2', 'ruta_id' => 'RUT_ZFUS_AA-ZFUS_BB']);
    }

    public function test_recorta_la_palabra_clave_a_100_caracteres_y_avisa(): void
    {
        $larga = str_repeat('A', 60);
        $otra = str_repeat('B', 60);

        $this->local('ZFUS_L1', $larga);
        $this->local('ZFUS_L2', $otra);

        $plan = DBFusionLocales::plan('ZFUS_L1', 'ZFUS_L2');
        $this->assertTrue($plan['palabra_clave_recortada']);
        $this->assertLessThanOrEqual(100, mb_strlen($plan['palabra_clave_resultante']));

        DBFusionLocales::aplicar('ZFUS_L1', 'ZFUS_L2');
        $this->assertLessThanOrEqual(100, mb_strlen(DB::table('locales')->where('id', 'ZFUS_L1')->value('palabra_clave')));
    }
}
