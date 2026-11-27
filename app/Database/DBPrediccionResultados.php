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
     * Tope duro de filas que se cargan cuando se pide "todas" (`limite = 0`).
     *
     * No es un límite de negocio: es para que una consulta sin límite no se lleve
     * la tabla entera a memoria en el servidor compartido.
     */
    public const TOPE_FILAS = 50000;

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
                // la CUARTA fecha y su sesgo van en `desglose` (longtext, nadie lo
                // lee como lista): así se guardan sin agregar columnas, que en
                // producción no se pueden migrar.
                'desglose' => self::desgloseJson($resultado),
                'advertencias' => json_encode($resultado->advertencias ?? [], JSON_UNESCAPED_UNICODE),
                'pedido_en' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * La ruta de cada referencia, para guardarla junto a la predicción.
     *
     * Se resuelve acá y no en el backtest porque la fila de la tabla ya trae el
     * `ref`; una sola consulta por lote de 500 refs alcanza.
     *
     * @param  array<int, string>  $refs
     * @return array<string, string> ref => ruta_id
     */
    private static function rutasDeRefs(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs, fn ($ref) => $ref !== '')));
        $salida = [];

        try {
            foreach (array_chunk($refs, 500) as $lote) {
                foreach (DB::table('referencias')->whereIn('ref', $lote)->get(['ref', 'ruta_id']) as $fila) {
                    if ($fila->ruta_id !== null && $fila->ruta_id !== '') {
                        $salida[(string) $fila->ref] = (string) $fila->ruta_id;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];   // sin tabla de referencias se guarda sin ruta, como antes
        }

        return $salida;
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
        // la ruta de cada ref: el backtest no la trae en la fila y sin `ruta_id`
        // los filtros por local no ven lo guardado desde acá
        $rutasPorRef = self::rutasDeRefs(array_map(fn ($fila) => (string) ($fila->ref ?? ''), $filas));

        foreach ($filas as $fila) {
            $predicho = $fila->predicho ?? null;
            $corte = $fila->ancla ?? null;

            if ($predicho === null || $corte === null || empty($fila->ref)) {
                $salida['saltadas']++;

                continue;
            }

            $ref = mb_substr((string) $fila->ref, 0, 10);
            $ruta = $rutasPorRef[$ref] ?? null;

            $preparadas[] = [
                'ref' => $ref,
                'placa' => isset($fila->placa) ? mb_substr((string) $fila->placa, 0, 10) : null,
                'ruta_id' => $ruta !== null && $ruta !== '' ? mb_substr((string) $ruta, 0, 100) : null,
                'ahora' => self::fechaSql($corte),
                'presenta' => self::fechaSql($predicho),
                'presenta_rapido' => self::fechaSql($fila->rapido ?? null),
                'presenta_tardado' => self::fechaSql($fila->tardado ?? null),
                'inicio_carga' => null,
                'evidencia' => mb_substr((string) ($fila->evidencia ?? 'sin_datos'), 0, 20),
                'confianza' => (int) ($fila->confianza ?? 0),
                'origen' => $origen,
                // igual que en guardar(): la cuarta fecha y el consenso viajan
                // en el desglose (sin columnas nuevas)
                'desglose' => self::desgloseJson((object) [
                    'presenta_probable' => $fila->probable ?? null,
                    'presenta_consenso' => $fila->consenso ?? null,
                    'presenta_perfecta' => $fila->perfecta ?? null,
                    'sesgo_clase' => $fila->sesgo_clase ?? null,
                    'sesgo_min' => $fila->sesgo_min ?? 0,
                ]),
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
     *               pct_tolerancia: ?int, error_mediano: ?int, filas: array, guardadas: int,
     *               refs: int, sin_ref: int, comparables: array}
     */
    public static function resumen(?Carbon $desde = null, ?Carbon $hasta = null, int $limite = 500,
                                   ?string $origen = null, int $filasMax = 50, ?string $local = null): array
    {
        $vacio = ['n' => 0, 'dentro_ventana' => 0, 'dentro_tolerancia' => 0, 'pct_ventana' => null,
                  'pct_tolerancia' => null, 'error_mediano' => null, 'filas' => [], 'guardadas' => 0,
                  'refs' => 0, 'sin_ref' => 0, 'comparables' => []];

        $desde = ($desde ?? Carbon::now()->subDays(90))->copy()->startOfDay();
        $hasta = ($hasta ?? Carbon::now())->copy()->endOfDay();
        $origen = trim((string) $origen);

        // El LOCAL es la planta de carga: el origen de la ruta de la referencia, o
        // sea el local donde el camión tiene que presentarse. Se filtra contra
        // `rutas` porque `prediccion_resultados` sólo guarda el `ruta_id`.
        $local = trim((string) $local);
        $filtroOrigen = $origen !== '' ? ' AND p.origen = ?' : '';
        $filtroLocal = $local !== '' ? ' AND ru.origen_id = ?' : '';
        $parametros = [$desde->toDateTimeString(), $hasta->toDateTimeString(),
                       $desde->toDateTimeString(), $hasta->toDateTimeString()];

        if ($origen !== '') {
            $parametros[] = $origen;
        }
        if ($local !== '') {
            $parametros[] = $local;
        }

        try {
            $filas = DB::select(
                "SELECT p.ref, p.placa, p.ahora, p.presenta, p.presenta_rapido, p.presenta_tardado,
                        p.evidencia, p.confianza, p.origen, p.pedido_en, p.desglose,
                        r.ref AS ref_existe,
                        r.presenta_para_carga AS presenta_real,
                        TIMESTAMPDIFF(MINUTE, p.presenta, r.presenta_para_carga) AS error_min
                 FROM prediccion_resultados p
                 LEFT JOIN referencias r ON r.ref = p.ref
                 -- el local sale de la ruta de la predicción; si la fila se guardó
                 -- sin `ruta_id` (lo que hacía el botón de aciertos), se resuelve
                 -- por la ruta de la referencia: si no, el filtro por local no ve
                 -- esas filas.
                 LEFT JOIN rutas ru ON ru.id = COALESCE(NULLIF(p.ruta_id, ''), r.ruta_id)
                 WHERE ((r.presenta_para_carga BETWEEN ? AND ?)
                    OR (p.ahora BETWEEN ? AND ?))" . $filtroOrigen . $filtroLocal . "
                 ORDER BY p.ahora DESC
                 LIMIT " . ($limite > 0 ? $limite : self::TOPE_FILAS),
                $parametros
            );
        } catch (\Throwable $e) {
            return $vacio;
        }

        // nos quedamos con la última predicción de cada ref hecha ANTES de que
        // presentara: es la que el despachador vio
        $porRef = [];
        $sinRef = 0;
        foreach ($filas as $fila) {
            if ($fila->ref_existe === null) {
                $sinRef++;      // ref que no está en `referencias` (pruebas, typos)
            }

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

            $consenso = self::consensoGuardada($fila->desglose ?? null);

            $detalle[] = (object) [
                'ref' => (string) $fila->ref,
                'placa' => (string) ($fila->placa ?? ''),
                'corte' => Carbon::parse($fila->ahora),
                'rapido' => $rapido,
                'predicho' => $predicho,
                'tardado' => $tardado,
                // la cuarta fecha, el consenso y la perfecta: dentro de `desglose`
                // (sin columnas nuevas)
                'probable' => self::probableGuardada($fila->desglose ?? null),
                'consenso' => $consenso,
                'perfecta' => self::perfectaGuardada($fila->desglose ?? null),
                'real' => $real,
                'error_min' => $error,
                'error_rapido_min' => self::error($real, $rapido),
                'error_tardado_min' => self::error($real, $tardado),
                'error_probable_min' => self::error($real, self::probableGuardada($fila->desglose ?? null)),
                'error_consenso_min' => self::error($real, $consenso),
                'error_perfecta_min' => self::error($real, self::perfectaGuardada($fila->desglose ?? null)),
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
            'filas' => array_slice($detalle, 0, max(1, $filasMax)),
            // todas las comparables (no sólo las que se dibujan): con esto el
            // tablero saca los desgloses sin volver a consultar
            'comparables' => array_values(array_filter($detalle,
                static fn (object $fila) => $fila->error_min !== null)),
            'guardadas' => count($filas),
            'refs' => count($porRef),
            'sin_ref' => $sinRef,
        ];
    }

    /** Diferencia en minutos entre la real y una fecha publicada (null si falta alguna). */
    private static function error(?Carbon $real, ?Carbon $fecha): ?int
    {
        if (!$real instanceof Carbon || !$fecha instanceof Carbon) {
            return null;
        }

        return (int) round(($fecha->getTimestamp() - $real->getTimestamp()) / 60);
    }

    private static function fechaSql($valor): ?string
    {
        if ($valor instanceof Carbon) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * El JSON que va en `desglose`: los pasos MÁS las fechas que no tienen columna.
     *
     * `desglose` es `longtext` y nadie lo lee como lista, así que ahí se guardan
     * `presenta_probable` (con el sesgo que se le aplicó), el consenso y la fecha
     * perfecta. Se hace así y no con columnas nuevas porque en producción no hay
     * consola para migrar.
     */
    private static function desgloseJson(stdClass $resultado): string
    {
        return json_encode([
            'pasos' => $resultado->desglose ?? [],
            'probable' => self::fechaSql($resultado->presenta_probable ?? null),
            'consenso' => self::fechaSql($resultado->presenta_consenso ?? null),
            'perfecta' => self::fechaSql($resultado->presenta_perfecta ?? null),
            'sesgo_clase' => $resultado->sesgo_clase ?? null,
            'sesgo_min' => (int) ($resultado->sesgo_min ?? 0),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Lee la fecha guardada en `desglose` (la cuarta o el consenso). Tolera las
     * filas viejas, que tienen una lista de pasos sin esa clave.
     */
    public static function fechaGuardada($desglose, string $clave = 'probable'): ?Carbon
    {
        $datos = is_string($desglose) ? json_decode($desglose, true) : null;

        if (!is_array($datos) || !isset($datos[$clave]) || !is_string($datos[$clave])) {
            return null;
        }

        try {
            return Carbon::parse($datos[$clave]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lee la cuarta fecha guardada en `desglose`. Se mantiene por compatibilidad:
     * es `fechaGuardada($desglose, 'probable')`.
     */
    public static function probableGuardada($desglose): ?Carbon
    {
        return self::fechaGuardada($desglose, 'probable');
    }

    /** El consenso guardado en `desglose` (NULL en las filas viejas). */
    public static function consensoGuardada($desglose): ?Carbon
    {
        return self::fechaGuardada($desglose, 'consenso');
    }

    /** La fecha "perfecta" guardada en `desglose` (NULL en las filas viejas). */
    public static function perfectaGuardada($desglose): ?Carbon
    {
        return self::fechaGuardada($desglose, 'perfecta');
    }
}
