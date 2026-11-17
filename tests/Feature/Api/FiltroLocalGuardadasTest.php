<?php

namespace Tests\Feature\Api;

use App\Database\DBPrediccion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filtro por LOCAL en "Predicciones guardadas".
 *
 * El local es la planta de carga: el `origen_id` de la ruta de la referencia, o
 * sea el local donde el camión tiene que presentarse. El combo con búsqueda sale
 * de `DBPrediccion::plantas_carga()` y usa el selector compartido de `tools`.
 *
 * Ojo con el detalle que lo rompía: `prediccion_resultados` sólo guarda el
 * `ruta_id`, y el botón "Guardar estas predicciones" lo dejaba en NULL, así que
 * el filtro (que cruza por `rutas`) no veía esas filas. Ahora se resuelve por la
 * ruta de la referencia cuando falta.
 */
class FiltroLocalGuardadasTest extends TestCase
{
    private const DESDE = '2026-10-01';
    private const HASTA = '2026-11-16';

    /** Marca de las filas que crea este test (se borran al terminar). */
    private const ORIGEN = 'zzloc';

    protected function tearDown(): void
    {
        DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->delete();
        parent::tearDown();
    }

    private function vista(array $extra): array
    {
        $this->withoutMiddleware();
        $usuario = User::query()->first();
        if ($usuario !== null) {
            Auth::login($usuario);
        }

        $peticion = Request::create('/lupita/prediccion/guardadas', 'GET', array_merge([
            'fecha_inicio' => self::DESDE,
            'fecha_fin' => self::HASTA,
            'limite' => '50',
        ], $extra));
        $peticion->setLaravelSession(app('session.store'));

        $respuesta = \App\Http\Controllers\PrediccionController::guardadas($peticion);

        return [$respuesta, $respuesta->getData()];
    }

    /** El local de cada fila guardada, con la misma resolución que el filtro. */
    private function localesComparables(): array
    {
        return DB::select(
            "SELECT ru.origen_id AS local, COUNT(*) AS filas
             FROM prediccion_resultados p
             LEFT JOIN referencias r ON r.ref = p.ref
             LEFT JOIN rutas ru ON ru.id = COALESCE(NULLIF(p.ruta_id, ''), r.ruta_id)
             WHERE r.presenta_para_carga BETWEEN ? AND ? AND ru.origen_id IS NOT NULL
             GROUP BY ru.origen_id ORDER BY filas DESC",
            [self::DESDE . ' 00:00:00', self::HASTA . ' 23:59:59']);
    }

    public function test_el_filtro_por_local_deja_solo_esa_planta(): void
    {
        $locales = $this->localesComparables();
        if ($locales === []) {
            $this->markTestSkipped('no hay predicciones guardadas con local en el rango');
        }

        $local = (string) $locales[0]->local;

        [, $todo] = $this->vista([]);
        [$vista, $filtrado] = $this->vista(['local' => $local]);

        // el filtro deja una muestra con datos (no la vacía)
        $this->assertGreaterThan(0, $filtrado['tablero']['produccion']['errores']['n']);

        // y TODAS las filas que quedan son de esa planta
        $comparables = $filtrado['tablero']['produccion']['errores'];
        $this->assertGreaterThan(0, $comparables['n']);

        $refs = array_map(fn ($fila) => (string) $fila->ref, $filtrado['resumen']['comparables']);
        if ($refs !== []) {
            $ajenas = DB::select(
                "SELECT COUNT(*) AS n
                 FROM prediccion_resultados p
                 LEFT JOIN referencias r ON r.ref = p.ref
                 LEFT JOIN rutas ru ON ru.id = COALESCE(NULLIF(p.ruta_id, ''), r.ruta_id)
                 WHERE p.ref IN (" . implode(',', array_fill(0, count($refs), '?')) . ")
                   AND (ru.origen_id IS NULL OR ru.origen_id <> ?)",
                array_merge($refs, [$local]));

            $this->assertSame(0, (int) $ajenas[0]->n, 'quedaron filas de otra planta');
        }

        // el motor también se mide sobre ese local, y la pantalla lo dice
        $html = (string) $vista->render();
        $this->assertStringContainsString('filtrando por <strong>' . $local . '</strong>', $html);
        $this->assertStringContainsString('name="local" id="local" value="' . $local . '"', $html);
        $this->assertSame($local, $filtrado['filtros']['local']);
    }

    public function test_la_corrida_del_motor_tambien_se_filtra_por_local(): void
    {
        $locales = $this->localesComparables();
        if ($locales === []) {
            $this->markTestSkipped('no hay predicciones guardadas con local en el rango');
        }

        $local = (string) $locales[0]->local;

        $corrida = \App\Prediccion\Servicio::backtest([
            'desde' => self::DESDE,
            'hasta' => self::HASTA,
            'limite' => 60,
            'planta' => $local,
        ]);

        if ($corrida->filas === []) {
            $this->markTestSkipped('la corrida no trajo filas para esa planta');
        }

        $refs = array_map(fn ($fila) => (string) $fila->ref, $corrida->filas);
        $ajenas = DB::select(
            "SELECT COUNT(*) AS n
             FROM referencias r JOIN rutas ru ON ru.id = r.ruta_id
             WHERE r.ref IN (" . implode(',', array_fill(0, count($refs), '?')) . ")
               AND ru.origen_id <> ?",
            array_merge($refs, [$local]));

        $this->assertSame(0, (int) $ajenas[0]->n, 'la corrida filtró mal: trajo refs de otra planta');
    }

    public function test_borrar_con_filtro_de_local_no_toca_las_otras_plantas(): void
    {
        // dos rutas de plantas distintas, cada una con una ref
        $rutas = DB::select("SELECT ru.id, ru.origen_id FROM rutas ru
                             WHERE ru.origen_id IS NOT NULL AND ru.origen_id <> ''
                             ORDER BY ru.origen_id LIMIT 2");
        $this->assertGreaterThanOrEqual(2, count($rutas), 'hacen falta dos rutas de plantas distintas');

        if ($rutas[0]->origen_id === $rutas[1]->origen_id) {
            $this->markTestSkipped('la base no tiene dos plantas distintas');
        }

        $refs = [];
        foreach ($rutas as $ruta) {
            $ref = DB::table('referencias')->where('ruta_id', $ruta->id)->value('ref');
            if ($ref === null) {
                $this->markTestSkipped('no hay referencias para esas rutas');
            }
            $refs[] = (string) $ref;
        }

        $ahora = '2026-10-15 08:00:00';

        DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->delete();
        foreach ($refs as $i => $ref) {
            DB::table('prediccion_resultados')->insert([
                'ref' => $ref,
                'placa' => 'ZZLOC',
                'ruta_id' => $rutas[$i]->id,
                'ahora' => $ahora,
                'presenta' => '2026-10-16 10:00:00',
                'origen' => self::ORIGEN,
                'evidencia' => 'cadena',
                'confianza' => 10,
                'desglose' => json_encode([]),
                'advertencias' => json_encode([]),
                'pedido_en' => now(),
            ]);
        }

        $this->withoutMiddleware();
        $pet = Request::create('/lupita/prediccion/guardadas/borrar', 'POST', [
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-31',
            'local' => (string) $rutas[0]->origen_id,
        ]);
        $pet->setLaravelSession(app('session.store'));

        \App\Http\Controllers\PrediccionController::borrar_guardadas($pet);

        $quedan = DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->pluck('ref')->all();

        $this->assertNotContains($refs[0], $quedan, 'la fila del local elegido tenía que borrarse');
        $this->assertContains($refs[1], $quedan, 'la fila de la otra planta NO se toca');

        DB::table('prediccion_resultados')->where('origen', self::ORIGEN)->delete();
    }

    public function test_el_combo_de_locales_trae_las_plantas_de_carga(): void
    {
        $plantas = DBPrediccion::plantas_carga();

        if ($plantas === []) {
            $this->markTestSkipped('la base no tiene rutas con origen');
        }

        $this->assertArrayHasKey('valor', $plantas[0]);
        $this->assertArrayHasKey('texto', $plantas[0]);
        $this->assertArrayHasKey('extra', $plantas[0]);

        // sin repetidos y ordenado por nombre
        $valores = array_column($plantas, 'valor');
        $this->assertSame(count($valores), count(array_unique($valores)));

        $textos = array_column($plantas, 'texto');
        $ordenados = $textos;
        usort($ordenados, fn ($a, $b) => strcasecmp($a, $b));
        $this->assertSame($ordenados, $textos);

        // la pantalla arma el combo con esas opciones
        [$vista] = $this->vista([]);
        $html = (string) $vista->render();
        $this->assertStringContainsString('Local donde presenta a cargar', $html);
        $this->assertStringContainsString('data-selector="simple"', $html);
        $this->assertStringContainsString('js/lupita-selectores.js', $html);
        $this->assertStringContainsString('data-valor="' . $plantas[0]['valor'] . '"', $html);
    }
}
