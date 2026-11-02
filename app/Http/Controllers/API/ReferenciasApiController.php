<?php

namespace App\Http\Controllers\Api;

use App\Database\DBConsultarReferencias;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Endpoints JSON sobre las llamadas de una o varias referencias (viajes).
 *
 * - GET  /api/referencias/{ref}/llamadas   -> llamadas de un ref
 * - POST /api/referencias/llamadas          -> {refs:[...]} llamadas agrupadas por ref
 * - GET  /api/referencias/llamadas?refs=a,b,c
 */
class ReferenciasApiController extends Controller
{
    public function llamadasPorRef(string $ref)
    {
        $ref = trim($ref);
        if ($ref === '') {
            return response()->json(['exito' => false, 'error' => 'ref vacío'], 422);
        }

        $llamadas = DBConsultarReferencias::llamadas_de_ref($ref);

        return response()->json([
            'exito'  => true,
            'ref'    => $ref,
            'total'  => count($llamadas),
            'llamadas' => $llamadas,
        ]);
    }

    public function llamadasPorRefs(Request $request)
    {
        // Aceptar tanto JSON ({refs:[...]}) como lista separada por coma/espacios (?refs=).
        $refs = $request->input('refs', []);
        if (is_string($refs)) {
            $refs = preg_split('/[,\s]+/', $refs, -1, PREG_SPLIT_NO_EMPTY);
        }
        $refs = array_values(array_unique(array_map(
            fn($r) => trim((string) $r), (array) $refs
        ), SORT_STRING));
        $refs = array_values(array_filter($refs, fn($r) => $r !== ''));

        if (empty($refs)) {
            return response()->json(['exito' => false, 'error' => 'refs requerido (array o lista separada por coma)'], 422);
        }

        // Tope defensivo para no explotar la consulta: mismo orden que la escritura
        // de referencias (ActualizarRefsController::MAX_REFS_POR_ENVIO).
        $max = 1000;
        $solicitados = count($refs);
        $truncado = $solicitados > $max;
        if ($truncado) {
            $refs = array_slice($refs, 0, $max);
        }

        $agrupado = DBConsultarReferencias::llamadas_de_refs($refs);

        // Total de llamadas (sumando resumenes) y de exitosas listadas.
        $totalLlamadas = 0;
        $totalExitosas = 0;
        foreach ($agrupado as $datos) {
            $totalLlamadas += $datos['resumen']['total'];
            $totalExitosas += $datos['resumen']['exitosas'];
        }

        // Refs de la lista que no se encontraron: sin fila en `referencias` (no son viaje).
        $noEncontradas = DBConsultarReferencias::refs_inexistentes($refs);

        // Refs que si existen como viaje pero no tienen llamadas registradas.
        $fueraDeLista = array_map(fn($r) => mb_strtoupper((string) $r), $noEncontradas);
        $sinLlamadas = [];
        foreach ($refs as $r) {
            $vacia = ($agrupado[$r]['resumen']['total'] ?? 0) === 0;
            if ($vacia && !in_array(mb_strtoupper((string) $r), $fueraDeLista, true)) {
                $sinLlamadas[] = $r;
            }
        }

        return response()->json([
            'exito'     => true,
            'solicitados' => $solicitados,   // total recibido, antes del recorte
            'procesados' => count($refs),
            'truncado'  => $truncado,
            'encontradas' => count($refs) - count($noEncontradas),
            'no_encontradas' => $noEncontradas,
            'sin_llamadas' => $sinLlamadas,
            'total_llamadas' => $totalLlamadas,
            'total_exitosas' => $totalExitosas,
            'refs'      => array_keys($agrupado),
            'por_ref'   => $agrupado,
        ]);
    }
}
