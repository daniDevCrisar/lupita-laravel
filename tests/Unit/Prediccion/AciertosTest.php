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

    /**
     * La fila de la tabla de aciertos trae el error de las cuatro fechas.
     */
    private function fila(int $rapido, int $normal, int $tardado, ?int $probable = null): object
    {
        return (object) [
            'error_rapido_min' => $rapido,
            'error_min' => $normal,
            'error_tardado_min' => $tardado,
            'error_probable_min' => $probable,
            'fuera_tope' => false,
        ];
    }

    public function test_mas_cerca_gana_cualquiera_de_las_cuatro(): void
    {
        // se comparan las cuatro, no la normal contra la probable: antes la
        // rapida y la tardada no llegaban a la vista y siempre ganaba normal
        $this->assertSame('rapido', Aciertos::mas_cerca($this->fila(20, 400, 600, 380))->clave);
        $this->assertSame('normal', Aciertos::mas_cerca($this->fila(400, 30, 600, 300))->clave);
        $this->assertSame('tardado', Aciertos::mas_cerca($this->fila(900, 400, -40, 380))->clave);
        $this->assertSame('probable', Aciertos::mas_cerca($this->fila(900, 400, 600, 35))->clave);
    }

    public function test_mas_cerca_usa_el_valor_absoluto_y_desempata_por_orden(): void
    {
        // -25 esta mas cerca que -400 y que +300
        $r = Aciertos::mas_cerca($this->fila(-400, 300, -25, 120));
        $this->assertSame('tardado', $r->clave);
        $this->assertSame(25, $r->abs_min);

        // empate exacto: gana la que se publica primero y la otra queda anotada
        $r = Aciertos::mas_cerca($this->fila(600, 90, 700, 90));
        $this->assertSame('normal', $r->clave);
        $this->assertSame(['probable'], $r->empates);
    }

    public function test_mas_cerca_sin_errores_informados_no_devuelve_nada(): void
    {
        $this->assertNull(Aciertos::mas_cerca((object) ['error_min' => null]));
        $this->assertNull(Aciertos::mas_cerca((object) []));
    }

    public function test_mas_cerca_acepta_arreglos_igual_que_objetos(): void
    {
        $r = Aciertos::mas_cerca([
            'error_rapido_min' => 30,
            'error_min' => 40,
            'error_tardado_min' => null,
            'error_probable_min' => null,
        ]);

        $this->assertSame('rapido', $r->clave);
    }

    public function test_carrera_cuenta_las_cuatro_y_saltea_las_filas_fuera_del_tope(): void
    {
        $filas = [
            $this->fila(10, 500, 600, 400),   // rapido
            $this->fila(500, 20, 600, 400),   // normal
            $this->fila(500, 600, 30, 400),   // tardado
            $this->fila(500, 600, 700, 40),   // probable
            (object) ['error_rapido_min' => 10, 'fuera_tope' => true], // no compite
            (object) ['error_min' => null],   // sin dato
        ];

        $c = Aciertos::carrera($filas);

        $this->assertSame(4, $c->n);
        foreach (['rapido', 'normal', 'tardado', 'probable'] as $clave) {
            $this->assertSame(1, $c->{$clave}, 'no se contó ' . $clave);
            $this->assertSame(25, $c->{'pct_' . $clave});
        }
    }

    public function test_carrera_sin_filas_no_inventa_porcentajes(): void
    {
        $c = Aciertos::carrera([]);

        $this->assertSame(0, $c->n);
        $this->assertSame(0, $c->probable);
        $this->assertNull($c->pct_probable);
    }
}
