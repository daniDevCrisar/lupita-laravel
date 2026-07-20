<?php
// ============================================================
// LIMPIAR CACHÉ DE LARAVEL (SIN TERMINAL)
// ============================================================
// Guarda este archivo como 'limpiar-cache.php' en la raíz del proyecto
// Luego visítalo desde el navegador: https://tudominio.com/limpiar-cache.php
// ELIMINA este archivo DESPUÉS de usarlo por seguridad.
// ============================================================

// Cargar el autoload de Laravel
require_once __DIR__ . '/vendor/autoload.php';

// Crear la aplicación
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Usar Artisan para limpiar caché
use Illuminate\Support\Facades\Artisan;

echo "<!DOCTYPE html><html><head><title>Limpiar Caché</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#00ff88;}";
echo ".ok{color:#00ff88;}.error{color:#ff4444;}.info{color:#ffd700;}</style></head><body>";
echo "<h1>🧹 Limpiando Caché de Laravel</h1>";
echo "<hr>";

// Lista de comandos a ejecutar
$comandos = [
    'config:clear'     => 'Limpiar caché de configuración',
    'route:clear'      => 'Limpiar caché de rutas',
    'view:clear'       => 'Limpiar caché de vistas',
    'cache:clear'      => 'Limpiar caché de la aplicación',
    'event:clear'      => 'Limpiar caché de eventos',
    'optimize:clear'   => 'Limpiar optimización',
];

foreach ($comandos as $comando => $descripcion) {
    echo "<div><span class='info'>▶</span> $descripcion... ";
    try {
        Artisan::call($comando);
        echo "<span class='ok'>✅ OK</span>";
    } catch (Exception $e) {
        echo "<span class='error'>❌ Error: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
}

// También intentar eliminar archivos de caché directamente
echo "<hr>";
echo "<div><span class='info'>▶</span> Eliminando archivos de caché... ";

$carpetas = [
    'bootstrap/cache/config.php',
    'bootstrap/cache/routes-v7.php',
    'bootstrap/cache/packages.php',
    'bootstrap/cache/services.php',
];

foreach ($carpetas as $archivo) {
    if (file_exists($archivo)) {
        @unlink($archivo);
        echo "<br>  ✅ Eliminado: $archivo";
    } else {
        echo "<br>  ⏭️ No existe: $archivo";
    }
}

echo "</div>";

// Ejecutar composer dump-autoload
echo "<hr>";
echo "<div><span class='info'>▶</span> Ejecutando composer dump-autoload... ";
$output = shell_exec('composer dump-autoload 2>&1');
if (strpos($output, 'Generated') !== false || strpos($output, 'OK') !== false) {
    echo "<span class='ok'>✅ OK</span>";
} else {
    echo "<span class='error'>❌ No se pudo ejecutar. Intenta manualmente.</span>";
}
echo "</div>";

echo "<hr>";
echo "<h2 class='ok'>✅ ¡Caché limpiada correctamente!</h2>";
echo "<p class='info'>Ahora intenta acceder a tu API nuevamente.</p>";
echo "<p><a href='/' style='color:#00ff88;'>⬅ Volver al inicio</a></p>";
echo "</body></html>";
?>
