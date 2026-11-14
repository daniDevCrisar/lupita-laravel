<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Prediccion\Estadistica;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Etapas E2..E6 por ruta:
 *  - el VIAJE (e4) sale del compilado del módulo de tiempos (`tiempos_ruta_promedio`,
 *    lo que sirve /api/tiempos/promedio) cuando existe: es el número que ve el
 *    usuario en esa pantalla, con topes y descarte de viajes raros;
 *  - el resto de las etapas son MEDIANAS de `referencias` (no promedios, que los
 *    inflan las colas de 20 h y los viajes raros).
 */
class EtapasTest extends TestCase
{
    public function test_el_viaje_sale_del_compilado_de_tiempos(): void
    {
        $hasta = Carbon::now();
        $etapas = DBPrediccion::etapas_por_ruta($hasta->copy()->subDays(120), $hasta);
        $compilados = DBPrediccion::promedios_ruta();

        $ruta = null;
        foreach ($etapas as $id => $fila) {
            $compilado = $compilados[(string) $id] ?? null;
            if ($compilado === null || (int) ($compilado->viajes ?? 0) < 3) {
                continue;
            }
            if (($fila['e4'] ?? null) === null) {
                continue;
            }
            $ruta = (string) $id;
            break;
        }

        if ($ruta === null) {
            $this->markTestSkipped('no hay rutas con compilado y con refs');
        }

        $compilado = $compilados[$ruta];
        $esperado = $compilado->mediana_min ?? $compilado->prom_min;

        $this->assertSame(
            (int) round((float) $esperado),
            $etapas[$ruta]['e4'],
            'e4 tiene que ser el compilado del módulo de tiempos (mediana, si no el promedio)'
        );
    }

    public function test_las_otras_etapas_son_medianas_de_las_refs(): void
    {
        $hasta = Carbon::now();
        $desde = $hasta->copy()->subDays(120);

        $etapas = DBPrediccion::etapas_por_ruta($desde, $hasta);

        $ruta = null;
        foreach ($etapas as $id => $fila) {
            if ((int) ($fila['n'] ?? 0) >= 40 && ($fila['e2'] ?? null) !== null) {
                $ruta = (string) $id;
                break;
            }
        }

        if ($ruta === null) {
            $this->markTestSkipped('no hay una ruta con muestra suficiente');
        }

        // e2 (presenta -> inicio de carga) no la pisa ninguna API compilada:
        // tiene que ser la MEDIANA de las refs, no el promedio
        $valores = [];
        foreach (DB::select("
            SELECT TIMESTAMPDIFF(MINUTE, presenta_para_carga, inicio_de_carga) AS e2
            FROM referencias
            WHERE ruta_id = ? AND presenta_para_carga BETWEEN ? AND ?
              AND inicio_de_carga IS NOT NULL
        ", [$ruta, $desde->toDateTimeString(), $hasta->toDateTimeString()]) as $fila) {
            $valor = (int) $fila->e2;
            if ($valor >= 0 && $valor <= DBPrediccion::TOPE_ETAPA) {
                $valores[] = $valor;
            }
        }

        $this->assertNotSame([], $valores);
        $this->assertSame(
            (int) round((float) Estadistica::mediana($valores)),
            $etapas[$ruta]['e2'],
            'e2 tiene que ser la mediana de las refs: el promedio lo inflan los casos raros'
        );
    }
}
