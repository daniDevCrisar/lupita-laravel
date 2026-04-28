<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Es buena práctica resetear la caché de permisos cada vez que se ejecuta el seeder
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Crear los permisos (agrúpalos por módulo para mejor organización)
        Permission::create(['name' => 'eliminar lote']);
        Permission::create(['name' => 'etiquetar llamada']);
        Permission::create(['name' => 'importar excel']);
        Permission::create(['name' => 'ver lote']);
        Permission::create(['name' => 'ver lista de lotes']);
        Permission::create(['name' => 'api de obtencion efletexia']);

        // 2. Crear los roles y asignarles permisos
        $todos_los_permisos=Permission::all(); //usarlo para excluir permisos
        // Rol Administrador: tiene todos los permisos
        $roleAdmin = Role::create(['name' => 'admin']);
        $roleAdmin->givePermissionTo(Permission::all()); //

        // Rol Editor: solo puede ver y editar
        $editor_excluir=['eliminar lote'];
        $editor_permisos= $todos_los_permisos->filter(function($permiso) use ($editor_excluir) {
            return !in_array($permiso->name, $editor_excluir);
        });

        $roleEditor = Role::create(['name' => 'editor']);
        $roleEditor->givePermissionTo($editor_permisos);

        $roleBasico = Role::create(['name' => 'invitado']);

        // Rol Visualizador: solo puede ver
//        $roleViewer = Role::create(['name' => 'visualizador']);
//        $roleViewer->givePermissionTo('ver llamadas');
    }
}
