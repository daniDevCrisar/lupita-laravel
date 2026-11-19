<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\AnalizadorTitulos;
use Tests\TestCase;

/**
 * Fidelidad con `sp_actualizar_ruta_id` (V3, sql/rutas.sql:243-400).
 *
 * Los casos salen de la documentacion real del SP (resources/views/refs/ruta_id_docs.blade.php).
 * El analizador tiene que copiar sus rarezas, no arreglarlas: si fuera mas listo que el SP el
 * diagnostico de /lupita/ubicaciones mentiria.
 */
class AnalizadorTitulosTest extends TestCase
{
    private function analizador(): AnalizadorTitulos
    {
        return new AnalizadorTitulos(
            [
                ['id' => 'LOC_AJEPER_GRUPO_PACIFIC_OIL', 'palabra_clave' => 'GRUPO PACIFIC OIL|(ACEITE)'],
                ['id' => 'LOC_CARAL', 'palabra_clave' => 'CARAL'],
                ['id' => 'LOC_HUACHIPA', 'palabra_clave' => 'HUACHIPA'],
                ['id' => 'LOC_BABEL_AMAZON', 'palabra_clave' => 'AMAZON|BABEL AMAZON'],
                ['id' => 'LOC_BABEL_MAXO', 'palabra_clave' => 'MAXO'],
                ['id' => 'LOC_BABEL_CHICLAYO', 'palabra_clave' => 'BABEL CHICLAYO'],
                ['id' => 'LOC_CHICLAYO', 'palabra_clave' => 'CHICLAYO'],
                ['id' => 'LOC_PUCALLPA', 'palabra_clave' => 'PUCALLPA'],
                ['id' => 'LOC_PTE_PIEDRA', 'palabra_clave' => 'PTE PIEDRA'],
            ],
            [
                ['ubigeo' => '150601', 'distrito' => 'SUPE PUERTO'],
                ['ubigeo' => '110101', 'distrito' => 'ICA'],
            ]
        );
    }

    public function test_resuelve_local_a_local(): void
    {
        $r = $this->analizador()->analizar('ENVIO PT AJEPER CARAL - BABEL MAXO / CARGA 16.04');

        $this->assertSame('ok', $r['motivo']);
        $this->assertSame('AJEPER CARAL', $r['origen']);
        $this->assertSame('BABEL MAXO', $r['destino']);
        $this->assertSame('RUT_CARAL-BABEL_MAXO', $r['ruta_id']);
        $this->assertNull($r['falla']);
        $this->assertTrue($r['ya_resolveria']);
    }

    public function test_el_guion_sin_espacios_no_alcanza_a_partir_el_titulo(): void
    {
        // Verificado contra MySQL con las funciones del SP: ' - ' aparece recien en
        // "AYACUCHO - CARGA" (posicion 43), asi que el corte NO es el guion de
        // INSUMOS-HUACHIPA. La pagina /lupita/refs/ruta-id dice lo contrario y esta mal:
        // el origen queda entero y lo que no resuelve es el destino "CARGA 22".
        $r = $this->analizador()->analizar('DESPACHO DE INSUMOS-HUACHIPA -PTA AYACUCHO - CARGA 22/04/2026');

        $this->assertSame('DE INSUMOS-HUACHIPA -PTA AYACUCHO', $r['origen']);
        $this->assertSame('CARGA 22', $r['destino']);
        $this->assertNull($r['destino_resuelto']);
        $this->assertSame('destino', $r['falla']);
        $this->assertNull($r['ruta_id']);
        $this->assertFalse($r['ya_resolveria']);
    }

    public function test_los_parentesis_se_convierten_en_espacio(): void
    {
        // El SP cambio: ahora REPLACE(..., '(', ' ') en vez de borrarlos, asi que
        // PUCALLPA(CORONADO) queda "PUCALLPA CORONADO" y SI matchea el local.
        $r = $this->analizador()->analizar('ENVIO DE PT PLANTA PUCALLPA(CORONADO) - CEDI TINGO MARIA');

        $this->assertSame('DE PT PLANTA PUCALLPA CORONADO', $r['origen']);
        $this->assertNotNull($r['origen_resuelto']);
        $this->assertSame('LOC_PUCALLPA', $r['origen_resuelto']['id']);
    }

    public function test_multiparada_se_rechaza_antes_de_partir_el_titulo(): void
    {
        $r = $this->analizador()->analizar('MULTIPARADA CARGA HUACHIPA NUEVOS NEGOCIOS / CALLAO/LIMA NORTE');

        $this->assertSame('multiparada', $r['motivo']);
        $this->assertNull($r['ruta_id']);
        $this->assertSame('', $r['origen']);
    }

    public function test_titulo_vacio(): void
    {
        $this->assertSame('sin_titulo', $this->analizador()->analizar(null)['motivo']);
        $this->assertSame('sin_titulo', $this->analizador()->analizar('   ')['motivo']);
    }

    public function test_si_no_hay_local_usa_el_ubigeo_del_distrito(): void
    {
        $r = $this->analizador()->analizar('ENVIO PT PLANTA CARAL - ICA');

        $this->assertSame('distrito', $r['destino_resuelto']['tipo']);
        $this->assertSame('110101', $r['destino_resuelto']['id']);
        $this->assertSame('RUT_CARAL-110101', $r['ruta_id']);
    }

    // [ReviewFocus 2] MySQL compara con collation _ai_ci: INSTR encuentra "multiparada"
    public function test_multiparada_en_minusculas_tambien_se_rechaza(): void
    {
        $this->assertSame('multiparada', $this->analizador()->analizar('multiparada carga huachipa')['motivo']);
    }

    // [ReviewFocus 3] el corte es solo en el primer "/"
    public function test_corta_solo_en_el_primer_slash(): void
    {
        $r = $this->analizador()->analizar('CARGA CARAL - BABEL MAXO / CALLAO/LIMA NORTE 18.04.2026');

        $this->assertSame('BABEL MAXO', $r['destino']);
    }

    // [ReviewFocus 1] una palabra_clave con regex invalido no puede lanzar ni ensuciar la salida
    public function test_palabra_clave_que_es_regex_invalido_no_revienta(): void
    {
        $a = new AnalizadorTitulos([['id' => 'LOC_MALA', 'palabra_clave' => 'CORP. (CHRISTIAN']], []);
        $r = $a->analizar('ENVIO PT CARAL - ALGO');

        $this->assertNull($r['destino_resuelto']);   // no matchea, no lanza
    }
}
