<?php

namespace App\Prediccion;

use Carbon\Carbon;

/**
 * Tablero del motor: convierte filas YA calculadas (comparables de producción,
 * corridas del backtest, compromisos del monitorista) en los números que se miran
 * en pantalla.
 *
 * Es puro: no toca la base, no lee el reloj y no predice nada. Recibe listas de
 * objetos con sus minutos y devuelve arreglos listos para dibujar, así que se
 * puede probar sin base de datos.
 *
 * Convención de signos:
 *  - el error del motor es `predicho − real`: positivo = el motor se quedó corto
 *    y el camión presentó más tarde de lo que se dijo;
 *  - el error del monitorista es `real − compromiso`: positivo = presentó después
 *    de lo que prometió.
 * El cara a cara compara valores ABSOLUTOS, así que el signo no favorece a
 * ninguno de los dos.
 */
final class Tablero
{
    /** Debajo de esta muestra un porcentaje es anécdota, no medición. */
    public const MUESTRA_MINIMA = 30;

    /**
     * Un compromiso que se aparta más de esto de la presentación real está mal
     * cargado (hay `compromiso_carga` con fechas de meses después, o del año
     * cero). Se descarta en vez de arrastrar el MAE. Es el mismo horizonte que el
     * motor considera fuera de dominio (`tope_hueco_min` = 7 días).
     */
    public const TOPE_COMPROMISO_MIN = 10080;

    /** Estratos de la confianza declarada por el motor (0..100). */
    public const TRAMOS_CONFIANZA = [[0, 20], [21, 40], [41, 70], [71, 95], [96, 100]];

    /**
     * Estratos del horizonte: cuánto tiempo ANTES del hecho se dijo la hora.
     * Cada tramo es [etiqueta, desde minutos, hasta minutos o null = sin tope].
     */
    public const TRAMOS_HORIZONTE = [
        ['hasta 1 h', 0, 60],
        ['1 a 6 h', 60, 360],
        ['6 a 24 h', 360, 1440],
        ['más de 24 h', 1440, null],
    ];

    /**
     * Estadística del error firmado y de su valor absoluto.
     *
     * `mae` y los cuantiles salen del valor absoluto; `sesgo` y `error_mediano`
     * conservan el signo para que se vea de qué lado se equivoca el motor.
     *
     * @param  array<int, mixed>  $valores  error en minutos (se ignoran los no numéricos)
     * @return array<string, mixed>
     */
    public static function errores(array $valores, int $tolerancia = 180): array
    {
        $errores = self::minutos($valores);
        $n = count($errores);

        $salida = [
            'n' => $n,
            'tolerancia' => $tolerancia,
            'mae' => null,
            'sesgo' => null,
            'error_mediano' => null,
            'p50' => null,
            'p75' => null,
            'p90' => null,
            'tope' => null,
            'adelanto' => 0,
            'atraso' => 0,
            'pct_tolerancia' => null,
        ];

        // la escalera de tolerancias (±30m · ±1h · ±2h · ±3h · ±6h · ±12h): es la
        // misma que muestra la tabla de aciertos, para poder comparar las dos varas
        foreach (Aciertos::TOLERANCIAS as $minutos) {
            $salida['pct_' . $minutos] = null;
        }

        if ($n === 0) {
            return $salida;
        }

        $absolutos = array_map(static fn (int $error) => abs($error), $errores);

        $salida['mae'] = (int) round(array_sum($absolutos) / $n);
        $salida['sesgo'] = (int) round(array_sum($errores) / $n);
        $salida['error_mediano'] = (int) round((float) Estadistica::mediana($errores));
        $salida['p50'] = (int) round((float) Estadistica::cuantil($absolutos, 0.5));
        $salida['p75'] = (int) round((float) Estadistica::cuantil($absolutos, 0.75));
        $salida['p90'] = (int) round((float) Estadistica::cuantil($absolutos, 0.90));
        $salida['tope'] = max($absolutos);
        $salida['adelanto'] = count(array_filter($errores, static fn (int $error) => $error < 0));
        $salida['atraso'] = count(array_filter($errores, static fn (int $error) => $error > 0));
        $salida['pct_tolerancia'] = self::pct(
            count(array_filter($absolutos, static fn (int $error) => $error <= $tolerancia)), $n);

        foreach (Aciertos::TOLERANCIAS as $minutos) {
            $salida['pct_' . $minutos] = self::pct(
                count(array_filter($absolutos, static fn (int $error) => $error <= $minutos)), $n);
        }

        return $salida;
    }

    /**
     * Agrupa las filas por una clave y resume cada grupo.
     *
     * Las filas sin `error_min` no cuentan (no hay con qué comparar). `$orden`
     * fija el orden de los grupos; los que no estén en esa lista van al final.
     *
     * @param  array<int, object>  $filas
     * @param  callable(object): string  $clave
     * @param  array<int, string>  $orden
     * @return array<int, array<string, mixed>>
     */
    public static function agrupar(array $filas, callable $clave, array $orden = [],
                                   int $tolerancia = 180, string $campoVentana = 'en_ventana'): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            if (!is_object($fila) || !isset($fila->error_min)) {
                continue;
            }

            $nombre = (string) $clave($fila);
            $grupos[$nombre]['errores'][] = $fila->error_min;
            $grupos[$nombre]['dentro'][] = self::dentro($fila, $campoVentana);
        }

        $claves = array_keys($grupos);
        $claves = array_merge(
            array_values(array_filter($orden, static fn (string $valor) => in_array($valor, $claves, true))),
            array_values(array_filter($claves, static fn (string $valor) => !in_array($valor, $orden, true)))
        );

        $salida = [];
        foreach ($claves as $nombre) {
            $salida[] = self::resumen_grupo($nombre, $grupos[$nombre]['errores'],
                $grupos[$nombre]['dentro'], $tolerancia);
        }

        return $salida;
    }

    /** Cómo se equívoca según la evidencia con la que se predijo. */
    public static function por_evidencia(array $filas, int $tolerancia = 180): array
    {
        return self::agrupar($filas, static fn ($fila) => (string) ($fila->evidencia ?? 'sin_datos'),
            ['medido', 'parcial', 'cadena', 'sin_datos'], $tolerancia);
    }

    /** Cómo se equívoca según quién pidió la predicción. */
    public static function por_origen(array $filas, int $tolerancia = 180): array
    {
        return self::agrupar($filas, static fn ($fila) => (string) ($fila->origen ?? '—'),
            ['api', 'aciertos', 'pantalla'], $tolerancia);
    }

    /**
     * ¿El número de confianza que publica el motor sirve? Se agrupa por estrato de
     * confianza: si el error no baja al subir la confianza, el número no informa.
     */
    public static function por_confianza(array $filas, int $tolerancia = 180): array
    {
        $etiquetas = [];
        foreach (self::TRAMOS_CONFIANZA as [$desde, $hasta]) {
            $etiquetas[] = $desde . '–' . $hasta;
        }

        return self::agrupar($filas, static function ($fila) {
            $confianza = max(0, min(100, (int) ($fila->confianza ?? 0)));
            foreach (self::TRAMOS_CONFIANZA as [$desde, $hasta]) {
                if ($confianza >= $desde && $confianza <= $hasta) {
                    return $desde . '–' . $hasta;
                }
            }

            return '96–100';
        }, $etiquetas, $tolerancia);
    }

    /**
     * Cómo se equívoca según cuánto tiempo ANTES del hecho se dijo la hora. Es la
     * curva de confianza del modelo: a más horizonte, más error.
     *
     * @param  string  $campoDesde  campo con el corte (producción: `corte`; backtest: `ancla`)
     */
    public static function por_horizonte(array $filas, int $tolerancia = 180,
                                         string $campoDesde = 'corte', string $campoVentana = 'en_ventana'): array
    {
        $etiquetas = array_column(self::TRAMOS_HORIZONTE, 0);

        return self::agrupar($filas, static function ($fila) use ($campoDesde) {
            $desde = self::fecha($fila->{$campoDesde} ?? null);
            $hasta = self::fecha($fila->real ?? null);
            if ($desde === null || $hasta === null) {
                return 'sin fecha';
            }

            $horizonte = ($hasta->getTimestamp() - $desde->getTimestamp()) / 60;
            foreach (self::TRAMOS_HORIZONTE as [$etiqueta, $minimo, $maximo]) {
                if ($horizonte >= $minimo && ($maximo === null || $horizonte < $maximo)) {
                    return $etiqueta;
                }
            }

            return $horizonte < 0 ? 'corte posterior' : 'más de 24 h';
        }, $etiquetas, $tolerancia, $campoVentana);
    }

    /**
     * ¿Dónde cayó la presentación REAL dentro de rápido–normal–tardado? Sirve para
     * ver si la ventana está corrida: muchas presentaciones antes de la fecha
     * rápida significan que el motor se fue para atrás.
     *
     * @return array<string, mixed>
     */
    public static function posicion(array $filas): array
    {
        $salida = ['n' => 0, 'antes_rapido' => 0, 'primera_mitad' => 0,
                   'segunda_mitad' => 0, 'despues_tardado' => 0];

        foreach ($filas as $fila) {
            $rapido = self::fecha($fila->rapido ?? null);
            $predicho = self::fecha($fila->predicho ?? null);
            $tardado = self::fecha($fila->tardado ?? null);
            $real = self::fecha($fila->real ?? null);

            if ($rapido === null || $predicho === null || $tardado === null || $real === null) {
                continue;
            }

            $salida['n']++;

            if ($real->lessThan($rapido)) {
                $salida['antes_rapido']++;
            } elseif ($real->lessThan($predicho)) {
                $salida['primera_mitad']++;
            } elseif ($real->lessThanOrEqualTo($tardado)) {
                $salida['segunda_mitad']++;
            } else {
                $salida['despues_tardado']++;
            }
        }

        return $salida;
    }

    /**
     * Las CUATRO fechas publicadas, comparadas contra la presentación real.
     *
     * Cada fecha se mide con su error (`fecha − real`) y con la misma escalera de
     * tolerancias, así se ve de un vistazo cuál conviene. También cuenta cuántas
     * veces cada una queda más cerca que las otras: ganar en promedio y ganar en
     * cantidad de veces son cosas distintas (el `tardado` se lleva los casos de
     * error enorme, la `probable` los normales).
     *
     * @param  array<int, object>  $filas  filas de DBPrediccionResultados::resumen()
     * @return array{filas: array<int, array<string, mixed>>, n: int}
     */
    public static function fechas(array $filas, int $tolerancia = 180): array
    {
        $fechas = [
            'rapido' => ['etiqueta' => 'Rápido', 'campo' => 'error_rapido_min',
                         'texto' => 'el extremo temprano de la banda'],
            'normal' => ['etiqueta' => 'Normal', 'campo' => 'error_min',
                         'texto' => 'la hora publicada por el motor'],
            'tardado' => ['etiqueta' => 'Tardado', 'campo' => 'error_tardado_min',
                          'texto' => 'el extremo tardío de la banda'],
            'probable' => ['etiqueta' => 'Más probable', 'campo' => 'error_probable_min',
                           'texto' => 'la normal corregida por el sesgo de su clase'],
        ];

        $valores = array_fill_keys(array_keys($fechas), []);
        $masCerca = array_fill_keys(array_keys($fechas), 0);
        $n = 0;

        foreach ($filas as $fila) {
            $error = [];

            foreach ($fechas as $clave => $info) {
                $valor = $fila->{$info['campo']} ?? null;
                if ($valor === null) {
                    continue;
                }

                $valor = (int) $valor;
                $error[$clave] = $valor;
                $valores[$clave][] = $valor;
            }

            if ($error === []) {
                continue;
            }

            $n++;
            $minimo = min(array_map('abs', $error));
            foreach ($error as $clave => $valor) {
                if (abs($valor) === $minimo) {
                    $masCerca[$clave]++;

                    break;      // empate: se lo queda el primero del orden
                }
            }
        }

        $salida = [];
        foreach ($fechas as $clave => $info) {
            $base = self::errores($valores[$clave], $tolerancia);
            $base['clave'] = $clave;
            $base['etiqueta'] = $info['etiqueta'];
            $base['texto'] = $info['texto'];
            $base['mas_cerca'] = $masCerca[$clave];
            $base['pct_mas_cerca'] = $n > 0 ? self::pct($masCerca[$clave], $n) : null;
            $salida[] = $base;
        }

        return ['filas' => $salida, 'n' => $n];
    }

    /**
     * Acierto del MONITORISTA: `compromiso_carga` (la hora que promete a mano)
     * contra la presentación real. Es la vara contra la que se mira el motor.
     *
     * @param  array<int, object>  $pares  objetos con `compromiso`, `real` y opcional `despacho`
     * @return array<string, mixed>
     */
    public static function monitorista(array $pares, int $tolerancia = 180): array
    {
        $errores = [];
        $antelaciones = [];
        $cumple = 0;
        $tarde = 0;

        foreach ($pares as $par) {
            $compromiso = self::fecha($par->compromiso ?? null);
            $real = self::fecha($par->real ?? null);
            if ($compromiso === null || $real === null) {
                continue;
            }

            $error = self::diferencia($real, $compromiso);
            $errores[] = $error;

            if ($error <= 0) {
                $cumple++;         // presentó antes (o justo) de lo prometido
            } else {
                $tarde++;
            }

            $despacho = self::fecha($par->despacho ?? null);
            if ($despacho !== null) {
                $antelaciones[] = self::diferencia($compromiso, $despacho);
            }
        }

        $base = self::errores($errores, $tolerancia);
        $antelacion = Estadistica::mediana($antelaciones);

        return array_merge($base, [
            'cumple' => $cumple,
            'tarde' => $tarde,
            'pct_cumple' => $base['n'] > 0 ? self::pct($cumple, $base['n']) : null,
            'antelacion_min' => $antelacion === null ? null : (int) round($antelacion),
        ]);
    }

    /**
     * Cara a cara motor vs monitorista sobre las MISMAS referencias.
     *
     * Ojo al leerlo: no compiten con la misma información. El compromiso se escribe
     * en el despacho (días antes) y el motor predice desde el último hito real
     * (horas antes). Por eso el tablero informa también la antelación de cada uno.
     *
     * @param  array<string, mixed>  $errorMotor  ref => error firmado del motor
     * @param  array<string, mixed>  $errorMonitorista  ref => error firmado del compromiso
     * @return array<string, mixed>
     */
    public static function cara_a_cara(array $errorMotor, array $errorMonitorista): array
    {
        $motor = [];
        $monitorista = [];
        $ganaMotor = 0;
        $ganaMonitorista = 0;
        $empates = 0;

        foreach ($errorMotor as $ref => $error) {
            if (!array_key_exists($ref, $errorMonitorista)) {
                continue;
            }

            $a = abs((int) $error);
            $b = abs((int) $errorMonitorista[$ref]);
            $motor[] = $a;
            $monitorista[] = $b;

            if ($a < $b) {
                $ganaMotor++;
            } elseif ($b < $a) {
                $ganaMonitorista++;
            } else {
                $empates++;
            }
        }

        $n = count($motor);
        $mae = static fn (array $valores) => $valores === []
            ? null
            : (int) round(array_sum($valores) / count($valores));

        return [
            'n' => $n,
            'mae_motor' => $mae($motor),
            'mae_monitorista' => $mae($monitorista),
            'gana_motor' => $ganaMotor,
            'gana_monitorista' => $ganaMonitorista,
            'empate' => $empates,
            'pct_gana_motor' => $n > 0 ? self::pct($ganaMotor, $n) : null,
            'diferencia' => ($mae($motor) !== null && $mae($monitorista) !== null)
                ? $mae($monitorista) - $mae($motor)
                : null,
        ];
    }

    /**
     * Resumen de un grupo: la misma estadística de `errores()` más el porcentaje
     * de aciertos dentro de la ventana rápido–tardado.
     *
     * @param  array<int, mixed>  $errores
     * @param  array<int, ?bool>  $dentro
     * @return array<string, mixed>
     */
    private static function resumen_grupo(string $nombre, array $errores, array $dentro,
                                          int $tolerancia): array
    {
        $base = self::errores($errores, $tolerancia);

        $conVentana = array_values(array_filter($dentro, static fn ($valor) => $valor !== null));
        $aciertos = count(array_filter($conVentana, static fn ($valor) => (bool) $valor));

        return array_merge($base, [
            'clave' => $nombre,
            'ventana_n' => count($conVentana),
            'dentro_ventana' => $aciertos,
            'pct_ventana' => $conVentana === [] ? null : self::pct($aciertos, count($conVentana)),
        ]);
    }

    /** Si la fila cayó o no en su ventana, aceptando los dos nombres del campo. */
    private static function dentro(object $fila, string $campo): ?bool
    {
        foreach ([$campo, 'dentro', 'en_ventana'] as $nombre) {
            if (property_exists($fila, $nombre)) {
                return $fila->{$nombre} === null ? null : (bool) $fila->{$nombre};
            }
        }

        return null;
    }

    /**
     * Deja sólo números redondeados (MySQL devuelve los minutos como string).
     *
     * @return array<int, int>
     */
    private static function minutos(array $valores): array
    {
        $salida = [];
        foreach ($valores as $valor) {
            if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
                $salida[] = (int) round((float) $valor);
            }
        }

        return $salida;
    }

    /** Diferencia en minutos entre dos fechas (`$hasta − $desde`). */
    private static function diferencia(Carbon $hasta, Carbon $desde): int
    {
        return (int) round(($hasta->getTimestamp() - $desde->getTimestamp()) / 60);
    }

    /** Acepta Carbon o texto; null si no hay fecha usable. */
    private static function fecha($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor;
        }

        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Porcentaje entero redondeado; null si no hay denominador. */
    private static function pct(int $parte, int $total): ?int
    {
        return $total > 0 ? (int) round(100 * $parte / $total) : null;
    }
}
