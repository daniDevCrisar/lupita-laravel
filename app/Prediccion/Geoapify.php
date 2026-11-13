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
 *
 * Cada petición HTTP se cuenta: en memoria (`llamadas()`, para saber cuántas
 * gastó una predicción) y en la tabla `geoapify_uso` (una fila por día y por
 * endpoint, ver App\Database\DBGeoapify). Es la única forma de saber cuántas
 * veces se usó el API sin entrar al panel del proveedor.
 */
final class Geoapify
{
    private const GEOCODE = 'https://api.geoapify.com/v1/geocode/search';

    private const ROUTING = 'https://api.geoapify.com/v1/routing';

    /** @var array<string, object|null> rutas ya pedidas en este request */
    private static array $rutas = [];

    /** @var array<string, object|null> coordenadas ya resueltas en este request */
    private static array $coords = [];

    /** Interruptor para procesos masivos (la tabla de aciertos corre cientos de filas). */
    private static bool $activo = true;

    /** Llamadas HTTP hechas a Geoapify en este proceso (para saber cuánto costó una predicción). */
    private static int $llamadas = 0;

    /** @var array<string, int> llamadas por endpoint, en este proceso */
    private static array $llamadasPorTipo = ['geocode' => 0, 'routing' => 0];

    /** Apaga Geoapify en este proceso: `disponible()` pasa a false y todo cae a los fallbacks. */
    public static function desactivar(): void
    {
        self::$activo = false;
    }

    public static function activar(): void
    {
        self::$activo = true;
    }

    public static function disponible(): bool
    {
        return self::$activo && trim((string) config('geoapify.key', '')) !== '';
    }

    /** Cuántas peticiones HTTP se le hicieron a Geoapify en este proceso. */
    public static function llamadas(): int
    {
        return self::$llamadas;
    }

    /** @return array<string, int> peticiones por endpoint en este proceso */
    public static function llamadas_por_tipo(): array
    {
        return self::$llamadasPorTipo;
    }

    /**
     * Una petición HTTP a Geoapify, CONTADA.
     *
     * Se cuenta antes de mandarla y se registra en `geoapify_uso` (ver
     * App\Database\DBGeoapify): cada request, exitoso o no, es lo que el
     * proveedor descuenta del plan. Devuelve null si la llamada falló, así los
     * llamadores siguen con sus fallbacks sin repetir el manejo de errores.
     */
    private static function pedir(string $url, array $parametros, string $tipo): ?\Illuminate\Http\Client\Response
    {
        self::$llamadas++;
        self::$llamadasPorTipo[$tipo] = (self::$llamadasPorTipo[$tipo] ?? 0) + 1;

        try {
            $respuesta = Http::connectTimeout(2)->timeout(4)->get($url, $parametros);
        } catch (\Throwable $e) {
            \App\Database\DBGeoapify::registrar($tipo, false);

            return null;
        }

        \App\Database\DBGeoapify::registrar($tipo, $respuesta->successful());

        return $respuesta->successful() ? $respuesta : null;
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

        $respuesta = self::pedir(self::ROUTING, [
            'waypoints' => $desde->lat . ',' . $desde->lng . '|' . $hasta->lat . ',' . $hasta->lng,
            'mode' => 'drive',
            'apiKey' => (string) config('geoapify.key', ''),
        ], 'routing');

        if ($respuesta === null) {
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

        // "ANCON, LIMA, LIMA" hace que el parser se confunda (provincia y
        // departamento repetidos): se mandan las partes únicas. Si el texto
        // completo no encuentra nada, se reintenta sólo con el distrito.
        $partes = array_values(array_unique(array_map('trim', explode(',', $nombre))));
        $textos = [
            implode(', ', $partes) . ', Peru',
            ($partes[0] ?? $nombre) . ', Peru',
        ];

        foreach ($textos as $texto) {
            $respuesta = self::pedir(self::GEOCODE, [
                'text' => $texto,
                'filter' => 'countrycode:pe',
                'limit' => 1,
                'lang' => 'es',
                'apiKey' => (string) config('geoapify.key', ''),
            ], 'geocode');

            if ($respuesta === null) {
                continue;
            }

            $lat = $respuesta->json('features.0.properties.lat');
            $lng = $respuesta->json('features.0.properties.lon');

            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }

            $rotulo = $nombre;
            $formateado = $respuesta->json('features.0.properties.formatted');
            if (is_string($formateado) && $formateado !== '') {
                $rotulo .= ' (' . $formateado . ')';
            }

            try {
                DB::table('geo_coordenadas')->upsert([[
                    'ubigeo' => $ubigeo,
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'texto' => $rotulo,
                    'fuente' => 'geoapify',
                    'actualizado_en' => now(),
                ]], ['ubigeo'], ['lat', 'lng', 'texto', 'fuente', 'actualizado_en']);
            } catch (\Throwable $e) {
                // sin tabla igual se usa en este request
            }

            return (object) ['lat' => (float) $lat, 'lng' => (float) $lng, 'texto' => $rotulo];
        }

        return null;
    }

    /** Limpia la memoización y los contadores del proceso (pruebas). */
    public static function olvidar(): void
    {
        self::$rutas = [];
        self::$coords = [];
        self::$activo = true;
        self::$llamadas = 0;
        self::$llamadasPorTipo = ['geocode' => 0, 'routing' => 0];
    }
}
