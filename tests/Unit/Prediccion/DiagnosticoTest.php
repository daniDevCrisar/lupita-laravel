<?php

namespace Tests\Unit\Prediccion;

use App\Database\DBPrediccion;
use Tests\TestCase;

class DiagnosticoTest extends TestCase
{
    public function test_resumen_del_diagnostico_arma_texto_con_los_numeros(): void
    {
        $texto = DBPrediccion::texto_diagnostico((object) [
            'refs' => 1000, 'con_ruta' => 940, 'pares' => 320,
            'hueco_p25' => 180, 'hueco_mediana' => 300, 'hueco_p75' => 720,
            'rutas_con_etapas' => 55, 'plantas_compiladas' => 12,
        ], 120);

        $this->assertStringContainsString('940/1000', $texto);
        $this->assertStringContainsString('320', $texto);
        $this->assertStringContainsString('300', $texto);
        $this->assertStringContainsString('120', $texto);
    }
}
