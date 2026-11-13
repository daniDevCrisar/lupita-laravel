<?php

namespace App\Http\Controllers\Api;

use App\Database\DBRefsActualizar;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ActualizarRefsController
 *
 * Puente con tceflexia.com (generalMilestonesRoute) para refrescar la tabla
 * `referencias` con data fresca del despachador, sin pasar por tmp_lotes_ref.
 *
 *  - devolver_refs_por_lotes(): GET /api/refs?lotes=csv  -> refs unicas de esos
 *    lotes que aun NO tienen fin_descargue (viajes completos = no se envian).
 *  - actualizar_refs():          POST /api/refs/actualizar -> escritura directa
 *    en referencias segun el flag referencias_de_lotes.
 *
 * Este controlador es SOLO API: los endpoints son publicos (sin `auth` y sin
 * middleware `permission`). Las pantallas del modulo viven en RefsController.
 *
 * La escritura y sus reglas de negocio se movieron a
 * `App\Database\DBRefsActualizar` para que el endpoint de predicción pueda
 * persistir la historia que recibe sin duplicarlas. Este controlador valida la
 * entrada y devuelve exactamente el mismo JSON que antes; ninguna regla cambió.
 */
class ActualizarRefsController extends Controller
{
    public const MAX_REFS_POR_ENVIO = DBRefsActualizar::MAX_REFS_POR_ENVIO;

    //=====================================================================
    // API PUBLICA (grupo /api, sin token, solo JSON) - estilo LlamadasApiController
    //=====================================================================

    /**
     * GET /api/refs?lotes=ID1,ID2,...
     * Devuelve las refs UNICAS de esos lotes que aun no tienen fin_descargue.
     */
    public function devolver_refs_por_lotes(Request $request)
    {
        $lotes = self::parse_lotes((string) $request->query('lotes', ''));

        if (empty($lotes)) {
            return response()->json([
                'success' => false,
                'error' => 'Parametro "lotes" vacio o invalido. Usar: ?lotes=ID1,ID2',
            ], 422);
        }

        $in = implode(',', array_fill(0, count($lotes), '?'));

        // refs de las llamadas de esos lotes, sin duplicados y excluding viajes
        // completos (fin_descargue ya registrado en referencias)
        $refs = DB::select("
            SELECT DISTINCT a.ref
            FROM llamadas a
            LEFT JOIN referencias r ON r.ref = a.ref
            WHERE a.lote_id IN ($in)
              AND a.ref IS NOT NULL AND a.ref <> ''
              AND r.fin_descargue IS NULL
            ORDER BY a.ref
        ", $lotes);

        $lista = array_map(fn ($x) => $x->ref, $refs);

        return response()->json([
            'refs' => $lista,
            'total' => count($lista),
        ]);
    }

    /**
     * POST /api/refs/actualizar  (solo JSON)
     * Body: { "referencias_de_lotes": true|false, "refs": { "REF": {campos}, ... } }
     * Tambien acepta "refs" como lista de objetos con campo "ref".
     */
    public function actualizar_refs(Request $request)
    {
        if (!$request->isJson()) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se acepta JSON (Content-Type: application/json)',
            ], 415);
        }

        $payload = $request->json()->all();

        if (!isset($payload['refs']) || !is_array($payload['refs'])) {
            return response()->json([
                'success' => false,
                'error' => 'Falta el campo "refs" (objeto {ref:{campos}} o lista [{ref:"X",...}])',
            ], 422);
        }

        $mapa = DBRefsActualizar::normalizar_refs($payload['refs']);

        if (empty($mapa)) {
            return response()->json([
                'success' => false,
                'error' => '"refs" llego vacio o sin claves de referencia validas',
            ], 422);
        }

        if (count($mapa) > self::MAX_REFS_POR_ENVIO) {
            return response()->json([
                'success' => false,
                'error' => 'Maximo ' . self::MAX_REFS_POR_ENVIO . ' referencias por envio',
            ], 422);
        }

        return response()->json(DBRefsActualizar::aplicar($payload));
    }

    /** deja solo lote_id numericos, unicos, max MAX_LOTES */
    private static function parse_lotes(string $csv): array
    {
        return DBRefsActualizar::parse_lotes($csv);
    }
}
