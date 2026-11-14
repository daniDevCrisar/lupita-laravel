<?php

namespace App\Http\Controllers;

use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
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
            return back()->with('error', 'No se guardó nada: ' . $resultado['error']);
        }

        $mensaje = 'Se guardaron ' . $resultado['guardadas'] . ' predicciones'
            . ($resultado['saltadas'] > 0 ? ' (' . $resultado['saltadas'] . ' ya estaban o no tenían hora)' : '')
            . '. Se ven más abajo, en "Acierto real de lo que se predijo".';

        return back()->with('estado', $mensaje);
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
     * Calibra `cruce_pares_k` contra la tabla de aciertos (entrenamiento +
     * validación) y deja la recomendación en pantalla.
     *
     * No guarda nada: la recomendación se aplica con el botón "Aplicar", que
     * reusa `guardar_parametros`. La corrida son 6 valores × 2 ventanas de la
     * tabla de aciertos, así que se cachea 10 minutos igual que esa pantalla.
     */
    public static function calibrar_cruce(Request $request)
    {
        $request->validate(['limite' => 'nullable|integer|min:10|max:300']);

        // en el servidor son decenas de segundos: el default de PHP (30 s) puede
        // cortar la corrida a la mitad
        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $opciones = [
            'rejilla' => ['cruce_pares_k' => self::REJILLA_CRUCE],
            'limite' => (int) $request->input('limite', 100),
            'metrica' => 'mae',
        ];

        $llave = 'prediccion:calibrar:cruce:v1:' . md5(json_encode($opciones)
            . json_encode((array) Parametros::cargar()));

        if ($request->boolean('refrescar')) {
            \Illuminate\Support\Facades\Cache::forget($llave);
        }

        try {
            $calibracion = \Illuminate\Support\Facades\Cache::remember(
                $llave,
                now()->addMinutes(10),
                fn () => Servicio::calibrar($opciones)
            );
        } catch (\Throwable $e) {
            $calibracion = ['ok' => false, 'error' => 'No se pudo calibrar: ' . $e->getMessage()];
        }

        // vuelve a la pantalla desde donde se disparó (calculadora o aciertos)
        $destino = url()->previous();
        $valido = str_contains($destino, '/lupita/prediccion/');

        return ($valido ? redirect()->to($destino) : redirect()->route('lupita.prediccion.presenta'))
            ->with('calibracion_cruce', $calibracion);
    }

    private static function carbon(?string $valor): ?Carbon
    {
        return $valor !== null && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
