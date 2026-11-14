<?php

namespace App\Prediccion;

use Carbon\Carbon;
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
 * El tiempo de manejo se pide como CAMIÓN y con el tráfico de la hora de salida
 * (`HoraPico`): en hora pico, sin tráfico, el número se queda corto por horas.
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

    /** Modo de ruteo por defecto: la flota son camiones. */
    public const MODO_RUTA_DEFECTO = 'truck';

    /** Modos que se aceptan en `geoapify.modo_ruta` (los de vehículo pesado + drive). */
    public const MODOS_RUTA = [
        'truck', 'light_truck', 'medium_truck', 'heavy_truck', 'long_truck',
        'truck_dangerous_goods', 'truck_traffic_approximated', 'drive',
    ];

    /** Tráfico con el que se pide el ruteo por defecto (`geoapify.trafico`). */
    public const TRAFICO_DEFECTO = 'approximated';

    /** @var array{0: string, 1: string|null}|null el escalón que funcionó en este proceso */
    private static ?array $escalon = null;

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

    /**
     * Modo de ruteo configurado (`geoapify.modo_ruta`, por defecto `truck`).
     *
     * Todos los viajes son de camiones: con `truck` el proveedor respeta las
     * restricciones de vehículo pesado y el tiempo de manejo deja de ser el de un
     * auto (en el Serpentín de Pasamayo son 36 km / 51 min, contra 32,6 km /
     * 30 min en `drive`).
     */
    public static function modo_ruta(): string
    {
        $modo = mb_strtolower(trim((string) config('geoapify.modo_ruta', self::MODO_RUTA_DEFECTO)));

        return in_array($modo, self::MODOS_RUTA, true) ? $modo : self::MODO_RUTA_DEFECTO;
    }

    /**
     * Tráfico configurado (`geoapify.trafico`): `approximated` pide el tiempo con
     * el tráfico estimado para esa hora, null pide vía libre.
     *
     * Es la diferencia entre 51 min y 134 min en el mismo tramo del Serpentín.
     */
    public static function trafico(): ?string
    {
        $trafico = mb_strtolower(trim((string) config('geoapify.trafico', self::TRAFICO_DEFECTO)));

        return in_array($trafico, ['approximated', 'free_flow'], true) ? $trafico : null;
    }

    /**
     * ¿Este tramo se pide CON tráfico?
     *
     * Tres condiciones: que el tráfico no esté apagado en la configuración, que
     * `trafico_siempre` no fuerce pedirlo, y que la hora de salida caiga en una
     * franja pico de la zona del viaje (App\Prediccion\HoraPico).
     *
     * Sin hora de salida se pide vía libre: si no se sabe cuándo sale el camión,
     * no se adivina el tráfico (y no se gasta la llamada con tráfico).
     */
    public static function trafico_para(?Carbon $salida, ?string $ubigeoOrigen, ?string $ubigeoDestino): ?string
    {
        $pedido = self::trafico();

        if ($pedido === null) {
            return null;   // tráfico apagado: vía libre siempre
        }

        if ((bool) config('geoapify.trafico_siempre', false)) {
            return $pedido;
        }

        if ($salida === null) {
            return null;
        }

        return HoraPico::es_pico($salida, HoraPico::zona($ubigeoOrigen, $ubigeoDestino))
            ? $pedido
            : null;
    }

    /** El modo con el que realmente se están pidiendo las rutas en este proceso. */
    public static function modo_ruta_usado(): string
    {
        return self::$escalon[0] ?? self::modo_ruta();
    }

    /** El tráfico con el que realmente se piden las rutas en este proceso. */
    public static function trafico_usado(): ?string
    {
        return self::$escalon === null ? self::trafico() : self::$escalon[1];
    }

    /**
     * Escalones de ruteo, del más específico al más simple.
     *
     * Se prueban en orden y el primero que el proveedor acepta queda para todo el
     * proceso: así un plan que no incluye tráfico o camiones no rompe el cálculo
     * ni duplica llamadas en cada request.
     *
     * `$trafico` es lo que decidió `trafico_para()` para ESTE tramo (hora pico o
     * vía libre); si viene null no se agrega el escalón con tráfico.
     *
     * @return array<int, array{0: string, 1: string|null}>
     */
    private static function escalones(?string $trafico = null): array
    {
        $modo = self::modo_ruta();

        $escalones = [];
        if ($trafico !== null) {
            $escalones[] = [$modo, $trafico];   // camión + tráfico aproximado
        }
        $escalones[] = [$modo, null];           // camión, vía libre

        $base = str_replace('_traffic_approximated', '', $modo);
        if ($base !== $modo) {
            $escalones[] = [$base, null];       // sin la variante con tráfico del nombre
        }
        if ($base !== 'truck') {
            $escalones[] = ['truck', null];
        }
        $escalones[] = ['drive', null];         // último recurso

        $unicos = [];
        foreach ($escalones as $escalon) {
            $llave = $escalon[0] . '|' . (string) $escalon[1];
            if (!isset($unicos[$llave])) {
                $unicos[$llave] = $escalon;
            }
        }

        return array_values($unicos);
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
     * proveedor descuenta del plan.
     *
     * Devuelve la respuesta (exista o no el recurso) o null si NO hubo respuesta
     * — sin red, timeout o key rechazada por conexión. La diferencia importa en
     * `routing()`: sólo un rechazo del proveedor (HTTP 4xx/5xx) hace que se
     * reintente con otro modo de ruteo; un problema de red no.
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

        return $respuesta;
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
     * `$salida` es la hora a la que el camión sale de verdad: con eso se decide
     * si se pide el tiempo CON tráfico (hora pico) o de vía libre (ver
     * App\Prediccion\HoraPico). Sin hora, se pide vía libre: no se adivina.
     *
     * @return object{min: int, km: float|null, desde: string, hasta: string, modo: string, trafico: string|null}|null
     */
    public static function ruta(?string $origen, ?string $destino, ?Carbon $salida = null): ?object
    {
        $origen = trim((string) $origen);
        $destino = trim((string) $destino);

        if ($origen === '' || $destino === '' || $origen === $destino || !self::disponible()) {
            return null;
        }

        $trafico = self::trafico_para($salida, $origen, $destino);
        $llave = $origen . '>' . $destino . '|' . ($trafico ?? 'libre');
        if (array_key_exists($llave, self::$rutas)) {
            return self::$rutas[$llave];
        }

        $desde = self::coord_ubigeo($origen);
        $hasta = self::coord_ubigeo($destino);

        if ($desde === null || $hasta === null) {
            return self::$rutas[$llave] = null;
        }

        return self::$rutas[$llave] = self::routing($desde, $hasta, $trafico);
    }

    /**
     * Tiempo de manejo desde un PUNTO (lat/lng) hasta un ubigeo.
     *
     * Es el camino cuando el operador escribió el nombre del distrito y no está en
     * el catálogo de ubigeos: se geocodifica el texto y se rutea desde ese punto.
     *
     * `$ubigeoOrigen` no se usa para rutear (el punto ya tiene coordenadas): sirve
     * sólo para saber si el viaje toca la capital y, con `$salida`, decidir si
     * corresponde pedir tráfico.
     */
    public static function ruta_desde_coords(float $lat, float $lng, ?string $ubigeoDestino,
                                             ?Carbon $salida = null, ?string $ubigeoOrigen = null): ?object
    {
        if (!self::disponible()) {
            return null;
        }

        $trafico = self::trafico_para($salida, $ubigeoOrigen, $ubigeoDestino);
        $llave = 'punto|' . $lat . ',' . $lng . '>' . (string) $ubigeoDestino . '|' . ($trafico ?? 'libre');
        if (array_key_exists($llave, self::$rutas)) {
            return self::$rutas[$llave];
        }

        $hasta = self::coord_ubigeo($ubigeoDestino);
        if ($hasta === null) {
            return self::$rutas[$llave] = null;
        }

        $desde = (object) ['lat' => $lat, 'lng' => $lng, 'texto' => 'el punto declarado'];

        return self::$rutas[$llave] = self::routing($desde, $hasta, $trafico);
    }

    /**
     * Geocodifica TEXTO LIBRE ("chiclayo", "pasamayo"): devuelve TODOS los
     * candidatos que encuentra en Perú, para que el que llama decida.
     *
     * Es a propósito una lista: "PASAMAYO" existe en Aucallama (Lima) y en
     * Yantalo (San Martín); tomar el primero sin avisar sería adivinar.
     *
     * @return array<int, object{lat: float, lng: float, texto: string}>
     */
    public static function geocodificar_texto(string $texto, int $limite = 5): array
    {
        $texto = trim($texto);
        if ($texto === '' || !self::disponible()) {
            return [];
        }

        $llave = 'texto|' . mb_strtolower($texto) . '|' . $limite;
        if (array_key_exists($llave, self::$coords)) {
            return self::$coords[$llave] ?? [];
        }

        $respuesta = self::pedir(self::GEOCODE, [
            'text' => $texto . ', Peru',
            'filter' => 'countrycode:pe',
            'limit' => max(1, min(10, $limite)),
            'lang' => 'es',
            'apiKey' => (string) config('geoapify.key', ''),
        ], 'geocode');

        if ($respuesta === null || !$respuesta->successful()) {
            return self::$coords[$llave] = [];
        }

        $salida = [];
        foreach ((array) $respuesta->json('features') as $feature) {
            $lat = $feature['properties']['lat'] ?? null;
            $lon = $feature['properties']['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $formateado = $feature['properties']['formatted'] ?? null;
            $salida[] = (object) [
                'lat' => (float) $lat,
                'lng' => (float) $lon,
                'texto' => is_string($formateado) && $formateado !== '' ? $formateado : $texto,
                'nombre' => (string) ($feature['properties']['name'] ?? ''),
            ];
        }

        return self::$coords[$llave] = $salida;
    }

    /**
     * Una llamada de routing entre dos puntos (ya resueltos).
     *
     * Se pide como camión y con el tráfico estimado de esa hora
     * (`geoapify.modo_ruta` = truck, `geoapify.trafico` = approximated): un auto
     * en vía libre no se parece a un tracto en hora punta (Serpentín de Pasamayo
     * -> San Martín de Porres: 134 min con tráfico, 51 min sin tráfico y 30 min
     * como auto).
     *
     * Si el proveedor RECHAZA el escalón —contesta 400 con "mode must be one of
     * [...]", que es lo que hace cuando el plan no incluye ese modo o el
     * tráfico— se prueba el siguiente escalón (sin tráfico, luego camión simple,
     * luego `drive`) y el primero que anda queda para todo el proceso: así no se
     * duplican llamadas en cada request.
     *
     * Un 500, un 429 o una caída de red NO bajan de escalón: no son un rechazo
     * del plan, son problemas del proveedor.
     */
    private static function routing(object $desde, object $hasta, ?string $trafico = null): ?object
    {
        $waypoints = $desde->lat . ',' . $desde->lng . '|' . $hasta->lat . ',' . $hasta->lng;
        $parametros = function (string $modo, ?string $trafico) use ($waypoints): array {
            $pedido = [
                'waypoints' => $waypoints,
                'mode' => $modo,
                'apiKey' => (string) config('geoapify.key', ''),
            ];

            if ($trafico !== null) {
                $pedido['traffic'] = $trafico;
            }

            return $pedido;
        };

        $respuesta = null;
        $elegido = null;

        foreach (self::escalones($trafico) as $escalon) {
            [$modo, $traficoEscalon] = $escalon;

            $respuesta = self::pedir(self::ROUTING, $parametros($modo, $traficoEscalon), 'routing');

            if ($respuesta === null) {
                return null;   // sin red: no tiene sentido seguir probando
            }

            if ($respuesta->successful()) {
                $elegido = $escalon;
                break;
            }

            if (!in_array($respuesta->status(), [400, 422], true)) {
                return null;   // 500 / 429: es el proveedor, no el escalón
            }
        }

        if ($elegido === null) {
            return null;
        }

        self::$escalon = $elegido;
        $segundos = $respuesta->json('features.0.properties.time');
        $metros = $respuesta->json('features.0.properties.distance');

        if (!is_numeric($segundos) || (float) $segundos <= 0) {
            return null;
        }

        return (object) [
            'min' => (int) round(((float) $segundos) / 60),
            'km' => is_numeric($metros) ? round(((float) $metros) / 1000, 1) : null,
            'desde' => $desde->texto,
            'hasta' => $hasta->texto,
            'modo' => self::modo_ruta_usado(),
            'trafico' => self::trafico_usado(),
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

            if ($respuesta === null || !$respuesta->successful()) {
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
        self::$escalon = null;
        self::$llamadas = 0;
        self::$llamadasPorTipo = ['geocode' => 0, 'routing' => 0];
    }
}
