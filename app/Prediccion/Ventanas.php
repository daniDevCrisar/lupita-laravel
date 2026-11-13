<?php

namespace App\Prediccion;

use Carbon\Carbon;
use stdClass;

/**
 * Ventanas de no-actividad del camión, inferidas de sus propios intervalos entre
 * hitos, y el desplazamiento de una predicción que cae dentro de una de ellas.
 *
 * Es la respuesta a "el conductor duerme y almuerza": nadie lo configura, se
 * deduce de la fracción de tiempo que el camión está haciendo algo en cada
 * franja del día. Con poca historia el llamador usa el perfil global de la
 * flota; si tampoco hay, los valores de `config/prediccion.php`.
 *
 * Las ventanas se expresan en minutos del día (0..1440). Una ventana que cruza
 * medianoche queda partida en dos: [22:00, 24:00] y [00:00, 06:00].
 *
 * Es puro: no consulta nada ni usa el reloj.
 */
final class Ventanas
{
    public const MINUTOS_DIA = 1440;

    /**
     * Franjas del día en las que el camión casi nunca está activo.
     *
     * Cobertura de una franja = minutos cubiertos por los intervalos / (días × granularidad).
     * Sólo se considera inactiva si NO llega al umbral (por defecto 10 %).
     *
     * @param  array<int, object>  $intervalos  cada uno con ->desde y ->fin (Carbon)
     * @return array<int, array{desde: int, hasta: int}>
     */
    public static function inactivas(array $intervalos, Carbon $desde, Carbon $hasta,
                                     float $umbral = 0.10, int $granularidad = 30): array
    {
        $granularidad = max(5, min(120, $granularidad));
        $franjas = intdiv(self::MINUTOS_DIA, $granularidad);
        $inicio = $desde->copy();
        $fin = $hasta->copy();

        $dias = max(1, (int) round($inicio->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay())) + 1);
        $cubierto = array_fill(0, $franjas, 0.0);

        foreach ($intervalos as $intervalo) {
            $desdeIntervalo = self::carbon($intervalo->desde ?? null);
            $finIntervalo = self::carbon($intervalo->fin ?? null);
            if ($desdeIntervalo === null || $finIntervalo === null) {
                continue;
            }

            $cursor = $desdeIntervalo->lessThan($inicio) ? $inicio->copy() : $desdeIntervalo->copy();
            $tope = $finIntervalo->greaterThan($fin) ? $fin->copy() : $finIntervalo->copy();
            if ($cursor->greaterThanOrEqualTo($tope)) {
                continue;
            }

            // se parte por día para no contar dos veces el mismo reloj
            while ($cursor->lessThan($tope)) {
                $finDia = $cursor->copy()->endOfDay();
                $corte = $finDia->lessThan($tope) ? $finDia : $tope->copy();

                $minutoInicio = (int) $cursor->format('G') * 60 + (int) $cursor->format('i');
                $minutoFin = $minutoInicio + (int) round($cursor->diffInSeconds($corte) / 60);
                self::sumar($cubierto, $minutoInicio, min(self::MINUTOS_DIA, $minutoFin), $granularidad);

                $cursor = $corte->copy()->addSecond();
            }
        }

        $salida = [];
        $abierta = null;
        for ($franja = 0; $franja < $franjas; $franja++) {
            $cobertura = $cubierto[$franja] / ($dias * $granularidad);
            $esInactiva = $cobertura <= $umbral;

            if ($esInactiva) {
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
            $desde = (int) ($ventana['desde'] ?? 0);
            $hasta = (int) ($ventana['hasta'] ?? 0);

            if ($minuto >= $desde && $minuto < $hasta) {
                return ['desde' => $desde, 'hasta' => $hasta];
            }
        }

        return null;
    }

    /** Suma los minutos de un segmento del día a las franjas que toca. */
    private static function sumar(array &$cubierto, int $minutoInicio, int $minutoFin, int $granularidad): void
    {
        if ($minutoFin <= $minutoInicio) {
            return;
        }

        $primera = intdiv(max(0, $minutoInicio), $granularidad);
        $ultima = intdiv(min(self::MINUTOS_DIA - 1, $minutoFin - 1), $granularidad);

        for ($franja = $primera; $franja <= $ultima; $franja++) {
            if (!isset($cubierto[$franja])) {
                continue;
            }
            $inicioFranja = $franja * $granularidad;
            $finFranja = $inicioFranja + $granularidad;
            $cubierto[$franja] += max(0, min($minutoFin, $finFranja) - max($minutoInicio, $inicioFranja));
        }
    }

    private static function carbon($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
