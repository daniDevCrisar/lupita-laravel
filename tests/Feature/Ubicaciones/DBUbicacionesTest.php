<?php

namespace Tests\Feature\Ubicaciones;

use App\Database\DBUbicaciones;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Capa de datos de Ubicaciones.
 *
 * OJO: la suite corre contra la base real (`lupita_bd`), no contra sqlite: los
 * `<env>` de phpunit.xml no ganan sobre el .env. Por eso estos tests van con
 * `DatabaseTransactions` y cada fila que insertan se revierte al terminar.
 */
class DBUbicacionesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('referencias')->insert([
            ['ref' => 'ZCONRUTA', 'titulo_viaje' => 'ENVIO PT CARAL - BABEL MAXO', 'ruta_id' => 'RUT_CARAL-BABEL_MAXO'],
            ['ref' => 'ZSINRUTA', 'titulo_viaje' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA', 'ruta_id' => null],
            // `titulo_viaje` es NOT NULL en esta base: "sin titulo" es cadena vacia, no NULL
            ['ref' => 'ZVACIA', 'titulo_viaje' => '', 'ruta_id' => ''],
        ]);
    }

    public function test_refs_sin_ruta_incluye_las_de_titulo_vacio(): void
    {
        $refs = collect(DBUbicaciones::refsSinRuta())->pluck('ref')->all();

        $this->assertContains('ZSINRUTA', $refs);
        $this->assertContains('ZVACIA', $refs);
        $this->assertNotContains('ZCONRUTA', $refs);
    }

    public function test_la_ref_sin_titulo_llega_con_titulo_vacio(): void
    {
        $fila = collect(DBUbicaciones::refsSinRuta())->firstWhere('ref', 'ZVACIA');

        $this->assertSame('', $fila['titulo']);
    }

    public function test_refs_con_ruta_devuelve_solo_las_que_ya_tienen(): void
    {
        $this->assertSame(
            ['ZCONRUTA'],
            DBUbicaciones::refsConRuta(['ZCONRUTA', 'ZSINRUTA', 'ZVACIA'])
        );
    }

    public function test_descartar_y_restaurar(): void
    {
        $clave = md5('MAQUILA AGUA');

        DBUbicaciones::descartar('palabra', $clave, 'MAQUILA AGUA', 'no es un local', 1);
        $this->assertArrayHasKey($clave, DBUbicaciones::descartes('palabra'));

        DBUbicaciones::restaurar('palabra', $clave);
        $this->assertArrayNotHasKey($clave, DBUbicaciones::descartes('palabra'));
    }

    public function test_descartar_dos_veces_no_duplica(): void
    {
        $clave = md5('MAQUILA AGUA');

        DBUbicaciones::descartar('palabra', $clave, 'MAQUILA AGUA', null, 1);
        DBUbicaciones::descartar('palabra', $clave, 'MAQUILA AGUA', null, 1);

        $this->assertSame(1, DB::table('ubicaciones_descartes')->where('id', $clave)->count());
    }

    public function test_los_descartes_estan_separados_por_tipo(): void
    {
        DBUbicaciones::descartar('palabra', md5('A'), 'A', null, 1);
        DBUbicaciones::descartar('fragmento', md5('B'), 'B', null, 1);

        $this->assertCount(1, DBUbicaciones::descartes('palabra'));
        $this->assertCount(1, DBUbicaciones::descartes('fragmento'));
    }

    public function test_el_sp_se_detecta_instalado(): void
    {
        // en esta base sp_actualizar_ruta_id esta instalado (verificado con SHOW PROCEDURE STATUS)
        $this->assertTrue(DBUbicaciones::spDisponible());
    }

    public function test_el_catalogo_trae_cliente_id_para_clasificar(): void
    {
        $catalogos = DBUbicaciones::catalogos();

        $this->assertNotEmpty($catalogos['locales']);
        $this->assertArrayHasKey('cliente_id', $catalogos['locales'][0]);
        $this->assertArrayHasKey('ubigeo', $catalogos['distritos'][0]);
    }
}
