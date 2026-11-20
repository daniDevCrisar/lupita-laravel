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
     * GET /lupita/ubicaciones/maps/distrito/{ubigeo}
     *
     * SE QUITO el atajo "centro del distrito": geocodificar un distrito POR NOMBRE no
     * es confiable en este pais. Geoapify resuelve "PUENTE PIEDRA, LIMA, LIMA" como
     * Chiclayo (-6.77,-79.84 en vez de -11.86,-77.08, ~700 km de error), y varias de
     * las filas que ya estaban en `geo_coordenadas` tienen el mismo problema (050101
     * AYACUCHO, 140101 CHICLAYO y 150601 HUARAL apuntan al mismo grifo en Lurin).
     *
     * Escribir un centroide asi en `locales.lat/lng` seria peor que no tener
     * coordenadas, porque la prediccion las va a consumir. El pin real se carga con
     * el link de Google Maps; si algun dia hace falta el centroide, el camino
     * confiable es geocodificacion INVERSA desde un punto, no directa desde un nombre.
     */
    public function distrito(string $ubigeo)
    {
        return response()->json([
            'ok'    => false,
            'error' => 'El centro del distrito no se carga automáticamente: buscar un distrito por '
                . 'nombre se equivoca de ciudad (Puente Piedra terminó en Chiclayo). Pegá el link '
                . 'de Google Maps del pin exacto.',
        ], 410);
    }
}
