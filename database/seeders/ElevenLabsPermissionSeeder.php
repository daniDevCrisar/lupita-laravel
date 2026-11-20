<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ElevenLabsPermissionSeeder extends Seeder
{
    /**
     * Permisos del modulo Eleven Labs.
     *
     * Va separado de RoleAndPermissionSeeder por el mismo motivo que
     * ScoringPermissionSeeder: ese usa Permission::create() y re-sembrarlo choca
     * contra el indice unico de spatie; aqui todo es firstOrCreate, asi que se
     * puede correr las veces que haga falta.
     *
     *   php artisan db:seed --class=ElevenLabsPermissionSeeder
     *
     * Los tres permisos se separan a proposito:
     *  - `ver eleven labs`          ver listado y detalle de llamadas en la API
     *  - `escuchar audio eleven labs`  descargar el MP3 por el endpoint propio
     *  - `importar eleven labs`     crear lotes desde la API (escribe en llamadas)
     * Escuchar audio se deja aparte porque el audio sale del workspace y puede
     * interesar restringirlo aun cuando el listado se vea.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $nombres = [
            'ver eleven labs',
            'escuchar audio eleven labs',
            'importar eleven labs',
        ];

        $creados = [];
        foreach ($nombres as $nombre) {
            $creados[] = Permission::firstOrCreate([
                'name' => $nombre,
                'guard_name' => 'web',
            ]);
        }

        foreach (['admin', 'editor'] as $nombre) {
            $rol = Role::where('name', $nombre)->first();

            if (! $rol) {
                $this->command?->warn('El rol ' . $nombre . ' no existe, se omite.');

                continue;
            }

            foreach ($creados as $permiso) {
                if (! $rol->hasPermissionTo($permiso)) {
                    $rol->givePermissionTo($permiso);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
