<?php
// 1) Que dice el analizador local con los titulos del reporte.
// 2) Hay alguna palabra clave que matchee CUALQUIER cosa (variante vacia)?
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Database\DBUbicaciones;
use Illuminate\Support\Facades\DB;

$titulos = [
    'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 05/08/2026',
    'ENVIO DE PT PLANTA PUCALLPA(CORONADO) - CEDI TINGO MARIA 12/05/2026',
    'DESPACHO PLANTA CHICLAYO - SPATRUJILLO (UTCON GPS CARRETA 24 PH) CARGAR 06-05-26',
    'ENVIO PT MAQUILA AGUA - CODISAL CUSCO / CARGA 04/08/2026',
    'DESPACHO DE INSUMOS-HUACHIPA -PTA AYACUCHO - CARGA 22/04/2026',
];

$analizador = DBUbicaciones::analizador();

echo '=== que dice el analizador LOCAL (con la base local) ===' . PHP_EOL;
foreach ($titulos as $t) {
    $r = $analizador->analizar($t);
    echo mb_substr($t, 0, 62) . PHP_EOL;
    echo '   origen=' . var_export($r['origen'], true) . ' -> ' . ($r['origen_resuelto']['id'] ?? 'NULL') . PHP_EOL;
    echo '   destino=' . var_export($r['destino'], true) . ' -> ' . ($r['destino_resuelto']['id'] ?? 'NULL') . PHP_EOL;
    echo '   ruta predicha: ' . var_export($r['ruta_id'], true) . PHP_EOL;
}

echo PHP_EOL . '=== palabras clave que matchean CUALQUIER texto (variante vacia) ===' . PHP_EOL;
$textoPrueba = 'ZZZQXWV NO DEBE MATCHEAR NADA';
$sospechosas = 0;
foreach (DB::table('locales')->orderBy('id')->get(['id', 'palabra_clave']) as $l) {
    $clave = trim((string) $l->palabra_clave);
    if ($clave === '') {
        echo '  (vacia) ' . $l->id . PHP_EOL;
        $sospechosas++;
        continue;
    }
    if (@preg_match('/\b(' . $clave . ')\b/iu', $textoPrueba) === 1) {
        $sospechosas++;
        echo '  MATCHEA TODO: ' . $l->id . ' => ' . var_export($l->palabra_clave, true) . PHP_EOL;
    }
}
echo 'locales sospechosos: ' . $sospechosas . PHP_EOL;

echo PHP_EOL . '=== LOC_SALEM_PIURA local ===' . PHP_EOL;
$salem = DB::table('locales')->where('id', 'LOC_SALEM_PIURA')->first(['id', 'nombre', 'palabra_clave', 'activo']);
echo $salem ? var_export((array) $salem, true) . PHP_EOL : 'no existe en la base local' . PHP_EOL;
