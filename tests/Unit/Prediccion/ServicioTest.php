<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Servicio;
use Tests\TestCase;

class ServicioTest extends TestCase
{
    public function test_clave_calibracion_respeta_el_50_por_ciento(): void
    {
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 80.0));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 50.0));
        $this->assertSame(['conductor', '7'], Servicio::clave_calibracion('ABC123', 7, 49.9));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', null, null));
        $this->assertSame(['placa', ''], Servicio::clave_calibracion(null, 7, 10.0));
    }
}
