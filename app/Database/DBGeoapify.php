<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Contador de llamadas al API de Geoapify.
 *
 * Cada petición HTTP que hace `App\Prediccion\Geoapify` (geocodificar un ubigeo
 * o pedir el tiempo de manejo entre dos) se suma acá: una fila por día y por
 * endpoint con `llamadas`, `exitosas` y `fallidas`. La pantalla
 * `/lupita/prediccion/geoapify` lee esto y muestra el gasto real.
 *
 * Se cuenta CADA petición, también la que falla o la que se reintenta: el
 * proveedor cobra por request, no por respuesta buena.
 *
 * Nunca rompe: si la tabla no existe o la base falla, `registrar()` devuelve
 * false y `resumen()` devuelve todo en cero con `tabla = false`. La predicción
 * sigue funcionando igual.
 */
class DBGeoapify
{
    /** Endpoints que se cuentan. */
    public const TIPOS = ['geocode', 'routing'];

    /**
     * Suma una llamada al contador del día.
     *
     * `config('geoapify.contar')` permite apagarlo: la suite de pruebas lo hace
     * para no ensuciar el contador real con llamadas simuladas.
     */
    public static function registrar(string $tipo, bool $exitosa = true): bool
    {
        if (!(bool) config('geoapify.contar', true)) {
            return false;
        }

        try {
            $ahora = now();

            DB::statement(
                'INSERT INTO geoapify_uso (fecha, tipo, llamadas, exitosas, fallidas, actualizado_en)
                 VALUES (?, ?, 1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE llamadas = llamadas + 1,
                                         exitosas = exitosas + ?,
                                         fallidas = fallidas + ?,
                                         actualizado_en = ?',
                [
                    $ahora->toDateString(),
                    mb_substr($tipo, 0, 20),
                    $exitosa ? 1 : 0,
                    $exitosa ? 0 : 1,
                    $ahora,
                    $exitosa ? 1 : 0,
                    $exitosa ? 0 : 1,
                    $ahora,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            return false;   // sin tabla se sigue prediciendo, sólo no se cuenta
        }
    }

    /**
     * Llamadas registradas en un día (por defecto hoy), en una sola consulta.
     *
     * Es el número que viaja en cada predicción ("¿cuánto se usó el API hoy?").
     */
    public static function hoy(?Carbon $dia = null): int
    {
        try {
            return (int) DB::table('geoapify_uso')
                ->where('fecha', ($dia ?? Carbon::now())->toDateString())
                ->sum('llamadas');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Uso de los últimos 30 días, más el acumulado histórico.
     *
     * `$hasta` es el día que se considera "hoy" (por defecto la fecha actual):
     * sirve para mirar el contador de un día pasado sin tocar el reloj.
     *
     * @return stdClass{
     *     tabla: bool, desde: Carbon, hasta: Carbon,
     *     hoy: int, ayer: int, ultimos_7: int, ultimos_30: int, total: int,
     *     hoy_geocode: int, hoy_routing: int, hoy_fallidas: int,
     *     total_geocode: int, total_routing: int, total_fallidas: int, total_otros: int,
     *     dias: array<int, stdClass>, dias_con_uso: int, promedio_dia_uso: ?float,
     *     ultima: ?Carbon
     * }
     */
    public static function resumen(?Carbon $hasta = null): stdClass
    {
        $hasta = ($hasta ?? Carbon::now())->copy()->startOfDay();
        $desde = $hasta->copy()->subDays(29);

        $vacio = (object) [
            'tabla' => false,
            'desde' => $desde,
            'hasta' => $hasta,
            'hoy' => 0,
            'ayer' => 0,
            'ultimos_7' => 0,
            'ultimos_30' => 0,
            'total' => 0,
            'hoy_geocode' => 0,
            'hoy_routing' => 0,
            'hoy_fallidas' => 0,
            'total_geocode' => 0,
            'total_routing' => 0,
            'total_fallidas' => 0,
            'total_otros' => 0,
            'dias' => [],
            'dias_con_uso' => 0,
            'promedio_dia_uso' => null,
            'ultima' => null,
        ];

        try {
            $totales = DB::select(
                'SELECT tipo, SUM(llamadas) AS llamadas, SUM(fallidas) AS fallidas, MAX(actualizado_en) AS ultima
                 FROM geoapify_uso
                 GROUP BY tipo'
            );

            $filas = DB::select(
                'SELECT fecha, tipo, llamadas, fallidas
                 FROM geoapify_uso
                 WHERE fecha BETWEEN ? AND ?
                 ORDER BY fecha DESC',
                [$desde->toDateString(), $hasta->toDateString()]
            );
        } catch (\Throwable $e) {
            return $vacio;   // tabla sin crear todavía
        }

        $uso = clone $vacio;
        $uso->tabla = true;

        // todos los días de la ventana, con ceros: la tabla se lee sin huecos
        $porDia = [];
        for ($i = 0; $i < 30; $i++) {
            $dia = $hasta->copy()->subDays($i);
            $porDia[$dia->toDateString()] = (object) [
                'fecha' => $dia,
                'geocode' => 0,
                'routing' => 0,
                'total' => 0,
                'fallidas' => 0,
            ];
        }

        foreach ($filas as $fila) {
            $clave = Carbon::parse($fila->fecha)->toDateString();
            if (!isset($porDia[$clave])) {
                continue;
            }

            $llamadas = (int) $fila->llamadas;
            $porDia[$clave]->total += $llamadas;
            $porDia[$clave]->fallidas += (int) $fila->fallidas;

            if ($fila->tipo === 'geocode') {
                $porDia[$clave]->geocode += $llamadas;
            } elseif ($fila->tipo === 'routing') {
                $porDia[$clave]->routing += $llamadas;
            }
        }

        $uso->dias = array_values($porDia);

        $hoy = $hasta->toDateString();
        $ayer = $hasta->copy()->subDay()->toDateString();
        $semana = $hasta->copy()->subDays(6)->toDateString();

        foreach ($uso->dias as $dia) {
            $clave = $dia->fecha->toDateString();
            $uso->ultimos_30 += $dia->total;

            if ($clave === $hoy) {
                $uso->hoy = $dia->total;
                $uso->hoy_geocode = $dia->geocode;
                $uso->hoy_routing = $dia->routing;
                $uso->hoy_fallidas = $dia->fallidas;
            }
            if ($clave === $ayer) {
                $uso->ayer = $dia->total;
            }
            if ($clave >= $semana && $clave <= $hoy) {
                $uso->ultimos_7 += $dia->total;
            }
            if ($dia->total > 0) {
                $uso->dias_con_uso++;
            }
        }

        if ($uso->dias_con_uso > 0) {
            $uso->promedio_dia_uso = round($uso->ultimos_30 / $uso->dias_con_uso, 1);
        }

        foreach ($totales as $total) {
            $llamadas = (int) $total->llamadas;
            $uso->total += $llamadas;
            $uso->total_fallidas += (int) $total->fallidas;

            if ($total->tipo === 'geocode') {
                $uso->total_geocode += $llamadas;
            } elseif ($total->tipo === 'routing') {
                $uso->total_routing += $llamadas;
            } else {
                $uso->total_otros += $llamadas;
            }

            $ultima = $total->ultima !== null ? Carbon::parse($total->ultima) : null;
            if ($ultima !== null && ($uso->ultima === null || $ultima->greaterThan($uso->ultima))) {
                $uso->ultima = $ultima;
            }
        }

        return $uso;
    }
}
