<?php

namespace App\Ubicaciones;

use Illuminate\Support\Facades\Http;

/**
 * LinkGoogleMaps
 *
 * Saca latitud, longitud y nombre del lugar desde un link de Google Maps.
 *
 * POR QUE UN PARSER Y NO UNA API: el link ya trae las coordenadas adentro. Pedirlas
 * a la API de Google costaria plata y una key; parsear el link es gratis y funciona
 * sin configurar nada. Lo unico que necesita red es el link corto (maps.app.goo.gl),
 * que hay que resolver siguiendo el redirect.
 *
 * Orden de preferencia de las fuentes:
 *  1. `!3d/!4d` del data block: es el punto del PIN.
 *  2. `@lat,lng`: es el centro del viewport, puede estar corrido respecto del pin.
 *  3. query params (`?q=`, `?ll=`, `?daddr=`, `?center=`).
 */
class LinkGoogleMaps
{
    /** Hosts aceptados. Cualquier otro se rechaza (evita que el endpoint sea un SSRF). */
    private const HOSTS = [
        'google.com',
        'www.google.com',
        'maps.google.com',
        'maps.app.goo.gl',
        'goo.gl',
    ];

    /** Hosts de link corto: hay que seguirlos para ver las coordenadas. */
    private const HOSTS_CORTOS = ['maps.app.goo.gl', 'goo.gl'];

    /** Saltos maximos al resolver un link corto. */
    private const MAX_SALTOS = 3;

    /**
     * @param callable|null $resolvedor fn(string $url): ?string  (se inyecta en los tests)
     *
     * @return array{ok: bool, lat: ?float, lng: ?float, zoom: ?int, nombre: ?string, fuente: ?string, error: ?string}
     */
    public static function extraer(string $link, ?callable $resolvedor = null): array
    {
        $salida = [
            'ok'     => false,
            'lat'    => null,
            'lng'    => null,
            'zoom'   => null,
            'nombre' => null,
            'fuente' => null,
            'error'  => null,
        ];

        $link = trim($link);
        if ($link === '') {
            return ['error' => 'Pegá un link de Google Maps.'] + $salida;
        }

        // el link puede venir sin esquema ("maps.app.goo.gl/abc")
        if (parse_url($link, PHP_URL_HOST) === null) {
            $link = 'https://' . ltrim($link, '/');
        }

        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        if (!in_array($host, self::HOSTS, true)) {
            return ['error' => 'El link tiene que ser de Google Maps (google.com o maps.app.goo.gl).'] + $salida;
        }

        // link corto: seguirlo hasta el link largo
        if (in_array($host, self::HOSTS_CORTOS, true)) {
            $largo = ($resolvedor ?? [self::class, 'resolver'])($link);

            if (!is_string($largo) || $largo === '') {
                return ['error' => 'No se pudo abrir el link corto. Copiá el link largo desde Compartir.'] + $salida;
            }

            $host_largo = strtolower((string) parse_url($largo, PHP_URL_HOST));
            if (!in_array($host_largo, self::HOSTS, true)) {
                return ['error' => 'El link corto redirige a un sitio que no es Google Maps.'] + $salida;
            }

            $link = $largo;
        }

        $salida['nombre'] = self::nombre($link);

        $coordenadas = self::coordenadas($link);
        if ($coordenadas === null) {
            return [
                'error' => 'El link no trae coordenadas. Abrí el lugar en Google Maps, tocá Compartir → Copiar link.',
            ] + $salida;
        }

        [$lat, $lng, $zoom, $fuente] = $coordenadas;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['error' => 'Las coordenadas del link están fuera de rango (lat ' . $lat . ', lng ' . $lng . ').']
                + $salida;
        }

        if (abs($lat) < 0.000001 && abs($lng) < 0.000001) {
            return ['error' => 'El link apunta a 0,0: parece mal copiado.'] + $salida;
        }

        return [
            'ok'     => true,
            'lat'    => $lat,
            'lng'    => $lng,
            'zoom'   => $zoom,
            'nombre' => $salida['nombre'],
            'fuente' => $fuente,
            'error'  => null,
        ];
    }

    /**
     * Link externo para ver un punto en el mapa.
     *
     * UNICO lugar donde se arma: las vistas no escriben la URL a mano (Google no es
     * una ruta de la aplicacion, pero la base vive en config para no repetir texto).
     */
    public static function urlMapa($lat, $lng): ?string
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return (string) config('ubicaciones.mapa_url', 'https://www.google.com/maps?q=')
            . $lat . ',' . $lng;
    }

    /**
     * Nombre del lugar que aparece en `/maps/place/...`.
     */
    private static function nombre(string $link): ?string
    {
        if (preg_match('#/maps/place/([^/@?]+)#', $link, $m) === 1) {
            $nombre = urldecode(str_replace('+', ' ', $m[1]));
            $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));

            return $nombre !== '' ? $nombre : null;
        }

        return null;
    }

    /**
     * Coordenadas del link, en orden de preferencia.
     *
     * @return array{0: float, 1: float, 2: ?int, 3: string}|null
     */
    private static function coordenadas(string $link): ?array
    {
        // 1) data block: el pin
        if (preg_match('/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', $link, $m) === 1) {
            return [(float) $m[1], (float) $m[2], null, 'data'];
        }

        // 2) @lat,lng,zoom
        if (preg_match('#/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)(?:,(\d+(?:\.\d+)?)z)?#', $link, $m) === 1) {
            $zoom = isset($m[3]) ? (int) round((float) $m[3]) : null;

            return [(float) $m[1], (float) $m[2], $zoom, 'arroba'];
        }

        // 3) query params
        $query = (string) parse_url($link, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);

            foreach (['q', 'll', 'daddr', 'center', 'sll'] as $param) {
                $valor = $params[$param] ?? null;
                if (!is_string($valor) || $valor === '') {
                    continue;
                }

                if (preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', trim($valor), $m) === 1) {
                    return [(float) $m[1], (float) $m[2], null, 'query'];
                }
            }
        }

        return null;
    }

    /**
     * Sigue el redirect de un link corto, a mano y con tope de saltos.
     *
     * Se hace con `allow_redirects => false` para validar cada salto contra la lista
     * de hosts antes de seguirlo, en vez de dejar que el cliente HTTP vaya solo.
     */
    private static function resolver(string $url): ?string
    {
        $actual = $url;

        for ($salto = 0; $salto < self::MAX_SALTOS; $salto++) {
            try {
                $respuesta = Http::withOptions([
                    'allow_redirects' => false,
                    'timeout'         => 5,
                ])->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; lupita-laravel)'])->get($actual);
            } catch (\Throwable $e) {
                return null;
            }

            $destino = $respuesta->header('Location');
            if ($destino === '') {
                return $actual;
            }

            $actual = $destino;

            if (!in_array(strtolower((string) parse_url($actual, PHP_URL_HOST)), self::HOSTS, true)) {
                return null;
            }
        }

        return $actual;
    }
}
