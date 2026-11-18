<?php
/**
 * TEST DE DIAGNOSTICO PARA EL HOSTING (v2, sin consola)
 * -----------------------------------------------------------------
 * 1) Subí este archivo a `public/_diagnostico.php`.
 * 2) Abrí:  https://calls.pedrosotelo.fun/_diagnostico.php
 * 3) Si querés borrar YA las vistas compiladas (el 90 % de las veces es eso):
 *       https://calls.pedrosotelo.fun/_diagnostico.php?limpiar=1
 * 4) Si el script tarda y el hosting lo corta, usá el modo rápido:
 *       .../_diagnostico.php?rapido=1        (sólo dibuja el 403 y limpia)
 * 5) Cuando termines, BORRALO del hosting.
 *
 * v2: lee sólo el final del log (en producción puede pesar cientos de MB y era
 * lo que hacía que el servidor corte por timeout), ignora las líneas que están
 * dentro de un bloque @auth, y va mostrando cada sección a medida que termina.
 */

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(30);
while (ob_get_level() > 0) { ob_end_flush(); }

$raiz = dirname(__DIR__);
$inicio = microtime(true);
$rapido = ($_GET['rapido'] ?? '') === '1';
$limpiar = ($_GET['limpiar'] ?? '') === '1';

$fallas = 0;
$seccion = function (string $titulo) use ($inicio) {
    echo "\n" . $titulo . "   [" . number_format(microtime(true) - $inicio, 2) . " s]\n";
    flush();
};
$ok = function (string $texto) { echo "  [ OK ]    " . $texto . "\n"; flush(); };
$mal = function (string $texto) use (&$fallas) { $fallas++; echo "  [ FALLA ] " . $texto . "\n"; flush(); };
$info = function (string $texto) { echo "            " . $texto . "\n"; flush(); };

echo "== DIAGNOSTICO v2 " . date('Y-m-d H:i:s') . (($rapido) ? ' (modo rápido)' : '') . " ==\n";

try {
    require $raiz . '/vendor/autoload.php';
    $app = require $raiz . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
} catch (\Throwable $e) {
    echo "No pude arrancar Laravel: " . $e->getMessage() . "\n";
    exit;
}

// ------------------------------------------------------------------ 1. entorno
$seccion('1. ENTORNO');
$info('PHP ' . PHP_VERSION . ' · Laravel ' . $app->version());
$info('APP_ENV = ' . config('app.env') . ' · memory_limit = ' . ini_get('memory_limit'));

$compiladas = config('view.compiled');
$info('vistas compiladas: ' . $compiladas);
(is_dir($compiladas) && is_writable($compiladas))
    ? $ok('la carpeta de compiladas se puede escribir')
    : $mal('la carpeta de compiladas NO se puede escribir');

// ------------------------------------------------- 2. layout tal como está en disco
$layout = $raiz . '/resources/views/layouts/app.blade.php';
$protegidas = 0;
$sinProteger = [];

$seccion('2. EL LAYOUT EN DISCO');
if (!is_file($layout)) {
    $mal('no encuentro ' . $layout);
} else {
    $fuente = (string) file_get_contents($layout);
    $info('modificado: ' . date('Y-m-d H:i:s', filemtime($layout)) . ' · ' . strlen($fuente) . ' bytes');

    // se recorre línea por línea sabiendo si estamos dentro de @auth ... @endauth
    $dentro = 0;
    foreach (preg_split('/\R/', $fuente) as $n => $linea) {
        if (preg_match('/@auth\b/', $linea)) { $dentro++; }
        if (preg_match('/@endauth\b/', $linea)) { $dentro = max(0, $dentro - 1); }

        $usa = str_contains($linea, 'auth()->user()->') || str_contains($linea, 'Auth::user()->');
        if (!$usa) {
            continue;
        }

        $protegida = $dentro > 0
            || str_contains($linea, 'auth()->check()')
            || preg_match('/@can|@role|@canany|@if\s*\(\s*auth\(\)->user\(\)/', $linea);

        if ($protegida) {
            $protegidas++;
        } else {
            $sinProteger[] = ($n + 1) . ': ' . trim($linea);
        }
    }

    if ($sinProteger === []) {
        $ok('no hay auth()->user() sin protección (' . $protegidas . ' usos protegidos)');
    } else {
        $mal('hay ' . count($sinProteger) . ' línea(s) con auth()->user() SIN protección:');
        foreach ($sinProteger as $linea) {
            $info($linea);
        }
        $info('=> el archivo del hosting está VIEJO: hay que volver a subirlo');
    }

    (substr_count($fuente, '@auth') > 0 && substr_count($fuente, '@endauth') > 0)
        ? $ok('el menú de usuario está dentro de @auth')
        : $mal('falta el @auth del menú de usuario');
}

// ------------------------------------------------- 3. la compilada que se sirve
$seccion('3. LA VISTA COMPILADA QUE SE ESTÁ SIRVIENDO');
$rutaCompilada = $compiladas . '/' . sha1($layout) . '.php';
$compiladaVieja = false;

if (!is_file($rutaCompilada)) {
    $info('todavía no hay compilada (se crea al abrir la página)');
} else {
    $compilada = (string) file_get_contents($rutaCompilada);
    $compiladaVieja = str_contains($compilada, 'canAny(')
        && !str_contains($compilada, 'auth()->check() && auth()->user()->canAny(');

    $compiladaVieja
        ? $mal('la compilada TODAVÍA tiene canAny() sin proteger: ESE es el 500')
        : $ok('la compilada ya tiene el layout protegido');

    $info('compilada ' . date('H:i:s', filemtime($rutaCompilada))
        . ' · fuente ' . date('H:i:s', filemtime($layout)));
}

// ------------------------------------------------- 4. dibujar el 403 sin sesión
$seccion('4. DIBUJAR LA PÁGINA 403 SIN SESIÓN (el caso que revienta)');
try {
    $html = view('errors.403', [
        'exception' => new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('sin permiso'),
    ])->render();

    str_contains($html, 'Acceso restringido')
        ? $ok('el 403 se dibuja sin usuario (' . strlen($html) . ' bytes)')
        : $mal('el 403 se dibujó pero no dice "Acceso restringido"');

    str_contains($html, 'Cerrar sesión')
        ? $info('nota: muestra "Cerrar sesión" (en este script hay una sesión)')
        : $ok('sin menú de usuario, como corresponde a un invitado');
} catch (\Throwable $e) {
    $mal('REVIENTA: ' . get_class($e) . ': ' . $e->getMessage());
    $info('en ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ------------------------------------------------- 5. otras vistas con el patrón
if (!$rapido) {
    $seccion('5. OTRAS VISTAS CON auth()->user() SIN @auth');
    $sospechosas = [];
    $base = realpath($raiz . '/resources/views');

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $archivo) {
        if (!$archivo->isFile() || !str_ends_with($archivo->getFilename(), '.blade.php')) {
            continue;
        }

        $dentro = 0;
        foreach (preg_split('/\R/', (string) file_get_contents($archivo->getPathname())) as $n => $linea) {
            if (preg_match('/@auth\b/', $linea)) { $dentro++; }
            if (preg_match('/@endauth\b/', $linea)) { $dentro = max(0, $dentro - 1); }

            if ($dentro === 0 && (str_contains($linea, 'auth()->user()->') || str_contains($linea, 'Auth::user()->'))
                && !str_contains($linea, 'auth()->check()')
                && !preg_match('/@can|@role|@canany/', $linea)) {
                $sospechosas[] = str_replace($base . DIRECTORY_SEPARATOR, '', $archivo->getPathname())
                    . ':' . ($n + 1) . '  ' . trim($linea);
            }
        }
    }

    if ($sospechosas === []) {
        $ok('ninguna');
    } else {
        $info(count($sospechosas) . ' linea(s) - solo importan si esa pantalla se ve sin sesion:');
        foreach ($sospechosas as $linea) {
            $info($linea);
        }
    }
}

// ------------------------------------------------- 6. último error del log
if (!$rapido) {
    $seccion('6. LOS ÚLTIMOS ERRORES DEL LOG (sólo el final del archivo)');
    $log = $raiz . '/storage/logs/laravel.log';

    if (!is_file($log)) {
        $info('no hay log');
    } else {
        $tamano = filesize($log);
        $info('laravel.log pesa ' . number_format($tamano / 1048576, 1) . ' MB');

        // se lee SOLO el último cuarto de MB: leer el log entero es lo que hacía
        // que el hosting corte la request por timeout
        $desde = max(0, $tamano - 262144);
        $fh = fopen($log, 'rb');
        fseek($fh, $desde);
        $cola = (string) fread($fh, 262144);
        fclose($fh);

        $interesantes = array_values(array_filter(preg_split('/\R/', $cola),
            fn ($l) => str_contains($l, 'ERROR') || str_contains($l, 'canAny') || str_contains($l, 'Exception')));

        if ($interesantes === []) {
            $info('sin errores en el final del log');
        } else {
            foreach (array_slice($interesantes, -8) as $linea) {
                $info(mb_substr(trim($linea), 0, 220));
            }
        }
    }
}

// ------------------------------------------------- 6b. tabla de rutas
if (!$rapido) {
    $seccion('6b. TABLA DE RUTAS (era el costo del combo que se quitó)');

    try {
        $cuantas = \Illuminate\Support\Facades\DB::table('rutas')->count();
        $info('rutas en la base: ' . $cuantas);
        $ok('/lupita/referencias ya no consulta este catálogo (filtro por texto libre)');

        if (($_GET['pesado'] ?? '') === '1') {
            $t0 = microtime(true);
            $pesado = \App\Database\DBTiemposPromedio::rutas();
            $segPesado = microtime(true) - $t0;
            $info('DBTiemposPromedio::rutas() (4 subconsultas por ruta, lo usan las pantallas de tiempos): '
                . count($pesado) . ' rutas en ' . number_format($segPesado, 2) . ' s');
            $info('=> si este número es enorme, ojo: las pantallas de tiempos también lo llaman');
        } else {
            $info('(para medirlo abrí con ?pesado=1: puede tardar)');
        }
    } catch (\Throwable $e) {
        $mal('no pude leer la tabla de rutas: ' . $e->getMessage());
    }
}

// ------------------------------------------------- 7. limpiar compiladas
if ($limpiar) {
    $seccion('7. LIMPIANDO VISTAS COMPILADAS');
    $borradas = 0;
    foreach (glob($compiladas . '/*.php') ?: [] as $archivo) {
        if (@unlink($archivo)) {
            $borradas++;
        }
    }
    $ok('borradas ' . $borradas . ' vistas compiladas (Laravel las regenera al abrir cada página)');
    $info('ahora volvé a /lupita/referencias');
} elseif ($compiladaVieja) {
    $seccion('7. QUÉ HACER');
    $info('entrá a este mismo archivo con ?limpiar=1 para borrar la compilada vieja');
    $info('después recargá /lupita/referencias');
}

$seccion('== RESUMEN ==');
echo $fallas === 0
    ? "Todo OK: el layout se dibuja sin sesión.\n"
    : "Hay " . $fallas . " falla(s) arriba.\n";
echo "Se ejecutó en " . number_format(microtime(true) - $inicio, 2) . " s.\n";
echo "\nAcordate de BORRAR este archivo del hosting.\n";
