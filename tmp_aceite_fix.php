<?php
// De las 7 refs que mencionan PACIFIC: que texto traen, y que palabra clave haria falta.
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (DB::table('referencias')->where('titulo_viaje', 'like', '%PACIFIC%')->get(['ref', 'titulo_viaje', 'ruta_id']) as $r) {
    $t = (string) $r->titulo_viaje;
    $matcheaGrupo = preg_match('/\b(GRUPO PACIFIC OIL)\b/iu', $t) === 1;
    $matcheaPacific = preg_match('/\b(PACIFIC OIL)\b/iu', $t) === 1;
    echo $r->ref . ' | ' . mb_substr($t, 0, 70) . PHP_EOL;
    echo '    "GRUPO PACIFIC OIL": ' . ($matcheaGrupo ? 'SI' : 'no') . ' | "PACIFIC OIL": ' . ($matcheaPacific ? 'SI' : 'no')
        . ' | ruta actual: ' . var_export($r->ruta_id, true) . PHP_EOL;
}

echo PHP_EOL . '=== prueba: con clave GRUPO PACIFIC OIL|PACIFIC OIL, matchea los titulos de PACIFIC? ===' . PHP_EOL;
$clave = 'GRUPO PACIFIC OIL|PACIFIC OIL';
foreach (DB::table('referencias')->where('titulo_viaje', 'like', '%PACIFIC%')->pluck('titulo_viaje') as $t) {
    echo '  ' . (preg_match('/\b(' . $clave . ')\b/iu', (string) $t) === 1 ? 'SI' : 'NO ') . ' ' . mb_substr((string) $t, 0, 60) . PHP_EOL;
}

echo PHP_EOL . '=== y NO matchea los de PATRONA / ALDEBSA / SUPERALIMENTOS? ===' . PHP_EOL;
foreach (['RECOJO ACEITE GRUPO PATRONA - PTA HUACHIPA', 'RECOJO DE NN ACEITE ALDEBSA - PTA HUACHIPA', 'RECOJO DE ACEITE SUPERALIMENTOS NN - PTA HUACHIPA'] as $t) {
    echo '  ' . (preg_match('/\b(' . $clave . ')\b/iu', $t) === 1 ? 'MATCHEA (mal)' : 'no matchea (bien)') . ' | ' . $t . PHP_EOL;
}
