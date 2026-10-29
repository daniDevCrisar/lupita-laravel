<?php

namespace App\Http\Controllers;

use App\Database\DBPalabrasLlamadas;
use App\Database\DBTiemposRuta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

class PalabrasController extends Controller
{
    public static function index(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'llamada_tipo_id' => 'nullable|numeric',
            'conductor' => 'nullable|string|max:100',
            'trt' => 'nullable|string|max:100',
            'ruta_id' => 'nullable|numeric',
            'universo' => 'nullable|string|max:20',
            'fuente' => 'nullable|string|max:20',
            'max_gram' => 'nullable|numeric|between:1,5',
            'min_llamadas' => 'nullable|numeric|min:1',
            'palabra' => 'nullable|string|max:60',
        ]);

        $analisis = new DBPalabrasLlamadas();
        $analisis::set_filtro($request);

        //-------------EVITAR DESBORDAMIENTO POR TIEMPO DE ESPERA
        DB::statement('SET SESSION wait_timeout = 600');
        //------------------------

        // todo el corpus sin fechas tokeniza ~140 mil mensajes: el pico real de
        // memoria ronda los 100 MB, se sube el limite de la request
        @ini_set('memory_limit', '512M');

        // una sola pasada sobre la transcripcion; las tres metricas salen de ahi
        $datos = $analisis::analizar();

        $vistas = new stdClass();
        $vistas->frecuencia = $analisis::top_frecuencia($datos->palabras);
        $vistas->lift = $analisis::top_lift($datos->palabras);
        $vistas->tfidf = $analisis::top_tfidf($datos->palabras);

        return view('lupita.palabras', [
            'analisis' => $analisis,
            'datos' => $datos,
            'vistas' => $vistas,
            'rutas' => DBTiemposRuta::rutas_lista(),
            'tipos' => $analisis::tipos_lista(),
        ]);
    }
}
