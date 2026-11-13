<?php

namespace App\Http\Controllers\Api;

use App\Database\DBRefsActualizar;
use App\Http\Controllers\Controller;
use App\Prediccion\Servicio;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * API pública del módulo de predicción de carga (grupo /api, sin auth ni
 * permission, igual que el resto de los endpoints del proyecto).
 *
 *  GET  /api/prediccion/presenta?ref=1171848
 *      Atajo sobre la base: predice con lo que ya está guardado en `referencias`.
 *      Opcionales: ahora (Y-m-d H:i), ancla (ref del viaje a usar como ancla),
 *      ubicacion_tipo (auto|local|ubigeo|indeterminado), ubicacion_id.
 *
 *  POST /api/prediccion/presenta      (sólo JSON)
 *      {"ref": "...", "ahora": "...", "ancla": "...", "ubicacion": {"tipo","id"},
 *       "viaje": {ref, ...}, "historia": [{ref, ...}], "guardar": true|false}
 *
 *      `viaje` es el último viaje y `historia` las referencias anteriores: cada
 *      una con sus hitos en fecha dd/mm/aaaa hh:mm. Con "guardar": true la API
 *      las escribe en `referencias` ANTES de predecir (mismo camino que
 *      /api/refs/actualizar), y recién entonces la predicción las ve.
 *
 *  GET  /api/prediccion/aciertos?desde=&hasta=&placa=&limit=
 *      Tabla de aciertos del rango: motor vs método actual del histograma.
 *
 * Errores: parámetros inválidos SIEMPRE 422 en JSON (nunca redirect), ref
 * inexistente 404, y si la base falla 500 con success=false.
 */
class PrediccionApiController extends Controller
{
    /** Predicción por GET, con lo que ya está en la base. */
    public function presenta_por_get(Request $request)
    {
        return $this->responder(
            $request,
            (string) $request->query('ref', ''),
            [
                'ahora' => $request->query('ahora'),
                'ancla_ref' => (string) $request->query('ancla', ''),
                'ubicacion_tipo' => (string) $request->query('ubicacion_tipo', 'auto'),
                'ubicacion_id' => (string) $request->query('ubicacion_id', ''),
            ],
        );
    }

    /** Predicción por POST: acepta el viaje y la historia en el cuerpo. */
    public function presenta_por_post(Request $request)
    {
        $payload = $request->json()->all();

        if (!$request->isJson()) {
            return self::error('Solo se acepta JSON (Content-Type: application/json)', 415);
        }

        $validador = [
            'ref' => 'required|string|min:3|max:10',
            'ahora' => 'nullable|date',
            'ancla' => 'nullable|string|max:10',
            'guardar' => 'nullable|boolean',
            'ubicacion' => 'nullable|array',
            'ubicacion.tipo' => 'nullable|in:auto,local,ubigeo,indeterminado',
            'ubicacion.id' => 'nullable|string|max:20',
            'viaje' => 'nullable|array',
            'viaje.ref' => 'nullable|string|max:10',
            'historia' => 'nullable|array',
            'historia.*.ref' => 'nullable|string|max:10',
        ];

        try {
            $request->merge($payload);
            $request->validate($validador);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Parametros invalidos',
                'detalle' => $e->errors(),
            ], 422);
        }

        $ref = mb_strtoupper(trim((string) ($payload['ref'] ?? '')));
        $tipoUbicacionTmp = (string) ($payload['ubicacion']['tipo'] ?? 'auto');
        $idUbicacionTmp = (string) ($payload['ubicacion']['id'] ?? '');
        $errorUbicacion = Servicio::ubicacion_invalida($tipoUbicacionTmp, $idUbicacionTmp);
        if ($errorUbicacion !== null) {
            return self::error($errorUbicacion, 422);
        }

        $guardar = (bool) ($payload['guardar'] ?? false);

        $refs = self::refs_del_payload($payload);

        $guardado = null;
        if ($guardar) {
            if ($refs === []) {
                return self::error('"guardar" requiere "viaje" o "historia" con referencias y sus hitos', 422);
            }

            try {
                $resultado = DBRefsActualizar::aplicar(['refs' => $refs]);
            } catch (\Throwable $e) {
                return self::error('No se pudo guardar: ' . $e->getMessage(), 500);
            }

            if (($resultado['success'] ?? false) !== true) {
                return self::error($resultado['error'] ?? 'No se pudo guardar la historia', 422);
            }
            $guardado = $resultado['resumen'] ?? null;
        }

        return $this->responder($request, $ref, [
            'ahora' => $payload['ahora'] ?? null,
            'ancla_ref' => (string) ($payload['ancla'] ?? ''),
            'ubicacion_tipo' => (string) ($payload['ubicacion']['tipo'] ?? 'auto'),
            'ubicacion_id' => (string) ($payload['ubicacion']['id'] ?? ''),
        ], $guardado);
    }

    /** Tabla de aciertos del rango en JSON. */
    public function aciertos(Request $request)
    {
        try {
            $request->validate([
                'desde' => 'nullable|date',
                'hasta' => 'nullable|date',
                'placa' => 'nullable|string|max:20',
                'limit' => 'nullable|numeric|min:1|max:1000',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Parametros invalidos',
                'detalle' => $e->errors(),
            ], 422);
        }

        try {
            $datos = Servicio::backtest([
                'desde' => $request->query('desde', Carbon::now()->subDays(29)->toDateString()),
                'hasta' => $request->query('hasta', Carbon::now()->toDateString()),
                'placa' => (string) $request->query('placa', ''),
                'limite' => (int) $request->query('limit', 200),
            ]);
        } catch (\Throwable $e) {
            return self::error('No se pudo correr la tabla: ' . $e->getMessage(), 500);
        }

        $filas = [];
        foreach ($datos->filas as $fila) {
            $filas[] = [
                'ref' => $fila->ref,
                'placa' => $fila->placa,
                'ancla_ref' => $fila->ancla_ref,
                'ancla' => self::fecha($fila->ancla),
                'hueco_min' => $fila->hueco_min,
                'predicho' => self::fecha($fila->predicho),
                'real' => self::fecha($fila->real),
                'error_min' => $fila->error_min,
                'dentro_ventana' => $fila->dentro,
                'evidencia' => $fila->evidencia,
                'confianza' => $fila->confianza,
                'metodo_actual' => self::fecha($fila->base),
                'metodo_actual_error_min' => $fila->base_error_min,
            ];
        }

        return response()->json([
            'success' => true,
            'desde' => self::fecha($datos->desde),
            'hasta' => self::fecha($datos->hasta),
            'aprendizaje_desde' => self::fecha($datos->aprendizaje_desde),
            'filas' => $filas,
            'descartados' => $datos->descartados,
            'resumen_motor' => $datos->resumen_motor,
            'resumen_metodo_actual' => $datos->resumen_base,
            'comparacion' => $datos->comparacion,
        ]);
    }

    //------------------- apoyo -------------------

    /**
     * Refs que se persisten con `guardar: true`, con sus hitos tal como vienen.
     *
     * Regla dura: se guarda EXACTAMENTE lo que el llamador mandó, cada ref con
     * SUS fechas. Antes esto copiaba los hitos de `viaje` (el viaje ancla) sobre
     * la ref a predecir y le reescribía los hitos con los de otro viaje — un
     * corruptor de datos silencioso, porque el resultado salía
     * `ya_presento: true` en vez de una hora.
     *
     * `viaje` es un objeto y `historia` puede ser lista `[{ref, ...}]` o mapa
     * `{ref: {...}}`; en el mapa la clave es la ref.
     *
     * @return array<string, array<string, mixed>> ref => item con sus hitos
     */
    public static function refs_del_payload(array $payload): array
    {
        $refs = [];

        // `viaje` es UN viaje: {ref, hitos...}
        $viaje = $payload['viaje'] ?? null;
        if (is_array($viaje) && $viaje !== []) {
            $item = self::item_con_ref($viaje, null);
            if ($item !== null) {
                $refs[$item['ref']] = $item;
            }
        }

        // `historia` es una colección: lista [{ref,...}] o mapa {ref: {...}}
        $historia = $payload['historia'] ?? null;
        if (is_array($historia)) {
            $esLista = array_is_list($historia);
            foreach ($historia as $indice => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ref = $item['ref'] ?? ($esLista ? null : $indice);
                $preparado = self::item_con_ref($item, $ref);
                if ($preparado !== null) {
                    $refs[$preparado['ref']] = $preparado;
                }
            }
        }

        return $refs;
    }

    /**
     * Normaliza un item del payload a `['ref' => REF] + hitos`, o NULL si no
     * tiene una ref usable.
     */
    private static function item_con_ref(array $item, $ref = null): ?array
    {
        $ref = $item['ref'] ?? $ref;
        if (!is_scalar($ref)) {
            return null;
        }

        $ref = mb_strtoupper(trim((string) $ref));
        if ($ref === '') {
            return null;
        }

        return ['ref' => $ref] + $item;
    }

    /** Corre la predicción y arma la respuesta; 404 si la ref no existe. */
    private function responder(Request $request, string $ref, array $opciones, ?array $guardado = null)
    {
        try {
            $request->validate([
                'ref' => 'required|string|min:3|max:10',
                'ahora' => 'nullable|date',
                'ancla' => 'nullable|string|max:10',
                'ubicacion_tipo' => 'nullable|in:auto,local,ubigeo,indeterminado',
                'ubicacion_id' => 'nullable|string|max:20',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Parametros invalidos',
                'detalle' => $e->errors(),
            ], 422);
        }

        $ref = mb_strtoupper(trim($ref));

        $errorUbicacion = Servicio::ubicacion_invalida(
            (string) ($opciones['ubicacion_tipo'] ?? 'auto'),
            (string) ($opciones['ubicacion_id'] ?? '')
        );
        if ($errorUbicacion !== null) {
            return self::error($errorUbicacion, 422);
        }

        try {
            $resultado = Servicio::predecir($ref, $opciones);
        } catch (\Throwable $e) {
            return self::error('No se pudo predecir: ' . $e->getMessage(), 500);
        }

        if (!empty($resultado->error)) {
            return self::error('Ref no encontrada', 404);
        }

        $candidatos = [];
        foreach (array_slice($resultado->candidatos ?? [], 0, 10) as $viaje) {
            $candidatos[] = [
                'ref' => $viaje->ref,
                'ruta' => $viaje->ruta,
                'hito' => $viaje->hito,
                'fecha' => self::fecha($viaje->fecha),
                'cerrado' => $viaje->cerrado,
            ];
        }

        $respuesta = [
            'success' => true,
            'ref' => $resultado->ref,
            'ahora' => self::fecha($resultado->ahora),
            'placa' => $resultado->placa,
            'ruta' => $resultado->ruta,
            'ruta_nombre' => $resultado->ruta_nombre,
            'planta_carga' => $resultado->planta_carga,
            'presenta' => self::fecha($resultado->presenta),
            'inicio_carga' => self::fecha($resultado->inicio_carga),
            'espera_min' => $resultado->espera_min,
            'ventana' => [
                'desde' => self::fecha($resultado->ventana['desde'] ?? null),
                'hasta' => self::fecha($resultado->ventana['hasta'] ?? null),
            ],
            'evidencia' => $resultado->evidencia,
            'confianza' => $resultado->confianza,
            'confianza_motivo' => $resultado->confianza_motivo,
            'ya_presento' => $resultado->ya_presento,
            'atrasado' => (bool) ($resultado->atrasado ?? false),
            'presenta_crudo' => self::fecha($resultado->presenta_crudo ?? null),
            'estado' => $resultado->estado,
            'ancla' => [
                'ref' => $resultado->ancla->ref ?? '',
                'hito' => $resultado->ancla->hito ?? null,
                'fecha' => self::fecha($resultado->ancla->fecha ?? null),
                'edad_min' => $resultado->ancla->edad_min ?? null,
                'cerrada' => (bool) ($resultado->ancla->cerrada ?? false),
            ],
            'desglose' => $resultado->desglose,
            'supuestos' => $resultado->supuestos,
            'advertencias' => $resultado->advertencias,
            'historia' => $resultado->historia ?? null,
            'candidatos' => $candidatos,
        ];

        if ($guardado !== null) {
            $respuesta['guardado'] = $guardado;
        }

        return response()->json($respuesta);
    }

    private static function error(string $mensaje, int $estado)
    {
        return response()->json(['success' => false, 'error' => $mensaje], $estado);
    }

    private static function fecha($valor): ?string
    {
        if ($valor instanceof Carbon) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
