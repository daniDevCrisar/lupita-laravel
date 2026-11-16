<?php

namespace App\Prediccion;

use App\Database\DBPrediccionResultados;
use Carbon\Carbon;
use stdClass;

/**
 * Sesgo por clase: cuánto se equivoca el motor, en mediana, en cada tipo de caso.
 *
 * El motor publica una hora y se equivoca con un sesgo que NO es parejo:
 *   - con evidencia `cadena` (sin pares medidos) y horizonte largo, publica
 *     DECENAS DE HORAS ANTES de lo que pasa;
 *   - con `parcial` y horizonte corto, publica un par de horas DESPUÉS.
 * Medido el 14/11/2026 (803 filas, validación temporal contra el trimestre
 * anterior): corregir la hora publicada por la mediana de su clase baja el MAE de
 * 1.237 a 1.095 min y sube ±3 h de 29,1 % a 31,1 %.
 *
 * La CLASE es `evidencia | tramo del horizonte PREDICHO` (no del real: el real no
 * existe al predecir). Con el horizonte real la corrección sería mucho mejor
 * (MAE 873) pero no se puede usar en producción.
 *
 * La tabla se mide con un backtest (contra el pasado ya cerrado) y se guarda en
 * `prediccion_config` como nueve claves enteras, porque esa tabla es clave/valor y
 * no hay migraciones en producción. Nada se aplica hasta apretar "Aplicar".
 */
final class Sesgo
{
    /** Muestra mínima por celda para creerle a su mediana. */
    public const MINIMO = 10;

    /**
     * Tramos del horizonte predicho, en minutos. `null` = sin tope.
     *
     * @var array<string, int|null>
     */
    public const TRAMOS = ['corto' => 360, 'medio' => 1440, 'largo' => null];

    /** Evidencias con las que se abre la tabla. */
    public const EVIDENCIAS = ['cadena', 'parcial', 'medido'];

    /** Celda de la fila de la tabla de aciertos: evidencia + tramo del horizonte. */
    public static function clase(string $evidencia, int $horizonteMin): string
    {
        $evidencia = in_array($evidencia, self::EVIDENCIAS, true) ? $evidencia : 'parcial';

        return $evidencia . '|' . self::tramo($horizonteMin);
    }

    /** Tramo al que pertenece un horizonte en minutos. */
    public static function tramo(int $horizonteMin): string
    {
        $horizonteMin = abs($horizonteMin);

        foreach (self::TRAMOS as $nombre => $tope) {
            if ($tope === null || $horizonteMin <= $tope) {
                return $nombre;
            }
        }

        return 'largo';
    }

    /** Las nueve clases, en el orden en que se muestran. */
    public static function clases(): array
    {
        $salida = [];
        foreach (self::EVIDENCIAS as $evidencia) {
            foreach (array_keys(self::TRAMOS) as $tramo) {
                $salida[] = $evidencia . '|' . $tramo;
            }
        }

        return $salida;
    }

    /** `cadena|largo` -> `sesgo_clase_cadena_largo` (clave en `prediccion_config`). */
    public static function clave(string $clase): string
    {
        return 'sesgo_clase_' . preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($clase));
    }

    /**
     * Las nueve claves con su valor por defecto (0), para `config/prediccion.php`.
     *
     * @return array<string, int>
     */
    public static function claves(): array
    {
        $salida = [];
        foreach (self::clases() as $clase) {
            $salida[self::clave($clase)] = 0;
        }

        return $salida;
    }

    /**
     * Mide el sesgo de cada clase contra el pasado ya cerrado.
     *
     * DOS FUENTES, y no dan lo mismo:
     *
     *  - `guardadas` (recomendada): las predicciones que hizo PRODUCCIÓN, con el
     *    ancla que usa producción (el último hito real). Su distribución es la que
     *    importa. Medido el 14/11/2026: mediana del error −34 min.
     *  - `aciertos`: el backtest, que fuerza el ancla en el `fin_descargue` del
     *    viaje anterior (peor caso). Su mediana es −137 min, o sea que se adelanta
     *    MÁS que producción: si se usa para corregir producción, corrige de más.
     *
     * Con menos de `$minimo` filas en la fuente pedida, devuelve error (no mezcla).
     *
     * @param  array{desde?: mixed, hasta?: mixed, limite?: int, fuente?: string}  $filtros
     * @return array{ok: bool, error: ?string, fuente: string, desde: string, hasta: string, n: int,
     *               celdas: array<int, array<string, mixed>>, evidencia: array<string, int>,
     *               global: int, aplicables: array<string, int>}
     */
    public static function medir(array $filtros = [], int $minimo = self::MINIMO): array
    {
        $desde = self::fecha($filtros['desde'] ?? null) ?? Carbon::now()->subDays(60)->startOfDay();
        $hasta = self::fecha($filtros['hasta'] ?? null, true) ?? Carbon::now()->endOfDay();
        $limite = (int) ($filtros['limite'] ?? 1000);
        $fuente = ($filtros['fuente'] ?? 'guardadas') === 'aciertos' ? 'aciertos' : 'guardadas';

        $salida = [
            'ok' => false,
            'error' => null,
            'fuente' => $fuente,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'n' => 0,
            'celdas' => [],
            'evidencia' => [],
            'global' => 0,
            'aplicables' => [],
        ];

        if ($fuente === 'guardadas') {
            $pares = self::erroresGuardados($desde, $hasta);
        } else {
            $pares = self::erroresBacktest($desde, $hasta, $limite, $salida);
            if ($pares === null) {
                return $salida;     // el backtest ya dejó el error en $salida
            }
        }

        $porClase = [];
        $porEvidencia = [];
        $todos = [];

        foreach ($pares as [$evidencia, $horizonte, $error]) {
            $clase = self::clase($evidencia, $horizonte);
            $porClase[$clase][] = $error;
            $porEvidencia[$evidencia][] = $error;
            $todos[] = $error;
        }

        $salida['n'] = count($todos);

        if ($salida['n'] < $minimo) {
            $salida['error'] = $fuente === 'guardadas'
                ? 'Hay ' . $salida['n'] . ' predicciones guardadas con hora real en el rango y el mínimo es '
                    . $minimo . ': usá un rango más largo, guardá más predicciones, o medí con la fuente "aciertos".'
                : 'La corrida tiene ' . $salida['n'] . ' filas y el mínimo es ' . $minimo
                    . ': alargá el rango antes de creerle a los sesgos.';

            return $salida;
        }

        foreach ($porEvidencia as $evidencia => $valores) {
            $mediana = Estadistica::mediana($valores);
            if ($mediana !== null) {
                $salida['evidencia'][$evidencia] = (int) round($mediana);
            }
        }

        $global = Estadistica::mediana($todos);
        $salida['global'] = (int) round((float) $global);

        foreach (self::clases() as $clase) {
            $valores = $porClase[$clase] ?? [];
            $evidencia = explode('|', $clase)[0];
            $propia = count($valores) >= $minimo ? Estadistica::mediana($valores) : null;
            $mediana = $propia ?? ($salida['evidencia'][$evidencia] ?? $salida['global']);

            $salida['celdas'][] = [
                'clase' => $clase,
                'evidencia' => $evidencia,
                'tramo' => explode('|', $clase)[1],
                'n' => count($valores),
                'mediana' => $propia === null ? null : (int) round($propia),
                'sesgo' => (int) round($mediana),
                'fuente' => $propia !== null ? 'propia' : (isset($salida['evidencia'][$evidencia]) ? 'evidencia' : 'global'),
            ];

            $salida['aplicables'][self::clave($clase)] = (int) round($mediana);
        }

        $salida['ok'] = true;

        return $salida;
    }

    /**
     * Errores de las predicciones que hizo PRODUCCIÓN (la fuente recomendada).
     *
     * El horizonte que se usa acá es el mismo que usa el motor: `presenta − corte`.
     *
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    private static function erroresGuardados(Carbon $desde, Carbon $hasta): array
    {
        try {
            $resumen = DBPrediccionResultados::resumen($desde, $hasta, 5000, null, 1);
        } catch (\Throwable $e) {
            return [];
        }

        $salida = [];
        foreach ($resumen['comparables'] ?? [] as $fila) {
            if ($fila->error_min === null || !$fila->predicho instanceof Carbon || !$fila->corte instanceof Carbon) {
                continue;
            }

            $horizonte = (int) round(($fila->predicho->getTimestamp() - $fila->corte->getTimestamp()) / 60);
            $salida[] = [(string) $fila->evidencia, $horizonte, (int) $fila->error_min];
        }

        return $salida;
    }

    /**
     * Errores del backtest (el ancla es siempre el fin de descargue: peor caso).
     *
     * @return array<int, array{0: string, 1: int, 2: int}>|null
     */
    private static function erroresBacktest(Carbon $desde, Carbon $hasta, int $limite, array &$salida): ?array
    {
        try {
            $corrida = Servicio::backtest([
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'limite' => $limite,
            ]);
        } catch (\Throwable $e) {
            $salida['error'] = 'No se pudo correr la tabla de aciertos: ' . $e->getMessage();

            return null;
        }

        $pares = [];
        foreach ($corrida->filas as $fila) {
            if ($fila->error_min === null || !$fila->predicho instanceof Carbon || !$fila->ancla instanceof Carbon) {
                continue;
            }

            $horizonte = (int) round(($fila->predicho->getTimestamp() - $fila->ancla->getTimestamp()) / 60);
            $pares[] = [(string) $fila->evidencia, $horizonte, (int) $fila->error_min];
        }

        return $pares;
    }

    /**
     * Cuántos minutos hay que corregir la hora publicada de esta fila.
     *
     * Devuelve 0 si el sesgo está apagado (`sesgo_clase_activo` = 0). El signo es
     * el de `error = predicho − real`: si la mediana fue −3.886, la hora publicada
     * venía 3.886 min ANTES de lo real y la fecha probable se corrige +3.886.
     */
    public static function desplazamiento(string $evidencia, int $horizonteMin, stdClass $p): int
    {
        if ((int) ($p->sesgo_clase_activo ?? 0) !== 1) {
            return 0;
        }

        $clave = self::clave(self::clase($evidencia, $horizonteMin));

        return (int) ($p->{$clave} ?? 0);
    }

    private static function fecha($valor, bool $fin = false): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $fin ? $valor->copy()->endOfDay() : $valor->copy()->startOfDay();
        }

        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        try {
            $carbon = Carbon::parse($valor);
        } catch (\Throwable $e) {
            return null;
        }

        return $fin ? $carbon->endOfDay() : $carbon->startOfDay();
    }
}
