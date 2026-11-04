<?php

namespace App\Http\Controllers\Api;

use App\Database\DBTiemposPromedioCompilado;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
 *      orden (viajes|promedio|promedio_asc|perdido|perdido_asc|mediana|ruta|
 *      actualizado) y limit (1..1000, default 300).
 *
 *  GET /api/tiempos/promedio/horario
 *      ruta_id, dia (0 = lunes), hora_desde, hora_hasta, min_viajes,
 *      orden (viajes|promedio|promedio_asc|dia|ruta) y limit (default 1000).
 *
 * Todos los tiempos salen en MINUTOS. En el resumen, `prom_ponderado` pesa
 * cada ruta o celda por sus viajes: es el promedio real del conjunto.
 */
class TiemposPromedioApiController extends Controller
{
    public function por_ruta(Request $request)
    {
        $validado = $request->validate([
            'buscar' => 'nullable|string|max:120',
            'ruta_id' => 'nullable|string|max:100',
            'dep_origen' => 'nullable|string|max:6',
            'dep_destino' => 'nullable|string|max:6',
            'min_viajes' => 'nullable|numeric|min:0',
            'con_fusion' => 'nullable',
            'orden' => 'nullable|string|max:20',
            'limit' => 'nullable|numeric|min:1|max:1000',
        ]);

        $filtros = [
            'buscar' => $validado['buscar'] ?? null,
            'ruta_id' => $validado['ruta_id'] ?? null,
            'dep_origen' => $validado['dep_origen'] ?? null,
            'dep_destino' => $validado['dep_destino'] ?? null,
            'min_viajes' => (int) ($validado['min_viajes'] ?? 1),
            'con_fusion' => $request->boolean('con_fusion'),
            'orden' => $validado['orden'] ?? 'viajes',
            'limit' => (int) ($validado['limit'] ?? 300),
        ];

        $datos = DBTiemposPromedioCompilado::por_ruta($filtros);

        return response()->json([
            'success' => true,
            'filtros' => $filtros,
            'resumen' => $datos->resumen,
            'rutas' => $datos->filas,
            'mostradas' => count($datos->filas),
            'total' => $datos->limitados,
        ]);
    }

    public function horario(Request $request)
    {
        $validado = $request->validate([
            'ruta_id' => 'nullable|string|max:100',
            'dia' => 'nullable|integer|min:0|max:6',
            'hora_desde' => 'nullable|integer|min:0|max:23',
            'hora_hasta' => 'nullable|integer|min:0|max:23',
            'min_viajes' => 'nullable|numeric|min:0',
            'orden' => 'nullable|string|max:20',
            'limit' => 'nullable|numeric|min:1|max:1000',
        ]);

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
}
