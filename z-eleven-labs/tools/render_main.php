<?php

/**
 * Renderiza la vista `elevenlabs.main` a HTML plano, autenticando como admin.
 *
 * Es el primer paso de `verificar_render_modal.mjs`: el modal de detalle se arma
 * con JavaScript dentro de la vista, asi que la unica forma de probarlo sin
 * navegador es tomar el HTML ya compilado por Blade (con los `@json(...)` ya
 * sustituidos) y ejecutarlo en Node con un DOM falso.
 *
 *   php z-eleven-labs/tools/render_main.php
 *   node z-eleven-labs/tools/verificar_render_modal.mjs
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\ElevenLabsController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// La vista pinta el menu segun permisos: se entra como admin para que salga el
// layout completo, igual que lo vera el usuario.
$admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
    ?: User::first();

if (! $admin) {
    fwrite(STDERR, "No hay usuarios en la base para autenticar.\n");
    exit(1);
}

Auth::login($admin);

$controller = app(ElevenLabsController::class);

$html = $controller->main(Request::create('/eleven-labs', 'GET'))->render();

$destino = __DIR__ . '/_render_main.html';
file_put_contents($destino, $html);

// El modal de Equivalencias se llena por fetch a /eleven-labs/mapeo. Para que el
// verificador en Node pueda ejercitar esas pestañas sin servidor, se guarda la
// misma respuesta aqui y el script la usa como respuesta falsa de fetch.
$mapeo = $controller->mapeo()->getContent();
file_put_contents(__DIR__ . '/_mapeo.json', $mapeo);

fwrite(STDOUT, 'HTML renderizado: ' . strlen($html) . ' bytes -> ' . $destino . PHP_EOL);
fwrite(STDOUT, 'Mapeo serializado: ' . strlen($mapeo) . ' bytes -> ' . __DIR__ . '/_mapeo.json' . PHP_EOL);
fwrite(STDOUT, 'Usuario: ' . $admin->id . ' ' . ($admin->nombres ?? '') . PHP_EOL);
