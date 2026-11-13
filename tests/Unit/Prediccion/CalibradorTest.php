<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Calibrador;
use Carbon\Carbon;
use Tests\TestCase;

class CalibradorTest extends TestCase
{
    private function pares(int $n, int $gapMin, string $rutaAncla = 'R1', string $rutaSig = 'R2',
                           string $horaAncla = '08:00', string $entidad = 'ABC123'): array
    {
        $pares = [];
        for ($i = 0; $i < $n; $i++) {
            $ancla = Carbon::parse('2026-11-0' . ($i + 2) . " $horaAncla");
            $pares[] = (object) [
                'ancla' => $ancla,
                'presenta' => $ancla->copy()->addMinutes($gapMin),
                'ruta_ancla' => $rutaAncla,
                'ruta_siguiente' => $rutaSig,
                'entidad' => $entidad,
            ];
        }

        return $pares;
    }

    public function test_par_de_rutas_con_tres_pares_es_medido(): void
    {
        $cal = Calibrador::calibrar($this->pares(5, 300));
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);

        $this->assertSame('medido', $est->nivel);
        $this->assertSame(300, $est->gap_min);
        $this->assertSame(300, $est->p25_min);
        $this->assertSame(5, $est->n);
    }

    public function test_menos_de_tres_pares_no_calibra(): void
    {
        $cal = Calibrador::calibrar($this->pares(2, 300));
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);

        $this->assertSame('cadena', $est->nivel);
        $this->assertNull($est->gap_min);
    }

    public function test_correccion_por_hora_del_ancla(): void
    {
        // misma placa: de ida vuelve en 4 h, de noche espera hasta la manana (+6 h)
        $pares = array_merge(
            $this->pares(4, 240, 'R1', 'R2', '08:00'),
            $this->pares(4, 600, 'R1', 'R2', '21:00')
        );
        $cal = Calibrador::calibrar($pares);

        $deDia = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);
        $deNoche = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 21);

        $this->assertGreaterThan($deDia->gap_min, $deNoche->gap_min);
        $this->assertSame(240, $deDia->gap_min);
        $this->assertSame(600, $deNoche->gap_min);
    }

    public function test_descarta_huecos_invertidos_o_mayores_a_siete_dias(): void
    {
        $pares = $this->pares(3, 300);
        $pares[] = (object) ['ancla' => Carbon::parse('2026-11-09 08:00'),
            'presenta' => Carbon::parse('2026-11-08 08:00'),
            'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];
        $pares[] = (object) ['ancla' => Carbon::parse('2026-11-10 08:00'),
            'presenta' => Carbon::parse('2026-11-30 08:00'),
            'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];

        $cal = Calibrador::calibrar($pares);

        $this->assertSame(3, $cal->par_rutas['R1>R2']['n']);
        $this->assertSame(300, Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8)->gap_min);
    }

    public function test_entidad_sin_par_de_rutas_calibra_parcial(): void
    {
        $cal = Calibrador::calibrar($this->pares(4, 300, 'R1', 'R2'));
        $est = Calibrador::estimar($cal, 'R7', 'R9', 'ABC123', 8);

        $this->assertSame('parcial', $est->nivel);
        $this->assertSame(300, $est->gap_min);
    }

    public function test_calibra_con_los_pares_mas_recientes(): void
    {
        // 10 pares viejos de 1000 min y los 3 mas nuevos de 300 min: sin recencia
        // la mediana seria 1000 y el motor sobreestimaria la vuelta
        $viejos = [];
        for ($i = 0; $i < 10; $i++) {
            $ancla = Carbon::parse(sprintf('2026-07-%02d 08:00', $i + 1));
            $viejos[] = (object) ['ancla' => $ancla, 'presenta' => $ancla->copy()->addMinutes(1000),
                'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];
        }
        $nuevos = [];
        for ($i = 0; $i < 3; $i++) {
            $ancla = Carbon::parse(sprintf('2026-10-%02d 08:00', 21 + $i));
            $nuevos[] = (object) ['ancla' => $ancla, 'presenta' => $ancla->copy()->addMinutes(300),
                'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];
        }
        $pares = array_merge($viejos, $nuevos);

        $sinRecencia = Calibrador::calibrar($pares);
        $conRecencia = Calibrador::calibrar($pares, 3, 3, 8, 5);

        $this->assertSame(1000, Calibrador::estimar($sinRecencia, 'R1', 'R2', 'ABC123', 8)->gap_min);
        $this->assertSame(300, Calibrador::estimar($conRecencia, 'R1', 'R2', 'ABC123', 8)->gap_min);
    }

    public function test_una_franja_con_un_solo_par_no_corrige_la_hora(): void
    {
        // 6 pares de 300 min a las 03:00 y UNO de 54 h a las 19:00: sin la guarda,
        // ese par suelto se tomaba como "tendencia de la tarde" y la predicción se
        // iba 48 h al futuro (caso real AHV874: 1 par de 54 h en la franja 18-21)
        $pares = array_merge(
            $this->pares(6, 300, 'R1', 'R2', '03:00'),
            [(object) [
                'ancla' => Carbon::parse('2026-10-10 19:00'),
                'presenta' => Carbon::parse('2026-10-12 01:00'),
                'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123',
            ]]
        );

        $cal = Calibrador::calibrar($pares);
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 19);

        $this->assertSame(0, $est->residual_min, 'una franja con un solo par no es una tendencia horaria');
        $this->assertSame(300, $est->gap_min);
    }

    public function test_una_franja_con_muestra_suficiente_si_corrige(): void
    {
        $pares = array_merge(
            $this->pares(4, 300, 'R1', 'R2', '03:00'),
            $this->pares(4, 900, 'R1', 'R2', '19:00')
        );

        $cal = Calibrador::calibrar($pares);

        $this->assertSame(300, Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 3)->gap_min);
        $this->assertSame(900, Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 19)->gap_min);
    }

    public function test_calibracion_a_partir_de_un_resumen_ya_calculado(): void
    {
        // es el camino del régimen "cerca": un resumen medido en otra poblacion
        $cal = Calibrador::de_resumen(['n' => 9, 'base_min' => 138, 'p25_min' => 12, 'p75_min' => 919]);
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'regimen', 19);

        $this->assertSame(138, $est->gap_min);
        $this->assertSame(12, $est->p25_min);
        $this->assertSame(919, $est->p75_min);
        $this->assertSame(9, $est->n);
        $this->assertSame('parcial', $est->nivel);
        $this->assertSame(0, $est->residual_min);
    }
}
