<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBLocales;
use App\Http\Controllers\Controller;
use App\Ubicaciones\LinkGoogleMaps;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ubicaciones\MapsController
 *
 * Pantalla "Desde Google Maps" (/lupita/ubicaciones/maps): pegar el link de la planta,
 * extraer las coordenadas del propio link (sin API ni key) y asignarlas a un local.
 *
 * El unico que escribe es `asignar()`, y solo las columnas de coordenadas del local.
 *
 * REGLA DE ACCESO: `role:admin` en la ruta.
 */
class MapsController extends Controller
{
    public function index()
    {
        $locales = DBLocales::listar();

        $con_coordenadas = array_values(array_filter($locales, fn ($l) => $l->lat !== null && $l->lng !== null));
        $sin_coordenadas = array_values(array_filter($locales, fn ($l) => $l->lat === null || $l->lng === null));

        return view('lupita.ubicaciones.maps', [
            'locales'         => $locales,
            'con_coordenadas' => count($con_coordenadas),
            'sin_coordenadas' => $sin_coordenadas,
        ]);
    }

    /**
     * POST /lupita/ubicaciones/maps/extraer : link -> {lat, lng, nombre}
     */
    public function extraer(Request $request)
    {
        $datos = $request->validate([
            'link' => ['required', 'string', 'max:2000'],
        ], [
            'link.required' => 'Pegá el link de Google Maps.',
            'link.max'      => 'El link es demasiado largo.',
        ]);

        $resultado = LinkGoogleMaps::extraer($datos['link']);

        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    /**
     * POST /lupita/ubicaciones/maps/asignar : coordenadas -> local
     */
    public function asignar(Request $request)
    {
        $datos = $request->validate([
            'local_id'  => ['required', 'string', 'max:50', Rule::exists('locales', 'id')],
            'lat'       => ['required', 'numeric', 'between:-90,90'],
            'lng'       => ['required', 'numeric', 'between:-180,180'],
            'geo_texto' => ['nullable', 'string', 'max:200'],
        ], [
            'local_id.exists' => 'El local elegido no existe.',
            'lat.between'     => 'La latitud tiene que estar entre -90 y 90.',
            'lng.between'     => 'La longitud tiene que estar entre -180 y 180.',
        ]);

        DBLocales::actualizarCoordenadas(
            $datos['local_id'],
            (float) $datos['lat'],
            (float) $datos['lng'],
            $datos['geo_texto'] ?? null,
            'maps'
        );

        return redirect()
            ->route('lupita.ubicaciones.maps')
            ->with('exito', 'Coordenadas guardadas en ' . $datos['local_id'] . '.');
    }

    /**
     * GET /lupita/ubicaciones/maps/distrito/{ubigeo} : centro del distrito
     *
     * Atajo para el formulario del local: si todavia no tenes el pin exacto, carga el
     * centro del distrito (de `geo_coordenadas`) y lo marca con fuente `distrito`.
     * No llama a ninguna API: si el distrito no esta cacheado, responde 404.
     */
    public function distrito(string $ubigeo)
    {
        $coordenadas = DBLocales::coordenadasDistrito($ubigeo);

        if ($coordenadas === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'Ese distrito todavía no tiene coordenadas guardadas.',
            ], 404);
        }

        return response()->json([
            'ok'     => true,
            'lat'    => $coordenadas['lat'],
            'lng'    => $coordenadas['lng'],
            'nombre' => $coordenadas['nombre'],
        ]);
    }
}
