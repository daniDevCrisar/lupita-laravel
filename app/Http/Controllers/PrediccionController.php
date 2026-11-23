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

    /** Tope del JSON de configuración que se puede importar (256 KB). */
    public const TAMANO_MAX_CONFIG = 262144;

    /** Calculadora: referencia a predecir + viaje ancla + ubicación declarada. */
    /**
     * Filas que se pueden pedir a la tabla de aciertos. `0` = todas (las que entren
     * en `Servicio::TOPE_EVALUACION`).
     *
     * OJO: es cuántas refs se EVALÚAN, no cuántas se muestran. Calibrar con 100
     * filas es ruido (la validación queda con 10-20 filas y cualquier parámetro
     * gana por suerte); la corrida tarda ~5 ms por fila, así que 1.000 son ~4 s y
     * 3.000 son ~7 s (se cachea 10 minutos).
     */
    public const FILAS_ACIERTOS = [10, 40, 80, 100, 300, 600, 1000, 2000, 0];

    /** Filas que se dibujan como máximo en la tabla (el resto no hace falta verlo). */
    public const FILAS_TABLA_ACIERTOS = 100;

    /**
     * Límite de refs de "Predicciones guardadas": cuántas se cargan para las
     * métricas y cuántas se le pasan al motor. `0` = todas (acotado por
     * `Servicio::TOPE_EVALUACION` / `DBPrediccionResultados::TOPE_FILAS`).
     *
     * El motor tarda ~5 ms por fila: 2.000 son ~10 s y 10.000 ~50 s, y la corrida
     * se cachea 10 minutos. Los valores chicos siguen estando para mirar rápido.
     */
    public const FILAS_GUARDADAS = [50, 100, 300, 600, 2000, 5000, 10000, 0];

    /** Límite con el que abre la pantalla de guardadas (2.000 refs). */
    public const FILAS_GUARDADAS_VALOR = 2000;

    /** Filas que se dibujan en la tabla de abajo (el resto sólo suma a las métricas). */
    public const FILAS_TABLA_GUARDADAS = 200;

    /**
     * Tope de refs de la CORRIDA DEL MOTOR (no del listado).
     *
     * El listado y las métricas de producción aguantan 10.000 o "todas" porque son
     * filas de base nomás. La corrida del motor no: medido, 2.000 filas dejan
     * 394 MB de pico y 5.000 agotan los 512 MB del host. Encima del tope se mide
     * con el tope y la pantalla lo avisa.
     */
    public const FILAS_MOTOR_TOPE = 2000;

    /**
     * Tope de horizonte de la tabla de aciertos, en HORAS.
     *
     * No es cosmético: el motor acierta dentro de ±3 h el 57 % de las veces cuando
     * el camión se presenta antes de 6 h desde el corte, y el 6 % cuando pasa de
     * 24 h. Sumar todo en un solo MAE castiga al motor por casos que no dependen
     * del modelo (el camión se quedó días sin volver).
     */
    public const HORIZONTES_ACIERTOS = [0, 6, 12, 24, 48, 72];

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
            'horizonte_max_h' => 'nullable|integer|min:0|max:168',
        ]);

        $limite = (int) $request->input('limite', self::FILAS_ACIERTOS[0]);
        if (!in_array($limite, self::FILAS_ACIERTOS, true)) {
            $limite = self::FILAS_ACIERTOS[0];
        }

        // 0 = sin tope. Con tope, las filas donde el camión quedó más de N horas
        // sin presentarse se ven pero NO entran en el MAE ni en las tolerancias: en
        // ese tramo el motor no tiene con qué acertar (medido: hasta 6 h de
        // horizonte el MAE es 338 y ±3 h 57 %; por encima de 24 h, 6 %).
        $horizonteMax = (int) $request->input('horizonte_max_h', 0);
        if (!in_array($horizonteMax, self::HORIZONTES_ACIERTOS, true)) {
            $horizonteMax = 0;
        }

        $filtros = [
            'desde' => $request->input('fecha_inicio'),
            'hasta' => $request->input('fecha_fin'),
            'placa' => trim((string) $request->input('placa', '')),
            'limite' => $limite,
            'horizonte_max_h' => $horizonteMax,
        ];

        // La corrida tarda ~5 ms por fila: se cachea 10 minutos. Con
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

        // La tabla muestra como máximo FILAS_TABLA_ACIERTOS filas: el resto no hace
        // falta verlo (las métricas se calculan sobre todas las evaluadas). Con el
        // check "solo dentro del tope" se esconden las que quedaron fuera.
        $soloDentro = $request->boolean('solo_dentro_tope');
        $filasTabla = $aciertos->filas;
        if ($soloDentro) {
            $filasTabla = array_values(array_filter($filasTabla,
                static fn (object $fila) => !($fila->fuera_tope ?? false)));
        }

        return view('lupita.prediccion_aciertos', array_merge(self::comunes(), [
            'filtros' => $filtros,
            'aciertos' => $aciertos,
            'calculo_ms' => $calculoMs,
            'solo_dentro_tope' => $soloDentro,
            'filas_tabla' => array_slice($filasTabla, 0, self::FILAS_TABLA_ACIERTOS),
            'filas_candidatas' => count($filasTabla),
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
            'horizonte_max_h' => 'nullable|integer|min:0|max:168',
        ]);

        $filtros = [
            'desde' => $request->input('fecha_inicio'),
            'hasta' => $request->input('fecha_fin'),
            'placa' => trim((string) $request->input('placa', '')),
            'limite' => (int) $request->input('limite', self::FILAS_ACIERTOS[0]),
            // el tope de horizonte viaja con los filtros: si no, la corrida del
            // botón sería otra que la de la tabla y el guardado no coincidiría
            'horizonte_max_h' => (int) $request->input('horizonte_max_h', 0),
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
            'horizonte_max_h' => $filtros['horizonte_max_h'] ?? null,
            'solo_dentro_tope' => $filtros['solo_dentro_tope'] ?? null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** Llave de caché de la tabla de aciertos (misma para mostrar y para guardar). */
    private static function llave_aciertos(array $filtros): string
    {
        // El número de versión sube cuando cambia la forma de las filas o de la
        // corrida (v6: horizonte por fila y tope de horizonte en las métricas).
        return 'prediccion:aciertos:v6:' . md5(json_encode([$filtros, (array) Parametros::cargar()]));
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
            'local' => 'nullable|string|max:100',
            'horizonte' => 'nullable|integer|in:' . implode(',', self::HORIZONTES_ACIERTOS),
            'limite' => 'nullable|integer|in:' . implode(',', self::FILAS_GUARDADAS),
        ]);

        $desde = (string) $request->input('fecha_inicio', '') ?: now()->subDays(29)->toDateString();
        $hasta = (string) $request->input('fecha_fin', '') ?: now()->toDateString();

        $limite = (int) $request->input('limite', self::FILAS_GUARDADAS_VALOR);
        if (!in_array($limite, self::FILAS_GUARDADAS, true)) {
            $limite = self::FILAS_GUARDADAS_VALOR;
        }

        // LOCAL = la planta de carga: el local donde el camión se presenta a cargar
        // (el origen de la ruta). Filtra lo guardado y también la corrida del motor,
        // para que las dos varas de la pantalla hablen del mismo local.
        $local = trim((string) $request->input('local', ''));

        // HORIZONTE = tope en horas entre el ancla y la presentación real (0 = sin
        // tope). Las filas con horizonte mayor quedan fuera de TODAS las métricas de
        // la pantalla, igual que en la tabla de aciertos.
        $horizonte = (int) $request->input('horizonte', 0);
        if (!in_array($horizonte, self::HORIZONTES_ACIERTOS, true)) {
            $horizonte = 0;
        }

        $filtros = [
            'desde' => $desde,
            'hasta' => $hasta,
            'origen' => trim((string) $request->input('origen', '')),
            'local' => $local,
            'horizonte' => $horizonte,
            'limite' => $limite,
        ];

        // `limite` = cuántas refs se cargan para las métricas (0 = todas, con tope
        // interno); la tabla de abajo dibuja sólo las primeras `FILAS_TABLA_GUARDADAS`.
        $resumen = \App\Database\DBPrediccionResultados::resumen(
            Carbon::parse($desde), Carbon::parse($hasta), $limite, $filtros['origen'],
            self::FILAS_TABLA_GUARDADAS, $local !== '' ? $local : null);

        // Qué fechas entran en la comparación de la tarjeta de producción.
        // `comparar` viaja SÓLO si se apretó el botón: así "no tildé ninguna" no
        // se confunde con "todavía no elegí" (un checkbox destildado no manda nada).
        $seleccionFechas = null;
        $avisoFechas = null;

        if ($request->boolean('comparar')) {
            $validas = [];
            foreach ((array) $request->input('fechas', []) as $clave) {
                if (is_string($clave) && in_array($clave, \App\Prediccion\Tablero::FECHAS, true)) {
                    $validas[] = $clave;
                }
            }

            if ($validas === []) {
                $avisoFechas = 'No tildaste ninguna fecha: se comparan todas las que tengan datos en el rango.';
            } else {
                $seleccionFechas = $validas;
            }
        }

        $tablero = self::tablero_guardadas($desde, $hasta, $resumen,
            $request->boolean('refrescar'), $seleccionFechas, $request->boolean('promediar'),
            $local !== '' ? $local : null, $limite, $horizonte);

        if ($avisoFechas !== null) {
            $tablero['fechas']['aviso'] = $avisoFechas;
        }

        return view('lupita.prediccion_guardadas', array_merge(self::comunes(), [
            'filtros' => $filtros,
            'origenes' => self::ORIGENES_GUARDADAS,
            'limites' => self::FILAS_GUARDADAS,
            'horizontes' => self::HORIZONTES_ACIERTOS,
            // plantas para el combo con búsqueda (local donde presenta a cargar)
            'plantas' => \App\Database\DBPrediccion::plantas_carga(),
            'resumen' => $resumen,
            'tablero' => $tablero,
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
                                              bool $refrescar = false, ?array $seleccionFechas = null,
                                              bool $promediarFechas = false, ?string $local = null,
                                              int $limiteMotor = 300, int $horizonteMaxH = 0): array
    {
        $tolerancia = \App\Database\DBPrediccionResultados::TOLERANCIA_ACEPTADA;
        $comparables = $resumen['comparables'] ?? [];
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        // HORIZONTE de cada fila de producción: lo que pasó entre el ÚLTIMO evento
        // real de la placa antes del corte (el ancla que usa el motor) y la
        // presentación real. Es el número que explica por qué el motor acierta o
        // no: medido, hasta 6 h el MAE es ~340 min y pasando 24 h el acierto a ±3 h
        // cae al 6 %. Se calcula con los hitos de la placa en una sola consulta.
        $horizontes = self::horizontes_produccion($comparables, $inicio, $fin);

        // FILTRO POR HORIZONTE: se quedan las filas cuyo horizonte real entra en el
        // tope pedido (0 = sin tope). Las que no tienen horizonte calculable (sin
        // ancla) también quedan fuera cuando se pide un tope, y se informan aparte.
        $fueraHorizonte = 0;
        $sinHorizonte = 0;

        if ($horizonteMaxH > 0) {
            $tope = $horizonteMaxH * 60;
            $comparables = array_values(array_filter($comparables, function ($fila) use ($tope, &$fueraHorizonte, &$sinHorizonte) {
                $minutos = $fila->horizonte_min ?? null;

                if ($minutos === null) {
                    $sinHorizonte++;

                    return false;
                }

                if ((int) $minutos > $tope) {
                    $fueraHorizonte++;

                    return false;
                }

                return true;
            }));
        }

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
            'horizonte_mediano' => $horizontes['mediano'],
            'horizonte_n' => $horizontes['n'],
            'horizonte_corto' => $horizontes['corto'],
            'horizonte_largo' => $horizontes['largo'],
            // filtro por horizonte: qué tope se pidió y cuántas filas dejó afuera
            'horizonte_filtro' => $horizonteMaxH,
            'horizonte_fuera' => $fueraHorizonte,
            'horizonte_sin' => $sinHorizonte,
            'horizonte_dentro' => count($comparables),
        ];

        // las cuatro fechas publicadas, cada una con su error y su escalera.
        // La comparación es cara a cara: sólo filas que tengan las fechas elegidas.
        // Con `promediar` se agrega una fila más: el promedio de las elegidas.
        $cuatroFechas = Tablero::fechas($comparables, $tolerancia, $seleccionFechas, $promediarFechas);

        // y las mismas fechas DENTRO de cada segmento: evidencia, horizonte,
        // confianza y origen. Antes cada desglose medía sólo la normal.
        $produccion['fechas_por'] = [
            'evidencia' => Tablero::fechas_por($comparables, 'evidencia', $tolerancia, $seleccionFechas),
            'horizonte' => Tablero::fechas_por($comparables, 'horizonte', $tolerancia, $seleccionFechas),
            'confianza' => Tablero::fechas_por($comparables, 'confianza', $tolerancia, $seleccionFechas),
            'origen' => Tablero::fechas_por($comparables, 'origen', $tolerancia, $seleccionFechas),
        ];

        // Sin elección y sin NINGUNA fila completa, se cae a las fechas que sí
        // tienen datos: las guardadas viejas no traen la cuarta, y abrir la
        // pantalla con la tabla vacía parece que el motor dejó de medir.
        if ($seleccionFechas === null && (int) $cuatroFechas['n'] === 0 && (int) $cuatroFechas['total'] > 0) {
            $conDatos = [];
            $etiquetas = [];
            foreach ($cuatroFechas['opciones'] as $opcion) {
                if ((int) $opcion['faltan'] < (int) $cuatroFechas['total']) {
                    $conDatos[] = $opcion['clave'];
                    $etiquetas[] = $opcion['etiqueta'];
                }
            }

            if ($conDatos !== []) {
                $cuatroFechas = Tablero::fechas($comparables, $tolerancia, $conDatos);
                $cuatroFechas['aviso'] = 'Ninguna fila guardada en este rango tiene las cuatro fechas:'
                    . ' se comparan las que sí tienen datos (' . implode(', ', $etiquetas) . ').'
                    . ' Las demás guardadas se llenan prediciendo de nuevo.';

                // Los cuatro desgloses siguen la misma elección: si se está
                // midiendo un subconjunto de fechas, se mide ese subconjunto.
                foreach (['evidencia', 'horizonte', 'confianza', 'origen'] as $dimension) {
                    $produccion['fechas_por'][$dimension] = Tablero::fechas_por(
                        $comparables, $dimension, $tolerancia, $conDatos);
                }
            }
        }

        // ---- motor: la corrida de aciertos (cacheada 10 min) -----------------
        // La llave incluye el local y el límite: con esos filtros la corrida es
        // otra y no se puede reusar la de antes.
        // El motor sigue el combo pero con su tope de memoria (ver FILAS_MOTOR_TOPE).
        $limiteMotorEfectivo = max(300, min(
            $limiteMotor > 0 ? $limiteMotor : self::FILAS_MOTOR_TOPE,
            self::FILAS_MOTOR_TOPE));

        $llave = 'prediccion:guardadas:motor:v4:'
            . md5($inicio->toDateString() . '|' . $fin->toDateString() . '|' . (string) $local
                . '|' . $limiteMotorEfectivo . '|' . $horizonteMaxH);

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
                    // el motor sigue el combo pero con su propio tope de memoria
                    'limite' => $limiteMotorEfectivo,
                    // misma planta de carga que el filtro de la pantalla
                    'planta' => $local,
                    // y el mismo tope de horizonte (0 = sin tope)
                    'horizonte_max_h' => $horizonteMaxH,
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
                // cuántas de esas filas entran en las métricas (con tope de horizonte)
                'dentro' => (int) ($corrida->resumen_motor->n ?? 0),
                'fuera_tope' => (int) ($corrida->fuera_tope ?? 0),
                // con cuántas refs se midió (el combo puede pedir más)
                'limite' => $limiteMotorEfectivo,
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
            'fechas' => $cuatroFechas,
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
            // qué está gobernando HOY en cada calibración: sin esto, cruce, ventana
            // y sesgo escriben claves que no se ven en ningún formulario
            'en_uso_calibraciones' => [
                'cruce' => Parametros::en_uso(['cruce_pares_k']),
                'ventana' => Parametros::en_uso([
                    'ventana_residuos', 'ventana_adelanto_min', 'ventana_atraso_min',
                    'ventana_adelanto_cadena', 'ventana_atraso_cadena',
                    'ventana_cobertura', 'ventana_min_filas',
                ]),
                'sesgo' => Parametros::en_uso(array_merge(
                    ['sesgo_clase_activo'], array_keys(\App\Prediccion\Sesgo::claves()))),
                // la ablación compara contra "en uso": estos son los que pisa
                'ablacion' => Parametros::en_uso(['dia_modo', 'curva_modo', 'pares_recientes_max']),
            ],
            'sesgo_clases' => array_column(Parametros::en_uso(
                array_keys(\App\Prediccion\Sesgo::claves())), null, 'clave'),
            // exportar/importar: cuántas claves entran en el archivo y cuáles tienen
            // valor propio guardado (las demás corren con el default de fábrica)
            'claves_totales' => count(Parametros::claves()),
            'claves_guardadas' => Parametros::guardadas(),
            // lo que el motor mira y no se toca desde acá (se mide o se calibra solo)
            'fijos' => [
                ['clave' => 'horario_activo', 'valor' => (int) ($enUso->horario_activo ?? 1),
                 'texto' => 'El viaje y el traslado de regreso salen del compilado POR HORA de la ruta '
                     . '(tiempos_ruta_horario), mezclado con lo medido de la ruta'],
                ['clave' => 'horario_viajes_k', 'valor' => (int) ($enUso->horario_viajes_k ?? 3),
                 'texto' => 'Peso de la celda día/hora en el viaje y en el traslado: w = viajes/(viajes+k)'],
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
            // camión vacío + escalón de coordenadas: se muestran en su propia tarjeta
            'vacio_coords' => Parametros::en_uso([
                'vacio_factor', 'coords_activo', 'coords_base_min', 'coords_min_km',
                'coords_km_h_min', 'coords_km_h_max',
            ]),
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
        // `limite` es cuántas filas se evalúan. El tope es el mismo que usa la
        // tabla de aciertos: antes era 300 y el formulario venía con 600
        // seleccionado, así que "Medir ventana" fallaba con la validación y
        // parecía que el botón no hacía nada.
        $request->validate([
            'limite' => 'nullable|integer|min:10|max:' . Servicio::TOPE_EVALUACION,
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

        // "Medir y aplicar": con `aplicar=1` se guarda la banda recomendada en el
        // mismo paso (incluye `ventana_residuos = 1`, que es lo que la activa).
        $aplicado = null;
        if ($request->boolean('aplicar') && ($medicion['ok'] ?? false)) {
            try {
                Parametros::guardar([
                    'ventana_residuos' => 1,
                    'ventana_adelanto_min' => (int) $medicion['adelanto'],
                    'ventana_atraso_min' => (int) $medicion['atraso'],
                    'ventana_adelanto_cadena' => (int) ($medicion['cadena_adelanto'] ?? $medicion['cadena_radio'] ?? 0),
                    'ventana_atraso_cadena' => (int) ($medicion['cadena_atraso'] ?? $medicion['cadena_radio'] ?? 0),
                ]);
                $aplicado = 'Ventana aplicada: −' . (int) $medicion['adelanto'] . ' / +' . (int) $medicion['atraso'] . ' min'
                    . ((int) ($medicion['cadena_radio'] ?? 0) > 0
                        ? ' (cadena −' . (int) ($medicion['cadena_adelanto'] ?? $medicion['cadena_radio'])
                            . ' / +' . (int) ($medicion['cadena_atraso'] ?? $medicion['cadena_radio']) . ')' : '') . '.';
            } catch (\Throwable $e) {
                $medicion['error'] = 'Se midió, pero no se pudo guardar: ' . $e->getMessage();
                $medicion['ok'] = false;
            }
        }

        // vuelve a la pantalla desde donde se disparó (calculadora o aciertos)
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.presenta'))
            ->with('ventana_medida', $medicion)
            ->with('estado', $aplicado);
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

        // "Medir y aplicar": con `aplicar=1` se guarda la recomendación en el mismo
        // paso. Sin el flag no se toca nada (medir y aplicar son cosas distintas:
        // el número se mira antes de dejarlo puesto).
        $aplicado = null;
        if ($request->boolean('aplicar') && ($medicion['ok'] ?? false)) {
            $recomendado = (int) ($medicion['recomendacion']['recomendado'] ?? 0);
            try {
                Parametros::guardar(['cruce_pares_k' => $recomendado]);
                $aplicado = 'cruce_pares_k = ' . $recomendado . ' guardado en la configuración.';
            } catch (\Throwable $e) {
                $aplicado = null;
                $calibracion['error'] = 'Se midió, pero no se pudo guardar: ' . $e->getMessage();
            }
        }

        // vuelve a la pantalla desde donde se disparó
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.parametros.ver'))
            ->with('calibracion_cruce', $calibracion)
            ->with('estado', $aplicado);
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

    /**
     * Borra las predicciones guardadas del rango.
     *
     * El motor se recalibra todo el tiempo: una predicción guardada hace un mes
     * compara contra un modelo que ya no existe y ensucia el acierto. Esto borra
     * las filas de `prediccion_resultados` cuyo CORTE cae en el rango (más el
     * origen, si se eligió uno), y devuelve cuántas borró.
     *
     * El rango se mira sobre el corte (`ahora`) a propósito: es lo que se ve en la
     * tabla de la pantalla y lo que el usuario tiene en la cabeza cuando dice
     * "borrá lo de este rango".
     */
    public static function borrar_guardadas(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'origen' => 'nullable|string|max:10',
            'local' => 'nullable|string|max:100',
            'limite' => 'nullable|integer|in:' . implode(',', self::FILAS_GUARDADAS),
        ]);

        $desde = Carbon::parse((string) $request->input('fecha_inicio') ?: now()->subDays(29)->toDateString())->startOfDay();
        $hasta = Carbon::parse((string) $request->input('fecha_fin') ?: now()->toDateString())->endOfDay();
        $origen = trim((string) $request->input('origen', ''));
        $local = trim((string) $request->input('local', ''));

        $consulta = DB::table('prediccion_resultados')
            ->whereBetween('ahora', [$desde->toDateTimeString(), $hasta->toDateTimeString()]);

        if ($origen !== '') {
            $consulta->where('origen', $origen);
        }

        // con filtro por local se borra SÓLO ese local (lo que se está viendo), no
        // todo el rango: la fila guarda el `ruta_id` y, si viene vacío, se mira la
        // ruta de la referencia
        if ($local !== '') {
            $rutasLocal = fn () => DB::table('rutas')->select('id')->where('origen_id', $local);

            $consulta->where(function ($q) use ($rutasLocal) {
                $q->whereIn('ruta_id', $rutasLocal())
                    ->orWhereIn('ref', DB::table('referencias')->select('ref')->whereIn('ruta_id', $rutasLocal()));
            });
        }

        try {
            $borradas = (int) $consulta->delete();
        } catch (\Throwable $e) {
            return redirect()->route('lupita.prediccion.guardadas', self::filtros_guardadas($request))
                ->with('error', 'No se pudo borrar: ' . $e->getMessage());
        }

        $detalle = $origen !== '' ? ' (origen ' . $origen . ')' : '';
        $detalle .= $local !== '' ? ' (local ' . $local . ')' : '';

        return redirect()->route('lupita.prediccion.guardadas', self::filtros_guardadas($request))
            ->with('estado', $borradas === 0
                ? 'No había predicciones guardadas con corte entre ' . $desde->format('d/m/Y') . ' y '
                    . $hasta->format('d/m/Y') . $detalle . '.'
                : 'Se borraron ' . $borradas . ' predicciones guardadas con corte entre '
                    . $desde->format('d/m/Y') . ' y ' . $hasta->format('d/m/Y') . $detalle . '.');
    }

    /** Filtros de la pantalla de guardadas, para volver a ella tal cual. */
    private static function filtros_guardadas(Request $request): array
    {
        return array_filter([
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'origen' => $request->input('origen'),
            'local' => $request->input('local'),
            'horizonte' => $request->input('horizonte'),
            'limite' => $request->input('limite'),
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /**
     * Horizonte de cada fila de producción: desde el ÚLTIMO evento real de la placa
     * antes del corte (el ancla que usa el motor) hasta la presentación real.
     *
     * Se resuelve con una sola consulta de hitos por placa (`hitos_por_placa`) y no
     * con una por fila. El ancla se toma como el último hito de la placa que cae
     * ANTES del corte de la predicción, que es exactamente lo que hace el motor.
     *
     * @param  array<int, object>  $comparables  filas de DBPrediccionResultados::resumen()
     * @return array{mediano: ?int, n: int, corto: int, largo: int}
     */
    private static function horizontes_produccion(array $comparables, Carbon $inicio, Carbon $fin): array
    {
        $vacio = ['mediano' => null, 'n' => 0, 'corto' => 0, 'largo' => 0];

        if ($comparables === []) {
            return $vacio;
        }

        $placas = [];
        foreach ($comparables as $fila) {
            $placa = trim((string) ($fila->placa ?? ''));
            if ($placa !== '') {
                $placas[$placa] = true;
            }
        }

        // el ancla puede estar antes del rango: se mira un mes hacia atrás
        $hitos = \App\Database\DBPrediccion::hitos_por_placa(
            $inicio->copy()->subDays(30), $fin, array_keys($placas), 500);

        $valores = [];
        $corto = 0;     // <= 6 h: es el tramo donde el motor acierta
        $largo = 0;     // > 24 h: el tramo donde no

        foreach ($comparables as $fila) {
            $placa = trim((string) ($fila->placa ?? ''));
            $real = $fila->real ?? null;
            $corte = $fila->corte ?? null;

            if ($placa === '' || !$real instanceof Carbon || !$corte instanceof Carbon) {
                $fila->horizonte_min = null;

                continue;
            }

            $ancla = null;
            foreach ($hitos[$placa] ?? [] as $hito) {
                if ($hito->greaterThan($corte)) {
                    break;      // vienen ordenados: el resto es futuro
                }
                $ancla = $hito;
            }

            if ($ancla === null) {
                $fila->horizonte_min = null;

                continue;
            }

            $minutos = (int) round(($real->getTimestamp() - $ancla->getTimestamp()) / 60);
            $fila->horizonte_min = $minutos;
            $fila->ancla_fecha = $ancla;
            $valores[] = $minutos;

            if ($minutos <= 360) {
                $corto++;
            } elseif ($minutos > 1440) {
                $largo++;
            }
        }

        if ($valores === []) {
            return $vacio;
        }

        $mediano = \App\Prediccion\Estadistica::mediana($valores);

        return [
            'mediano' => $mediano === null ? null : (int) round($mediano),
            'n' => count($valores),
            'corto' => $corto,
            'largo' => $largo,
        ];
    }

    /**
     * Mide el SESGO POR CLASE y deja la tabla en pantalla.
     *
     * Corre la tabla de aciertos del rango, agrupa el error por (evidencia × tramo
     * del horizonte predicho) y saca la mediana de cada celda. Es lo que alimenta
     * la cuarta fecha (`presenta_probable`). No aplica nada: eso lo hace
     * `aplicar_sesgo()` cuando el operador aprieta el botón.
     */
    public static function calibrar_sesgo(Request $request)
    {
        $request->validate([
            'dias' => 'nullable|integer|min:15|max:365',
            'limite' => 'nullable|integer|min:100|max:1000',
            'fuente' => 'nullable|in:guardadas,aciertos',
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $filtros = [
            'desde' => now()->subDays(max(15, (int) $request->input('dias', 60)))->toDateString(),
            'hasta' => now()->toDateString(),
            'limite' => max(100, (int) $request->input('limite', 800)),
            // la fuente recomendada son las predicciones guardadas: es la
            // distribución real de producción, no la del backtest (que fuerza el
            // ancla al fin de descargue y se adelanta más)
            'fuente' => $request->input('fuente') === 'aciertos' ? 'aciertos' : 'guardadas',
        ];

        $llave = 'prediccion:sesgo:v1:' . md5(json_encode([$filtros, (array) Parametros::cargar()]));

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        try {
            $medicion = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => \App\Prediccion\Sesgo::medir($filtros)
            );
        } catch (\Throwable $e) {
            $medicion = ['ok' => false, 'error' => 'No se pudo medir: ' . $e->getMessage(), 'celdas' => []];
        }

        // "Medir y aplicar": guarda las nueve claves y PRENDE el interruptor. Es el
        // atajo para que la cuarta fecha deje de ser igual a la normal sin tener que
        // tildar el check a mano.
        $aplicado = null;
        if ($request->boolean('aplicar') && ($medicion['ok'] ?? false)) {
            try {
                Parametros::guardar(array_merge($medicion['aplicables'], ['sesgo_clase_activo' => 1]));
                $aplicado = 'Sesgos aplicados (9 clases): la cuarta fecha ya corrige la hora publicada.';
            } catch (\Throwable $e) {
                $medicion['ok'] = false;
                $medicion['error'] = 'Se midió, pero no se pudo guardar: ' . $e->getMessage();
            }
        }

        return redirect()->route('lupita.prediccion.parametros.ver')
            ->with('sesgo_medido', $medicion)
            ->with('estado', $aplicado);
    }

    /** Aplica los sesgos medidos (o los apaga) y guarda las 9 claves. */
    public static function aplicar_sesgo(Request $request)
    {
        $valores = [];
        foreach (\App\Prediccion\Sesgo::claves() as $clave => $ignorado) {
            if ($request->filled($clave)) {
                $valores[$clave] = (int) $request->input($clave);
            }
        }

        $valores['sesgo_clase_activo'] = $request->boolean('sesgo_clase_activo') ? 1 : 0;

        try {
            Parametros::guardar($valores);
            $mensaje = $valores['sesgo_clase_activo'] === 1
                ? 'Sesgos aplicados (' . count($valores) . ' claves). La cuarta fecha ya los usa.'
                : 'Sesgos apagados: la cuarta fecha vuelve a ser igual a la normal.';
        } catch (\Throwable $e) {
            $mensaje = 'No se pudieron guardar los sesgos: ' . $e->getMessage();
        }

        return redirect()->route('lupita.prediccion.parametros.ver')->with('estado', $mensaje);
    }

    /**
     * Descarga la configuración completa del motor en un JSON.
     *
     * Se lleva la configuración EFECTIVA (defaults pisados por lo guardado) y la
     * lista de claves que tienen valor propio, así el mismo archivo sirve para
     * copiar la configuración entre servidores o para volver atrás si una
     * calibración sale mal (no hay historial en la base).
     */
    public static function exportar()
    {
        $json = json_encode(Parametros::exportar(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nombre = 'prediccion-config-' . now()->format('Ymd-His') . '.json';

        return response()->make($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
        ]);
    }

    /**
     * Importa un JSON de configuración (el que arma `exportar()`).
     *
     * Mismo patrón que las calibraciones: no se guarda nada sin confirmar.
     *  - `accion=ver`  → deja el diff en pantalla y no toca la base;
     *  - `accion=aplicar` (o el botón del diff, con `confirmado=1`) → guarda.
     */
    public static function importar(Request $request)
    {
        $destino = redirect()->route('lupita.prediccion.parametros.ver');

        // El botón "Importar N claves" del diff: los valores vuelven como campos
        // ocultos, así no hay que volver a subir el archivo.
        if ($request->boolean('confirmado')) {
            $nombre = (string) $request->input('archivo_nombre', 'configuración revisada');
            $normalizado = Parametros::normalizar((array) $request->input('cambios', []));
        } else {
            $archivo = $request->file('archivo');

            if ($archivo === null) {
                return $destino->with('importar_error', 'Elegí el archivo JSON que exportaste.')
                    ->with('estado', 'No se importó nada: falta el archivo.');
            }

            if ($archivo->getSize() > self::TAMANO_MAX_CONFIG) {
                return $destino
                    ->with('importar_error', 'El archivo es demasiado grande (máximo 256 KB).')
                    ->with('estado', 'No se importó nada: el archivo no se leyó.');
            }

            $nombre = (string) $archivo->getClientOriginalName();
            $normalizado = self::leer_configuracion((string) file_get_contents($archivo->getRealPath()));

            if (isset($normalizado['error'])) {
                return $destino->with('estado', $normalizado['error'])
                    ->with('importar_error', $normalizado['error']);
            }
        }

        // diff contra lo que está en uso, para que el cambio se vea antes y después
        $actual = Parametros::cargar();
        $cambios = [];
        foreach ($normalizado['validos'] as $clave => $valor) {
            $antes = $actual->{$clave} ?? null;
            if ($antes === null || (string) $antes !== (string) $valor) {
                $cambios[$clave] = ['antes' => $antes, 'despues' => $valor];
            }
        }

        $paquete = [
            'nombre' => $nombre,
            'recibidas' => count($normalizado['validos']),
            'ignoradas' => $normalizado['ignoradas'],
            'rechazadas' => $normalizado['rechazadas'],
            'cambios' => $cambios,
            'valores' => $normalizado['validos'],
        ];

        if ($request->input('accion') === 'ver' && !$request->boolean('confirmado')) {
            return $destino
                ->with('importar_vista', $paquete)
                ->with('estado', 'Revisá qué cambiaría: todavía no se guardó nada.');
        }

        $error = null;
        try {
            Parametros::guardar($normalizado['validos']);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $paquete['error'] = $error;
        $paquete['aplicado'] = $error === null;

        $mensaje = $error === null
            ? 'Configuración importada: ' . count($cambios) . ' claves cambiadas de '
                . $paquete['recibidas'] . ' recibidas.'
            : 'No se pudo guardar la configuración: ' . $error;

        return $destino->with('importar_resultado', $paquete)->with('estado', $mensaje);
    }

    /**
     * Lee el JSON de un archivo de configuración y lo normaliza.
     *
     * Acepta las tres formas que puede tener un archivo hecho a mano:
     * `{"parametros": {...}}` (lo que exporta la pantalla), `{"claves": {...}}` o
     * un objeto plano `{"clave": valor}`.
     *
     * @return array{validos?: array<string, int|float>, ignoradas?: array<int, string>,
     *               rechazadas?: array<string, string>, error?: string}
     */
    private static function leer_configuracion(string $contenido): array
    {
        $datos = json_decode($contenido, true);

        if (!is_array($datos)) {
            return ['error' => 'El archivo no es un JSON válido (no se pudo leer).'];
        }

        foreach (['parametros', 'claves', 'configuracion'] as $envoltorio) {
            if (isset($datos[$envoltorio]) && is_array($datos[$envoltorio])) {
                $datos = $datos[$envoltorio];
                break;
            }
        }

        if ($datos === []) {
            return ['error' => 'El archivo no trae valores para importar.'];
        }

        return Parametros::normalizar($datos);
    }

    private static function carbon(?string $valor): ?Carbon
    {
        return $valor !== null && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
