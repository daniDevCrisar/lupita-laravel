<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Aciertos;
use Tests\TestCase;

class AciertosTest extends TestCase
{
    public function test_resumen_con_errores_conocidos(): void
    {
        $r = Aciertos::resumir([-30, 0, 60, 180, 600], [true, true, true, false, false]);

        $this->assertSame(5, $r->n);
        $this->assertSame(174, $r->mae_min);   // (30+0+60+180+600)/5
        $this->assertSame(60, $r->mediana_error_min);
        $this->assertSame(60, $r->pct_60);     // 30, 0 y 60 estan dentro de +/-60
        $this->assertSame(80, $r->pct_180);    // y 180 tambien
        $this->assertSame(60, $r->pct_ventana);
    }

    public function test_resumen_vacio_no_inventa_metricas(): void
    {
        $r = Aciertos::resumir([], []);

        $this->assertSame(0, $r->n);
        $this->assertNull($r->mae_min);
        $this->assertNull($r->pct_60);
    }
}
