<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Historial de predicciones: cada vez que la pantalla o el API predicen, queda
 * una fila acá.
 *
 * Existe para dos cosas:
 *  - poder responder "¿qué dijimos de esta ref y cuándo?" sin recalcular;
 *  - medir el acierto REAL de lo que se predijo en producción: cuando la ref
 *    consigue su `presenta_para_carga`, se compara contra lo que se dijo y sale
 *    el porcentaje de éxito (dentro de la ventana rápido–tardado y a ±3 h).
 *
 * Nunca rompe: si la tabla no existe o la base falla, `guardar()` devuelve false
 * y la predicción sigue igual.
 */
class DBPrediccionResultados
{
    /** Tolerancia "de éxito" clásica, en minutos. */
    public const TOLERANCIA_ACEPTADA = 180;

    /**
     * Guarda una predicción. `origen` es 'pantalla' o 'api'.
     */
    public static function guardar(stdClass $resultado, string $origen = 'api'): bool
    {
        if (empty($resultado->ref) || empty($resultado->ahora)) {
            return false;   // sin ref/corte no hay nada que guardar (error, ya_presento, etc.)
        }

        try {
            DB::table('prediccion_resultados')->insert([
                'ref' => mb_substr((string) $resultado->ref, 0, 10),
                'placa' => $resultado->placa !== null ? mb_substr((string) $resultado->placa, 0, 10) : null,
                'ruta_id' => $resultado->ruta !== null ? mb_substr((string) $resultado->ruta, 0, 100) : null,
                'ahora' => self::fechaSql($resultado->ahora),
                'presenta' => self::fechaSql($resultado->presenta ?? null),
                'presenta_rapido' => self::fechaSql($resultado->presenta_rapido ?? null),
                'presenta_tardado' => self::fechaSql($resultado->presenta_tardado ?? null),
                'inicio_carga' => self::fechaSql($resultado->inicio_carga ?? null),
                'evidencia' => mb_substr((string) ($resultado->evidencia ?? 'sin_datos'), 0, 20),
                'confianza' => (int) ($resultado->confianza ?? 0),
                'origen' => mb_substr($origen, 0, 10),
                'desglose' => json_encode($resultado->desglose ?? [], JSON_UNESCAPED_UNICODE),
                'advertencias' => json_encode($resultado->advertencias ?? [], JSON_UNESCAPED_UNICODE),
                'pedido_en' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Guarda de una vez las predicciones de una corrida de la tabla de aciertos.
     *
     * Es lo que hace el botón "Guardar estas predicciones": deja en
     * `prediccion_resultados` lo que el motor dijo para cada ref, con el CORTE que
     * usó la tabla, para poder responder después "¿qué dijimos de esta ref y
     * cuándo?" y comparar contra la presentación real cuando llegue.
     *
     * No duplica: si ya hay una fila con la misma ref, el mismo corte y el mismo
     * origen, no la vuelve a insertar (el botón se puede apretar dos veces).
     * Tampoco inventa: las filas sin hora predicha (evidencia `sin_datos`) se
     * saltean.
     *
     * @param  array<int, object>  $filas  filas de Servicio::backtest()
     * @return array{guardadas: int, saltadas: int, error: ?string}
     */
    public static function guardar_filas(array $filas, string $origen = 'aciertos'): array
    {
        $salida = ['guardadas' => 0, 'saltadas' => 0, 'error' => null];
        $origen = mb_substr($origen, 0, 10);

        $preparadas = [];
        foreach ($filas as $fila) {
            $predicho = $fila->predicho ?? null;
            $corte = $fila->ancla ?? null;

            if ($predicho === null || $corte === null || empty($fila->ref)) {
                $salida['saltadas']++;

                continue;
            }

            $preparadas[] = [
                'ref' => mb_substr((string) $fila->ref, 0, 10),
                'placa' => isset($fila->placa) ? mb_substr((string) $fila->placa, 0, 10) : null,
                'ruta_id' => null,
                'ahora' => self::fechaSql($corte),
                'presenta' => self::fechaSql($predicho),
                'presenta_rapido' => self::fechaSql($fila->rapido ?? null),
                'presenta_tardado' => self::fechaSql($fila->tardado ?? null),
                'inicio_carga' => null,
                'evidencia' => mb_substr((string) ($fila->evidencia ?? 'sin_datos'), 0, 20),
                'confianza' => (int) ($fila->confianza ?? 0),
                'origen' => $origen,
                'desglose' => json_encode([], JSON_UNESCAPED_UNICODE),
                'advertencias' => json_encode([], JSON_UNESCAPED_UNICODE),
                'pedido_en' => now(),
            ];
        }

        if ($preparadas === []) {
            return $salida;
        }

        // dedupe contra lo que ya está guardado (misma ref, mismo corte, mismo origen)
        $existentes = [];
        try {
            $refs = array_values(array_unique(array_column($preparadas, 'ref')));
            foreach (array_chunk($refs, 500) as $lote) {
                $guardadas = DB::table('prediccion_resultados')
                    ->whereIn('ref', $lote)
                    ->where('origen', $origen)
                    ->get(['ref', 'ahora']);

                foreach ($guardadas as $fila) {
                    $existentes[$fila->ref . '|' . $fila->ahora] = true;
                }
            }
        } catch (\Throwable $e) {
            $salida['error'] = 'no se pudo leer `prediccion_resultados`: ¿corriste `php artisan migrate`?';

            return $salida;
        }

        $nuevas = [];
        foreach ($preparadas as $fila) {
            $llave = $fila['ref'] . '|' . $fila['ahora'];
            if (isset($existentes[$llave])) {
                $salida['saltadas']++;

                continue;
            }

            $nuevas[] = $fila;
            $existentes[$llave] = true;
        }

        try {
            foreach (array_chunk($nuevas, 200) as $lote) {
                DB::table('prediccion_resultados')->insert($lote);
                $salida['guardadas'] += count($lote);
            }
        } catch (\Throwable $e) {
            $salida['error'] = 'no se pudo guardar: ' . $e->getMessage();
        }

        return $salida;
    }

    /**
     * Acierto real de lo que se predijo en el rango.
     *
     * El rango se mira sobre el CORTE de la predicción (`ahora`), no sobre cuándo
     * se pidió: así una tanda guardada desde la tabla de aciertos (que predice
     * cortes pasados) aparece en el rango que se está mirando. De cada ref se toma
     * la última predicción anterior a su presentación real (la que "valía") y se
     * compara.
     *
     * @return array{n: int, dentro_ventana: int, dentro_tolerancia: int, pct_ventana: ?int,
     *               pct_tolerancia: ?int, error_mediano: ?int, filas: array, guardadas: int}
     */
    public static function resumen(?Carbon $desde = null, ?Carbon $hasta = null, int $limite = 500): array
    {
        $vacio = ['n' => 0, 'dentro_ventana' => 0, 'dentro_tolerancia' => 0, 'pct_ventana' => null,
                  'pct_tolerancia' => null, 'error_mediano' => null, 'filas' => [], 'guardadas' => 0];

        $desde = ($desde ?? Carbon::now()->subDays(90))->copy()->startOfDay();
        $hasta = ($hasta ?? Carbon::now())->copy()->endOfDay();

        try {
            $filas = DB::select(
                "SELECT p.ref, p.placa, p.ahora, p.presenta, p.presenta_rapido, p.presenta_tardado,
                        p.evidencia, p.confianza, p.origen, p.pedido_en,
                        r.presenta_para_carga AS presenta_real,
                        TIMESTAMPDIFF(MINUTE, p.presenta, r.presenta_para_carga) AS error_min
                 FROM prediccion_resultados p
                 LEFT JOIN referencias r ON r.ref = p.ref
                 WHERE (r.presenta_para_carga BETWEEN ? AND ?)
                    OR (p.ahora BETWEEN ? AND ?)
                 ORDER BY p.ahora DESC
                 LIMIT " . max(1, $limite),
                [$desde->toDateTimeString(), $hasta->toDateTimeString(),
                 $desde->toDateTimeString(), $hasta->toDateTimeString()]
            );
        } catch (\Throwable $e) {
            return $vacio;
        }

        // nos quedamos con la última predicción de cada ref hecha ANTES de que
        // presentara: es la que el despachador vio
        $porRef = [];
        foreach ($filas as $fila) {
            $real = $fila->presenta_real !== null ? Carbon::parse($fila->presenta_real) : null;
            $corte = Carbon::parse($fila->ahora);

            if ($real !== null && $corte->greaterThan($real)) {
                continue;
            }

            $clave = (string) $fila->ref;
            if (!isset($porRef[$clave]) || Carbon::parse($fila->ahora)->greaterThan(Carbon::parse($porRef[$clave]->ahora))) {
                $porRef[$clave] = $fila;
            }
        }

        $n = 0;
        $dentroVentana = 0;
        $dentroTolerancia = 0;
        $errores = [];
        $detalle = [];

        foreach ($porRef as $clave => $fila) {
            $real = $fila->presenta_real !== null ? Carbon::parse($fila->presenta_real) : null;
            $predicho = $fila->presenta !== null ? Carbon::parse($fila->presenta) : null;
            $rapido = $fila->presenta_rapido !== null ? Carbon::parse($fila->presenta_rapido) : null;
            $tardado = $fila->presenta_tardado !== null ? Carbon::parse($fila->presenta_tardado) : null;

            $enVentana = null;
            $error = null;

            if ($real !== null && $predicho !== null) {
                $n++;
                $error = (int) round(($predicho->getTimestamp() - $real->getTimestamp()) / 60);
                $errores[] = $error;

                if ($rapido !== null && $tardado !== null) {
                    $enVentana = $real->greaterThanOrEqualTo($rapido) && $real->lessThanOrEqualTo($tardado);
                    if ($enVentana) {
                        $dentroVentana++;
                    }
                }
                if (abs($error) <= self::TOLERANCIA_ACEPTADA) {
                    $dentroTolerancia++;
                }
            }

            $detalle[] = (object) [
                'ref' => (string) $fila->ref,
                'placa' => (string) ($fila->placa ?? ''),
                'corte' => Carbon::parse($fila->ahora),
                'rapido' => $rapido,
                'predicho' => $predicho,
                'tardado' => $tardado,
                'real' => $real,
                'error_min' => $error,
                'en_ventana' => $enVentana,
                'evidencia' => (string) $fila->evidencia,
                'confianza' => (int) $fila->confianza,
                'origen' => (string) $fila->origen,
                'pedido_en' => Carbon::parse($fila->pedido_en),
            ];
        }

        return [
            'n' => $n,
            'dentro_ventana' => $dentroVentana,
            'dentro_tolerancia' => $dentroTolerancia,
            'pct_ventana' => $n > 0 ? (int) round(100 * $dentroVentana / $n) : null,
            'pct_tolerancia' => $n > 0 ? (int) round(100 * $dentroTolerancia / $n) : null,
            'error_mediano' => $errores === [] ? null : (int) round((float) \App\Prediccion\Estadistica::mediana($errores)),
            'filas' => array_slice($detalle, 0, 50),
            'guardadas' => count($filas),
        ];
    }

    private static function fechaSql($valor): ?string
    {
        if ($valor instanceof Carbon) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
