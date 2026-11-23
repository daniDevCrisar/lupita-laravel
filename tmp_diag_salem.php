<?php
// 1) MySQL tambien matchea todo con esa palabra clave? (lo mismo que hace el SP)
// 2) Cuantas refs quedaron con ruta de SALEM_PIURA que no deberian.
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo '=== MySQL: el mismo chequeo que hace el SP ===' . PHP_EOL;
$prueba = DB::selectOne("
    SELECT
      ('TEXTO QUE NO DEBE MATCHEAR' REGEXP CONCAT('\\\\b(', 'SALEM PIURA|ALMACEN PIURA|', ')\\\\b')) AS con_barra_final,
      ('TEXTO QUE NO DEBE MATCHEAR' REGEXP CONCAT('\\\\b(', 'SALEM PIURA|ALMACEN PIURA', ')\\\\b')) AS sin_barra_final
");
echo '  con "|" al final: ' . ($prueba->con_barra_final ? 'MATCHEA TODO (roto)' : 'no matchea') . PHP_EOL;
echo '  sin "|" al final: ' . ($prueba->sin_barra_final ? 'MATCHEA (roto)' : 'no matchea (bien)') . PHP_EOL;

echo PHP_EOL . '=== locales con variante vacia (matchean todo) ===' . PHP_EOL;
$roto = 0;
foreach (DB::table('locales')->orderBy('id')->get(['id', 'palabra_clave']) as $l) {
    $clave = (string) $l->palabra_clave;
    $variantes = explode('|', $clave);
    $vacias = count(array_filter($variantes, fn ($v) => trim($v) === ''));
    if ($vacias > 0) {
        $roto++;
        echo '  ' . str_pad($l->id, 30) . var_export($clave, true) . '  (' . $vacias . ' variante(s) vacia(s))' . PHP_EOL;
    }
}
echo 'total con variante vacia: ' . $roto . PHP_EOL;

echo PHP_EOL . '=== dano: refs con ruta RUT_SALEM_PIURA-... ===' . PHP_EOL;
$total = DB::table('referencias')->where('ruta_id', 'like', 'RUT_SALEM_PIURA-%')->count();
echo 'refs con ruta que empieza en SALEM_PIURA: ' . $total . PHP_EOL;

$refs = DB::table('referencias')
    ->where('ruta_id', 'like', 'RUT_SALEM_PIURA-%')
    ->get(['ref', 'titulo_viaje', 'ruta_id']);
$sospechosas = [];
foreach ($refs as $r) {
    $t = mb_strtoupper((string) $r->titulo_viaje);
    // si el titulo no menciona PIURA ni SALEM, es que salio por la variante vacia
    if (strpos($t, 'PIURA') === false && strpos($t, 'SALEM') === false) {
        $sospechosas[] = $r;
    }
}
echo 'de esas, el titulo NO menciona PIURA ni SALEM: ' . count($sospechosas) . PHP_EOL;
foreach (array_slice($sospechosas, 0, 10) as $r) {
    echo '  ' . $r->ref . ' | ' . $r->ruta_id . ' | ' . mb_substr((string) $r->titulo_viaje, 0, 60) . PHP_EOL;
}
