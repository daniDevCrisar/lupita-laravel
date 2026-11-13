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

        // se acepta `ubicacion: {tipo, id}` (como está documentado) y también
        // `ubicacion_tipo`/`ubicacion_id` sueltos, para que el ejemplo del GET
        // sirva tal cual en el POST
        return $this->responder($request, $ref, [
            'ahora' => $payload['ahora'] ?? null,
            'ancla_ref' => (string) ($payload['ancla'] ?? ''),
            'ubicacion_tipo' => (string) ($payload['ubicacion']['tipo'] ?? $payload['ubicacion_tipo'] ?? 'auto'),
            'ubicacion_id' => (string) ($payload['ubicacion']['id'] ?? $payload['ubicacion_id'] ?? ''),
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
                'rapido' => self::fecha($fila->rapido ?? null),
                'predicho' => self::fecha($fila->predicho),
                'tardado' => self::fecha($fila->tardado ?? null),
                'real' => self::fecha($fila->real),
                'error_min' => $fila->error_min,
                'dentro_ventana' => $fila->dentro,
                'evidencia' => $fila->evidencia,
                'confianza' => $fila->confianza,
                // con qué historia se calibró esta fila: la placa o el conductor
                'calibra_con' => $fila->grupo ?? 'placa',
                'pares_calibracion' => $fila->pares_calibracion ?? 0,
                // el camión quedó cerca de la planta de carga (mismo local/distrito)
                'cerca' => (bool) ($fila->cerca ?? false),
                'cerca_regimen' => $fila->cerca_regimen ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'desde' => self::fecha($datos->desde),
            'hasta' => self::fecha($datos->hasta),
            'aprendizaje_desde' => self::fecha($datos->aprendizaje_desde),
            'filas' => $filas,
            'descartados' => $datos->descartados,
            'sin_ruta' => $datos->sin_ruta ?? 0,
            'resumen_motor' => $datos->resumen_motor,
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
            // una ref sin ruta existe, pero no se puede predecir: sin planta de
            // carga no hay traslado que estimar. Se distingue de "no encontrada"
            // para que el consumidor sepa que no es un problema de su request.
            if ((string) $resultado->error === 'sin_ruta') {
                return self::error('La referencia no tiene ruta asignada: sin planta de carga no se puede '
                    . 'estimar el traslado ni la espera', 409);
            }

            // la ref ya cargó y va camino del destino, pero le falta el
            // `presenta_para_carga`: no hay presentación que adivinar
            if ((string) $resultado->error === 'ya_en_curso') {
                return self::error('La referencia ya tiene hitos de carga y ruta sin `presenta_para_carga`: '
                    . 'el camión ya presentó, no hay una presentación que predecir', 409);
            }

            return self::error('Ref no encontrada', 404);
        }

        // historial: queda guardado para medir el acierto real después
        \App\Database\DBPrediccionResultados::guardar($resultado, 'api');

        $respuesta = self::respuesta_json($resultado);

        if ($guardado !== null) {
            $respuesta['guardado'] = $guardado;
        }

        return response()->json($respuesta);
    }

    /**
     * El JSON público de una predicción.
     *
     * Está separado del endpoint para que la pantalla de la calculadora muestre
     * EXACTAMENTE lo mismo que devuelve el API: si se muestra el request del API
     * y su respuesta, tienen que coincidir de verdad.
     */
    public static function respuesta_json(stdClass $resultado): array
    {
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

        return [
            'success' => true,
            'ref' => $resultado->ref,
            'ahora' => self::fecha($resultado->ahora),
            'placa' => $resultado->placa,
            'ruta' => $resultado->ruta,
            'ruta_nombre' => $resultado->ruta_nombre,
            'planta_carga' => $resultado->planta_carga,
            'planta_carga_nombre' => $resultado->planta_carga_nombre ?? null,
            'presenta' => self::fecha($resultado->presenta),
            'presenta_rapido' => self::fecha($resultado->presenta_rapido ?? null),
            'presenta_tardado' => self::fecha($resultado->presenta_tardado ?? null),
            'presentaciones' => $resultado->presentaciones ?? [],
            'inicio_carga' => self::fecha($resultado->inicio_carga),
            'espera_min' => $resultado->espera_min,
            'espera_hhmm' => \App\Prediccion\Tiempo::hhmm($resultado->espera_min),
            'duracion_total_hhmm' => $resultado->duracion_total ?? null,
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
            'pasos' => $resultado->pasos ?? [],
            'geoapify' => $resultado->geoapify ?? (object) ['usado' => false],
            'desglose' => $resultado->desglose,
            'supuestos' => $resultado->supuestos,
            'advertencias' => $resultado->advertencias,
            'historia' => $resultado->historia ?? null,
            'candidatos' => $candidatos,
        ];
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
