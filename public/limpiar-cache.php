<?php
/**
 * limpiar-cache.php
 * ---------------------------------------------------------------------------
 * Utilidad para PRODUCCION: limpia la cache de la aplicacion y muestra el
 * estado real de los permisos del administrador (usuario 1).
 *
 * POR QUE EXISTE
 *   Spatie guarda los permisos en cache (24 h por defecto). Cuando los
 *   permisos se cambian con un IMPORT SQL (phpMyAdmin) o con un UPDATE
 *   directo, esa cache NO se invalida: el menu sigue mostrando lo viejo y
 *   el usuario "sigue restringido" aunque la base ya este correcta.
 *
 * COMO USAR
 *   1) Subir este archivo a  public/  del servidor.
 *   2) Abrir en el navegador:
 *        https://tudominio.com/limpiar-cache.php
 *      Si ya estas logged como el administrador, no pide nada mas.
 *      Si no puedes entrar todavia (porque no tienes permisos), usa:
 *        https://tudominio.com/limpiar-cache.php?clave=la-clave-de-abajo
 *   3) Salir y volver a entrar en el panel.
 *   4) BORRAR este archivo del servidor cuando ya no lo necesites.
 *
 * SEGURIDAD
 *   Solo el usuario 1 (administrador) puede ejecutar la limpieza. El acceso
 *   por clave es para casos de emergencia: cambia CLAVE antes de subirlo.
 * ---------------------------------------------------------------------------
 */

const CLAVE = 'cambia-esta-clave-1234';   // <-- cambiala al subirlo
const ADMIN_ID = 1;

// --- Levantar Laravel ---
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require_once $base . '/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// --- Autenticar con la sesion del navegador (si la hay) ---
// Se hace una peticion interna para que corran los middleware de sesion:
// asi el guard 'web' puede resolver al usuario que ya esta logged.
try {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $respuesta = $kernel->handle($peticion);
    $kernel->terminate($peticion, $respuesta);
} catch (Throwable $e) {
    // si falla, se sigue: la pagina mostrara el diagnostico igual
}

$sesionOk = (int) auth()->id() === ADMIN_ID;
$claveOk = isset($_GET['clave']) && hash_equals(CLAVE, (string) $_GET['clave']);
$autorizado = $sesionOk || $claveOk;

// --- Si esta autorizado, limpiar ---
$limpio = false;
$pasos = [];

if ($autorizado) {
    $pasos = [
        'view:clear'         => 'vistas compiladas',
        'cache:clear'        => 'cache de la aplicacion',
        'config:clear'       => 'configuracion',
        'route:clear'        => 'rutas',
        'optimize:clear'     => 'optimizaciones',
    ];

    foreach ($pasos as $cmd => $desc) {
        try {
            Illuminate\Support\Facades\Artisan::call($cmd);
            $pasos[$cmd] = 'OK (' . $desc . ')';
        } catch (Throwable $e) {
            $pasos[$cmd] = 'ERROR: ' . $e->getMessage();
        }
    }

    // El que de verdad importa: la cache de permisos de Spatie
    try {
        Illuminate\Support\Facades\Artisan::call('permission:cache-reset');
        $pasos['permission:cache-reset'] = 'OK (cache de permisos de Spatie)';
    } catch (Throwable $e) {
        $pasos['permission:cache-reset'] = 'sin comando: se olvida directo';
    }

    try {
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $pasos['forgetCachedPermissions'] = 'OK (cache de Spatie olvidada en memoria)';
    } catch (Throwable $e) {
        $pasos['forgetCachedPermissions'] = 'ERROR: ' . $e->getMessage();
    }

    $limpio = true;
}

// --- Diagnostico ---
$db = config('database.connections.' . config('database.default') . '.database');
$info = [];
$permisosEfectivos = 0;
$roles = [];
$directos = [];

try {
    $u = App\Models\User::find(ADMIN_ID);
    if ($u) {
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $u = App\Models\User::find(ADMIN_ID);
        $roles = $u->getRoleNames()->all();
        $permisosEfectivos = $u->getAllPermissions()->count();
    }

    $directos = Illuminate\Support\Facades\DB::table('model_has_permissions')
        ->where('model_id', ADMIN_ID)
        ->get()
        ->map(function ($f) {
            $p = Illuminate\Support\Facades\DB::table('permissions')->where('id', $f->permission_id)->first();
            return [
                'permiso' => $p->name ?? ('#' . $f->permission_id),
                'guard'   => $p->guard_name ?? '?',
                'model_type' => $f->model_type,
            ];
        })
        ->all();

    $rolesEnBd = Illuminate\Support\Facades\DB::table('model_has_roles')
        ->where('model_id', ADMIN_ID)
        ->get()
        ->map(function ($f) {
            $r = Illuminate\Support\Facades\DB::table('roles')->where('id', $f->role_id)->first();
            return [
                'rol' => $r->name ?? ('#' . $f->role_id),
                'guard' => $r->guard_name ?? '?',
                'model_type' => $f->model_type,
            ];
        })
        ->all();
} catch (Throwable $e) {
    $info[] = 'Error al leer los permisos: ' . $e->getMessage();
}

$totalPermisos = 0;
try {
    $totalPermisos = Illuminate\Support\Facades\DB::table('permissions')->count();
} catch (Throwable $e) {
}

// -------------------------------------------------------------------
// SALIDA
// -------------------------------------------------------------------
$ok = $autorizado && $permisosEfectivos >= $totalPermisos && $totalPermisos > 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Limpiar cache y estado de permisos</title>
    <style>
        body { font-family: Consolas, monospace; background: #12141c; color: #e6e6e6; margin: 0; padding: 28px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 26px 0 8px; color: #7dd3fc; border-bottom: 1px solid #2a2f3d; padding-bottom: 5px; }
        .sub { color: #94a3b8; font-size: 12px; margin-bottom: 18px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 10px; }
        th, td { border: 1px solid #2a2f3d; padding: 6px 10px; text-align: left; }
        th { background: #1b1f2b; color: #cbd5e1; }
        .ok { color: #4ade80; } .bad { color: #f87171; } .warn { color: #fbbf24; }
        .box { padding: 12px 16px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
        .box-ok { background: #14301f; border: 1px solid #1f6b3d; }
        .box-bad { background: #331717; border: 1px solid #7f2a2a; }
        .box-warn { background: #332a10; border: 1px solid #7f5a1f; }
        code { background: #1b1f2b; padding: 2px 6px; border-radius: 3px; color: #fbbf24; }
    </style>
</head>
<body>

<h1>Limpiar cache + estado de permisos</h1>
<div class="sub">Base de datos en uso: <code><?= htmlspecialchars((string) $db) ?></code></div>

<?php if ($limpio): ?>
    <div class="box box-ok">
        <strong>CACHE LIMPIADA.</strong> Cierra sesion y vuelve a entrar en el panel.
    </div>
    <h2>Pasos ejecutados</h2>
    <table>
        <tr><th>Comando</th><th>Resultado</th></tr>
        <?php foreach ($pasos as $cmd => $res): ?>
            <tr>
                <td><code><?= htmlspecialchars($cmd) ?></code></td>
                <td class="<?= str_starts_with($res, 'OK') ? 'ok' : 'bad' ?>"><?= htmlspecialchars($res) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <div class="box box-bad">
        <strong>NO SE EJECUTO LA LIMPIEZA.</strong>
        Inicia sesion como el administrador (usuario <?= ADMIN_ID ?>) y vuelve a abrir esta pagina,
        o usa <code>?clave=<?= htmlspecialchars(CLAVE) ?></code>.
    </div>
<?php endif; ?>

<h2>Estado del administrador (usuario <?= ADMIN_ID ?>)</h2>
<table>
    <tr><th>Comprobacion</th><th>Valor</th><th>Estado</th></tr>
    <tr>
        <td>Permisos en la tabla <code>permissions</code></td>
        <td><?= (int) $totalPermisos ?></td>
        <td><?= $totalPermisos >= 22 ? '<span class="ok">OK</span>' : '<span class="bad">FALTAN</span>' ?></td>
    </tr>
    <tr>
        <td>Permisos EFECTIVOS del usuario (los que lee Spatie)</td>
        <td><?= (int) $permisosEfectivos ?></td>
        <td><?= $permisosEfectivos >= $totalPermisos && $totalPermisos > 0 ? '<span class="ok">OK - ACCESO TOTAL</span>' : '<span class="bad">RESTRINGIDO</span>' ?></td>
    </tr>
    <tr>
        <td>Roles del usuario</td>
        <td><?= $roles ? htmlspecialchars(implode(', ', $roles)) : '(ninguno)' ?></td>
        <td><?= in_array('admin', $roles, true) ? '<span class="ok">OK</span>' : '<span class="warn">SIN ROL ADMIN</span>' ?></td>
    </tr>
</table>

<?php if (!empty($rolesEnBd)): ?>
    <h2>Roles en la base (tabla model_has_roles)</h2>
    <table>
        <tr><th>Rol</th><th>Guard</th><th>model_type</th><th>Estado</th></tr>
        <?php foreach ($rolesEnBd as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string) $r['rol']) ?></td>
                <td><?= htmlspecialchars((string) $r['guard']) ?></td>
                <td><?= htmlspecialchars((string) $r['model_type']) ?></td>
                <td><?= $r['model_type'] === 'App\Models\User' ? '<span class="ok">OK</span>' : '<span class="bad">MAL: debe ser App\Models\User</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php if (!empty($directos)): ?>
    <h2>Permisos directos en la base (tabla model_has_permissions)</h2>
    <table>
        <tr><th>Permiso</th><th>Guard</th><th>model_type</th><th>Estado</th></tr>
        <?php foreach ($directos as $d): ?>
            <tr>
                <td><?= htmlspecialchars((string) $d['permiso']) ?></td>
                <td><?= htmlspecialchars((string) $d['guard']) ?></td>
                <td><?= htmlspecialchars((string) $d['model_type']) ?></td>
                <td><?= ($d['model_type'] === 'App\Models\User' && $d['guard'] === 'web') ? '<span class="ok">OK</span>' : '<span class="bad">Spatie no lo lee</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <div class="box box-warn">
        El usuario <?= ADMIN_ID ?> <strong>no tiene permisos directos</strong> en la base.
        Si el rol <code>admin</code> tampoco los tiene, quedara restringido.
    </div>
<?php endif; ?>

<?php if ($ok): ?>
    <div class="box box-ok" style="margin-top:20px;">
        <strong>TODO CORRECTO.</strong> Cierra sesion, entra de nuevo y el menu debe aparecer completo.
        Si aun sale recortado, el problema ya no es la base: revisa que la app apunte a esta misma base.
    </div>
<?php else: ?>
    <div class="box box-warn" style="margin-top:20px;">
        <strong>Aun hay diferencias.</strong> Si la tabla <code>permissions</code> esta vacia o falta el rol
        <code>admin</code>, ejecuta <code>sql/fix_usuario1_operaciones.sql</code> en la base.
    </div>
<?php endif; ?>

</body>
</html>