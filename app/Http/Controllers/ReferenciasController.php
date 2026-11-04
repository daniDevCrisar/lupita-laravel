<?php

namespace App\Http\Controllers;

use App\Database\DBConsultarReferencias;
use App\Database\DBRutas;
use Illuminate\Http\Request;

class ReferenciasController extends Controller
{
    /** Vista de consulta de referencias: listado + KPIs + mapa por departamento. */
    public function index(Request $request)
    {
        // La pantalla no consulta la base al abrirse: solo cuando se envia el formulario
        // (el filtro viaja con el flag oculto `filtrar`).
        $filtrando = $request->boolean('filtrar');

        if (!$filtrando) {
            return view('lupita.consultar_referencias', [
                'referencias'   => null,
                'kpis'          => null,
                'mapa'          => ['origen' => [], 'destino' => []],
                'topRutas'      => [],
                'depas'         => DBRutas::$depas,
                'filtrando'     => false,
                'noEncontradas' => [],
                'etapas'        => DBConsultarReferencias::ETAPAS_LABELS,
                'enPlanta'      => null,
                'ventanas'      => DBConsultarReferencias::VENTANAS_PLANTA,
            ]);
        }

        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date',
            'etapa'        => 'nullable|in:' . implode(',', array_keys(DBConsultarReferencias::ETAPAS_LABELS)),
            'ventana'      => 'nullable|integer|in:' . implode(',', DBConsultarReferencias::VENTANAS_PLANTA),
            'q'            => 'nullable|string',
            'ruta'         => 'nullable|string',
            'conductor_id' => 'nullable|numeric',
            'trt_id'       => 'nullable|numeric',
            'sin_fin'      => 'nullable|boolean',
            'completas'    => 'nullable|boolean',
        ]);

        // Limite de ejecucion alto: el join referencias x llamadas puede ser pesado.
        \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 600');

        $referencias = DBConsultarReferencias::listar($request, 25);
        $kpis         = DBConsultarReferencias::kpis($request);
        $mapa         = DBConsultarReferencias::mapa($request);
        $topRutas     = DBConsultarReferencias::top_rutas($request, 8);

        // Si el buscador trae una lista de refs, se reporta cual de ellas no existe.
        $listaRefs     = DBConsultarReferencias::lista_de_refs($request->input('q'));
        $noEncontradas = $listaRefs ? DBConsultarReferencias::refs_inexistentes($listaRefs) : [];

        return view('lupita.consultar_referencias', [
            'referencias'   => $referencias,
            'kpis'          => $kpis,
            'mapa'          => $mapa,
            'topRutas'      => $topRutas,
            'depas'         => DBRutas::$depas,
            'filtrando'     => true,
            'noEncontradas' => $noEncontradas,
            'etapas'        => DBConsultarReferencias::ETAPAS_LABELS,
            'enPlanta'      => DBConsultarReferencias::en_planta($request, self::ventana($request)),
            'ventanas'      => DBConsultarReferencias::VENTANAS_PLANTA,
        ]);
    }

    /** Ventana (horas) elegida para acotar los estados "en planta"; 72 h por defecto. */
    private static function ventana(Request $request): int
    {
        $horas = (int) $request->input('ventana', 72);
        return in_array($horas, DBConsultarReferencias::VENTANAS_PLANTA, true) ? $horas : 72;
    }

    /** Mapa animado de movimientos por ruta, con los mismos filtros de la consulta. */
    public function mapa(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date',
            'etapa'        => 'nullable|in:' . implode(',', array_keys(DBConsultarReferencias::ETAPAS_LABELS)),
            'q'            => 'nullable|string',
            'ruta'         => 'nullable|string',
            'conductor_id' => 'nullable|numeric',
            'trt_id'       => 'nullable|numeric',
            'sin_fin'      => 'nullable|boolean',
            'completas'    => 'nullable|boolean',
        ]);

        \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 600');

        $limite = DBConsultarReferencias::LIMITE_MAPA;
        $movimientos = DBConsultarReferencias::movimientos($request, $limite);

        return view('lupita.mapa_rutas', [
            'movimientos' => $movimientos,
            'depas'       => DBRutas::$depas,
            'limite'      => $limite,
            'etapas'      => DBConsultarReferencias::ETAPAS_LABELS,
            // estados en planta (espera/carga/espera/descarga) animados en el mapa
            'estadosPlanta' => DBConsultarReferencias::estados_planta_anim($request, $limite),
        ]);
    }
}
