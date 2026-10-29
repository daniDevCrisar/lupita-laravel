<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposRuta;
use Illuminate\Http\Request;
use stdClass;

class TiemposRutaController extends Controller
{
    public static function index(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'ruta_id' => 'nullable|string|max:100',
            'conductor' => 'nullable|string|max:100',
            'trt' => 'nullable|string|max:100',
            'min_viajes' => 'nullable|numeric',
            'max_etapa' => 'nullable|numeric|min:1',
            'limit' => 'nullable|numeric',
            'ordenar_por' => 'nullable|string|max:30',
        ]);

        $tiempos = new DBTiemposRuta();
        $tiempos::set_filtro($request);

        //-------------EVITAR DESBORDAMIENTO POR TIEMPO DE ESPERA
        \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 600');
        //------------------------

        $datos = new stdClass();
        $datos->resumen = $tiempos::resumen();
        $datos->descartados = $tiempos::viajes_descartados();
        $datos->por_ruta = $tiempos::tiempo_promedio_por_ruta();
        $datos->por_conductor = $tiempos::tiempo_promedio_por_conductor();
        $datos->matriz = $tiempos::matriz_ruta_conductor();

        $rutas = $tiempos::rutas_lista();
        $etapas = DBTiemposRuta::$etapas;

        return view('lupita.tiempos_ruta', [
            'tiempos' => $tiempos,
            'datos' => $datos,
            'rutas' => $rutas,
            'etapas' => $etapas,
        ]);
    }
}
