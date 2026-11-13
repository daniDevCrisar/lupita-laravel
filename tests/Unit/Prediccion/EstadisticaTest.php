<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Estadistica;
use Tests\TestCase;

class EstadisticaTest extends TestCase
{
    public function test_mediana_impar_y_par(): void
    {
        $this->assertSame(30.0, Estadistica::mediana([40, 10, 30]));
        $this->assertSame(25.0, Estadistica::mediana([10, 20, 30, 40]));
        $this->assertNull(Estadistica::mediana([]));
    }

    public function test_cuantil_interpola_lineal(): void
    {
        $datos = [10, 20, 30, 40, 50];

        $this->assertSame(20.0, Estadistica::cuantil($datos, 0.25));
        $this->assertSame(40.0, Estadistica::cuantil($datos, 0.75));
        $this->assertSame(10.0, Estadistica::cuantil($datos, 0.0));
        $this->assertSame(50.0, Estadistica::cuantil($datos, 1.0));
    }

    public function test_iqr_y_desviacion(): void
    {
        $datos = [10, 20, 30, 40, 50];

        $this->assertSame(20.0, Estadistica::iqr($datos));
        $this->assertEqualsWithDelta(14.14, Estadistica::desviacion($datos), 0.01);
    }

    public function test_bin_horario_en_ocho_franjas_de_tres_horas(): void
    {
        $this->assertSame(0, Estadistica::bin_horario(0));
        $this->assertSame(0, Estadistica::bin_horario(2));
        $this->assertSame(1, Estadistica::bin_horario(3));
        $this->assertSame(7, Estadistica::bin_horario(23));
    }
}
