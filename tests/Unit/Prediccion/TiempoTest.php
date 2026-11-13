<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Tiempo;
use Tests\TestCase;

class TiempoTest extends TestCase
{
    public function test_hhmm_no_corta_en_dias(): void
    {
        $this->assertSame('0:00', Tiempo::hhmm(0));
        $this->assertSame('0:07', Tiempo::hhmm(7));
        $this->assertSame('1:49', Tiempo::hhmm(109));
        $this->assertSame('9:07', Tiempo::hhmm(547));
        $this->assertSame('96:27', Tiempo::hhmm(5787));
        $this->assertSame('—', Tiempo::hhmm(null));
        $this->assertSame('-1:30', Tiempo::hhmm(-90));
    }

    public function test_dias_para_textos_largos(): void
    {
        $this->assertSame('0d 09:07', Tiempo::dias(547));
        $this->assertSame('4d 00:27', Tiempo::dias(5787));
    }

    public function test_nombre_legible_del_hito(): void
    {
        $this->assertSame('fin de descargue', Tiempo::hito('fin_descargue'));
        $this->assertSame('ubicación declarada', Tiempo::hito('ubicacion_declarada'));
        $this->assertSame('sin hito', Tiempo::hito(null));
        $this->assertSame('otro_hito', Tiempo::hito('otro_hito'));
    }
}
