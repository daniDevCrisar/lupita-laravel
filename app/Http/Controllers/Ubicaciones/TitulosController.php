<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBUbicaciones;
use App\Http\Controllers\Controller;
use App\Ubicaciones\AnalisisTitulos;
use Illuminate\Http\Request;

/**
 * Ubicaciones\TitulosController
 *
 * Pantalla "Títulos sin ruta" (/lupita/ubicaciones/titulos): las refs que
 * quedaron sin `ruta_id`, agrupadas por titulo, con el motivo por el que fallo
 * cada una.
 *
 * Solo lectura: el analisis se recalcula en cada request (0,4 s sobre esta base)
 * y no escribe nada. La unica accion de escritura del modulo es el boton de
 * reprocesar, que vive en ReprocesarController.
 *
 * REGLA DE ACCESO: el middleware `role:admin` esta en la ruta, no aca.
 */
class TitulosController extends Controller
{
    public function index(Request $request)
    {
        $refs = DBUbicaciones::refsSinRuta();
        $calculo = AnalisisTitulos::desdeBase()->calcular($refs);

        $titulos = $calculo['titulos_detalle'];

        // los que ya resolverian van aparte: no necesitan local nuevo, solo que
        // alguien corra el SP (ver la seccion 3c del diseno)
        $resueltos = array_values(array_filter($titulos, fn ($t) => $t['ya_resolveria']));
        $pendientes = array_values(array_filter($titulos, fn ($t) => !$t['ya_resolveria']));

        return view('lupita.ubicaciones.titulos', [
            'pendientes'     => $pendientes,
            'resueltos'      => $resueltos,
            'refs'           => $calculo['refs'],
            'titulos_total'  => $calculo['titulos'],
            'fragmentos'     => $calculo['fragmentos'],
            'ya_resolverian' => $calculo['ya_resolverian'],
            'sp_disponible'  => \App\Database\DBReprocesar::spDisponible(),
            // llega desde "Ver títulos" del ranking de palabras
            'fragmento'      => (function ($v) { return is_string($v) ? mb_substr(trim($v), 0, 120) : ''; })($request->query('fragmento', '')),
        ]);
    }
}
