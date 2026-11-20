<?php

namespace App\Providers;

use App\ElevenLabs\AgentesElevenLabs;
use App\ElevenLabs\ElevenLabsClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El cliente de Eleven Labs se arma desde config/services.php: su
        // constructor pide la key y la URL base, que el contenedor no puede
        // adivinar (parámetros primitivos). Singleton porque no guarda estado.
        $this->app->singleton(ElevenLabsClient::class, fn () => ElevenLabsClient::desdeConfig());

        $this->app->bind(
            AgentesElevenLabs::class,
            fn ($app) => new AgentesElevenLabs($app->make(ElevenLabsClient::class))
        );
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
