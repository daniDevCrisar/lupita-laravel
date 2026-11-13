<?php

namespace App\Prediccion;

use InvalidArgumentException;
use stdClass;

/**
 * Calibración del motor: qué parámetros usar para que prediga mejor.
 *
 * La idea es simple y no tiene magia: se corre la MISMA tabla de aciertos
 * (Servicio::backtest) con distintas combinaciones de parámetros sobre una
 * ventana de ENTRENAMIENTO y se elige la que mejor mide; después se comprueba
 * contra una ventana de VALIDACIÓN que el usuario no vio (el final del tramo),
 * porque optimizar contra el mismo tramo en que se mide siempre gana en falso.
 *
 * Es puro: no toca base de datos ni el reloj. El comando
 * `prediccion:calibrar` es el que orquesta (ver routes/console.php).
 */
final class Calibracion
{
    /**
     * Rejilla por defecto: las claves que más mueven el error, con un rango
     * razonable alrededor del valor que trae la configuración.
     *
     * 3x3x3 = 27 corridas. Cada corrida cuesta lo mismo que abrir la tabla de
     * aciertos, así que la rejilla por defecto está pensada para terminar en
     * minutos, no en horas.
     */
    public const REJILLA = [
        'pares_recientes_max' => [10, 20, 40],
        'min_pares_ruta' => [2, 3, 5],
        'bins_horarios' => [4, 8, 12],
    ];

    /**
     * Métricas que se pueden optimizar.
     *
     * 'menor' dice para dónde se optimiza: el MAE cuanto más bajo mejor, los
     * porcentajes cuanto más altos mejor.
     *
     * @var array<string, array{campo: string, menor: bool, etiqueta: string}>
     */
    public const METRICAS = [
        'mae' => ['campo' => 'mae_min', 'menor' => true, 'etiqueta' => 'error medio absoluto'],
        'pct_1h' => ['campo' => 'pct_60', 'menor' => false, 'etiqueta' => 'aciertos a ±1 h'],
        'pct_3h' => ['campo' => 'pct_180', 'menor' => false, 'etiqueta' => 'aciertos a ±3 h'],
        'pct_ventana' => ['campo' => 'pct_ventana', 'menor' => false, 'etiqueta' => 'real dentro de rápido–tardado'],
    ];

    /** Tope de combinaciones: más que esto es un error de configuración, no una corrida. */
    public const MAXIMO_COMBINACIONES = 500;

    /**
     * Producto cartesiano de la rejilla, en orden estable.
     *
     * @param  array<string, array<int, int|float>>  $rejilla
     * @return array<int, array<string, int|float>> cada combinación es clave => valor
     */
    public static function combinaciones(array $rejilla, int $maximo = self::MAXIMO_COMBINACIONES): array
    {
        $claves = array_keys($rejilla);
        $salida = [[]];

        foreach ($claves as $clave) {
            $valores = array_values((array) $rejilla[$clave]);
            if ($valores === []) {
                continue;
            }

            $nuevas = [];
            foreach ($salida as $parcial) {
                foreach ($valores as $valor) {
                    $nuevas[] = $parcial + [$clave => $valor];
                }
            }
            $salida = $nuevas;
        }

        if (count($salida) > $maximo) {
            throw new InvalidArgumentException('La rejilla tiene ' . count($salida)
                . ' combinaciones y el tope es ' . $maximo . ': quitá valores o usá --rejilla.');
        }

        return $salida;
    }

    /**
     * Copia de la configuración con los valores de una combinación.
     *
     * No muta `$base`: la calibración necesita comparar variantes del mismo
     * punto de partida.
     */
    public static function con(stdClass $base, array $valores): stdClass
    {
        $copia = clone $base;

        foreach ($valores as $clave => $valor) {
            if (!property_exists($copia, (string) $clave)) {
                continue;
            }

            // las claves que el motor trata como float se guardan como float
            $copia->{$clave} = in_array((string) $clave, ['spread_cadena'], true)
                ? (float) $valor
                : (int) $valor;
        }

        return $copia;
    }

    /** Valor crudo de la métrica en una corrida (null si esa corrida no mide nada). */
    public static function valor(stdClass $resumen, string $metrica): ?float
    {
        if (!isset(self::METRICAS[$metrica])) {
            throw new InvalidArgumentException('Métrica desconocida: ' . $metrica
                . '. Usá: ' . implode(', ', array_keys(self::METRICAS)));
        }

        $campo = self::METRICAS[$metrica]['campo'];
        $valor = $resumen->{$campo} ?? null;

        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * Puntaje comparable: SIEMPRE menor es mejor.
     *
     * Una corrida sin medición (cero filas) devuelve INF, que nunca gana.
     */
    public static function puntaje(stdClass $resumen, string $metrica): float
    {
        $valor = self::valor($resumen, $metrica);
        if ($valor === null) {
            return INF;
        }

        return self::METRICAS[$metrica]['menor'] ? $valor : 100.0 - $valor;
    }

    /**
     * El mejor resultado de la lista.
     *
     * `$validacion` elige con qué resumen se puntúa: cuando hay ventana de
     * validación se puntúa con ESA (es la que el usuario no vio); si ninguna
     * corrida tiene validación, se cae al entrenamiento en vez de no elegir nada.
     *
     * Los porcentajes son enteros y empatan seguido (tres combinaciones con 20 %
     * a ±3 h): en el empate gana el que tenga MEJOR MAE, y después el que tenga
     * más filas. Sin ese desempate el "ganador" podía ser el peor en error medio.
     *
     * `$nMinimo` descarta corridas con pocas filas: con n=3 cualquier parámetro
     * “gana” por suerte.
     *
     * @param  array<int, stdClass>  $resultados  cada uno con ->entrenamiento y ->validacion
     */
    public static function mejor(array $resultados, string $metrica, bool $validacion = true,
                                int $nMinimo = 20): ?stdClass
    {
        $hayValidacion = false;
        if ($validacion) {
            foreach ($resultados as $resultado) {
                if (($resultado->validacion ?? null) !== null) {
                    $hayValidacion = true;
                    break;
                }
            }
        }
        $usarValidacion = $validacion && $hayValidacion;

        $mejor = null;
        $mejorPuntaje = INF;
        $mejorMae = INF;
        $mejorN = -1;

        foreach ($resultados as $resultado) {
            $resumen = $usarValidacion
                ? ($resultado->validacion ?? null)
                : ($resultado->entrenamiento ?? null);

            if ($resumen === null || (int) ($resumen->n ?? 0) < $nMinimo) {
                continue;
            }

            $puntaje = self::puntaje($resumen, $metrica);
            if ($puntaje === INF) {
                continue;
            }

            $n = (int) ($resumen->n ?? 0);
            $mae = is_numeric($resumen->mae_min ?? null) ? (float) $resumen->mae_min : INF;

            $gana = $mejor === null
                || $puntaje < $mejorPuntaje
                || ($puntaje === $mejorPuntaje && $mae < $mejorMae)
                || ($puntaje === $mejorPuntaje && $mae === $mejorMae && $n > $mejorN);

            if ($gana) {
                $mejor = $resultado;
                $mejorPuntaje = $puntaje;
                $mejorMae = $mae;
                $mejorN = $n;
            }
        }

        return $mejor;
    }

    /** "pares_recientes_max=10 · min_pares_ruta=3 · bins_horarios=8" */
    public static function etiqueta(array $valores): string
    {
        $partes = [];
        foreach ($valores as $clave => $valor) {
            $partes[] = $clave . '=' . (is_float($valor) ? rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') : $valor);
        }

        return implode(' · ', $partes);
    }
}
