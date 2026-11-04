<?php

namespace App\Http\Controllers;

use App\Database\DBConductoresDuplicados;
use Illuminate\Http\Request;

/**
 * Pantalla de limpieza de conductores duplicados.
 *
 * GET  muestra los grupos con el mismo nombre y permite marcar cuales se
 * fusionan. El id que absorbe no se elige: lo decide el servidor (el activo con
 * mas llamadas, o el mas antiguo si empatan). POST ejecuta la fusion: reasigna
 * las llamadas (y referencias, telefonos e historial) al id elegido y desactiva
 * los sobrantes. No borra filas.
 */
class ConductoresDuplicadosController extends Controller
{
    public static function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $solo_activos = $request->boolean('solo_activos');

        $grupos = DBConductoresDuplicados::grupos($q, $solo_activos);

        // Datos minimos para Alpine: tamano del grupo y parecidos (para calcular
        // el nombre resultante al vuelo).
        $js = [];
        foreach ($grupos as $g) {
            $js[] = [
                'nombres'     => $g['nombres'],
                'conductores' => $g['conductores'],
                // Solo lo necesario para calcular el nombre resultante al vuelo.
                'parecidos'   => array_map(fn($p) => [
                    'id'      => $p['id'],
                    'nombres' => $p['nombres'],
                ], $g['parecidos']),
            ];
        }

        return view('lupita.conductores_duplicados', [
            'grupos'       => $grupos,
            'totales'      => DBConductoresDuplicados::totales($grupos),
            'q'            => $q,
            'solo_activos' => $solo_activos,
            'limite'       => DBConductoresDuplicados::LIMITE_GRUPOS,
            'js_grupos'    => $js,
        ]);
    }

    public static function fusionar(Request $request)
    {
        $data = $request->validate([
            'fusion'             => 'required|array',
            'fusion.*.nombres'   => 'nullable|string|max:255',
            'fusion.*.principal' => 'nullable|integer|min:1',
            'fusion.*.aplicar'   => 'nullable|boolean',
            'fusion.*.ids'       => 'nullable|array',
            'fusion.*.ids.*'     => 'integer|min:1',
            'fusion.*.parecidos'   => 'nullable|array',
            'fusion.*.parecidos.*' => 'integer|min:1',
            'mover_logs'         => 'nullable|boolean',
            'q'                  => 'nullable|string',
            'solo_activos'       => 'nullable|boolean',
        ]);

        $res = DBConductoresDuplicados::fusionar_varios($data['fusion'], $request->boolean('mover_logs'));

        return redirect()
            ->route('lupita.conductores.duplicados', array_filter([
                'q'            => $data['q'] ?? null,
                'solo_activos' => $request->boolean('solo_activos') ? 1 : null,
            ]))
            ->with('fusion', $res);
    }
}
