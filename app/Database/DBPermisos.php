<?php

namespace App\Database;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DBPermisos
 *
 * Capa de datos de la pantalla de permisos por rol (`/lupita/permisos`):
 * catalogo de permisos del panel y edicion de los permisos de cada rol.
 *
 * El catalogo es la unica fuente de verdad: lo usan tanto el seeder
 * (`PermisosPanelSeeder`, que crea los permisos y arma la matriz inicial)
 * como la pantalla que los edita.
 */
class DBPermisos
{
    /**
     * Permisos de solo lectura: ver un modulo del panel.
     *
     * NOTA: `ver api` abre el grupo API del menu (Actualizar Refs, Docs API
     * Refs y Probar API Tiempos). Los endpoints JSON de /api/* NO pasan por
     * aqui: son publicos.
     */
    public const LECTURA = [
        'ver llamadas',
        'ver conductores',
        'ver transportistas',
        'ver referencias',
        'ver tiempos',
        'ver reportes',
        'ver scoring',
        'ver lote',
        'ver lista de lotes',
        'ver api',
    ];

    /**
     * Permisos de escritura: accion sobre un modulo.
     */
    public const ESCRITURA = [
        'editar palabras clave',
        'etiquetar llamada',
        'importar excel',
        'importar json',
        'importar vapi',
        'compilar promedios',
        'gestionar duplicados conductores',
        'gestionar casos conductores',
    ];

    /**
     * Permisos sensibles: acciones destructivas o de administracion.
     */
    public const SENSIBLES = [
        'eliminar lote',
        'fusionar conductores',
        'administrar usuarios',
    ];

    /**
     * Permiso heredado que ya existia en la base (no se usa en las rutas,
     * pero se conserva para no romper el historial).
     */
    public const HEREDADOS = [
        'api de obtencion efletexia',
    ];

    /**
     * Permiso que abre la administracion de usuarios (y de permisos).
     */
    public const ADMINISTRAR_USUARIOS = 'administrar usuarios';

    /**
     * Catalogo agrupado por tipo de accion (clave = grupo, valor = permisos).
     */
    public static function grupos(): array
    {
        return [
            'Lectura' => self::LECTURA,
            'Escritura' => self::ESCRITURA,
            'Sensibles' => self::SENSIBLES,
            'Heredados' => self::HEREDADOS,
        ];
    }

    /**
     * Todos los permisos del catalogo, sin repetir.
     */
    public static function todos(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::grupos()))));
    }

    /**
     * Los permisos sensibles (los que solo deberia tener el rol admin).
     */
    public static function sensibles(): array
    {
        return self::SENSIBLES;
    }

    /**
     * Roles del sistema con los permisos que tienen ahora.
     */
    public static function roles(): array
    {
        $salida = [];

        foreach (Role::with('permissions')->orderBy('name')->get() as $rol) {
            $salida[] = [
                'id'         => (int) $rol->id,
                'name'       => (string) $rol->name,
                'permisos'   => $rol->permissions->pluck('name')->toArray(),
            ];
        }

        return $salida;
    }

    /**
     * Guarda los permisos de un rol y refresca la cache de Spatie para que el
     * cambio surta efecto de inmediato.
     *
     * @return int cantidad de permisos que quedaron en el rol
     */
    public static function guardar_rol(string $rol, array $permisos): int
    {
        $r = Role::where('name', $rol)->first();

        if (!$r) {
            return 0;
        }

        // solo se guardan permisos que existen en la base
        $validos = Permission::whereIn('name', $permisos)->pluck('name')->toArray();

        $r->syncPermissions($validos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return count($validos);
    }
}
