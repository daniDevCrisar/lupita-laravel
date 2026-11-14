<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Prediccion\Estadistica;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cruce de las APIs compiladas con los tiempos de la placa/chofer:
 *  - viaje: compilado de la ruta mezclado con lo que esa placa tarda en ella;
 *  - cola y descargue: compilado por PLANTA cuando la ruta termina en una planta.
 */
class CruceFuentesTest extends TestCase
{
    public function test_el_viaje_de_una_placa_en_una_ruta_es_la_mediana_de_sus_viajes(): void
    {
        $hasta = Carbon::now();
        $desde = $hasta->copy()->subDays(120);

        $fila = DB::selectOne("
            SELECT placa, ruta_id, COUNT(*) AS n
            FROM referencias
            WHERE placa <> '' AND ruta_id IS NOT NULL AND ruta_id <> ''
              AND presenta_para_carga BETWEEN ? AND ?
              AND inicio_ruta IS NOT NULL AND qr_llegada_destino IS NOT NULL
            GROUP BY placa, ruta_id
            HAVING n >= 2
            ORDER BY n DESC
            LIMIT 1
        ", [$desde->toDateTimeString(), $hasta->toDateTimeString()]);

        if ($fila === null) {
            $this->markTestSkipped('no hay placa+ruta con dos viajes medidos');
        }

        $valores = [];
        foreach (DB::select("
            SELECT TIMESTAMPDIFF(MINUTE, inicio_ruta, qr_llegada_destino) AS e4
            FROM referencias
            WHERE placa = ? AND ruta_id = ? AND presenta_para_carga BETWEEN ? AND ?
              AND inicio_ruta IS NOT NULL AND qr_llegada_destino IS NOT NULL
        ", [$fila->placa, $fila->ruta_id, $desde->toDateTimeString(), $hasta->toDateTimeString()]) as $viaje) {
            $valor = (int) $viaje->e4;
            if ($valor >= 0 && $valor <= DBPrediccion::TOPE_ETAPA) {
                $valores[] = $valor;
            }
        }

        $viaje = DBPrediccion::viaje_placa((string) $fila->placa, (string) $fila->ruta_id, $desde, $hasta);

        $this->assertSame(count($valores), $viaje['n']);
        $this->assertSame((int) round((float) Estadistica::mediana($valores)), $viaje['mediana']);
    }

    public function test_la_cola_y_el_descargue_salen_del_compilado_por_planta(): void
    {
        $hasta = Carbon::now();
        $etapas = DBPrediccion::etapas_por_ruta($hasta->copy()->subDays(120), $hasta);
        $plantas = DBPrediccion::promedios_planta();
        $rutas = DBPrediccion::rutas();

        $ruta = null;
        $planta = null;
        foreach ($rutas as $id => $filaRuta) {
            $destino = (string) ($filaRuta->destino_id ?? '');
            $p = $destino !== '' ? ($plantas[$destino] ?? null) : null;
            if ($p === null || $p->prom_fuera_descarga_min === null || $p->prom_dentro_descarga_min === null) {
                continue;
            }
            if (!isset($etapas[(string) $id])) {
                continue;
            }
            $ruta = (string) $id;
            $planta = $p;
            break;
        }

        if ($ruta === null) {
            $this->markTestSkipped('no hay rutas que terminen en una planta con descarga compilada');
        }

        $this->assertSame((int) round((float) $planta->prom_fuera_descarga_min), $etapas[$ruta]['e5']);
        $this->assertSame((int) round((float) $planta->prom_dentro_descarga_min), $etapas[$ruta]['e6']);
    }

    public function test_el_cruce_del_viaje_premia_a_la_placa_con_muestra(): void
    {
        // compilado 1.185 (19 h 45) y una placa que hace 2.106 en esa ruta: con
        // 5 viajes propios la mezcla queda en 1.761, no en el promedio de la flota
        $this->assertSame(1761, Servicio::mezclar_viaje(1185, 2106, 5, 3));
    }
}
