<?php

namespace App\Http\Controllers;

use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use App\Prediccion\Tablero;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pantallas del módulo de predicción de carga:
 *
 *  - presenta(): calculadora de la hora de `presenta_para_carga` de una
 *    referencia, con el último viaje del camión como ancla.
 *  - aciertos(): la tabla que corre el motor contra las referencias ya cerradas
 *    y lo compara con el método que hay hoy en la ficha de comportamiento.
 *
 * Las dos cuelgan de `auth` + `permission:ver tiempos`, igual que comportamiento.
 * La cuenta la hace entera `App\Prediccion\Servicio`; el controlador sólo valida
 * el formulario y arma la vista.
 */
class PrediccionController extends Controller
{
    /** Claves de `prediccion_config` que la pantalla deja editar. */
    public const PARAMETROS_EDITABLES = [
        'pares_recientes_max',
        'min_pares_ruta',
        'min_pares_entidad',
        'min_hitos_bin',
        'max_desplazamiento_min',
        'traslado_defecto_min',
        'muerto_defecto_min',
        'espera_carga_defecto_min',
    ];

    /**
     * Claves que NO se editan a mano: se calibran con la tabla de aciertos
     * (entrenamiento + validación) desde los botones de la pantalla.
     */
    public const PARAMETROS_CALIBRABLES = [
        'cruce_pares_k',
        'ventana_residuos',
        'ventana_adelanto_min',
        'ventana_atraso_min',
        'ventana_adelanto_cadena',
        'ventana_atraso_cadena',
    ];

    /** Rejilla del botón "Calibrar cruce_pares_k". */
    public const REJILLA_CRUCE = [1, 2, 3, 5, 8, 12];

    /** Calculadora: referencia a predecir + viaje ancla + ubicación declarada. */
    /**
     * Filas que se pueden pedir a la tabla de aciertos. Hasta 1000: calibrar con
     * 100 filas es ruido (la tabla de validación queda con 10-20 filas y cualquier
     * parámetro gana por suerte). El backtest tarda ~3 s cada 300 filas, así que
     * 1000 son ~10 s la primera vez y después sale de la caché de 10 minutos.
     */
    public const FILAS_ACIERTOS = [10, 40, 80, 100, 300, 600, 1000];

    /** Filas que se pueden mostrar en la pantalla de predicciones guardadas. */
    public const FILAS_GUARDADAS = [50, 100, 300, 600];

    /**
     * Filas con las que se miden los KPIs del motor en "Predicciones guardadas".
     *
     * Es fijo y no sigue al selector de filas de la tabla: el número de arriba
     * tiene que ser comparable entre visitas, y 300 filas dan ~3-4 s (se cachea
     * 10 minutos igual que la tabla de aciertos).
     */
    public const FILAS_MOTOR = 300;

    /** Orígenes con los que se guarda una predicción (`prediccion_resultados.origen`). */
    public const ORIGENES_GUARDADAS = ['api', 'aciertos', 'pantalla'];

    public static function presenta(Request $request)
    {
        $request->validate([
            'ref' => 'nullable|string|max:10',
            'placa' => 'nullable|string|max:20',
            'ahora' => 'nullable|date',
            'ancla' => 'nullable|string|max:10',
            'ubicacion_tipo' => 'nullable|in:auto,local,ubigeo,geopunto,indeterminado',
            'ubicacion_id' => [
                'nullable', 'string', 'max:20',
                function (string $atributo, $valor, \Closure $falla) use ($request) {
                    $error = Servicio::ubicacion_invalida(
                        (string) $request->input('ubicacion_tipo', 'auto'),
                        (string) $valor
                    );
                    if ($error !== null) {
                        $falla($error);
                    }
                },
            ],
            'ubicacion_texto' => 'nullable|string|max:120',
        ]);

        $ref = mb_strtoupper(trim((string) $request->input('ref', '')));
        $placa = mb_strtoupper(trim((string) $request->input('placa', '')));
        $ahora = trim((string) $request->input('ahora', ''));

        $opciones = [
            'ahora' => $ahora !== '' ? $ahora : null,
            'ancla_ref' => trim((string) $request->input('ancla', '')),
            'ubicacion_tipo' => (string) $request->input('ubicacion_tipo', 'auto'),
            'ubicacion_id' => trim((string) $request->input('ubicacion_id', '')),
        ];

        // Distrito/local escrito a mano: se resuelve contra el catálogo y, si no
        // está, se geocodifica con Geoapify (el traslado sale del ruteo real).
        $ubicacionTexto = trim((string) $request->input('ubicacion_texto', ''));
        $ubicacionTextoResuelta = '';
        $ubicacionError = null;
        $candidatosUbicacion = [];

        if ($ubicacionTexto !== '') {
            $resolucion = Servicio::resolver_ubicacion_texto($ubicacionTexto);

            if (($resolucion['ambigua'] ?? false) === true) {
                $ubicacionError = $resolucion['detalle']
                    ?? ('Hay varias coincidencias para "' . $ubicacionTexto . '": elegí una de la lista.');
                $candidatosUbicacion = $resolucion['candidatos'] ?? [];
            } elseif (($resolucion['encontrada'] ?? false) !== true) {
                $ubicacionError = 'No se pudo ubicar "' . $ubicacionTexto
                    . '": no está en el catálogo de ubigeos/locales y no se pudo geocodificar.';
            } else {
                $opciones['ubicacion_tipo'] = (string) $resolucion['tipo'];
                $opciones['ubicacion_id'] = (string) ($resolucion['id'] ?? '');
                $opciones['ubicacion_texto'] = (string) ($resolucion['texto'] ?? $ubicacionTexto);
                $opciones['ubicacion_resuelta'] = $resolucion;
                $ubicacionTextoResuelta = (($resolucion['fuente'] ?? '') === 'geoapify'
                        ? 'geocodificado con Geoapify: ' : 'del catálogo: ')
                    . $opciones['ubicacion_texto'];

                if (($resolucion['tipo'] ?? '') === 'geopunto') {
                    $opciones['ubicacion_id'] = '';
                    $opciones['ubicacion_coords'] = [
                        'lat' => $resolucion['lat'],
                        'lng' => $resolucion['lng'],
                        'texto' => (string) $resolucion['texto'],
                    ];
                }
            }
        }

        $resultado = null;
        if ($ref !== '') {
            $resultado = Servicio::predecir($ref, $opciones);
            if ($placa === '') {
                $placa = (string) ($resultado->placa ?? '');
            }
            // queda en el historial: es lo que después mide el % de éxito
            if (empty($resultado->error)) {
                \App\Database\DBPrediccionResultados::guardar($resultado, 'pantalla');
            }
        }

        $candidatos = $resultado->candidatos ?? [];
        if ($candidatos === [] && $placa !== '' && $resultado === null) {
            $candidatos = Servicio::candidatos_viaje($placa, self::carbon($opciones['ahora']));
        }

        // texto legible de lo que ya está elegido (los inputs son buscables)
        $ubicacionId = (string) ($opciones['ubicacion_id'] ?? '');
        $tipoUbicacion = (string) ($opciones['ubicacion_tipo'] ?? 'auto');
        $localTexto = $tipoUbicacion === 'local' && $ubicacionId !== ''
            ? \App\Database\DBPrediccion::texto_ubicacion($ubicacionId, null) . ' · ' . $ubicacionId
            : '';
        $ubigeoTexto = $tipoUbicacion === 'ubigeo' && $ubicacionId !== ''
            ? (\App\Prediccion\Geoapify::nombre_ubigeo($ubicacionId) ?? $ubicacionId) . ' · ' . $ubicacionId
            : '';

        return view('lupita.prediccion_presenta', array_merge(self::comunes(), [
            'ref' => $ref,
            'placa' => $placa,
            'ahora' => $ahora,
            'opciones' => $opciones,
            'resultado' => $resultado,
            'candidatos' => $candidatos,
            'local_texto' => $localTexto,
            'ubigeo_texto' => $ubigeoTexto,
            'ubicacion_texto' => $ubicacionTexto,
            'ubicacion_texto_resuelta' => $ubicacionTextoResuelta,
            'ubicacion_error' => $ubicacionError,
            'candidatos_ubicacion' => $candidatosUbicacion,
            'geoapify_hoy' => \App\Database\DBGeoapify::hoy(),
        ]));
    }

    /**
     * Cuántas veces se usó el API de Geoapify: contador por día y por endpoint.
     *
     * Geoapify sólo entra cuando no hay ningún promedio compilado del par, así
     * que normalmente el número es cero; acá está lo que sí se gastó.
     */
    public static function geoapify()
    {
        return view('lupita.prediccion_geoapify', [
            'uso' => \App\Database\DBGeoapify::resumen(),
            'contando' => (bool) config('geoapify.contar', true),
            'disponible' => \App\Prediccion\Geoapify::disponible(),
        ]);
    }

    /** Tabla de aciertos del rango: motor vs método actual. */
    public static function aciertos(Request $request)
    {
        // por defecto se mira HOY (el día actual viene seleccionado)
        if (!$request->filled('fecha_inicio') && !$request->filled('fecha_fin')) {
            $request->merge([
                'fecha_inicio' => now()->toDateString(),
                'fecha_fin' => now()->toDateString(),
            ]);
        }

        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'placa' => 'nullable|string|max:20',
            'limite' => 'nullable|in:' . implode(',', self::FILAS_ACIERTOS),
        ]);

        $limite = (int) $request->input('limite', self::FILAS_ACIERTOS[0]);
        if (!in_array($limite, self::FILAS_ACIERTOS, true)) {
            $limite = self::FILAS_ACIERTOS[0];
        }

        $filtros = [
            'desde' => $request->input('fecha_inicio'),
            'hasta' => $request->input('fecha_fin'),
            'placa' => trim((string) $request->input('placa', '')),
            'limite' => $limite,
        ];

        // La corrida tarda ~3-4 s (300 filas): se cachea 10 minutos. Con
        // `?refrescar=1` se fuerza de nuevo.
        $llave = self::llave_aciertos($filtros);

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        $inicio = microtime(true);
        $aciertos = \Illuminate\Support\Facades\Cache::remember(
            $llave,
            now()->addMinutes(10),
            fn () => Servicio::backtest($filtros)
        );
        $calculoMs = (int) round((microtime(true) - $inicio) * 1000);

        return view('lupita.prediccion_aciertos', array_merge(self::comunes(), [
            'filtros' => $filtros,
            'aciertos' => $aciertos,
            'calculo_ms' => $calculoMs,
            'guardadas' => \App\Database\DBPrediccionResultados::resumen($aciertos->desde, $aciertos->hasta, 500),
        ]));
    }

    /**
     * Guarda en `prediccion_resultados` las predicciones de la corrida actual.
     *
     * Es el botón "Guardar estas predicciones" de la tabla de aciertos: sin esto
     * la tabla sólo se llena cuando alguien predice por el API, así que la pantalla
     * de historial queda vacía y no hay con qué comparar "qué dijimos".
     *
     * Reusa la corrida cacheada (misma llave que `aciertos()`), así que apretarlo
     * después de correr la tabla no vuelve a calcular.
     */
    public static function guardar_aciertos(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'placa' => 'nullable|string|max:20',
            'limite' => 'nullable|in:' . implode(',', self::FILAS_ACIERTOS),
        ]);

        $filtros = [
            'desde' => $request->input('fecha_inicio'),
            'hasta' => $request->input('fecha_fin'),
            'placa' => trim((string) $request->input('placa', '')),
            'limite' => (int) $request->input('limite', self::FILAS_ACIERTOS[0]),
        ];

        if (!in_array($filtros['limite'], self::FILAS_ACIERTOS, true)) {
            $filtros['limite'] = self::FILAS_ACIERTOS[0];
        }

        $llave = self::llave_aciertos($filtros);

        try {
            $aciertos = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => Servicio::backtest($filtros)
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo correr la tabla: ' . $e->getMessage());
        }

        $resultado = \App\Database\DBPrediccionResultados::guardar_filas($aciertos->filas);

        if ($resultado['error'] !== null) {
            return redirect()->route('lupita.prediccion.aciertos', self::filtros_query($filtros))
                ->with('error', 'No se guardó nada: ' . $resultado['error']);
        }

        $mensaje = 'Se guardaron ' . $resultado['guardadas'] . ' predicciones'
            . ($resultado['saltadas'] > 0 ? ' (' . $resultado['saltadas'] . ' ya estaban o no tenían hora)' : '')
            . '. Se ven en la pantalla "Predicciones guardadas".';

        // se vuelve EXPLÍCITAMENTE a la misma tabla y con los mismos filtros (con
        // `back()` dependíamos del referer: si el navegador no lo manda, el POST
        // caía en / y la sensación era "el botón no hace nada")
        return redirect()->route('lupita.prediccion.aciertos', self::filtros_query($filtros))
            ->with('estado', $mensaje);
    }

    /** Filtros de la tabla de aciertos listos para la query string (sin vacíos). */
    private static function filtros_query(array $filtros): array
    {
        return array_filter([
            'fecha_inicio' => $filtros['desde'] ?? null,
            'fecha_fin' => $filtros['hasta'] ?? null,
            'placa' => $filtros['placa'] ?? null,
            'limite' => $filtros['limite'] ?? null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** Llave de caché de la tabla de aciertos (misma para mostrar y para guardar). */
    private static function llave_aciertos(array $filtros): string
    {
        // El número de versión sube cuando cambia la forma de las filas o de la
        // corrida (v5: sin línea base, con grupo de calibración y cercanía).
        return 'prediccion:aciertos:v5:' . md5(json_encode([$filtros, (array) Parametros::cargar()]));
    }

    /** Documentación del API de predicción (para quien lo consume). */
    public static function documentacion()
    {
        return view('lupita.prediccion_documentacion');
    }

    /**
     * Predicción de UNA ref pegándole al API público (no calcula nada acá).
     *
     * La pantalla muestra el request y, con un clic, el navegador llama al
     * endpoint: cada clic genera la predicción y la deja guardada en
     * `prediccion_resultados` con origen `api`.
     */
    public static function api_ref(Request $request)
    {
        $request->validate([
            'ref' => 'required|string|min:3|max:10',
            'ahora' => 'nullable|date',
            'ancla' => 'nullable|string|max:10',
            'ubicacion_tipo' => 'nullable|in:auto,local,ubigeo,indeterminado',
            'ubicacion_id' => 'nullable|string|max:20',
        ]);

        return view('lupita.prediccion_api_ref', [
            'ref' => mb_strtoupper(trim((string) $request->input('ref'))),
            'ahora' => trim((string) $request->input('ahora', '')),
            'ancla' => trim((string) $request->input('ancla', '')),
            'tipoUbicacion' => (string) $request->input('ubicacion_tipo', 'auto'),
            'ubicacionId' => trim((string) $request->input('ubicacion_id', '')),
        ]);
    }

    /** Búsqueda para los inputs de local y ubigeo de la calculadora. */
    public static function buscar(Request $request)
    {
        $texto = trim((string) $request->query('texto', ''));
        $tipo = (string) $request->query('tipo', 'local');

        if (mb_strlen($texto) < 2) {
            return response()->json(['resultados' => []]);
        }

        $resultados = [];

        try {
            if ($tipo === 'ubigeo') {
                $filas = DB::select(
                    "SELECT d.ubigeo, d.distrito, p.provincia, dep.departamento
                     FROM ubigeo_distritos d
                     LEFT JOIN ubigeo_provincias p ON p.id = d.provincia_id
                     LEFT JOIN ubigeo_departamentos dep ON dep.id = d.departamento_id
                     WHERE d.distrito LIKE ? OR p.provincia LIKE ? OR d.ubigeo LIKE ?
                     ORDER BY d.distrito
                     LIMIT 15",
                    ['%' . $texto . '%', '%' . $texto . '%', $texto . '%']
                );

                foreach ($filas as $fila) {
                    $partes = array_filter([
                        (string) $fila->distrito,
                        (string) ($fila->provincia ?? ''),
                        (string) ($fila->departamento ?? ''),
                    ], fn (string $parte) => $parte !== '');

                    $resultados[] = [
                        'id' => (string) $fila->ubigeo,
                        'texto' => implode(', ', $partes) . ' · ' . $fila->ubigeo,
                    ];
                }
            } else {
                $filas = DB::select(
                    "SELECT id, nombre, palabra_clave, distrito_ubigeo
                     FROM locales
                     WHERE activo = 1 AND (nombre LIKE ? OR palabra_clave LIKE ? OR id LIKE ?)
                     ORDER BY nombre
                     LIMIT 15",
                    ['%' . $texto . '%', '%' . $texto . '%', '%' . $texto . '%']
                );

                foreach ($filas as $fila) {
                    $distrito = (string) ($fila->distrito_ubigeo ?? '');
                    $nombreDistrito = $distrito !== '' ? (\App\Prediccion\Geoapify::nombre_ubigeo($distrito) ?? '') : '';
                    $resultados[] = [
                        'id' => (string) $fila->id,
                        'texto' => (string) $fila->nombre
                            . ($nombreDistrito !== '' ? ' · ' . $nombreDistrito : '')
                            . ' · ' . $fila->id,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['resultados' => [], 'error' => 'No se pudo buscar'], 200);
        }

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * Predicciones guardadas (`prediccion_resultados`) en su propia pantalla.
     *
     * Antes esta tarjeta estaba al final de la tabla de aciertos; ahora tiene su
     * página, con filtros propios (rango, origen y filas) y la explicación de cómo
     * se llena.
     */
    public static function guardadas(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'origen' => 'nullable|string|max:10',
            'limite' => 'nullable|integer|in:' . implode(',', self::FILAS_GUARDADAS),
        ]);

        $desde = (string) $request->input('fecha_inicio', '') ?: now()->subDays(29)->toDateString();
        $hasta = (string) $request->input('fecha_fin', '') ?: now()->toDateString();

        $limite = (int) $request->input('limite', self::FILAS_GUARDADAS[0]);
        if (!in_array($limite, self::FILAS_GUARDADAS, true)) {
            $limite = self::FILAS_GUARDADAS[0];
        }

        $filtros = [
            'desde' => $desde,
            'hasta' => $hasta,
            'origen' => trim((string) $request->input('origen', '')),
            'limite' => $limite,
        ];

        $resumen = \App\Database\DBPrediccionResultados::resumen(
            Carbon::parse($desde), Carbon::parse($hasta), 2000, $filtros['origen'], $limite);

        return view('lupita.prediccion_guardadas', array_merge(self::comunes(), [
            'filtros' => $filtros,
            'origenes' => self::ORIGENES_GUARDADAS,
            'limites' => self::FILAS_GUARDADAS,
            'resumen' => $resumen,
            'tablero' => self::tablero_guardadas($desde, $hasta, $resumen, $request->boolean('refrescar')),
        ]));
    }

    /**
     * Arma los KPIs de "Predicciones guardadas".
     *
     * Son tres varas distintas y se muestran separadas a propósito:
     *
     *  - producción: lo que se guardó y ya presentó. Muestra chica (y se avisa),
     *    porque el historial tiene pocas referencias distintas;
     *  - motor: la misma corrida de la tabla de aciertos (MAE, tolerancias,
     *    ventana, y cómo se equivoca por evidencia / horizonte / confianza);
     *  - monitorista: `compromiso_carga` contra la presentación real, más el cara
     *    a cara contra el motor sobre las MISMAS referencias.
     *
     * @param  array<string, mixed>  $resumen  salida de DBPrediccionResultados::resumen()
     * @return array<string, mixed>
     */
    private static function tablero_guardadas(string $desde, string $hasta, array $resumen,
                                              bool $refrescar = false): array
    {
        $tolerancia = \App\Database\DBPrediccionResultados::TOLERANCIA_ACEPTADA;
        $comparables = $resumen['comparables'] ?? [];
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        // ---- producción: lo guardado que ya presentó -------------------------
        $produccion = [
            'errores' => Tablero::errores(array_column($comparables, 'error_min'), $tolerancia),
            'por_evidencia' => Tablero::por_evidencia($comparables, $tolerancia),
            'por_origen' => Tablero::por_origen($comparables, $tolerancia),
            'por_confianza' => Tablero::por_confianza($comparables, $tolerancia),
            'por_horizonte' => Tablero::por_horizonte($comparables, $tolerancia),
            'posicion' => Tablero::posicion($comparables),
            'refs' => (int) ($resumen['refs'] ?? 0),
            'sin_ref' => (int) ($resumen['sin_ref'] ?? 0),
            'muestra_suficiente' => (int) ($resumen['n'] ?? 0) >= Tablero::MUESTRA_MINIMA,
        ];

        // ---- motor: la corrida de aciertos (cacheada 10 min) -----------------
        $llave = 'prediccion:guardadas:motor:v1:' . md5($inicio->toDateString() . '|' . $fin->toDateString());

        if ($refrescar) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        $corrida = null;
        $errorCorrida = null;

        try {
            $corrida = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => Servicio::backtest([
                    'desde' => $inicio->toDateString(),
                    'hasta' => $fin->toDateString(),
                    'limite' => self::FILAS_MOTOR,
                ])
            );
        } catch (\Throwable $e) {
            $errorCorrida = 'No se pudo medir el motor: ' . $e->getMessage();
        }

        $motor = null;
        $errorMotorPorRef = [];
        $horizonteMotor = [];

        if ($corrida !== null) {
            $motor = [
                'n' => count($corrida->filas),
                'resumen' => $corrida->resumen_motor,
                'por_evidencia' => Tablero::por_evidencia($corrida->filas, $tolerancia),
                'por_horizonte' => Tablero::por_horizonte($corrida->filas, $tolerancia, 'ancla', 'dentro'),
                'por_confianza' => Tablero::por_confianza($corrida->filas, $tolerancia),
                'posicion' => Tablero::posicion($corrida->filas),
                'desde' => (string) $corrida->desde,
                'hasta' => (string) $corrida->hasta,
                'sin_ruta' => (int) $corrida->sin_ruta,
                'descartados' => (int) $corrida->descartados,
            ];

            foreach ($corrida->filas as $fila) {
                if ($fila->error_min === null) {
                    continue;
                }

                $errorMotorPorRef[(string) $fila->ref] = (int) $fila->error_min;
            }

            // cuántas horas antes del hecho predice el motor (contra las 25 h de
            // antelación del compromiso): sin esto el cara a cara engaña
            $motor['horizonte_min'] = self::mediana_minutos($corrida->filas, 'ancla', 'real');
        }

        // ---- monitorista: compromiso_carga contra la realidad -----------------
        $compromisos = \App\Database\DBPrediccion::compromisos($inicio, $fin);

        $errorCompromisoPorRef = [];
        $validos = [];
        $descartados = 0;
        $conCompromiso = 0;
        $ultimoCompromiso = null;

        foreach ($compromisos as $fila) {
            if ($fila->compromiso === null || $fila->real === null) {
                continue;
            }

            // se mide sobre la presentación REAL del rango: una ref cuyo compromiso
            // cae en el rango pero que presentó fuera (o al revés) no aporta
            if ($fila->real->lessThan($inicio) || $fila->real->greaterThan($fin)) {
                continue;
            }

            $minutos = (int) round(($fila->real->getTimestamp() - $fila->compromiso->getTimestamp()) / 60);

            // compromiso imposible (fechas del año cero o promesas de dentro de
            // meses): contamina el MAE y el "% cumplió", así que no se mide
            if (abs($minutos) > Tablero::TOPE_COMPROMISO_MIN) {
                $descartados++;

                continue;
            }

            $validos[] = $fila;
            $errorCompromisoPorRef[(string) $fila->ref] = $minutos;

            // cobertura: de las refs que presentaron en el rango, cuántas traen
            // compromiso cargado. Sin esto el acierto del monitorista se lee como
            // si fuera de toda la operación y no de una parte.
            $conCompromiso++;

            if ($ultimoCompromiso === null || $fila->compromiso->greaterThan($ultimoCompromiso)) {
                $ultimoCompromiso = $fila->compromiso;
            }
        }

        $monitorista = Tablero::monitorista($validos, $tolerancia);

        $refsRango = \App\Database\DBPrediccion::refs_presentadas($inicio, $fin);

        $monitorista['refs_rango'] = $refsRango;
        $monitorista['con_compromiso'] = $conCompromiso;
        $monitorista['cobertura'] = $refsRango > 0 ? (int) round(100 * $conCompromiso / $refsRango) : null;
        $monitorista['ultimo_compromiso'] = $ultimoCompromiso?->toDateString();
        $monitorista['descartados'] = $descartados;
        $monitorista['muestra_suficiente'] = $monitorista['n'] >= Tablero::MUESTRA_MINIMA;

        // ---- parámetros en uso: con qué está corriendo el motor ---------------
        $p = Parametros::cargar();

        return [
            'tolerancia' => $tolerancia,
            'desde' => $inicio->toDateString(),
            'hasta' => $fin->toDateString(),
            'produccion' => $produccion,
            'motor' => $motor,
            'error_motor' => $errorCorrida,
            'monitorista' => $monitorista,
            'cara_a_cara' => Tablero::cara_a_cara($errorMotorPorRef, $errorCompromisoPorRef),
            'parametros' => [
                ['clave' => 'cruce_pares_k', 'valor' => $p->cruce_pares_k ?? null,
                 'texto' => 'peso del viaje propio de la placa contra el compilado de la ruta'],
                ['clave' => 'ventana_adelanto_min', 'valor' => $p->ventana_adelanto_min ?? null,
                 'texto' => 'minutos antes de la hora publicada en que se abre la fecha "rápida"'],
                ['clave' => 'ventana_atraso_min', 'valor' => $p->ventana_atraso_min ?? null,
                 'texto' => 'minutos después en que se cierra la "tardada"'],
                ['clave' => 'ventana_cobertura', 'valor' => $p->ventana_cobertura ?? null,
                 'texto' => 'cobertura que se le pide a esa ventana (medida sobre el error real)'],
                ['clave' => 'dia_min_pares', 'valor' => $p->dia_min_pares ?? null,
                 'texto' => 'muestra mínima por día de la semana para creerle al factor propio'],
                ['clave' => 'cola_llegadas_min', 'valor' => $p->cola_llegadas_min ?? null,
                 'texto' => 'llegadas por hora a la planta desde las que se suma espera'],
                ['clave' => 'cola_espera_extra_min', 'valor' => $p->cola_espera_extra_min ?? null,
                 'texto' => 'minutos extra de espera cuando hay cola'],
                ['clave' => 'horario_viajes_k', 'valor' => $p->horario_viajes_k ?? null,
                 'texto' => 'peso de la celda día/hora del viaje: w = viajes/(viajes+k)'],
                ['clave' => 'pares_recientes_max', 'valor' => $p->pares_recientes_max ?? null,
                 'texto' => 'cuántos huecos recientes de la placa se usan para calibrar'],
            ],
        ];
    }

    /**
     * Mediana de la diferencia en minutos entre dos fechas de un campo de las
     * filas del backtest (sirve para saber con cuánta antelación predice el motor).
     *
     * @param  array<int, object>  $filas
     */
    private static function mediana_minutos(array $filas, string $desde, string $hasta): ?int
    {
        $valores = [];
        foreach ($filas as $fila) {
            $a = $fila->{$desde} ?? null;
            $b = $fila->{$hasta} ?? null;
            if (!$a instanceof Carbon || !$b instanceof Carbon) {
                continue;
            }

            $valores[] = ($b->getTimestamp() - $a->getTimestamp()) / 60;
        }

        $mediana = \App\Prediccion\Estadistica::mediana($valores);

        return $mediana === null ? null : (int) round($mediana);
    }

    /**
     * Pantalla de parámetros del motor (vista propia).
     *
     * Reúne lo que antes estaba incrustado abajo de la calculadora y de la tabla de
     * aciertos, con la explicación de cómo se usa: qué se puede tocar a mano, qué se
     * calibra con un botón, qué queda como está y qué pasa cuando aplicás.
     */
    public static function parametros()
    {
        $enUso = Parametros::cargar();

        return view('lupita.prediccion_parametros', array_merge(self::comunes(), [
            'en_uso' => $enUso,
            // lo que dice `config/prediccion.php`, para que la pantalla muestre
            // cuánto se apartó el valor guardado del de fábrica
            'por_defecto' => Parametros::por_defecto(),
            'calibrables' => self::PARAMETROS_CALIBRABLES,
            'rejilla_cruce' => self::REJILLA_CRUCE,
            // lo que el motor mira y no se toca desde acá (se mide o se calibra solo)
            'fijos' => [
                ['clave' => 'horario_activo', 'valor' => (int) ($enUso->horario_activo ?? 1),
                 'texto' => 'El viaje sale del compilado POR HORA de la ruta (tiempos_ruta_horario)'],
                ['clave' => 'horario_viajes_k', 'valor' => (int) ($enUso->horario_viajes_k ?? 3),
                 'texto' => 'Peso de la celda día/hora: w = viajes/(viajes+k)'],
                ['clave' => 'descontar_transcurrido', 'valor' => (int) ($enUso->descontar_transcurrido ?? 1),
                 'texto' => 'Descuenta el tiempo ya manejado y, si la mediana se consumió, espera hasta el p75'],
                ['clave' => 'dia_activo', 'valor' => (int) ($enUso->dia_activo ?? 1),
                 'texto' => 'El hueco se multiplica por el factor del día (ver dia_modo)'],
                ['clave' => 'dia_modo', 'valor' => (int) ($enUso->dia_modo ?? 1),
                 'texto' => 'De dónde sale el factor del día: 0 apagado · 1 flota (prior lunes 2,68) · '
                     . '2 sólo placa con muestra · 3 compilado por ruta (tiempos_ruta_horario) · 4 día del ancla'],
                ['clave' => 'dia_compilado_k', 'valor' => (int) ($enUso->dia_compilado_k ?? 10),
                 'texto' => 'Encogimiento del factor compilado por muestra: 1 + (ratio-1)·viajes/(viajes+k)'],
                ['clave' => 'curva_modo', 'valor' => (int) ($enUso->curva_modo ?? 0),
                 'texto' => 'Corrección por hora del ancla: 0 residuo absoluto (viejo) · 1 proporcional y '
                     . 'encogido · 2 apagada'],
                ['clave' => 'curva_k', 'valor' => (int) ($enUso->curva_k ?? 5),
                 'texto' => 'Peso de la franja horaria en el modo 1: n/(n+k)'],
                ['clave' => 'feriado_activo', 'valor' => (int) ($enUso->feriado_activo ?? 1),
                 'texto' => 'Un feriado usa el peor factor de la semana'],
                ['clave' => 'cola_activa', 'valor' => (int) ($enUso->cola_activa ?? 1),
                 'texto' => 'Con 11+ llegadas por hora a la planta se suman minutos a la espera para cargar'],
                ['clave' => 'ventana_residuos', 'valor' => (int) ($enUso->ventana_residuos ?? 0),
                 'texto' => 'La ventana rápido/tardado sale de los residuos medidos, no del p25-p75 del hueco'],
            ],
        ]));
    }

    /** Guarda los parámetros editables en `prediccion_config`. */
    public static function guardar_parametros(Request $request)
    {
        $valores = [];
        foreach (array_merge(self::PARAMETROS_EDITABLES, self::PARAMETROS_CALIBRABLES) as $clave) {
            if ($request->filled($clave)) {
                $valores[$clave] = (int) $request->input($clave);
            }
        }

        try {
            Parametros::guardar($valores);
            $mensaje = 'Parámetros guardados (' . count($valores) . ' claves).';
        } catch (\Throwable $e) {
            $mensaje = 'No se pudieron guardar los parámetros: ' . $e->getMessage();
        }

        // vuelve a la pantalla desde donde se guardó (calculadora o aciertos);
        // si no hay referrer, a la calculadora
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.presenta'))
            ->with('estado', $mensaje);
    }

    /**
     * Calcula la ventana rápido/tardado con los RESIDUOS del backtest y deja la
     * recomendación en pantalla (mismo patrón que `calibrar_cruce`).
     *
     * El p25-p75 del hueco contiene el valor real ~35 % de las veces; el radio
     * medido sobre el error real cubre la cobertura pedida (70 % por defecto).
     */
    public static function calibrar_ventana(Request $request)
    {
        $request->validate([
            'limite' => 'nullable|integer|min:10|max:300',
            'cobertura' => 'nullable|numeric|min:0.3|max:0.95',
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $parametros = Parametros::cargar();
        $cobertura = (float) $request->input('cobertura', $parametros->ventana_cobertura ?? 0.7);
        $cobertura = max(0.3, min(0.95, $cobertura));

        $filtros = ['limite' => (int) $request->input('limite', 600)];
        $llave = 'prediccion:ventana:v1:' . md5(json_encode([$filtros, $cobertura]));

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        try {
            $medicion = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => Servicio::ventana_residuos($filtros, $cobertura,
                    (int) ($parametros->ventana_min_filas ?? 40))
            );
        } catch (\Throwable $e) {
            $medicion = ['ok' => false, 'error' => 'No se pudo medir: ' . $e->getMessage()];
        }

        // vuelve a la pantalla desde donde se disparó (calculadora o aciertos)
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.presenta'))
            ->with('ventana_medida', $medicion);
    }

    /** Catálogos y parámetros que comparten las dos pantallas. */
    private static function comunes(): array
    {
        return [
            'catalogos' => Servicio::catalogos(),
            'parametros_editables' => self::PARAMETROS_EDITABLES,
        ];
    }

    /**
     * Calibra `cruce_pares_k` y deja la recomendación en pantalla.
     *
     * OJO: NO se mide con la tabla de aciertos. Esa tabla sólo evalúa refs con el
     * viaje anterior CERRADO, y ahí `restante_viaje` no se usa: el cruce
     * compilado/placa no cambia nada y todos los valores empatan (por eso antes
     * recomendaba siempre 1, el primero de la lista). Se mide contra el e4 real de
     * cada viaje (`Servicio::calibrar_cruce()`), con leave-one-out.
     */
    public static function calibrar_cruce(Request $request)
    {
        $request->validate(['dias' => 'nullable|integer|min:7|max:365']);

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $filtros = [
            'desde' => now()->subDays(max(7, (int) $request->input('dias', 90)))->toDateString(),
            'hasta' => now()->toDateString(),
        ];

        $llave = 'prediccion:calibrar:cruce:v2:' . md5(json_encode($filtros));

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        try {
            $medicion = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => Servicio::calibrar_cruce($filtros)
            );
        } catch (\Throwable $e) {
            $medicion = ['ok' => false, 'error' => 'No se pudo medir: ' . $e->getMessage()];
        }

        $calibracion = self::calibracion_para_vista($medicion);

        // vuelve a la pantalla desde donde se disparó
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.parametros.ver'))
            ->with('calibracion_cruce', $calibracion);
    }

    /**
     * Traduce la medición de `cruce_pares_k` a la forma que dibuja la pantalla.
     *
     * @param  array<string, mixed>  $m  salida de Servicio::calibrar_cruce()
     * @return array<string, mixed>
     */
    private static function calibracion_para_vista(array $m): array
    {
        if (!($m['ok'] ?? false)) {
            return ['ok' => false, 'error' => $m['error'] ?? 'No se pudo medir.'];
        }

        $mejor = $m['mejor'];
        $rec = $m['recomendacion'];
        $resumen = fn (array $fila) => (object) [
            'mae_min' => $fila['mae'],
            'n' => $fila['n'],
            'pct_180' => null,
            'pct_ventana' => null,
        ];

        $resultados = [];
        foreach ($m['valores'] as $fila) {
            $resultados[] = [
                'valores' => ['cruce_pares_k' => $fila['k']],
                'etiqueta' => 'cruce_pares_k=' . $fila['k'],
                'entrenamiento' => $resumen($fila),
                'validacion' => $resumen($fila),
                'puntaje' => $fila['mae'],
                'error' => null,
                'elegido' => $fila['k'] === $mejor['k'],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'fuente' => 'viaje',
            'n' => $m['n'],
            'rutas' => $m['rutas'],
            'placas' => $m['placas'],
            'ruido' => $m['ruido'],            'combinaciones' => count($m['valores']),
            'limite' => $m['n'],
            'metrica' => 'mae',
            'entrenamiento' => ['desde' => $m['desde'], 'hasta' => $m['hasta']],
            'validacion' => ['desde' => $m['desde'], 'hasta' => $m['hasta']],
            'actual' => [
                'entrenamiento' => $resumen(['mae' => $m['actual']['mae'], 'n' => $m['n']]),
                'validacion' => $resumen(['mae' => $m['actual']['mae'], 'n' => $m['n']]),
            ],
            'mejor' => [
                'valores' => ['cruce_pares_k' => $mejor['k']],
                'etiqueta' => 'cruce_pares_k=' . $mejor['k'],
                'entrenamiento' => $resumen($mejor),
                'validacion' => $resumen($mejor),
                'puntaje' => $mejor['mae'],
            ],
            'recomendacion' => [[
                'clave' => 'cruce_pares_k',
                'actual' => $rec['actual'],
                'recomendado' => $rec['recomendado'],
                'difiere' => $rec['recomendado'] !== $rec['actual'],
                'mejora' => $rec['mejora'],
            ]],
            'resultados' => $resultados,
        ];
    }

    /**
     * Corre la ABLACIÓN del motor y deja la tabla en pantalla.
     *
     * Es el mismo instrumento que el comando `prediccion:ablacion`, con la lista
     * corta de variantes para que pueda correr desde el navegador: compara la
     * configuración en uso contra apagar el factor de día, usar el compilado por
     * ruta y volver a la de antes. Los parámetros se pisan en memoria: no se guarda
     * nada en `prediccion_config`.
     */
    public static function ablacion(Request $request)
    {
        $request->validate([
            'dias' => 'nullable|integer|min:7|max:365',
            'limite' => 'nullable|integer|min:10|max:1000',
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $filtros = [
            'desde' => now()->subDays(max(7, (int) $request->input('dias', 90)))->toDateString(),
            'hasta' => now()->toDateString(),
            'limite' => max(10, (int) $request->input('limite', 300)),
        ];

        $llave = 'prediccion:ablacion:v1:' . md5(json_encode($filtros));

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        try {
            $medicion = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(15),
                fn () => \App\Prediccion\Ablacion::correr($filtros, \App\Prediccion\Ablacion::VARIANTES_WEB)
            );
        } catch (\Throwable $e) {
            $medicion = ['error' => 'No se pudo medir: ' . $e->getMessage(), 'filas' => []];
        }

        return redirect()->route('lupita.prediccion.parametros.ver')->with('ablacion', $medicion);
    }

    private static function carbon(?string $valor): ?Carbon
    {
        return $valor !== null && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
