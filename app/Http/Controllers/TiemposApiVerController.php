<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposRuta;

/**
 * TiemposApiVerController
 *
 * Pantalla interna para probar el endpoint publico GET /api/tiempos/ruta.
 * Solo arma el formulario y el datalist de rutas; la llamada la hace el JS.
 *
 * Es una PANTALLA del panel, no un endpoint: por eso vive aqui y NO en
 * app/Http/Controllers/Api, donde solo estan los endpoints publicos en JSON
 * (sin `auth` y sin middleware `permission`).
 *
 * REGLA DE ACCESO: no pide permiso de Spatie (los API son publicos por ahora);
 * solo se exige iniciar sesion (middleware `auth` del grupo /lupita).
 */
class TiemposApiVerController extends Controller
{
    public function index()
    {
        return view('tiempos.api', [
            'endpoint' => url('/api/tiempos/ruta'),
            'rutas' => DBTiemposRuta::rutas_lista(),
        ]);
    }
}
