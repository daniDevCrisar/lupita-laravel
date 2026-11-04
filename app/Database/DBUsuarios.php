<?php

namespace App\Database;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DBUsuarios
 *
 * Capa de datos de la pantalla de administracion de usuarios
 * (`/lupita/usuarios`): listar, crear, modificar y desactivar.
 *
 * La tabla `users` tiene: id, name, nombres, password, activo, email y las
 * marcas de tiempo. OJO:
 *  - `name` es el campo con el que se inicia sesion (LoginController::username()).
 *  - `nombres` es el nombre completo que muestra el sistema.
 *  - `activo` es lo que impide entrar a un usuario desactivado.
 *
 * Los roles son los de Spatie (admin / editor / invitado): al asignarlos se
 * refresca la cache de permisos para que el cambio surta efecto de inmediato.
 */
class DBUsuarios
{
    /**
     * Unico usuario que puede crear, modificar y desactivar usuarios por ahora.
     * (Luego se puede cambiar por un permiso de Spatie sin tocar las vistas.)
     */
    public const ADMIN_ID = 1;

    /**
     * Listado de usuarios con sus roles y permisos efectivos.
     */
    public static function listar(): array
    {
        $filas = [];
        foreach (User::orderBy('id')->get() as $u) {
            $filas[] = self::fila($u);
        }

        return $filas;
    }

    /**
     * Un usuario por id (null si no existe).
     */
    public static function buscar(int $id): ?array
    {
        $u = User::find($id);

        return $u ? self::fila($u) : null;
    }

    /**
     * Roles disponibles (los de Spatie).
     */
    public static function roles_existentes(): array
    {
        return Role::orderBy('name')->pluck('name')->toArray();
    }

    /**
     * Roles disponibles con los permisos que trae cada uno.
     */
    public static function roles_con_permisos(): array
    {
        $salida = [];
        foreach (Role::with('permissions')->orderBy('name')->get() as $r) {
            $salida[$r->name] = $r->permissions->pluck('name')->toArray();
        }

        return $salida;
    }

    /**
     * Crea un usuario (activo) y le asigna los roles indicados.
     *
     * @return int id del usuario creado
     */
    public static function crear(array $datos, array $roles): int
    {
        $u = new User();
        $u->name = $datos['name'];
        $u->nombres = $datos['nombres'];
        $u->email = $datos['email'] ?: null;
        $u->password = $datos['password'];   // el cast 'hashed' del modelo lo encripta
        $u->activo = 1;
        $u->save();

        self::asignar_roles($u, $roles);

        return (int) $u->id;
    }

    /**
     * Actualiza los datos del usuario. La contrasena solo cambia si viene informed.
     */
    public static function actualizar(int $id, array $datos, array $roles): bool
    {
        $u = User::find($id);
        if (!$u) {
            return false;
        }

        $u->name = $datos['name'];
        $u->nombres = $datos['nombres'];
        $u->email = $datos['email'] ?: null;
        if (!empty($datos['password'])) {
            $u->password = $datos['password'];
        }
        $u->activo = (int) $datos['activo'];
        $u->save();

        self::asignar_roles($u, $roles);

        return true;
    }

    /**
     * Alterna el estado activo/inactivo del usuario.
     *
     * @return array|null ['id'=>int,'activo'=>int] o null si no existe
     */
    public static function alternar_activo(int $id): ?array
    {
        $u = User::find($id);
        if (!$u) {
            return null;
        }

        $u->activo = ((int) $u->activo === 1) ? 0 : 1;
        $u->save();

        return ['id' => (int) $u->id, 'activo' => (int) $u->activo];
    }

    /**
     * Asigna los roles y refresca la cache de permisos de Spatie.
     */
    private static function asignar_roles(User $u, array $roles): void
    {
        $u->syncRoles($roles);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Normaliza una fila de usuario a un arreglo plano para la vista.
     */
    private static function fila(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'nombres' => (string) $u->nombres,
            'email' => (string) $u->email,
            'activo' => (int) $u->activo,
            'roles' => $u->getRoleNames()->toArray(),
            'permisos' => $u->getAllPermissions()->pluck('name')->toArray(),
            'created_at' => (string) $u->created_at,
            'updated_at' => (string) $u->updated_at,
        ];
    }
}
