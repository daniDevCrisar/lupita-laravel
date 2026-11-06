<?php

namespace App\Http\Controllers\Api;

use App\Database\DBTiemposPlantaCompilado;
use App\Database\DBTiemposPromedioCompilado;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * TiemposPromedioApiController
 *
 * Endpoints publicos (grupo /api, solo JSON) que devuelven lo que YA se
 * compilo en las pantallas `/lupita/tiempos/promedio` y
 * `/lupita/tiempos/promedio/horario`. No recalculan nada: leen
 * `tiempos_ruta_promedio` y `tiempos_ruta_horario`.
 *
 *  GET /api/tiempos/promedio
 *      buscar, ruta_id, dep_origen, dep_destino, min_viajes, con_fusion,
 *      orden (viajes|promedio|promedio_asc|perdido|perdido_asc|mediana|ciclo|
 *      ruta|actualizado), limit (1..1000, default 300).
 *      resumir=1             filas con menos campos y sin el objeto `resumen`.
 *      palabras_claves=0     omite el mapa `ubicaciones` (clave => nombre y
 *                            palabras clave del local). Por defecto SI se
 *                            incluye, listado una sola vez por ubicacion.
 *
 *  GET /api/tiempos/promedio/horario
 *      ruta_id, dia (0 = lunes), hora_desde, hora_hasta, min_viajes,
 *      orden (viajes|promedio|promedio_asc|dia|ruta) y limit (default 1000).
 *
 *  GET /api/tiempos/promedio/plantas
 *      Promedios de espera por planta ya compilados (`tiempos_planta_promedio`):
 *      buscar, planta_id, departamento, min_lados, orden (viajes|espera|fuera|
 *      dentro|descartados|planta|actualizado) y limit (default 300).
 *      Cada planta trae fuera y dentro separados por rol (carga E2/E3 y
 *      descarga E5/E6) y los fusionados (prom_fuera_min / prom_dentro_min /
 *      prom_espera_min).
 *
 * Todos los tiempos salen en MINUTOS. En el resumen, `prom_ponderado` y
 * `prom_ciclo` pesan cada ruta o celda por sus viajes: es el promedio real del
 * conjunto. `prom_ciclo_min` es el ciclo completo (E2..E6) guardado al
 * compilar: la suma de los promedios de las cinco etapas, y solo existe si
 * todas aportan datos.
 *
 * Los parametros invalidos devuelven SIEMPRE 422 en JSON (no un redirect 302),
 * aunque el cliente no envie `Accept: application/json`.
 */
class TiemposPromedioApiController extends Controller
{
    /**
     * En hosting compartido no se puede tocar el php.ini, y algunos servidores
     * traen `serialize_precision = 17`: eso hace que `json_encode` vuelque la
     * expansion binaria completa de un float (16.7 sale como
     * 16.699999999999999 y 675.7 como 675.70000000000004547). No es un error de
     * la columna DECIMAL (que es exacta), sino de como PHP serializa floats.
     *
     * Se corrige solo para las peticiones a este controller (no es config
     * global del proyecto ni del request siguiente): -1 usa la representacion
     * mas corta que round-tripea, o sea 16.7 y 675.7 limpios.
     */
    public function __construct()
    {
        ini_set('precision', '14');
        ini_set('serialize_precision', '-1');
    }

    /**
     * Respuesta de validacion SIEMPRE en JSON 422 (sin el header Accept,
     * Laravel responderia con un redirect 302).
     */
    private static function error_validacion(ValidationException $e)
    {
        return response()->json([
            'success' => false,
            'error' => 'Parametros invalidos',
            'detalle' => $e->errors(),
        ], 422);
    }

    public function por_ruta(Request $request)
    {
        try {
            $validado = $request->validate([
                'buscar' => 'nullable|string|max:120',
                'ruta_id' => 'nullable|string|max:100',
                'dep_origen' => 'nullable|string|max:6',
                'dep_destino' => 'nullable|string|max:6',
                'min_viajes' => 'nullable|numeric|min:0',
                'con_fusion' => 'nullable',
                'orden' => 'nullable|string|max:20',
                'limit' => 'nullable|numeric|min:1|max:1000',
                'resumir' => 'nullable',
                'palabras_claves' => 'nullable',
            ]);
        } catch (ValidationException $e) {
            return self::error_validacion($e);
        }

        $resumir = $request->boolean('resumir');
        // el mapa de ubicaciones viene por defecto: solo se omite con
        // palabras_claves=0 (respuestas mas livianas)
        $con_palabras = $request->boolean('palabras_claves', true);

        $filtros = [
            'buscar' => $validado['buscar'] ?? null,
            'ruta_id' => $validado['ruta_id'] ?? null,
            'dep_origen' => $validado['dep_origen'] ?? null,
            'dep_destino' => $validado['dep_destino'] ?? null,
            'min_viajes' => (int) ($validado['min_viajes'] ?? 1),
            'con_fusion' => $request->boolean('con_fusion'),
            'orden' => $validado['orden'] ?? 'viajes',
            'limit' => (int) ($validado['limit'] ?? 300),
            'resumir' => $resumir,
            'palabras_claves' => $con_palabras,
        ];

        $datos = DBTiemposPromedioCompilado::por_ruta($filtros);

        $rutas = $datos->filas;
        if ($resumir) {
            $rutas = array_map([$this, 'resumida'], $rutas);
        }

        $respuesta = [
            'success' => true,
            'filtros' => $filtros,
        ];

        // con resumir=1 el consumidor solo quiere la lista de rutas
        if (!$resumir) {
            $respuesta['resumen'] = $datos->resumen;
        }

        $respuesta['rutas'] = $rutas;
        $respuesta['mostradas'] = count($rutas);
        $respuesta['total'] = $datos->limitados;

        // las palabras clave se listan UNA sola vez: el mapa de ubicaciones
        if ($con_palabras) {
            $respuesta['ubicaciones'] = (object) $datos->ubicaciones;
        }

        return response()->json($respuesta);
    }

    /**
     * Fila reducida de `resumir=1`: sin ids de ruta, tope, viajes medibles,
     * descartados, promedio fusionado crudo ni fecha de compilacion. El ciclo
     * promedio si viaja: es el dato que se usa para planificar.
     */
    private function resumida($fila): array
    {
        return [
            'nombre' => $fila->nombre,
            'origen_id' => $fila->origen_id,
            'destino_id' => $fila->destino_id,
            'origen_nombre' => $fila->origen_nombre,
            'destino_nombre' => $fila->destino_nombre,
            'dep_origen' => $fila->dep_origen,
            'dep_destino' => $fila->dep_destino,
            'viajes' => $fila->viajes,
            'pct_perdido' => $fila->pct_perdido,
            'prom_min' => $fila->prom_min,
            'prom_sin_tope_min' => $fila->prom_sin_tope_min,
            'prom_ciclo_min' => $fila->prom_ciclo_min,
            'prom_ciclo_sin_tope_min' => $fila->prom_ciclo_sin_tope_min,
            'ciclo_viajes' => $fila->ciclo_viajes,
            'mediana_min' => $fila->mediana_min,
            'p25_min' => $fila->p25_min,
            'p75_min' => $fila->p75_min,
            'desv_min' => $fila->desv_min,
            'viajes_fusion' => $fila->viajes_fusion,
            'prom_efectivo' => $fila->prom_efectivo,
        ];
    }

    public function horario(Request $request)
    {
        try {
            $validado = $request->validate([
                'ruta_id' => 'nullable|string|max:100',
                'dia' => 'nullable|integer|min:0|max:6',
                'hora_desde' => 'nullable|integer|min:0|max:23',
                'hora_hasta' => 'nullable|integer|min:0|max:23',
                'min_viajes' => 'nullable|numeric|min:0',
                'orden' => 'nullable|string|max:20',
                'limit' => 'nullable|numeric|min:1|max:1000',
            ]);
        } catch (ValidationException $e) {
            return self::error_validacion($e);
        }

        $filtros = [
            'ruta_id' => $validado['ruta_id'] ?? null,
            'dia' => $validado['dia'] ?? null,
            'hora_desde' => $validado['hora_desde'] ?? null,
            'hora_hasta' => $validado['hora_hasta'] ?? null,
            'min_viajes' => (int) ($validado['min_viajes'] ?? 1),
            'orden' => $validado['orden'] ?? 'viajes',
            'limit' => (int) ($validado['limit'] ?? DBTiemposPromedioCompilado::LIMITE_HORARIO),
        ];

        $datos = DBTiemposPromedioCompilado::horario($filtros);

        return response()->json([
            'success' => true,
            'filtros' => $filtros,
            'dias' => $datos->dias,
            'resumen' => $datos->resumen,
            'matriz' => $datos->matriz,
            'por_dia' => $datos->por_dia,
            'por_hora' => $datos->por_hora,
            'celdas' => $datos->celdas,
            'mostradas' => count($datos->celdas),
            'total' => $datos->limitados,
        ]);
    }

    /**
     * Promedios de espera por planta ya compilados: fuera y dentro separados
     * por rol (carga E2/E3, descarga E5/E6) y los fusionados de ambos roles.
     */
    public function plantas(Request $request)
    {
        try {
            $validado = $request->validate([
                'buscar' => 'nullable|string|max:120',
                'planta_id' => 'nullable|string|max:50',
                'departamento' => 'nullable|string|max:60',
                'min_lados' => 'nullable|numeric|min:0',
                'orden' => 'nullable|string|max:20',
                'limit' => 'nullable|numeric|min:1|max:1000',
            ]);
        } catch (ValidationException $e) {
            return self::error_validacion($e);
        }

        $filtros = [
            'buscar' => $validado['buscar'] ?? null,
            'planta_id' => $validado['planta_id'] ?? null,
            'departamento' => $validado['departamento'] ?? null,
            'min_lados' => (int) ($validado['min_lados'] ?? 0),
            'orden' => $validado['orden'] ?? 'viajes',
            'limit' => (int) ($validado['limit'] ?? DBTiemposPlantaCompilado::LIMITE),
        ];

        $datos = DBTiemposPlantaCompilado::por_planta($filtros);

        return response()->json([
            'success' => true,
            'filtros' => $filtros,
            'resumen' => $datos->resumen,
            'plantas' => $datos->filas,
            'mostradas' => count($datos->filas),
            'total' => $datos->limitados,
        ]);
    }
}
