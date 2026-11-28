<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Database\DBPrediccionResultados;
use App\Prediccion\Geoapify;
use App\Prediccion\MotorPresenta;
use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEXTA FECHA — "PERFECTA".
 *
 * No es una banda alrededor de otra fecha: es la cadena entera armada como si el
 * viaje saliera perfecto.
 *
 *   1. el viaje y el traslado usan el p25 de la ruta compilada, SIN mezclar con
 *      la placa ni con la celda día/hora (esas son medianas);
 *   2. no se suma la espera ni el descanso del conductor;
 *   3. no se corren las franjas inactivas;
 *   4. el ruteo de Geoapify se pide en vía libre.
 *
 * La normal y el rápido NO cambian: la perfecta es una fecha más, que se publica
 * y se mide igual que las otras cinco.
 */
class FechaPerfectaTest extends TestCase
{
    /** Claves que estos tests escriben en `prediccion_config`. */
    private const CLAVES = ['perfecta_activa', 'perfecta_cuantil', 'perfecta_sin_trafico'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        try {
            DB::table('prediccion_config')->whereIn('clave', self::CLAVES)->delete();
        } catch (\Throwable $e) {
            // sin tabla se trabaja con los defaults
        }
        Parametros::olvidar();
    }

    //----------------------- motor -----------------------

    /**
     * La hora perfecta es t_fin (p25) + traslado (p25), SIN tiempo muerto. El
     * tiempo muerto es justamente lo que separa a la perfecta de la normal.
     */
    public function test_la_perfecta_es_el_p25_mas_el_traslado_sin_tiempo_muerto(): void
    {
        $ahora = Carbon::parse('2026-11-20 08:00');

        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            // el camión va en viaje: le faltan 600 min de etapas y lleva 100 manejados
            'ancla' => (object) [
                'hito' => 'inicio_ruta',
                'fecha' => $ahora->copy()->subMinutes(100),
                'cerrada' => false,
            ],
            'etapas' => ['e4' => 500, 'e5' => 60, 'e6' => 40, 'e4_p25' => 400, 'e5_p25' => 30, 'e6_p25' => 20],
            'traslado' => (object) ['min' => 200, 'fuente' => 'ruta_medida', 'sentido' => 'vacio'],
            'calibrador' => (object) ['gap_min' => 900, 'p25_min' => 700, 'p75_min' => 1200, 'n' => 8,
                                      'nivel' => 'medido'],
            'perfecta' => (object) [
                'activa' => true,
                'cuantil' => 'p25',
                'sin_trafico' => true,
                'traslado' => (object) ['min' => 160, 'fuente' => 'ruta_medida', 'sentido' => 'vacio'],
                'restante_min' => 450,
                'tramo' => null,
            ],
            'espera_carga_min' => null,
            'parametros' => Parametros::cargar(),
        ]);

        $this->assertNotNull($salida->presenta_perfecta, 'la perfecta se publica');
        // 450 − 100 ya transcurridos = 350, + 160 de vuelta, + 0 de descanso
        $esperada = $ahora->copy()->addMinutes(350 + 160);
        $this->assertSame($esperada->format('Y-m-d H:i'),
            $salida->presenta_perfecta->format('Y-m-d H:i'));

        // la normal usa el hueco medido (más el descanso incluido ahí): es más tarde
        $this->assertGreaterThan($salida->presenta_perfecta->getTimestamp(),
            $salida->presenta->getTimestamp(), 'la normal no se toca');

        // y viaja como sexta fecha en el orden publicado
        $claves = array_column($salida->presentaciones, 'clave');
        $this->assertSame(['rapido', 'consenso', 'normal', 'probable', 'tardado', 'perfecta'], $claves);
    }

    /**
     * EL ESCENARIO PERFECTO CUENTA DESDE QUE EL CAMIÓN QUEDÓ LIBRE, NO DESDE EL
     * CORTE. Caso real (ref 1197736): ancla fin de descargue 08:27, corte 10:26
     * (120 min ya libre), traslado perfecto 3:26. Publicaba 13:52:52 = corte + 3:26
     * —exactamente la hora de la normal (326 = 120 ya libres + 206 de viaje)—, o sea
     * parecía que no omitía ni el descanso ni el recorte. Lo correcto es
     * 08:27 + 3:26 = 11:53.
     */
    public function test_la_perfecta_cuenta_desde_el_ancla_y_no_desde_el_corte(): void
    {
        $ahora = Carbon::parse('2026-11-27 10:26:52');
        $ancla = Carbon::parse('2026-11-27 08:27:00');

        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $ancla, 'cerrada' => true],
            // la normal de ese caso: hueco 326 (traslado 256 + 60 de muerto, ×1.03 del día)
            'traslado' => (object) ['min' => 256, 'fuente' => 'ruta_medida'],
            'calibrador' => null,
            'espera_carga_min' => 96,
            'perfecta' => (object) [
                'activa' => true, 'cuantil' => 'p25', 'sin_trafico' => true,
                'traslado' => (object) ['min' => 206, 'fuente' => 'ruta_medida', 'sentido' => 'cargado'],
                'restante_min' => null, 'tramo' => null,
            ],
            'parametros' => Parametros::cargar(),
        ]);

        // 08:27 + 3:26 = 11:53, no 10:26 + 3:26
        $this->assertSame('2026-11-27 11:53',
            $salida->presenta_perfecta->format('Y-m-d H:i'));

        // y ahora sí queda ANTES de la normal por todo lo que el escenario omite:
        // sin descanso (60) y con el p25 en vez de la mediana (256 → 206). La normal
        // de este caso es ancla + 316 = 13:43 (sin factor del día: el real sumaba
        // 10 más y daba 13:53)
        $this->assertSame('2026-11-27 13:43', $salida->presenta->format('Y-m-d H:i'));
        $this->assertSame(110, (int) round(
            ($salida->presenta->getTimestamp() - $salida->presenta_perfecta->getTimestamp()) / 60),
            '110 = 60 de descanso + 50 de p25 en vez de mediana');
        $this->assertStringNotContainsString('perfecta_tarde', implode(' | ', $salida->advertencias));
    }

    /** Si el escenario perfecto ya venció (ancla + viaje < corte), se publica el corte. */
    public function test_si_el_escenario_perfecto_ya_vencio_se_publica_el_corte(): void
    {
        $ahora = Carbon::parse('2026-11-27 10:26:52');

        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ancla' => (object) ['hito' => 'fin_descargue',
                                 'fecha' => $ahora->copy()->subHours(6), 'cerrada' => true],
            'traslado' => (object) ['min' => 256, 'fuente' => 'ruta_medida'],
            'calibrador' => (object) ['gap_min' => 326, 'p25_min' => 300, 'p75_min' => 400, 'n' => 8,
                                      'nivel' => 'medido'],
            'espera_carga_min' => 96,
            'perfecta' => (object) [
                'activa' => true, 'cuantil' => 'p25', 'sin_trafico' => true,
                'traslado' => (object) ['min' => 206, 'fuente' => 'ruta_medida'],
                'restante_min' => null, 'tramo' => null,
            ],
            'parametros' => Parametros::cargar(),
        ]);

        $this->assertSame($ahora->format('Y-m-d H:i'), $salida->presenta_perfecta->format('Y-m-d H:i'));
    }

    public function test_sin_piezas_no_se_publica_la_perfecta(): void
    {
        $ahora = Carbon::parse('2026-11-20 08:00');

        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $ahora->copy()->subMinutes(30),
                                 'cerrada' => true],
            'traslado' => (object) ['min' => 200, 'fuente' => 'ruta_medida'],
            'calibrador' => (object) ['gap_min' => 900, 'p25_min' => 700, 'p75_min' => 1200, 'n' => 8,
                                      'nivel' => 'medido'],
            'espera_carga_min' => null,
            'parametros' => Parametros::cargar(),
        ]);

        $this->assertNull($salida->presenta_perfecta);
        $this->assertNotContains('perfecta', array_column($salida->presentaciones, 'clave'));
    }

    public function test_con_el_camion_en_la_planta_la_perfecta_es_el_corte(): void
    {
        $ahora = Carbon::parse('2026-11-20 08:00');

        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $ahora->copy()->subMinutes(30),
                                 'cerrada' => true],
            'traslado' => (object) ['min' => 200, 'fuente' => 'ruta_medida'],
            'calibrador' => (object) ['gap_min' => 900, 'p25_min' => 700, 'p75_min' => 1200, 'n' => 8,
                                      'nivel' => 'medido'],
            'en_planta' => true,
            'perfecta' => (object) [
                'activa' => true, 'cuantil' => 'p25', 'sin_trafico' => true,
                'traslado' => (object) ['min' => 0, 'fuente' => 'ya_esta_en_planta'],
                'restante_min' => null, 'tramo' => null,
            ],
            'espera_carga_min' => null,
            'parametros' => Parametros::cargar(),
        ]);

        $this->assertSame($ahora->format('Y-m-d H:i'), $salida->presenta_perfecta->format('Y-m-d H:i'));
    }

    public function test_si_la_perfecta_cae_despues_de_la_normal_se_avisa(): void
    {
        $ahora = Carbon::parse('2026-11-20 08:00');

        // hueco medido de 60 min (la normal queda enseguida) contra un traslado
        // perfecto de 500: el escenario "perfecto" termina más tarde
        $salida = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $ahora->copy(), 'cerrada' => true],
            'traslado' => (object) ['min' => 60, 'fuente' => 'ruta_medida'],
            'calibrador' => (object) ['gap_min' => 60, 'p25_min' => 40, 'p75_min' => 90, 'n' => 8,
                                      'nivel' => 'medido'],
            'perfecta' => (object) [
                'activa' => true, 'cuantil' => 'p25', 'sin_trafico' => true,
                'traslado' => (object) ['min' => 500, 'fuente' => 'ruta_medida'],
                'restante_min' => null, 'tramo' => null,
            ],
            'espera_carga_min' => null,
            'parametros' => Parametros::cargar(),
        ]);

        $this->assertGreaterThan($salida->presenta->getTimestamp(),
            $salida->presenta_perfecta->getTimestamp());

        $aviso = implode(' | ', $salida->advertencias);
        $this->assertStringContainsString('perfecta_tarde', $aviso);
    }

    //----------------------- datos -----------------------

    /** Una ruta compilada con p25 cargado. */
    private function rutaConP25(): ?object
    {
        return DB::selectOne(
            "SELECT p.ruta_id, p.mediana_min, p.p25_min, p.viajes
             FROM tiempos_ruta_promedio p
             WHERE p.p25_min IS NOT NULL AND p.p25_min > 0
               AND p.mediana_min IS NOT NULL AND p.mediana_min > 0
               AND p.p25_min < p.mediana_min
             ORDER BY p.viajes DESC
             LIMIT 1"
        );
    }

    public function test_el_p25_compilado_manda_sobre_la_mediana(): void
    {
        $ruta = $this->rutaConP25();
        if ($ruta === null) {
            $this->markTestSkipped('no hay rutas compiladas con p25');
        }

        $desde = Carbon::parse('2026-09-01');
        $hasta = Carbon::parse('2026-10-30');
        $etapas = DBPrediccion::etapas_por_ruta($desde, $hasta);

        $id = (string) $ruta->ruta_id;
        $this->assertArrayHasKey($id, $etapas, 'la ruta tiene referencias en la ventana');
        $this->assertSame((int) $ruta->p25_min, (int) $etapas[$id]['e4_p25'],
            'el p25 de la etapa sale del compilado');

        $p25 = DBPrediccion::viaje_ruta_base($id, $etapas, 'p25');
        $mediana = DBPrediccion::viaje_ruta_base($id, $etapas);

        $this->assertSame((int) $ruta->p25_min, $p25);
        $this->assertGreaterThan($p25, $mediana, 'la mediana sigue mandando en la cadena normal');
    }

    /**
     * El p25 va PURO: la placa y la celda día/hora son medianas de otra
     * distribución, así que no se mezclan (si se mezclaran, la fecha perfecta se
     * volvería la normal).
     */
    public function test_el_p25_no_se_mezcla_con_la_placa(): void
    {
        $ruta = $this->rutaConP25();
        if ($ruta === null) {
            $this->markTestSkipped('no hay rutas compiladas con p25');
        }

        $id = (string) $ruta->ruta_id;
        $etapas = DBPrediccion::etapas_por_ruta(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-30'));
        if (!isset($etapas[$id])) {
            $this->markTestSkipped('la ruta no tiene referencias en la ventana');
        }

        $puro = Servicio::viaje_ruta($id, $etapas, Carbon::parse('2026-11-20 20:00'), 'ABC123',
            Carbon::parse('2026-08-20'), Carbon::parse('2026-11-20'), Parametros::cargar(), 'p25');

        if ($puro === null) {
            $this->markTestSkipped('la ruta no tiene nada medido');
        }

        $this->assertFalse((bool) $puro->mezclado, 'la perfecta no mezcla con la placa');
        $this->assertSame((int) $puro->base, (int) $puro->min, 'el minuto es el p25 de la base');
        $this->assertSame('p25', $puro->cuantil);

        // la cadena normal sí mezcla cuando hay muestra de la placa
        $normal = Servicio::viaje_ruta($id, $etapas, Carbon::parse('2026-11-20 20:00'), 'ABC123',
            Carbon::parse('2026-08-20'), Carbon::parse('2026-11-20'), Parametros::cargar());
        $this->assertTrue((bool) ($normal->mezclado ?? false));
    }

    public function test_el_traslado_de_la_perfecta_lleva_su_cuantil_y_su_via_libre(): void
    {
        $ruta = DB::selectOne(
            "SELECT r.origen_id, r.destino_id FROM rutas r WHERE r.origen_id IS NOT NULL
               AND r.origen_id <> '' AND r.destino_id IS NOT NULL AND r.destino_id <> '' LIMIT 1"
        );
        if ($ruta === null) {
            $this->markTestSkipped('el catálogo de rutas está vacío');
        }

        $traslado = DBPrediccion::traslado((string) $ruta->origen_id, (string) $ruta->destino_id,
            null, null, [], null, 240, false, null,
            fn (string $pedida): object => (object) ['min' => 300, 'base' => 300, 'tras_placa' => 300,
                'placa' => null, 'placa_n' => 0, 'celda' => null, 'celda_viajes' => 0,
                'celda_dia' => null, 'celda_hora' => null],
            Parametros::cargar(), 'p25', true);

        $this->assertSame('p25', $traslado->cuantil ?? null);
        $this->assertTrue((bool) ($traslado->sin_trafico ?? false));
    }

    public function test_geoapify_pide_via_libre_cuando_es_la_perfecta(): void
    {
        $salida = Carbon::parse('2026-11-20 19:00');

        // el pedido normal en hora pico pide tráfico aproximado…
        $this->assertSame('approximated', Geoapify::trafico_para($salida, '150101', '150101'));
        // …y la perfecta pide vía libre sin importar la hora
        $this->assertNull(Geoapify::trafico_para($salida, '150101', '150101', true));
    }

    //----------------------- punta a punta -----------------------

    public function test_la_prediccion_publica_la_sexta_fecha(): void
    {
        $ref = $this->refMedible();
        $r = Servicio::predecir($ref, [
            'ahora' => $this->ahora($ref),
            'ubicacion_tipo' => 'ubigeo',
            'ubicacion_id' => '140101',
        ]);

        if ($r->presenta === null) {
            $this->markTestSkipped('la ref elegida no se puede predecir en ese corte');
        }

        $this->assertNotNull($r->presenta_perfecta, 'el JSON trae presenta_perfecta');
        $this->assertSame('perfecta', end($r->presentaciones)['clave']);
        $this->assertSame('p25', $r->historia->perfecta->cuantil ?? null);
        $this->assertTrue((bool) ($r->historia->perfecta->sin_trafico ?? false));

        // nunca antes del corte
        $this->assertGreaterThanOrEqual($r->ahora->getTimestamp(), $r->presenta_perfecta->getTimestamp());

        // y si queda después de la normal, está avisado
        if ($r->presenta_perfecta->greaterThan($r->presenta)) {
            $this->assertStringContainsString('perfecta_tarde', implode(' | ', $r->advertencias));
        }
    }

    public function test_la_perfecta_se_guarda_y_se_lee_en_el_desglose(): void
    {
        $ref = 'ZPERF-' . random_int(1000, 9999);
        $ahora = Carbon::parse('2026-11-20 08:00');

        $resultado = (object) [
            'ref' => $ref,
            'placa' => 'ABC123',
            'ruta' => null,
            'ahora' => $ahora,
            'presenta' => $ahora->copy()->addHours(10),
            'presenta_rapido' => $ahora->copy()->addHours(2),
            'presenta_tardado' => $ahora->copy()->addHours(20),
            'presenta_probable' => $ahora->copy()->addHours(11),
            'presenta_consenso' => $ahora->copy()->addHours(10)->addMinutes(30),
            'presenta_perfecta' => $ahora->copy()->addHours(7),
            'inicio_carga' => $ahora->copy()->addHours(11),
            'evidencia' => 'parcial',
            'confianza' => 55,
            'desglose' => [],
            'advertencias' => [],
            'sesgo_clase' => null,
            'sesgo_min' => 0,
        ];

        try {
            $this->assertTrue(DBPrediccionResultados::guardar($resultado, 'prueba'));

            $fila = DB::table('prediccion_resultados')->where('ref', $ref)->first(['desglose']);
            $this->assertNotNull($fila);
            $this->assertSame($resultado->presenta_perfecta->format('Y-m-d H:i:s'),
                DBPrediccionResultados::perfectaGuardada($fila->desglose)?->format('Y-m-d H:i:s'));
        } finally {
            DB::table('prediccion_resultados')->where('ref', $ref)->delete();
        }
    }

    private function refMedible(): string
    {
        $fila = DB::table('referencias')
            ->whereNotNull('ruta_id')->where('ruta_id', '<>', '')
            ->whereNotNull('presenta_para_carga')
            ->where('presenta_para_carga', '>=', '2026-10-15')
            ->orderByDesc('presenta_para_carga')
            ->first(['ref']);

        if ($fila === null) {
            $this->markTestSkipped('la base no tiene referencias medibles');
        }

        return (string) $fila->ref;
    }

    private function ahora(string $ref): string
    {
        $fila = DB::table('referencias')->where('ref', $ref)->first(['presenta_para_carga']);

        return Carbon::parse($fila->presenta_para_carga)->subHours(10)->format('Y-m-d H:i');
    }
}
