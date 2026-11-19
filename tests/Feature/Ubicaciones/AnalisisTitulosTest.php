<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\AnalisisTitulos;
use App\Ubicaciones\AnalizadorTitulos;
use App\Ubicaciones\Clasificador;
use App\Ubicaciones\ExtractorCandidatos;
use Tests\TestCase;

/**
 * Orquestacion: refs sin ruta -> titulos agrupados -> fragmentos sin resolver ->
 * candidatos clasificados.
 */
class AnalisisTitulosTest extends TestCase
{
    private function refs(): array
    {
        return [
            ['ref' => 'A1', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 01.01'],
            ['ref' => 'A2', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL CUSCO / CARGA 02.01'],
            ['ref' => 'A3', 'titulo' => 'ENVIO DE PT PLANTA PUCALLPA(CORONADO) - CEDI TINGO MARIA'],
            ['ref' => 'A4', 'titulo' => 'ENVIO PT AJEPER CARAL - BABEL MAXO'],
            ['ref' => 'A5', 'titulo' => ''],
        ];
    }

    private function analisis(): AnalisisTitulos
    {
        $analizador = new AnalizadorTitulos(
            [
                ['id' => 'LOC_CARAL', 'palabra_clave' => 'CARAL', 'cliente_id' => 'CLI_AJEPER'],
                ['id' => 'LOC_BABEL_MAXO', 'palabra_clave' => 'MAXO', 'cliente_id' => 'CLI_BABEL'],
                ['id' => 'LOC_CODISAL_JULIACA', 'palabra_clave' => 'CODISAL JULIACA', 'cliente_id' => 'CLI_CODISAL'],
                ['id' => 'LOC_CODISAL_CUSCO', 'palabra_clave' => 'CODISAL CUSCO', 'cliente_id' => 'CLI_CODISAL'],
                ['id' => 'LOC_PUCALLPA', 'palabra_clave' => 'PUCALLPA', 'cliente_id' => 'CLI_AJEPER'],
                ['id' => 'LOC_PRADERAS_TINGO_MARIA', 'palabra_clave' => 'PRADERAS TINGO MARIA', 'cliente_id' => 'CLI_PRADERAS'],
            ],
            []
        );

        $config = [
            'palabras_ignoradas'  => ['DE', 'PT', 'PLANTA', 'CARGA', 'CEDI'],
            'minimo_caracteres'   => 3,
            'maximo_ngrama'       => 3,
            'maximo_candidato'    => 60,
            'maximo_fragmento'    => 120,
            'parecido_porcentaje' => 85,
            'parecido_distancia'  => 2,
        ];

        return new AnalisisTitulos(
            $analizador,
            new ExtractorCandidatos($config),
            new Clasificador($analizador, $config)
        );
    }

    public function test_cuenta_cada_grupo(): void
    {
        $r = $this->analisis()->calcular($this->refs());

        $this->assertSame(5, $r['refs']);
        $this->assertSame(5, $r['titulos']);        // incluye el titulo vacio
        $this->assertSame(1, $r['ya_resolverian']); // A4
    }

    public function test_maquila_agua_es_el_candidato_mas_fuerte_y_es_nuevo(): void
    {
        $r = $this->analisis()->calcular($this->refs());
        $primero = $r['candidatos'][0];

        $this->assertSame('MAQUILA AGUA', $primero['frase']);
        $this->assertSame(2, $primero['refs']);
        $this->assertSame('nuevo', $primero['veredicto']);
        $this->assertSame('A1', $primero['ejemplo_ref']);
    }

    public function test_las_frases_especificas_van_antes_que_las_palabras_sueltas(): void
    {
        $r = $this->analisis()->calcular($this->refs());
        $frases = collect($r['candidatos'])->pluck('frase')->take(3)->all();

        // 2-grama primero, despues las palabras de 1
        $this->assertSame('MAQUILA AGUA', $frases[0]);
        $this->assertContains('AGUA', $frases);
        $this->assertContains('MAQUILA', $frases);
    }

    public function test_pucallpa_sale_como_cubierto_por_parseo(): void
    {
        $r = $this->analisis()->calcular($this->refs());
        $fila = collect($r['candidatos'])->firstWhere('frase', 'PUCALLPA');

        $this->assertNotNull($fila, 'PUCALLPA esta pegado dentro de PUCALLPACORONADO y debe aparecer');
        $this->assertSame('cubierto_parseo', $fila['veredicto']);
        $this->assertSame('LOC_PUCALLPA', $fila['local_id']);
    }

    public function test_los_fragmentos_no_llevan_numeros_ni_duplicados(): void
    {
        $r = $this->analisis()->calcular($this->refs());
        $fragmentos = collect($r['detalle_fragmentos'])->pluck('fragmento')->all();

        $this->assertContains('MAQUILA AGUA', $fragmentos);
        $this->assertSame(count($fragmentos), count(array_unique($fragmentos)));
        // MAQUILA AGUA + DE PT PLANTA PUCALLPACORONADO + CEDI TINGO MARIA
        // (el destino de A3 tampoco resuelve: la clave es "PRADERAS TINGO MARIA")
        $this->assertSame(3, $r['fragmentos']);
    }

    public function test_un_titulo_repetido_cuenta_sus_refs_pero_es_un_solo_fragmento(): void
    {
        $refs = [
            ['ref' => 'B1', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 01.01'],
            ['ref' => 'B2', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 02.01'],
            ['ref' => 'B3', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 03.01'],
        ];

        $r = $this->analisis()->calcular($refs);

        $this->assertSame(3, $r['refs']);
        $this->assertSame(1, $r['titulos']);
        $this->assertSame(1, $r['fragmentos']);
        $this->assertSame(3, collect($r['candidatos'])->firstWhere('frase', 'MAQUILA AGUA')['refs']);
    }
}
