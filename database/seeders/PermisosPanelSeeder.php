<?php

namespace Database\Seeders;

use App\Database\DBPermisos;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del panel web de Lupita.
 *
 * El panel tiene una ruta por modulo y cada modulo tiene su permiso de lectura
 * (y de escritura cuando aplica). Este seeder es IDEMPOTENTE: se puede correr
 * las veces que sea sin duplicar permisos ni roles, y deja la matriz de acceso
 * siempre igual.
 *
 * Matriz de roles:
 *  - admin:    todos los permisos (incluye los sensibles).
 *  - editor:   todo menos los sensibles (eliminar lote, fusionar conductores y
 *              administrar usuarios).
 *  - invitado: igual que editor (acceso de lectura y operacion, sin acciones
 *              destructivas ni administracion de usuarios).
 *
 * Los API (`/api/*`) son publicos y NO tienen permiso: es una decision del
 * proyecto y no se tocan desde aqui.
 */
class PermisosPanelSeeder extends Seeder
{
    /**
     * Permisos del panel agrupados por tipo de accion.
     *
     * El catalogo vive en DBPermisos (misma fuente que usa la pantalla de
     * permisos por rol), asi que aqui ya no se duplica la lista.
     */
    private const USUARIOS_INVITADO = [2, 3];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permisos (firstOrCreate: idempotente)
        $catalogo = DBPermisos::todos();

        foreach ($catalogo as $nombre) {
            Permission::firstOrCreate(['name' => $nombre]);
        }

        // 1b. Permisos que ya NO aplican (por ejemplo los de los API, que son
        //     publicos): se desasignan y se borran, para que la matriz de la
        //     pantalla de permisos no muestre permisos que ya no controlan nada.
        $obsoletos = Permission::whereNotIn('name', $catalogo)->get();

        foreach ($obsoletos as $permiso) {
            $permiso->roles()->detach();
            $permiso->users()->detach();
            $permiso->delete();
        }

        // 2. Roles (firstOrCreate: idempotente)
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $editor = Role::firstOrCreate(['name' => 'editor']);
        $invitado = Role::firstOrCreate(['name' => 'invitado']);

        // 3. Permisos por rol (syncPermissions: deja el rol como debe quedar)
        $admin->syncPermissions(Permission::all());

        $permisos_sin_sensibles = Permission::all()
            ->reject(fn($p) => in_array($p->name, DBPermisos::sensibles(), true));

        foreach ([$editor, $invitado] as $rol) {
            $rol->syncPermissions($permisos_sin_sensibles);
        }

        // 4. Usuarios no administradores con el rol `invitado`.
        //    Se usa assignRole (aditivo) para no quitarle el rol `editor` al
        //    usuario 2 si ya lo tiene.
        foreach (self::USUARIOS_INVITADO as $id) {
            $usuario = User::find($id);
            if ($usuario) {
                $usuario->assignRole($invitado);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Permisos del panel actualizados: ' . Permission::count() . ' permisos, 3 roles.');
    }
}
