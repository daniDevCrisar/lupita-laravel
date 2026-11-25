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

    /** Parámetros del motor con el factor de camión vacío que pida el test. */
    private function parametros(float $vacioFactor = 1.0): object
    {
        return (object) array_merge((array) Parametros::cargar(), [
            'vacio_factor' => $vacioFactor,
            'coords_activo' => 0,      // los tests de ruta no dependen de las coordenadas
        ]);
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
            },
            $this->parametros());

        $this->assertNotNull($traslado);
        $this->assertSame('ruta_medida', $traslado->fuente);
        $this->assertSame(300, (int) $traslado->min, 'el minuto sale del resolvedor, no de la base');
        $this->assertSame($estimado, $traslado->viaje, 'y las piezas viajan en la respuesta');
        $this->assertContains((string) $ruta->id, $pedidas, 'el resolvedor recibe la ruta, no los minutos');
    }

    /**
     * El traslado es SIEMPRE la vuelta sin carga. Si la única ruta medida del par
     * es la de ida (sale de la planta), su promedio sobreestima el regreso y se
     * corrige con `vacio_factor`; si la ruta ya es el sentido del regreso, no se toca.
     */
    public function test_el_factor_de_camion_vacio_corrige_solo_el_sentido_cargado(): void
    {
        $ruta = $this->rutaCualquiera();
        if ($ruta === null) {
            $this->markTestSkipped('el catálogo de rutas está vacío');
        }

        $estimado = (object) [
            'min' => 300, 'base' => 350, 'tras_placa' => 300,
            'placa' => null, 'placa_n' => 0,
            'celda' => null, 'celda_viajes' => 0, 'celda_dia' => null, 'celda_hora' => null,
        ];
        $resolver = fn (string $pedida): object => $estimado;

        $origen = (string) $ruta->origen_id;
        $destino = (string) $ruta->destino_id;

        // la planta es el ORIGEN de la ruta medida: el regreso va al revés, sin carga
        $cargado = DBPrediccion::traslado($origen, $destino, null, null, [], null, 240, false, null,
            $resolver, $this->parametros(0.83));

        $this->assertSame('cargado', $cargado->sentido);
        $this->assertSame(249, (int) $cargado->min, '300 × 0,83: el promedio de ida sobreestima la vuelta');
        $this->assertEqualsWithDelta(0.83, (float) $cargado->factor_vacio, 1e-6);

        // con el factor apagado queda como antes
        $sinFactor = DBPrediccion::traslado($origen, $destino, null, null, [], null, 240, false, null,
            $resolver, $this->parametros(1.0));
        $this->assertSame(300, (int) $sinFactor->min);

        // la planta es el DESTINO de la ruta medida: esa ruta ya es la vuelta vacía
        $vacio = DBPrediccion::traslado($destino, $origen, null, null, [], null, 240, false, null,
            $resolver, $this->parametros(0.83));

        $this->assertSame('vacio', $vacio->sentido);
        $this->assertSame(300, (int) $vacio->min, 'el sentido vacío no se corrige');
        $this->assertEqualsWithDelta(1.0, (float) $vacio->factor_vacio, 1e-6);
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
            null, null, [], null, 240, false, null, null, $this->parametros());

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

    //------------------- coordenadas (sin promedios) -------------------

    public function test_la_distancia_es_haversine(): void
    {
        $a = (object) ['lat' => 0.0, 'lng' => 0.0];
        $b = (object) ['lat' => 0.0, 'lng' => 1.0];

        $this->assertEqualsWithDelta(111.19, (float) DBPrediccion::distancia_km($a, $b), 0.5);
        $this->assertEqualsWithDelta(0.0, (float) DBPrediccion::distancia_km($a, $a), 0.001);
        $this->assertNull(DBPrediccion::distancia_km($a, null));
    }

    public function test_el_ajuste_de_distancia_sale_de_las_rutas_con_promedio(): void
    {
        $calibracion = DBPrediccion::calibracion_distancias();

        if ($calibracion['global'] === null) {
            $this->markTestSkipped('no hay rutas con promedio y coordenadas en los dos extremos');
        }

        $this->assertGreaterThanOrEqual(3, $calibracion['global']['n']);
        $this->assertGreaterThan(0, $calibracion['global']['pendiente'],
            'más kilómetros tienen que ser más minutos');
        $this->assertGreaterThanOrEqual(0, $calibracion['global']['base']);
    }

    public function test_sin_promedios_el_traslado_sale_de_las_coordenadas(): void
    {
        // dos locales con coordenadas y SIN ninguna ruta que los una (ni ida ni vuelta)
        $par = DB::selectOne(
            "SELECT a.id AS a_id, b.id AS b_id
             FROM locales a
             JOIN locales b ON b.id > a.id
             WHERE a.lat IS NOT NULL AND a.lng IS NOT NULL
               AND b.lat IS NOT NULL AND b.lng IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM rutas r
                    WHERE (r.origen_id = a.id AND r.destino_id = b.id)
                       OR (r.origen_id = b.id AND r.destino_id = a.id))
             LIMIT 1"
        );

        if ($par === null) {
            $this->markTestSkipped('no hay dos locales con coordenadas y sin ruta entre ellos');
        }

        $traslado = DBPrediccion::traslado((string) $par->a_id, (string) $par->b_id, null, null,
            [], null, 240, false, null, null, $this->parametrosConCoordenadas());

        $this->assertNotNull($traslado);
        $this->assertSame('coordenadas', $traslado->fuente, 'sin promedios manda la distancia');
        $this->assertGreaterThan(0, (float) $traslado->km);
        $this->assertGreaterThan(0, (int) $traslado->min);
        $this->assertContains($traslado->ajuste, ['par', 'global', 'config']);
        $this->assertSame('vacio', $traslado->sentido);
    }

    public function test_las_coordenadas_no_inventan_puntos(): void
    {
        $this->assertNull(DBPrediccion::coord_local('LOC_QUE_NO_EXISTE_ZZ'));
        $this->assertNull(DBPrediccion::coord_ubicacion(null, null));
        $this->assertNull(DBPrediccion::coord_ubicacion('', 'no-es-ubigeo'));
    }

    public function test_el_factor_de_camion_vacio_es_configurable_y_viene_de_fabrica(): void
    {
        $this->assertEqualsWithDelta(0.83, (float) Parametros::por_defecto()->vacio_factor, 1e-6);
        $this->assertSame('0,83', Parametros::texto('vacio_factor', 0.83));
    }

    /**
     * El tramo que le falta al camión desde la ubicación declarada es manejo SIN
     * paradas, no un viaje completo: usar el ajuste del viaje completo (que incluye
     * descansos) sumaba los descansos dos veces. Caso real: VIRÚ → PLANTA CHICLAYO,
     * 208 km en línea recta, daba 15:50 cuando el manejo son ~7 h.
     */
    public function test_el_tramo_parcial_usa_velocidad_de_camion_y_no_el_viaje_completo(): void
    {
        $p = $this->parametrosConCoordenadas();

        $parcial = DBPrediccion::tramo_por_coordenadas(null, '131201', 'LOC_CHICLAYO', null,
            $p, 'VIRU → PLANTA CHICLAYO', true);
        $completo = DBPrediccion::tramo_por_coordenadas(null, '131201', 'LOC_CHICLAYO', null,
            $p, 'VIRU → PLANTA CHICLAYO', false);

        if ($parcial === null || $completo === null) {
            $this->markTestSkipped('faltan las coordenadas de VIRÚ (131201) o de LOC_CHICLAYO');
        }

        $this->assertGreaterThan(0, (float) $parcial->km);
        $this->assertLessThan((int) $completo->min, (int) $parcial->min,
            'el tramo parcial no puede costar lo mismo que el viaje completo');

        // el parcial sale de la velocidad de camión de la configuración
        $this->assertSame('config', (string) $parcial->ajuste);
        $esperado = round((float) $p->coords_parcial_base_min
            + (float) $p->coords_parcial_min_km * (float) $parcial->km);
        $this->assertEqualsWithDelta($esperado, (int) $parcial->min, 1);

        // y el completo, del ajuste medido con las rutas
        $this->assertContains((string) $completo->ajuste, ['par', 'global', 'config']);
        $this->assertNull($parcial->aviso, 'a velocidad de camión no hay nada que acotar');
    }

    public function test_el_tramo_parcial_se_acota_a_15_km_h_y_avisa(): void
    {
        // con una pendiente absurda (10 min por km recto = 6 km/h) el motor tiene que
        // recortar al piso de camión y avisar
        $p = (object) array_merge((array) $this->parametrosConCoordenadas(), [
            'coords_parcial_base_min' => 0,
            'coords_parcial_min_km' => 10.0,
            'coords_parcial_km_h_min' => 15,
        ]);

        $parcial = DBPrediccion::tramo_por_coordenadas(null, '131201', 'LOC_CHICLAYO', null,
            $p, null, true);

        if ($parcial === null) {
            $this->markTestSkipped('faltan las coordenadas de VIRÚ (131201) o de LOC_CHICLAYO');
        }

        $this->assertSame('tramo_lento', (string) $parcial->aviso);

        $tope = (int) ceil((float) $parcial->km / 15 * 60);
        $this->assertEqualsWithDelta($tope, (int) $parcial->min, 1, 'se acota a 15 km/h en línea recta');
    }

    public function test_los_ajustes_por_par_de_departamentos_son_creibles(): void
    {
        $calibracion = DBPrediccion::calibracion_distancias();

        foreach ($calibracion['pares'] as $par => $ajuste) {
            // con 5 puntos o menos la recta se ajusta a mano: en los datos reales
            // había pares con base -1.283 y 7,96 min/km (n = 3)
            $this->assertGreaterThanOrEqual(5, $ajuste['n'], 'par ' . $par . ': muestra insuficiente');
            $this->assertGreaterThanOrEqual(0, $ajuste['base'], 'par ' . $par . ': base negativa');

            $kmH = 60 / $ajuste['pendiente'];
            $this->assertGreaterThanOrEqual(5, $kmH, 'par ' . $par . ': demasiado lento para ser camión');
            $this->assertLessThanOrEqual(45, $kmH, 'par ' . $par . ': demasiado rápido para ser camión');
        }
    }

    /** Parámetros con el escalón de coordenadas prendido. */
    private function parametrosConCoordenadas(): object
    {
        return (object) array_merge((array) Parametros::cargar(), [
            'coords_activo' => 1,
            'vacio_factor' => 1.0,
        ]);
    }
}
