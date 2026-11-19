<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El traslado de regreso se calcula IGUAL que el tramo que le falta al camión.
 *
 * Antes el traslado miraba sólo el promedio diario de la ruta: no abría el
 * compilado POR DÍA Y HORA, así que un regreso a las 20 h de un viernes se
 * estimaba con la mediana del día. Los dos procesos son el mismo problema (cuánto
 * tarda el camión en un tramo), así que ahora comparten cadena:
 *
 *   1. lo medido de la ruta, 2. mezclado con la placa, 3. mezclado con día y hora.
 *
 * La incertidumbre del modelo no está en el tránsito (ida y vuelta son casi
 * iguales) sino en lo que el conductor descansa, come o duerme: eso se suma aparte
 * como tiempo muerto.
 */
class TrasladoConHorarioTest extends TestCase
{
    /** Una ruta del catálogo que además tenga tiempo medido (escalón 1 del traslado). */
    private function rutaCualquiera(): ?object
    {
        return DB::selectOne(
            "SELECT r.id, r.origen_id, r.destino_id
             FROM rutas r
             JOIN tiempos_ruta_promedio p ON p.ruta_id = r.id
             WHERE r.origen_id IS NOT NULL AND r.origen_id <> ''
               AND r.destino_id IS NOT NULL AND r.destino_id <> ''
               AND (p.prom_fusion_min > 0 OR p.prom_min > 0 OR p.mediana_min > 0)
             LIMIT 1"
        );
    }

    public function test_el_traslado_usa_el_mismo_resolvedor_que_el_viaje(): void
    {
        $ruta = $this->rutaCualquiera();
        if ($ruta === null) {
            $this->markTestSkipped('el catálogo de rutas está vacío');
        }

        $pedidas = [];
        $estimado = (object) [
            'min' => 300, 'base' => 350, 'tras_placa' => 330,
            'placa' => 320, 'placa_n' => 3,
            'celda' => 280, 'celda_viajes' => 12, 'celda_dia' => 4, 'celda_hora' => 19,
        ];

        $traslado = DBPrediccion::traslado(
            (string) $ruta->origen_id, (string) $ruta->destino_id, null, null, [], null, 240, false,
            Carbon::parse('2026-11-20 19:00'),
            function (string $pedida) use (&$pedidas, $estimado): object {
                $pedidas[] = $pedida;

                return $estimado;
            });

        $this->assertNotNull($traslado);
        $this->assertSame('ruta_medida', $traslado->fuente);
        $this->assertSame(300, (int) $traslado->min, 'el minuto sale del resolvedor, no de la base');
        $this->assertSame($estimado, $traslado->viaje, 'y las piezas viajan en la respuesta');
        $this->assertContains((string) $ruta->id, $pedidas, 'el resolvedor recibe la ruta, no los minutos');
    }

    public function test_sin_resolvedor_el_traslado_sigue_usando_lo_medido_de_la_ruta(): void
    {
        $ruta = $this->rutaCualquiera();
        if ($ruta === null) {
            $this->markTestSkipped('el catálogo de rutas está vacío');
        }

        if (DBPrediccion::viaje_ruta_base((string) $ruta->id, []) === null) {
            $this->markTestSkipped('esa ruta no tiene tiempo medido');
        }

        $traslado = DBPrediccion::traslado((string) $ruta->origen_id, (string) $ruta->destino_id,
            null, null, [], null, 240);

        $this->assertNotNull($traslado);
        $this->assertSame('ruta_medida', $traslado->fuente);
        $this->assertNull($traslado->viaje, 'sin resolvedor no hay mezcla que informar');
        $this->assertGreaterThan(0, (int) $traslado->min);
    }

    public function test_viaje_ruta_mezcla_ruta_placa_y_dia_hora_con_el_peso_de_la_muestra(): void
    {
        $celda = DB::selectOne(
            "SELECT ruta_id, dia, hora, viajes, prom_min FROM tiempos_ruta_horario
             WHERE viajes >= 3 AND prom_min > 0
             ORDER BY viajes DESC LIMIT 1"
        );

        if ($celda === null) {
            $this->markTestSkipped('tiempos_ruta_horario está vacía');
        }

        $ruta = (string) $celda->ruta_id;
        $base = DBPrediccion::viaje_ruta_base($ruta, []);
        if ($base === null) {
            $this->markTestSkipped('la ruta de la celda no tiene promedio diario');
        }

        // la hora de salida que caiga en esa celda (día 0 = lunes, como la tabla)
        $salida = Carbon::parse('2026-11-16 00:00');
        $salida = $salida->addDays((((int) $celda->dia - ((int) $salida->format('N') - 1)) + 7) % 7)
            ->setHour((int) $celda->hora)->setMinute(0);

        $params = Parametros::cargar();
        $desde = Carbon::parse('2020-01-01');
        $hasta = Carbon::parse('2030-01-01');

        // una placa con viajes en esa ruta, si hay, para probar también esa mezcla
        $fila = DB::selectOne(
            "SELECT placa FROM referencias
             WHERE ruta_id = ? AND placa <> '' AND inicio_ruta IS NOT NULL AND qr_llegada_destino IS NOT NULL
             LIMIT 1", [$ruta]);
        $placa = (string) ($fila->placa ?? '');

        $estimado = Servicio::viaje_ruta($ruta, [], $salida, $placa, $desde, $hasta, $params);

        $this->assertNotNull($estimado);
        $this->assertSame($base, (int) $estimado->base, 'el escalón 1 es lo medido de la ruta');
        $this->assertSame((int) round((float) $celda->prom_min), (int) $estimado->celda);
        $this->assertSame((int) $celda->viajes, (int) $estimado->celda_viajes);
        $this->assertSame((int) $celda->dia, (int) $estimado->celda_dia);
        $this->assertSame((int) $celda->hora, (int) $estimado->celda_hora);

        // y la cuenta es la misma que hace el viaje: placa primero, día/hora después
        $propio = $placa !== ''
            ? DBPrediccion::viaje_placa($placa, $ruta, $desde, $hasta)
            : ['n' => 0, 'mediana' => null];

        $trasPlaca = Servicio::mezclar_viaje($base, $propio['mediana'], (int) $propio['n'],
            (int) ($params->cruce_pares_k ?? 3)) ?? $base;

        $this->assertSame($trasPlaca, (int) $estimado->tras_placa);
        $this->assertSame(
            Servicio::mezclar_viaje($trasPlaca, (int) round((float) $celda->prom_min),
                (int) $celda->viajes, (int) ($params->horario_viajes_k ?? 3)),
            (int) $estimado->min);

        // con el horario apagado la celda no entra (el interruptor sigue mandando)
        $sinHorario = Servicio::viaje_ruta($ruta, [], $salida, $placa, $desde, $hasta,
            (object) array_merge((array) $params, ['horario_activo' => 0]));

        $this->assertNull($sinHorario->celda);
        $this->assertSame($trasPlaca, (int) $sinHorario->min);

        // y sin fecha de salida no se inventa la franja
        $sinFecha = Servicio::viaje_ruta($ruta, [], null, $placa, $desde, $hasta, $params);
        $this->assertNull($sinFecha->celda);
    }

    public function test_sin_nada_medido_para_la_ruta_el_resolvedor_devuelve_null(): void
    {
        $this->assertNull(Servicio::viaje_ruta('RUT_QUE_NO_EXISTE_ZZ', [], Carbon::now(), 'ZZTEST'));
        $this->assertNull(Servicio::viaje_ruta('', [], Carbon::now(), 'ZZTEST'));
        $this->assertNull(DBPrediccion::viaje_ruta_base('RUT_QUE_NO_EXISTE_ZZ', []));
        $this->assertNull(DBPrediccion::viaje_ruta_base(null, []));
    }

    public function test_la_respuesta_trae_la_mezcla_del_traslado_igual_que_la_del_viaje(): void
    {
        $fila = DB::selectOne(
            "SELECT ref, presenta_para_carga FROM referencias
             WHERE presenta_para_carga >= '2026-10-01' AND presenta_para_carga < '2026-11-01'
               AND ruta_id IS NOT NULL AND ruta_id <> '' AND placa <> ''
             ORDER BY presenta_para_carga DESC LIMIT 1"
        );

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias en el rango de prueba');
        }

        $ahora = Carbon::parse((string) $fila->presenta_para_carga)->subDay()->toDateTimeString();

        $r = $this->getJson('/api/prediccion/presenta?ref=' . urlencode((string) $fila->ref)
            . '&ahora=' . urlencode($ahora));

        $r->assertOk();

        $historia = (array) $r->json('historia');
        $this->assertArrayHasKey('traslado_cruce', $historia);
        $this->assertArrayHasKey('traslado_horario', $historia);
        $this->assertContains($historia['traslado_fuente'], [
            'ruta_medida', 'mismo_ubigeo', 'ruta_de_la_ref', 'geoapify', 'departamentos', 'defecto',
            'ya_esta_en_planta', 'sin_dato',
        ], 'el traslado tiene que salir de un escalón conocido');

        // si el regreso salió de una ruta medida, tiene que explicar cómo se armó
        if (in_array($historia['traslado_fuente'], ['ruta_medida', 'mismo_ubigeo', 'ruta_de_la_ref'], true)) {
            $this->assertNotNull($historia['traslado_cruce'],
                'un traslado por ruta tiene que traer la mezcla con la placa');
        }

        if ($historia['traslado_horario'] !== null) {
            $this->assertArrayHasKey('horario', (array) $historia['traslado_horario']);
            $this->assertArrayHasKey('viajes', (array) $historia['traslado_horario']);
            // el paso del traslado explica la misma cadena
            $paso = collect($r->json('pasos'))->firstWhere('concepto', 'traslado');
            $this->assertStringContainsString('franja', (string) $paso['detalle']);
        }
    }
}
