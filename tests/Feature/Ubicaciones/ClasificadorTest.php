<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\AnalizadorTitulos;
use App\Ubicaciones\Clasificador;
use Tests\TestCase;

/**
 * Veredicto de una frase candidata contra el catalogo: dice si hay que crear un
 * local, si el local ya existe y el titulo se corto, si es un typo, o si es una
 * palabra que no hay que registrar.
 */
class ClasificadorTest extends TestCase
{
    private function clasificador(): Clasificador
    {
        $analizador = new AnalizadorTitulos(
            [
                ['id' => 'LOC_AJEPER_GRUPO_PACIFIC_OIL', 'palabra_clave' => 'GRUPO PACIFIC OIL|(ACEITE)'],
                ['id' => 'LOC_HUACHIPA', 'palabra_clave' => 'HUACHIPA'],
                ['id' => 'LOC_MAKRO_CAÑETE', 'palabra_clave' => 'MAKRO CA¥ETE'],
                ['id' => 'LOC_MAKRO_PIURA', 'palabra_clave' => 'MAKRO PIURA'],
                ['id' => 'LOC_PUCALLPA', 'palabra_clave' => 'PUCALLPA'],
            ],
            []
        );

        return new Clasificador($analizador, [
            'palabras_ignoradas'  => [],
            'parecido_porcentaje' => 85,
            'parecido_distancia'  => 2,
        ]);
    }

    public function test_frase_nueva(): void
    {
        $r = $this->clasificador()->clasificar('MAQUILA AGUA', ['clientes' => ['CLI_CODISAL'], 'refs' => 92]);

        $this->assertSame('nuevo', $r['veredicto']);
        $this->assertNull($r['local_id']);
    }

    public function test_marca_cubierto_cuando_el_local_existe(): void
    {
        $r = $this->clasificador()->clasificar('PUCALLPA', ['clientes' => ['CLI_PRADERAS'], 'refs' => 78]);

        $this->assertSame('cubierto_parseo', $r['veredicto']);
        $this->assertSame('LOC_PUCALLPA', $r['local_id']);
    }

    public function test_marca_typo(): void
    {
        $r = $this->clasificador()->clasificar('HUCHIPA', ['clientes' => ['CLI_AJEPER'], 'refs' => 9]);

        $this->assertSame('typo', $r['veredicto']);
        $this->assertSame('LOC_HUACHIPA', $r['local_id']);
    }

    public function test_marca_familia_cuando_varios_locales_empiezan_igual(): void
    {
        // el catalogo tiene varias tiendas MAKRO: no es "un" local que falte
        $r = $this->clasificador()->clasificar('MAKRO', ['clientes' => ['CLI_BABEL'], 'refs' => 103]);

        $this->assertSame('familia', $r['veredicto']);
    }

    public function test_marca_generica_cuando_son_varios_clientes(): void
    {
        $r = $this->clasificador()->clasificar('INSUMOS', ['clientes' => ['CLI_AJEPER', 'CLI_CODISAL'], 'refs' => 30]);

        $this->assertSame('generica', $r['veredicto']);
    }

    public function test_aceite_es_generica_por_venir_de_un_parentesis(): void
    {
        // LOC_AJEPER_GRUPO_PACIFIC_OIL declara "GRUPO PACIFIC OIL|(ACEITE)":
        // ACEITE es un producto, no un cliente, y captura refs ajenas
        $r = $this->clasificador()->clasificar('ACEITE', ['clientes' => ['CLI_AJEPER'], 'refs' => 30]);

        $this->assertSame('generica', $r['veredicto']);
        $this->assertStringContainsString('paréntesis', $r['detalle']);
    }

    public function test_marca_basura(): void
    {
        $r = $this->clasificador()->clasificar('----', ['clientes' => [], 'refs' => 15]);

        $this->assertSame('basura', $r['veredicto']);
    }
}
