<?php

namespace Tests;

use App\Database\DBPrediccion;
use App\Prediccion\Geoapify;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * La suite NO habla con Geoapify: las pruebas que lo necesitan lo activan con
     * `config(['geoapify.key' => 'test-key'])` y `Http::fake()`.
     *
     * Sin esto, cualquier test que prediga contra la base real dispararía
     * llamadas HTTP de verdad (lentas y dependientes de la red).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['geoapify.key' => '']);
        // el contador de `geoapify_uso` es de producción: la suite no lo ensucia
        config(['geoapify.contar' => false]);
        Geoapify::olvidar();
        DBPrediccion::olvidar();
    }
}
