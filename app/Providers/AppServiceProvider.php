<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('mensajes-llamada-textbox', \App\Livewire\MensajesLlamadaTextbox::class);
        Paginator::useBootstrapFive();

        // Los componentes Livewire se actualizan por POST /livewire/update, que
        // no pasa por las rutas del panel: sin esto los middleware de permiso
        // no se volverian a ejecutar en las peticiones AJAX y un usuario sin
        // permiso podria seguir usando la pantalla.
        Livewire::addPersistentMiddleware([
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
            \Spatie\Permission\Middleware\RoleMiddleware::class,
            \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    }
}
