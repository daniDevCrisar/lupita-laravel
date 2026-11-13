<?php

namespace App\Prediccion;

/**
 * Estadística mínima del motor de predicción: mediana, cuantiles, rango
 * intercuartílico, desviación y el bin horario con el que se agrupa la
 * corrección por hora del día.
 *
 * Es puro: no toca la base ni el reloj. Cada función ordena una copia, así que
 * el arreglo que recibe nunca se modifica. Los valores no numéricos se ignoran;
 * si no queda ninguno, la respuesta es NULL en vez de un cero que mienta.
 */
final class Estadistica
{
    /** Mediana de los valores (NULL si la lista queda vacía). */
    public static function mediana(array $valores): ?float
    {
        $valores = self::numeros($valores);
        $n = count($valores);
        if ($n === 0) {
            return null;
        }

        sort($valores, SORT_NUMERIC);
        $medio = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $valores[$medio]
            : ((float) $valores[$medio - 1] + (float) $valores[$medio]) / 2;
    }

    /**
     * Cuantil con interpolación lineal entre valores ordenados.
     *
     * @param  float  $p  posición entre 0 y 1 (se acota)
     */
    public static function cuantil(array $valores, float $p): ?float
    {
        $valores = self::numeros($valores);
        $n = count($valores);
        if ($n === 0) {
            return null;
        }

        sort($valores, SORT_NUMERIC);
        $posicion = ($n - 1) * max(0.0, min(1.0, $p));
        $bajo = (int) floor($posicion);
        $alto = (int) ceil($posicion);

        if ($bajo === $alto) {
            return (float) $valores[$bajo];
        }

        return (float) $valores[$bajo]
            + ($posicion - $bajo) * ((float) $valores[$alto] - (float) $valores[$bajo]);
    }

    /** Rango intercuartílico (p75 - p25): el ancho útil de una predicción. */
    public static function iqr(array $valores): ?float
    {
        $p25 = self::cuantil($valores, 0.25);
        $p75 = self::cuantil($valores, 0.75);

        return ($p25 === null || $p75 === null) ? null : $p75 - $p25;
    }

    /** Desviación estándar poblacional. */
    public static function desviacion(array $valores): ?float
    {
        $valores = self::numeros($valores);
        $n = count($valores);
        if ($n === 0) {
            return null;
        }

        $media = array_sum($valores) / $n;
        $suma = 0.0;
        foreach ($valores as $valor) {
            $suma += ($valor - $media) ** 2;
        }

        return sqrt($suma / $n);
    }

    /**
     * Franja del día (0..bins-1) a la que pertenece una hora. Con el valor por
     * defecto son 8 franjas de 3 horas: la corrección por hora del ancla.
     */
    public static function bin_horario(int $hora, int $bins = 8): int
    {
        $bins = max(1, min(24, $bins));
        $ancho = intdiv(24, $bins);
        $hora = (($hora % 24) + 24) % 24;

        return intdiv($hora, max(1, $ancho));
    }

    /**
     * Deja sólo números (acepta strings numéricos de MySQL) como float.
     *
     * @return array<int, float>
     */
    private static function numeros(array $valores): array
    {
        $salida = [];
        foreach ($valores as $valor) {
            if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
                $salida[] = (float) $valor;
            }
        }

        return $salida;
    }
}
