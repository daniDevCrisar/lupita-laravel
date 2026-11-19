<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\ExtractorCandidatos;
use Tests\TestCase;

/**
 * De un fragmento de titulo a frases candidatas a `palabra_clave`.
 */
class ExtractorCandidatosTest extends TestCase
{
    private function extractor(array $config = []): ExtractorCandidatos
    {
        return new ExtractorCandidatos($config + [
            'palabras_ignoradas' => ['DE', 'PT', 'PLANTA', 'CARGA'],
            'minimo_caracteres'  => 3,
            'maximo_ngrama'      => 3,
            'maximo_candidato'   => 60,
        ]);
    }

    public function test_saca_palabras_y_ngramas(): void
    {
        $frases = $this->extractor()->desdeFragmento('DE PT MAQUILA AGUA');

        $this->assertContains('MAQUILA', $frases);
        $this->assertContains('AGUA', $frases);
        $this->assertContains('MAQUILA AGUA', $frases);
        $this->assertNotContains('DE', $frases);
        $this->assertNotContains('PT', $frases);
        $this->assertNotContains('PLANTA', $frases);
    }

    public function test_no_repite_frases(): void
    {
        $frases = $this->extractor()->desdeFragmento('MAQUILA AGUA MAQUILA');

        $this->assertSame(count($frases), count(array_unique($frases)));
    }

    // [ReviewFocus 4] una frase demasiado larga no puede llegar a `palabra_clave`
    public function test_no_devuelve_frases_de_mas_de_maximo_candidato(): void
    {
        $e = $this->extractor(['maximo_candidato' => 20, 'palabras_ignoradas' => []]);

        foreach ($e->desdeFragmento('MAKRO ATEUT CON GPS REQUIERE AUXILIAR') as $frase) {
            $this->assertLessThanOrEqual(20, mb_strlen($frase), 'frase demasiado larga: ' . $frase);
        }
    }

    // [ReviewFocus 5] descartar el mismo candidato con otra tilde o doble espacio tiene que coincidir
    public function test_normalizar_y_clave_ignoran_tildes_mayusculas_y_espacios(): void
    {
        $this->assertSame(
            ExtractorCandidatos::clave('RECOJO  ACEITE'),
            ExtractorCandidatos::clave('recojo aceite')
        );
        $this->assertSame(
            ExtractorCandidatos::clave('CAÑETE'),
            ExtractorCandidatos::clave('canete')
        );
        $this->assertSame('RECOJO ACEITE', ExtractorCandidatos::normalizar('  recojo   aceite '));
    }

    public function test_ignora_numeros_y_palabras_cortas(): void
    {
        $frases = $this->extractor()->desdeFragmento('UT 30 TN CARGA 07 MAKRO');

        $this->assertSame(['MAKRO'], $frases);
    }
}
