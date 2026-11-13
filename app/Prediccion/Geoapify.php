<?php

namespace App\Prediccion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Puente con Geoapify: coordenadas de un ubigeo y tiempo de manejo entre dos
 * ubigeos. Se usa sólo cuando NO hay tránsito medido con viajes reales: el dato
 * de la flota siempre manda.
 *
 * Por qué así y no con un promedio compilado:
 *  - los E4 medidos se inflan (dentro de "inicio de ruta -> llegada a destino"
 *    hay colas y descansos, y un AVG se los come): Caral -> Chiclayo promedia
 *    1371 min en la base mientras el manejo real son 547 min;
 *  - para un par que nunca se corrió no hay promedio: ahí entra Geoapify.
 *
 * Las coordenadas sí se guardan (`geo_coordenadas`, una fila por ubigeo): es un
 * dato estable y evita geocodificar en cada request. Los TIEMPOS no se guardan:
 * se piden en vivo y se memorizan sólo durante el request.
 *
 * Si no hay key, si la API falla o si el ubigeo no se puede ubicar, todo
 * devuelve null y el motor cae a sus fallbacks de siempre. Nunca rompe.
 */
final class Geoapify
{
    private const GEOCODE = 'https://api.geoapify.com/v1/geocode/search';

    private const ROUTING = 'https://api.geoapify.com/v1/routing';

    /** @var array<string, object|null> rutas ya pedidas en este request */
    private static array $rutas = [];

    /** @var array<string, object|null> coordenadas ya resueltas en este request */
    private static array $coords = [];

    public static function disponible(): bool
    {
        return trim((string) config('geoapify.key', '')) !== '';
    }

    /**
     * Coordenadas del centro del distrito, con su texto legible.
     *
     * @return object{lat: float, lng: float, texto: string}|null
     */
    public static function coord_ubigeo(?string $ubigeo): ?object
    {
        $ubigeo = trim((string) $ubigeo);

        if (preg_match('/^\d{6}$/', $ubigeo) !== 1) {
            return null;
        }

        if (array_key_exists($ubigeo, self::$coords)) {
            return self::$coords[$ubigeo];
        }

        // 1) de la tabla (una fila por ubigeo)
        try {
            $fila = DB::table('geo_coordenadas')->where('ubigeo', $ubigeo)->first();
        } catch (\Throwable $e) {
            $fila = null;   // tabla sin crear todavía
        }

        if ($fila !== null) {
            return self::$coords[$ubigeo] = (object) [
                'lat' => (float) $fila->lat,
                'lng' => (float) $fila->lng,
                'texto' => (string) ($fila->texto ?? $ubigeo),
            ];
        }

        // 2) geocodificar por nombre del distrito
        return self::$coords[$ubigeo] = self::geocodificar($ubigeo);
    }

    /**
     * Tiempo de manejo entre dos ubigeos.
     *
     * @return object{min: int, km: float|null, desde: string, hasta: string}|null
     */
    public static function ruta(?string $origen, ?string $destino): ?object
    {
        $origen = trim((string) $origen);
        $destino = trim((string) $destino);

        if ($origen === '' || $destino === '' || $origen === $destino || !self::disponible()) {
            return null;
        }

        $llave = $origen . '>' . $destino;
        if (array_key_exists($llave, self::$rutas)) {
            return self::$rutas[$llave];
        }

        $desde = self::coord_ubigeo($origen);
        $hasta = self::coord_ubigeo($destino);

        if ($desde === null || $hasta === null) {
            return self::$rutas[$llave] = null;
        }

        try {
            $respuesta = Http::timeout(6)->get(self::ROUTING, [
                'waypoints' => $desde->lat . ',' . $desde->lng . '|' . $hasta->lat . ',' . $hasta->lng,
                'mode' => 'drive',
                'apiKey' => (string) config('geoapify.key', ''),
            ]);
        } catch (\Throwable $e) {
            return self::$rutas[$llave] = null;
        }

        if (!$respuesta->successful()) {
            return self::$rutas[$llave] = null;
        }

        $segundos = $respuesta->json('features.0.properties.time');
        $metros = $respuesta->json('features.0.properties.distance');

        if (!is_numeric($segundos) || (float) $segundos <= 0) {
            return self::$rutas[$llave] = null;
        }

        return self::$rutas[$llave] = (object) [
            'min' => (int) round(((float) $segundos) / 60),
            'km' => is_numeric($metros) ? round(((float) $metros) / 1000, 1) : null,
            'desde' => $desde->texto,
            'hasta' => $hasta->texto,
        ];
    }

    /** Texto legible de un ubigeo: "Chiclayo, Chiclayo, Lambayeque". */
    public static function nombre_ubigeo(string $ubigeo): ?string
    {
        try {
            $fila = DB::selectOne(
                'SELECT d.distrito, p.provincia, dep.departamento
                 FROM ubigeo_distritos d
                 LEFT JOIN ubigeo_provincias p ON p.id = d.provincia_id
                 LEFT JOIN ubigeo_departamentos dep ON dep.id = d.departamento_id
                 WHERE d.ubigeo = ?',
                [$ubigeo]
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($fila === null) {
            return null;
        }

        $partes = array_filter([
            trim((string) ($fila->distrito ?? '')),
            trim((string) ($fila->provincia ?? '')),
            trim((string) ($fila->departamento ?? '')),
        ], fn (string $parte) => $parte !== '');

        return $partes === [] ? null : implode(', ', $partes);
    }

    /** Geocodifica el distrito y guarda las coordenadas para no repetir la llamada. */
    private static function geocodificar(string $ubigeo): ?object
    {
        if (!self::disponible()) {
            return null;
        }

        $nombre = self::nombre_ubigeo($ubigeo);
        if ($nombre === null) {
            return null;
        }

        try {
            $respuesta = Http::timeout(6)->get(self::GEOCODE, [
                'text' => $nombre . ', Peru',
                'filter' => 'countrycode:pe',
                'limit' => 1,
                'lang' => 'es',
                'apiKey' => (string) config('geoapify.key', ''),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$respuesta->successful()) {
            return null;
        }

        $lat = $respuesta->json('features.0.properties.lat');
        $lng = $respuesta->json('features.0.properties.lon');

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $texto = $nombre;
        $formateado = $respuesta->json('features.0.properties.formatted');
        if (is_string($formateado) && $formateado !== '') {
            $texto .= ' (' . $formateado . ')';
        }

        try {
            DB::table('geo_coordenadas')->upsert([[
                'ubigeo' => $ubigeo,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'texto' => $texto,
                'fuente' => 'geoapify',
                'actualizado_en' => now(),
            ]], ['ubigeo'], ['lat', 'lng', 'texto', 'fuente', 'actualizado_en']);
        } catch (\Throwable $e) {
            // sin tabla igual se usa en este request
        }

        return (object) ['lat' => (float) $lat, 'lng' => (float) $lng, 'texto' => $texto];
    }

    /** Limpia la memoización por request (pruebas). */
    public static function olvidar(): void
    {
        self::$rutas = [];
        self::$coords = [];
    }
}
