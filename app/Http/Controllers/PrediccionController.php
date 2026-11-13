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

    /** Calculadora: referencia a predecir + viaje ancla + ubicación declarada. */
    public static function presenta(Request $request)
    {
        $request->validate([
            'ref' => 'nullable|string|max:10',
            'placa' => 'nullable|string|max:20',
            'ahora' => 'nullable|date',
            'ancla' => 'nullable|string|max:10',
            'ubicacion_tipo' => 'nullable|in:auto,local,ubigeo,indeterminado',
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

        $resultado = null;
        if ($ref !== '') {
            $resultado = Servicio::predecir($ref, $opciones);
            if ($placa === '') {
                $placa = (string) ($resultado->placa ?? '');
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
        ]));
    }

    /** Tabla de aciertos del rango: motor vs método actual. */
    public static function aciertos(Request $request)
    {
        // sin rango explícito se miran los últimos 30 días, como comportamiento
        if (!$request->filled('fecha_inicio') && !$request->filled('fecha_fin')) {
            $request->merge([
                'fecha_inicio' => now()->subDays(29)->toDateString(),
                'fecha_fin' => now()->toDateString(),
            ]);
        }

        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'placa' => 'nullable|string|max:20',
            'limite' => 'nullable|numeric',
        ]);

        $filtros = [
            'desde' => $request->input('fecha_inicio'),
            'hasta' => $request->input('fecha_fin'),
            'placa' => trim((string) $request->input('placa', '')),
            'limite' => (int) $request->input('limite', 300),
        ];

        return view('lupita.prediccion_aciertos', array_merge(self::comunes(), [
            'filtros' => $filtros,
            'aciertos' => Servicio::backtest($filtros),
        ]));
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
        foreach (self::PARAMETROS_EDITABLES as $clave) {
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

    /** Catálogos y parámetros que comparten las dos pantallas. */
    private static function comunes(): array
    {
        return [
            'catalogos' => Servicio::catalogos(),
            'parametros_editables' => self::PARAMETROS_EDITABLES,
        ];
    }

    private static function carbon(?string $valor): ?Carbon
    {
        return $valor !== null && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
