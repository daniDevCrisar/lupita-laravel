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
}
