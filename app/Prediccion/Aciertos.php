<?php

namespace App\Prediccion;

use stdClass;

/**
 * Métricas de acierto de una corrida de predicción: error medio absoluto, sesgo,
 * porcentajes dentro de tolerancias y comparación contra la línea base.
 *
 * Convención de signos: el error es `predicho - real`, así que un error positivo
 * significa que el motor se quedó corto (el camión llegó más tarde). El MAE y los
 * porcentajes usan el valor absoluto; la mediana y el sesgo usan el signo.
 *
 * Es puro: sólo aritmética sobre los errores que ya calculó el servicio.
 */
final class Aciertos
{
    /**
     * Tolerancias publicadas en minutos. Las dos últimas están porque el hueco
     * real mediano ronda las 19 h: con ±1 h y ±3 h solas la tabla no describe
     * nada.
     */
    public const TOLERANCIAS = [30, 60, 120, 180, 360, 720];

    /**
     * @param  array<int, int|float|null>  $erroresMin  error firmado por referencia (min)
     * @param  array<int, bool>            $dentroVentana  si la predicción cayó en su ventana p25-p75
     */
    public static function resumir(array $erroresMin, array $dentroVentana = []): stdClass
    {
        $errores = [];
        foreach ($erroresMin as $valor) {
            if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
                $errores[] = (int) round((float) $valor);
            }
        }

        $n = count($errores);
        $salida = (object) [
            'n' => $n,
            'mae_min' => null,
            'mediana_error_min' => null,
            'sesgo_min' => null,
            'pct_30' => null,
            'pct_60' => null,
            'pct_120' => null,
            'pct_180' => null,
            'pct_360' => null,
            'pct_720' => null,
            'pct_ventana' => null,
        ];

        if ($n === 0) {
            return $salida;
        }

        $absolutos = array_map(fn (int $error) => abs($error), $errores);

        $salida->mae_min = (int) round(array_sum($absolutos) / $n);
        $salida->mediana_error_min = (int) round((float) Estadistica::mediana($errores));
        $salida->sesgo_min = (int) round(array_sum($errores) / $n);

        foreach (self::TOLERANCIAS as $tolerancia) {
            $salida->{'pct_' . $tolerancia} = self::pct($absolutos, $tolerancia);
        }

        $resultado = self::pct($dentroVentana, true, $n);
        $salida->pct_ventana = $dentroVentana === [] ? null : $resultado;

        return $salida;
    }

    /** Compara motor contra línea base: gana quien tenga 30 min menos de MAE. */
    public static function comparar(stdClass $motor, stdClass $base): stdClass
    {
        $maeMotor = $motor->mae_min ?? null;
        $maeBase = $base->mae_min ?? null;
        $pctMotor = $motor->pct_60 ?? null;
        $pctBase = $base->pct_60 ?? null;

        $deltaMae = ($maeMotor !== null && $maeBase !== null) ? (int) $maeMotor - (int) $maeBase : null;
        $deltaPct = ($pctMotor !== null && $pctBase !== null) ? (int) $pctMotor - (int) $pctBase : null;

        $gana = 'empate';
        if ($deltaMae !== null) {
            if ($deltaMae <= -30) {
                $gana = 'motor';
            } elseif ($deltaMae >= 30) {
                $gana = 'base';
            }
        }

        return (object) [
            'delta_mae_min' => $deltaMae,
            'delta_pct_60' => $deltaPct,
            'gana' => $gana,
        ];
    }

    /**
     * Porcentaje de valores que cumplen la condición. Sin denominador explícito
     * se usa el tamaño de la lista.
     *
     * @param  array<int, int|float|bool>  $valores
     * @param  int|bool  $limite  tolerancia en minutos (o el valor a contar)
     */
    private static function pct(array $valores, $limite, ?int $total = null): ?int
    {
        $total = $total ?? count($valores);
        if ($total <= 0) {
            return null;
        }

        $cumplen = 0;
        foreach ($valores as $valor) {
            if (is_bool($valor) || is_bool($limite)) {
                if ((bool) $valor === (bool) $limite) {
                    $cumplen++;
                }
                continue;
            }
            if (abs((float) $valor) <= $limite) {
                $cumplen++;
            }
        }

        return (int) round(100 * $cumplen / $total);
    }
}
