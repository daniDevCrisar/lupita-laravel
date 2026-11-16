<?php

namespace App\Prediccion;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ablación del motor: corre la tabla de aciertos con distintas configuraciones y
 * devuelve el MAE de cada una. Es el instrumento que dice si un mecanismo aporta
 * o resta, en vez de discutirlo.
 *
 * Existe porque el 14/11/2026 se midió que el motor completo (MAE 1.568) era PEOR
 * que la mediana simple de la placa (1.116): apilaba correcciones que restaban.
 * La ablación encontró al culpable (el factor de día, −131 min al apagarlo) y el
 * guardia de circularidad que lo hacía peor todavía.
 *
 * Los parámetros se pisan EN MEMORIA (`Servicio::backtest($filtros, $parametros)`),
 * así que medir no cambia lo que el motor usa en producción.
 *
 * Es puro de punta a punta salvo por la base: no escribe nada.
 */
final class Ablacion
{
    /**
     * Variantes que se comparan. La clave es la etiqueta que se imprime; el valor,
     * los parámetros que se pisan sobre los que están en uso.
     *
     * "en uso" va sin cambios a propósito: es la vara contra la que se lee todo.
     */
    public const VARIANTES = [
        'en uso (como está)' => [],
        'sin factor de día' => ['dia_modo' => 0],
        'día compilado (por ruta)' => ['dia_modo' => 3],
        'día por placa' => ['dia_modo' => 2],
        'día flota (el viejo)' => ['dia_modo' => 1],
        'día por ancla' => ['dia_modo' => 4],
        'curva apagada' => ['curva_modo' => 2],
        'curva vieja (absoluta)' => ['curva_modo' => 0],
        'curva encogida' => ['curva_modo' => 1],
        'todo lo de antes (v1.19)' => ['dia_modo' => 1, 'curva_modo' => 0, 'pares_recientes_max' => 20],
    ];

    /** Cuántas variantes corre el botón de la pantalla (el comando corre todas). */
    public const VARIANTES_WEB = [
        'en uso (como está)' => [],
        'sin factor de día' => ['dia_modo' => 0],
        'día compilado (por ruta)' => ['dia_modo' => 3],
        'todo lo de antes (v1.19)' => ['dia_modo' => 1, 'curva_modo' => 0, 'pares_recientes_max' => 20],
    ];

    /**
     * Corre las variantes sobre un rango y devuelve la tabla.
     *
     * @param  array{desde?: string, hasta?: string, limite?: int}  $filtros
     * @param  array<string, array<string, int>>  $variantes  etiqueta => parámetros pisados
     * @return array{n: int, desde: string, hasta: string, limite: int, filas: array<int, array<string, mixed>>}
     */
    public static function correr(array $filtros, array $variantes = self::VARIANTES): array
    {
        $base = Parametros::cargar();
        $desde = (string) ($filtros['desde'] ?? Carbon::now()->subDays(90)->toDateString());
        $hasta = (string) ($filtros['hasta'] ?? Carbon::now()->toDateString());
        $limite = (int) ($filtros['limite'] ?? 600);

        $salida = ['n' => 0, 'desde' => $desde, 'hasta' => $hasta, 'limite' => $limite, 'filas' => []];

        foreach ($variantes as $nombre => $cambios) {
            $parametros = clone $base;
            foreach ($cambios as $clave => $valor) {
                $parametros->{$clave} = $valor;
            }

            $fila = ['nombre' => $nombre, 'cambios' => $cambios, 'n' => 0, 'mae' => null,
                     'pct_180' => null, 'pct_360' => null, 'pct_ventana' => null,
                     'sesgo' => null, 'error' => null];

            try {
                $corrida = Servicio::backtest(['desde' => $desde, 'hasta' => $hasta, 'limite' => $limite],
                    $parametros);
                $resumen = $corrida->resumen_motor;

                $fila['n'] = (int) ($resumen->n ?? 0);
                $fila['mae'] = $resumen->mae_min ?? null;
                $fila['pct_180'] = $resumen->pct_180 ?? null;
                $fila['pct_360'] = $resumen->pct_360 ?? null;
                $fila['pct_ventana'] = $resumen->pct_ventana ?? null;
                $fila['sesgo'] = $resumen->sesgo_min ?? null;
                $salida['n'] = max($salida['n'], $fila['n']);
            } catch (\Throwable $e) {
                $fila['error'] = $e->getMessage();
            }

            $salida['filas'][] = $fila;
        }

        // el MAE de la primera variante ("en uso") es la referencia
        $referencia = $salida['filas'][0]['mae'] ?? null;
        foreach ($salida['filas'] as $i => $fila) {
            $salida['filas'][$i]['delta'] = ($fila['mae'] !== null && $referencia !== null)
                ? $fila['mae'] - $referencia
                : null;
        }

        return $salida;
    }

    /**
     * Baselines: cuánto se equivoca el motor comparado con reglas de una línea,
     * midiendo SÓLO con información anterior a cada ancla (sin filtrar el futuro).
     *
     *  - mediana global del hueco de la flota,
     *  - mediana de los últimos N huecos de la MISMA placa,
     *  - mediana de los últimos N huecos del MISMO par de rutas.
     *
     * Si el motor no le gana a la mediana de la placa, todo lo que se apiló encima
     * del calibrador está restando.
     *
     * @return array{fechas: array{desde: string, hasta: string}, filas: array<int, array<string, mixed>>}
     */
    public static function baselines(array $filtros = [], int $recientes = 20): array
    {
        $desde = Carbon::parse((string) ($filtros['desde'] ?? Carbon::now()->subDays(90)->toDateString()))->startOfDay();
        $hasta = Carbon::parse((string) ($filtros['hasta'] ?? Carbon::now()->toDateString()))->endOfDay();
        $limite = (int) ($filtros['limite'] ?? 600);
        $recientes = max(1, $recientes);

        $salida = ['fechas' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
                   'filas' => []];
        $vacio = static fn (string $nombre) => ['nombre' => $nombre, 'n' => 0, 'mae' => null, 'pct_180' => null, 'error' => null];

        try {
            $pares = DB::select(
                "SELECT ref, placa, ruta_ancla, ruta_siguiente, fin_ancla,
                        TIMESTAMPDIFF(MINUTE, fin_ancla, presenta_para_carga) AS hueco
                 FROM (SELECT r.ref, r.placa, r.ruta_id AS ruta_siguiente, r.presenta_para_carga,
                              LAG(r.ruta_id) OVER w AS ruta_ancla, LAG(r.fin_descargue) OVER w AS fin_ancla
                       FROM referencias r
                       WHERE r.placa <> '' AND r.presenta_para_carga >= '2020-01-01'
                       WINDOW w AS (PARTITION BY r.placa ORDER BY r.presenta_para_carga)) x
                 WHERE fin_ancla IS NOT NULL");
        } catch (\Throwable $e) {
            $salida['filas'][] = array_merge($vacio('pares'), ['error' => $e->getMessage()]);

            return $salida;
        }

        $porRef = [];
        $porPlaca = [];
        $porRuta = [];
        foreach ($pares as $par) {
            $hueco = (int) $par->hueco;
            if ($hueco <= 0 || $hueco > Calibrador::TOPE_HUECO) {
                continue;
            }

            $fila = ['placa' => (string) $par->placa,
                     'ruta' => (string) $par->ruta_ancla . '>' . (string) $par->ruta_siguiente,
                     'hueco' => $hueco, 'ts' => strtotime((string) $par->fin_ancla)];
            $porRef[(string) $par->ref] = $fila;
            $porPlaca[$fila['placa']][] = $fila;
            $porRuta[$fila['ruta']][] = $fila;
        }

        try {
            $corrida = Servicio::backtest(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
                'limite' => $limite]);
        } catch (\Throwable $e) {
            $salida['filas'][] = array_merge($vacio('motor'), ['error' => $e->getMessage()]);

            return $salida;
        }

        $global = [];
        foreach ($porPlaca as $lista) {
            foreach ($lista as $fila) {
                $global[] = $fila['hueco'];
            }
        }
        $medianaGlobal = Estadistica::mediana($global);

        $acumuladores = ['mediana de la flota' => [], 'mediana de la placa' => [],
                         'mediana del par de rutas' => [], 'motor completo' => [],
                         // el motor medido SÓLO en las filas donde la mediana de la
                         // placa tiene estimación: sin esto se compara un motor que
                         // incluye los casos sin historia (cadena, MAE 1.500+) contra
                         // un baseline que esos casos no puede calcular
                         'motor en las filas de la placa' => []];

        foreach ($corrida->filas as $fila) {
            $ref = (string) $fila->ref;
            $par = $porRef[$ref] ?? null;
            if ($par === null || $fila->error_min === null) {
                continue;
            }

            $real = $par['hueco'];
            if ($medianaGlobal !== null) {
                $acumuladores['mediana de la flota'][] = abs((int) round($medianaGlobal) - $real);
            }

            $dePlaca = self::as_of($porPlaca[$par['placa']] ?? [], $par['ts'], $recientes);
            if ($dePlaca !== null) {
                $acumuladores['mediana de la placa'][] = abs($dePlaca - $real);
                $acumuladores['motor en las filas de la placa'][] = abs((int) $fila->error_min);
            }

            $deRuta = self::as_of($porRuta[$par['ruta']] ?? [], $par['ts'], $recientes);
            if ($deRuta !== null) {
                $acumuladores['mediana del par de rutas'][] = abs($deRuta - $real);
            }

            $acumuladores['motor completo'][] = abs((int) $fila->error_min);
        }

        foreach ($acumuladores as $nombre => $valores) {
            if ($valores === []) {
                $salida['filas'][] = $vacio($nombre);

                continue;
            }

            $n = count($valores);
            $salida['filas'][] = [
                'nombre' => $nombre,
                'n' => $n,
                'mae' => (int) round(array_sum($valores) / $n),
                'pct_180' => (int) round(100 * count(array_filter($valores, static fn ($v) => $v <= 180)) / $n),
                'error' => null,
            ];
        }

        return $salida;
    }

    /**
     * Mediana del hueco usando SÓLO los pares anteriores al ancla (sin futuro),
     * y como mucho los `$max` más recientes.
     *
     * @param  array<int, array{hueco: int, ts: int}>  $lista
     */
    private static function as_of(array $lista, int $antesDe, int $max): ?int
    {
        $previos = [];
        foreach ($lista as $fila) {
            if ($fila['ts'] < $antesDe) {
                $previos[] = $fila;
            }
        }

        if (count($previos) < 2) {
            return null;
        }

        usort($previos, static fn (array $a, array $b) => $b['ts'] <=> $a['ts']);
        $mediana = Estadistica::mediana(array_column(array_slice($previos, 0, $max), 'hueco'));

        return $mediana === null ? null : (int) round($mediana);
    }
}
