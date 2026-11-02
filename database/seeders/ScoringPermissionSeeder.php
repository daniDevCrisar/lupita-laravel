<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ScoringPermissionSeeder extends Seeder
{
    /**
     * Permisos del modulo Scoring.
     *
     * Va separado de RoleAndPermissionSeeder porque ese usa Permission::create() y
     * re-sembrarlo choca contra el indice unico de spatie; aqui todo es
     * firstOrCreate, asi que se puede correr las veces que haga falta.
     *
     *   php artisan db:seed --class=ScoringPermissionSeeder
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $nombre_rol = ['ver scoring', 'editar palabras clave'];

        $creados = [];
        foreach ($nombre_rol as $nombre) {
            $creados[] = Permission::firstOrCreate([
                'name' => $nombre,
                'guard_name' => 'web',
            ]);
        }

        // admin y editor pueden editar pesos; invitado no recibe nada nuevo
        foreach (['admin', 'editor'] as $nombre) {
            $rol = Role::where('name', $nombre)->first();

            if (!$rol) {
                $this->command?->warn('El rol ' . $nombre . ' no existe, se omite.');
                continue;
            }

            foreach ($creados as $permiso) {
                if (!$rol->hasPermissionTo($permiso)) {
                    $rol->givePermissionTo($permiso);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
