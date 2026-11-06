<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RefsController
 *
 * Pantallas internas del modulo de referencias (grupo /lupita):
 *  - seleccion():     GET /lupita/refs/actualizar -> seleccion de lotes por rango
 *                      y link a tceflexia.
 *  - documentacion(): GET /lupita/refs/docs       -> contrato del API.
 *  - documentacion_ruta_id(): GET /lupita/refs/ruta-id -> como se deduce el ruta_id del titulo.
 *
 * Son PANTALLAS del panel, no endpoints: por eso viven aqui y NO en
 * app/Http/Controllers/Api, donde solo estan los endpoints publicos en JSON
 * (sin `auth` y sin middleware `permission`).
 *
 * REGLA DE ACCESO: no piden permiso de Spatie (los API son publicos por ahora);
 * solo se exige iniciar sesion (middleware `auth` del grupo /lupita).
 */
class RefsController extends Controller
{
    /**
     * GET /lupita/refs/actualizar : seleccion de lotes por rango + link a tceflexia
     */
    public function seleccion(Request $request)
    {
        $fecha_inicio = $request->query('fecha_inicio', date('Y-m-d', strtotime('-30 days')));
        $fecha_fin = $request->query('fecha_fin', date('Y-m-d'));

        // lote_id es BIGINT; se filtra por fecha de importacion del lote
        $lotes = DB::select("
            SELECT l.lote_id, l.nombre, l.comentario, l.created_at, u.nombres AS user_nombres,
                   COALESCE(rc.refs, 0) AS refs,
                   COALESCE(rc.pendientes, 0) AS pendientes
            FROM tmp_lotes l
            INNER JOIN users u ON u.id = l.usuario_id
            LEFT JOIN (
                SELECT a.lote_id,
                       COUNT(DISTINCT IF(a.ref <> '', a.ref, NULL)) AS refs,
                       COUNT(DISTINCT IF(a.ref <> '' AND r.fin_descargue IS NULL, a.ref, NULL)) AS pendientes
                FROM llamadas a
                LEFT JOIN referencias r ON r.ref = a.ref
                WHERE a.lote_id IN (
                    SELECT lote_id FROM tmp_lotes
                    WHERE procesado = 1 AND DATE(created_at) BETWEEN ? AND ?
                )
                GROUP BY a.lote_id
            ) rc ON rc.lote_id = l.lote_id
            WHERE l.procesado = 1 AND DATE(l.created_at) BETWEEN ? AND ?
            ORDER BY l.created_at DESC
            LIMIT 300
        ", [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);

        return view('refs.seleccion', compact('lotes', 'fecha_inicio', 'fecha_fin'));
    }

    /**
     * GET /lupita/refs/docs : documentacion del contrato del API
     */
    public function documentacion()
    {
        return view('refs.docs');
    }

    /**
     * GET /lupita/refs/ruta-id : documentacion del sp_actualizar_ruta_id
     * (solo lectura: explica como se deduce el ruta_id desde el titulo_viaje)
     */
    public function documentacion_ruta_id()
    {
        return view('refs.ruta_id_docs');
    }
}
