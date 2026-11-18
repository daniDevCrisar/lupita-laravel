<?php
/**
 * BORRAR LAS VISTAS COMPILADAS  (el archivo más rápido para el hosting)
 * ---------------------------------------------------------------------
 * No arranca Laravel: sólo borra los .php de storage/framework/views.
 * Laravel los vuelve a generar la próxima vez que se abre cada página.
 *
 * 1) Subilo a `public/_limpiar_vistas.php`
 * 2) Abrí:  https://calls.pedrosotelo.fun/_limpiar_vistas.php
 * 3) Recargá la pantalla que daba 500
 * 4) BORRALO del hosting
 */

header('Content-Type: text/plain; charset=utf-8');

$carpeta = realpath(__DIR__ . '/../storage/framework/views');

echo "borrando vistas compiladas\n";
echo "carpeta: " . ($carpeta ?: '(no existe)') . "\n\n";

if ($carpeta === false || !is_dir($carpeta)) {
    echo "FALLA: no encuentro storage/framework/views desde " . __DIR__ . "\n";
    exit;
}

if (!is_writable($carpeta)) {
    echo "FALLA: la carpeta NO se puede escribir. Hay que darle permisos (755 o 775) por FTP.\n";
    echo "Sin eso Laravel no puede recompilar y va a seguir sirviendo lo viejo.\n";
    exit;
}

$borradas = 0;
$errores = [];

foreach (glob($carpeta . '/*.php') ?: [] as $archivo) {
    if (@unlink($archivo)) {
        $borradas++;
    } else {
        $errores[] = basename($archivo);
    }
}

echo "borradas: " . $borradas . "\n";

if ($errores !== []) {
    echo "no se pudieron borrar: " . implode(', ', $errores) . "\n";
}

echo "\nlisto. Recargá la página que daba 500 (con Ctrl+F5).\n";
echo "Si vuelve a fallar, subí _diagnostico.php y abrilo: ahí dice qué está pasando.\n";
