<?php

namespace App\Prediccion;

use Carbon\Carbon;
use stdClass;

/**
 * Ventanas de no-actividad del camión y desplazamiento de una predicción que cae
 * dentro de una de ellas.
 *
 * La pregunta que responde es "¿a esta hora el camión nunca hace nada?" — o sea
 * dónde duerme y cuándo almuerza, sin que nadie lo configure. Se mide con la
 * densidad de HITOS por franja del día: una franja donde en todo el lookback
 * hubo menos de `minHitos` marcas es una franja muerta.
 *
 * Ojo: no se mide "tiempo cubierto por intervalos". El hueco entre un fin de
 * descargue y la presentación siguiente también es un intervalo, y contarlo como
 * actividad hace que el descanso sea invisible (fue el primer error de este
 * archivo).
 *
 * Las ventanas se expresan en minutos del día (0..1440). Una que cruza
 * medianoche queda partida en dos: [22:00, 24:00] y [00:00, 06:00].
 *
 * Es puro: no consulta nada ni usa el reloj.
 */
final class Ventanas
{
    public const MINUTOS_DIA = 1440;

    /**
     * Franjas del día sin actividad del camión.
     *
     * @param  array<int, Carbon|string>  $hitos  marcas horarias del camión en el lookback
     * @return array<int, array{desde: int, hasta: int}>
     */
    public static function inactivas(array $hitos, Carbon $desde, Carbon $hasta,
                                     int $minHitos = 2, int $granularidad = 30): array
    {
        $granularidad = max(5, min(120, $granularidad));
        $franjas = intdiv(self::MINUTOS_DIA, $granularidad);
        $conteo = array_fill(0, $franjas, 0);

        $inicio = $desde->copy();
        $fin = $hasta->copy();
        $registrados = 0;

        foreach ($hitos as $hito) {
            $fecha = $hito instanceof Carbon ? $hito : (is_string($hito) && $hito !== '' ? Carbon::parse($hito) : null);
            if ($fecha === null || $fecha->lessThan($inicio) || $fecha->greaterThan($fin)) {
                continue;
            }

            $minuto = (int) $fecha->format('G') * 60 + (int) $fecha->format('i');
            $franja = intdiv($minuto, $granularidad);
            if (isset($conteo[$franja])) {
                $conteo[$franja]++;
                $registrados++;
            }
        }

        if ($registrados === 0) {
            return [];
        }

        $salida = [];
        $abierta = null;
        for ($franja = 0; $franja < $franjas; $franja++) {
            $muerta = $conteo[$franja] < max(1, $minHitos);

            if ($muerta) {
                if ($abierta === null) {
                    $abierta = $franja * $granularidad;
                }
                continue;
            }

            if ($abierta !== null) {
                $salida[] = ['desde' => $abierta, 'hasta' => $franja * $granularidad];
                $abierta = null;
            }
        }
        if ($abierta !== null) {
            $salida[] = ['desde' => $abierta, 'hasta' => self::MINUTOS_DIA];
        }

        return $salida;
    }

    /**
     * Mueve una fecha al final de la ventana inactiva en la que cayó.
     *
     * Si al salir de una ventana cae en otra, se repite (máximo 3 pasadas) y
     * nunca se desplaza más de `$maxMin` minutos: al llegar al tope se devuelve
     * lo caminado con un aviso, para que la pantalla lo pueda decir.
     */
    public static function desplazar(Carbon $t, array $ventanas, int $maxMin = 600): stdClass
    {
        $fecha = $t->copy();
        $total = 0;

        for ($pasada = 0; $pasada < 3; $pasada++) {
            $minuto = (int) $fecha->format('G') * 60 + (int) $fecha->format('i');
            $ventana = self::ventanaDe($minuto, $ventanas);
            if ($ventana === null) {
                return (object) ['fecha' => $fecha, 'min' => $total, 'aviso' => null];
            }

            $faltan = $ventana['hasta'] - $minuto;
            if ($faltan <= 0) {
                return (object) ['fecha' => $fecha, 'min' => $total, 'aviso' => null];
            }

            $restante = $maxMin - $total;
            if ($restante <= 0) {
                return (object) ['fecha' => $fecha, 'min' => $total, 'aviso' => 'desplazamiento_maximo'];
            }

            if ($faltan > $restante) {
                $fecha->addMinutes($restante);

                return (object) ['fecha' => $fecha, 'min' => $total + $restante, 'aviso' => 'desplazamiento_maximo'];
            }

            $fecha->addMinutes($faltan);
            $total += $faltan;
        }

        return (object) ['fecha' => $fecha, 'min' => $total, 'aviso' => 'desplazamiento_maximo'];
    }

    /**
     * Ventana que contiene el minuto del día, o NULL. Un límite `hasta` de 1440
     * significa "hasta la medianoche", así que el minuto 1439 todavía entra.
     */
    public static function ventanaDe(int $minuto, array $ventanas): ?array
    {
        foreach ($ventanas as $ventana) {
            if (!is_array($ventana) || !isset($ventana['desde'], $ventana['hasta'])) {
                continue;
            }

            $desde = (int) $ventana['desde'];
            $hasta = (int) $ventana['hasta'];

            if ($minuto >= $desde && $minuto < $hasta) {
                return ['desde' => $desde, 'hasta' => $hasta];
            }
        }

        return null;
    }

    /** Cuántos minutos del día quedan cubiertos por las ventanas. */
    public static function minutosInactivos(array $ventanas): int
    {
        $total = 0;
        foreach ($ventanas as $ventana) {
            if (is_array($ventana) && isset($ventana['desde'], $ventana['hasta'])) {
                $total += max(0, (int) $ventana['hasta'] - (int) $ventana['desde']);
            }
        }

        return $total;
    }
}
