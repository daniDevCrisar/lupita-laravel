<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\LlamadasController;
use Illuminate\Http\Request;

$fecha = $argv[1] ?? '2026-10-28';
$request = Request::create('/lupita/reporte/portada', 'GET', ['fecha_inicio' => $fecha]);
$request->validate = null;

try {
    $view = LlamadasController::reporte_portada($request);
    $html = $view->render();
    echo "OK render, longitud=" . strlen($html) . PHP_EOL;
    // checks rápidos
    foreach (['Portada de Reporte VAPI', 'Resumen de éxito por etapa logistica',
              'Top 5 Rutas', 'Errores Técnicos', 'Ver detalle',
              'Total confirmaciones', 'danidevcrisar.github.io'] as $s) {
        echo (str_contains($html, $s) ? '  ok  ' : ' FALTA ') . $s . PHP_EOL;
    }
    // contar botones "Ver detalle" (debe ser 5 tipos)
    echo '  botones detalle=' . substr_count($html, 'Ver detalle') . PHP_EOL;
    // URL de ejemplo
    if (preg_match('#https://danidevcrisar\.github\.io[^"]+\.html#', $html, $m)) {
        echo '  url ejemplo: ' . $m[0] . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    echo $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
