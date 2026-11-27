<?php

namespace App\Prediccion;

use stdClass;

/**
 * Métricas de acierto de una corrida de predicción: error medio absoluto, sesgo
 * y porcentajes dentro de tolerancias.
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
     * Las seis fechas que publica el motor, EN EL ORDEN DE PRIORIDAD POR PRECISIÓN
     * que definió el negocio: rápido, consenso, normal, base ajustada, tardado y
     * —al final, porque todavía no está medida— perfecta.
     *
     * `rapido` es el extremo temprano de la banda; `consenso` el promedio de rápido,
     * normal y base ajustada; `normal` la estimación central; `probable` (que se
     * publica como **base ajustada**) la normal corregida con el sesgo de su clase;
     * `tardado` el extremo tardío; `perfecta` el escenario sin descanso, sin
     * franjas y sin tráfico (p25 de la ruta).
     */
    public const FECHAS = [
        'rapido' => 'rápido',
        'consenso' => 'consenso',
        'normal' => 'normal',
        'probable' => 'base ajustada',
        'tardado' => 'tardado',
        'perfecta' => 'perfecta',
    ];

    /**
     * Campo con el error de cada fecha. Se llaman igual en la tabla de aciertos
     * (backtest) y en las predicciones guardadas, así que el mismo helper sirve
     * para las dos pantallas.
     */
    private const CAMPOS = [
        'rapido' => 'error_rapido_min',
        'consenso' => 'error_consenso_min',
        'normal' => 'error_min',
        'probable' => 'error_probable_min',
        'tardado' => 'error_tardado_min',
        'perfecta' => 'error_perfecta_min',
    ];

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
            'dentro_ventana' => 0,
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
        $salida->dentro_ventana = count(array_filter($dentroVentana, fn ($valor) => (bool) $valor));

        return $salida;
    }

    /**
     * Cuál de las CUATRO fechas quedó más cerca de la real en una fila.
     *
     * Compara las cuatro, no la normal contra la probable: en muchos viajes la
     * que gana es la rápida o la tardada, y mirar sólo dos esconde justo eso.
     * Con empate gana la que se publica primero (rápido, normal, tardado,
     * probable) y las otras quedan en `empates` para poder mostrarlas.
     *
     * @param  object|array<string, mixed>  $fila
     */
    public static function mas_cerca($fila): ?stdClass
    {
        $errores = self::errores($fila);
        if ($errores === []) {
            return null;
        }

        $minimo = min(array_map('abs', $errores));

        $ganadoras = [];
        foreach ($errores as $clave => $error) {
            if (abs($error) === $minimo) {
                $ganadoras[] = $clave;
            }
        }

        return (object) [
            'clave' => $ganadoras[0],
            'nombre' => self::FECHAS[$ganadoras[0]],
            'abs_min' => $minimo,
            'empates' => array_slice($ganadoras, 1),
        ];
    }

    /**
     * Carrera de las cuatro fechas: cuántas veces gana cada una.
     *
     * Se saltean las filas fuera del tope de horizonte: son los casos en que el
     * camión tardó días en volver y ninguna fecha compite de verdad.
     *
     * @param  array<int, object>  $filas
     */
    public static function carrera(array $filas): stdClass
    {
        $cuenta = array_fill_keys(array_keys(self::FECHAS), 0);
        $n = 0;

        foreach ($filas as $fila) {
            if (!is_object($fila) || ($fila->fuera_tope ?? false)) {
                continue;
            }

            $ganadora = self::mas_cerca($fila);
            if ($ganadora === null) {
                continue;
            }

            $cuenta[$ganadora->clave]++;
            $n++;
        }

        $salida = (object) ['n' => $n];
        foreach ($cuenta as $clave => $veces) {
            $salida->{$clave} = $veces;
            $salida->{'pct_' . $clave} = $n > 0 ? (int) round(100 * $veces / $n) : null;
        }

        return $salida;
    }

    /**
     * Error firmado de cada fecha que esté informada.
     *
     * @param  object|array<string, mixed>  $fila
     * @return array<string, int>
     */
    private static function errores($fila): array
    {
        $salida = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $valor = is_array($fila) ? ($fila[$campo] ?? null) : ($fila->{$campo} ?? null);
            if ($valor === null || !is_numeric($valor)) {
                continue;
            }
            $salida[$clave] = (int) round((float) $valor);
        }

        return $salida;
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
