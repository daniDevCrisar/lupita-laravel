<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El layout tiene que poder dibujarse SIN usuario.
 *
 * Producción se cayó con `Call to a member function canAny() on null` en
 * /lupita/referencias: el middleware `permission:` lanza UnauthorizedException y
 * Laravel dibuja `errors/403`, que hereda `layouts.app`… y el layout llamaba a
 * `auth()->user()->canAny(...)` (y a `auth()->user()->name`) sin preguntar si
 * había sesión. En local nunca se veía porque uno está logueado.
 */
class LayoutSinSesionTest extends TestCase
{
    /**
     * La página de error 403 se dibuja sin usuario, tal como la pinta el handler
     * cuando el middleware `permission:` corta la request.
     */
    public function test_la_pagina_403_se_dibuja_sin_sesion(): void
    {
        $html = view('errors.403', [
            'exception' => new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'No tienes permisos para ver esta pantalla.'),
        ])->render();

        $this->assertStringContainsString('Acceso restringido', $html);
        // sin sesión no hay menú de usuario: se ofrece entrar
        $this->assertStringNotContainsString('Cerrar sesión', $html);
        $this->assertStringContainsString('Entrar', $html);
    }

    /** Una ruta con `permission:` sin sesión responde 403, no 500. */
    public function test_una_ruta_con_permiso_sin_sesion_no_revienta(): void
    {
        $respuesta = $this->get('/lupita/referencias');

        $this->assertNotSame(500, $respuesta->getStatusCode(),
            'la página de error se está cayendo: el layout no soporta un usuario null');

        $this->assertContains($respuesta->getStatusCode(), [302, 403],
            'sin sesión se espera redirección al login o el 403');
    }

    /** Lo mismo para otras pantallas con permiso (el layout es común a todas). */
    public function test_otras_pantallas_con_permiso_sin_sesion_tampoco_revientan(): void
    {
        foreach (['/lupita/referencias', '/lupita/aciertos', '/lupita/conductores'] as $url) {
            $respuesta = $this->get($url);

            $this->assertNotSame(500, $respuesta->getStatusCode(), $url . ' se cayó sin sesión');
        }
    }
}
