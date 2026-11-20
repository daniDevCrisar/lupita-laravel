<?php

namespace Tests\Feature\Ubicaciones;

use Tests\TestCase;

/**
 * Guardia de sintaxis del JavaScript que vive dentro de las vistas Blade.
 *
 * Existe porque dos bugs llegaron a produccion desde aca: un parentesis mal cerrado
 * y una concatenacion con "." estilo PHP (`'a' . 'b'`) rompian TODO el <script> del
 * formulario de local, y con el la cascada de ubigeo quedaba en blanco sin error
 * visible. Los tests de PHP no ven el JS; este lo pasa por `node --check`.
 *
 * Si node no esta instalado, se saltea: no es una dependencia de la aplicacion.
 */
class VistasJsTest extends TestCase
{
    private const VISTAS = [
        'resources/views/lupita/locales/form.blade.php',
        'resources/views/lupita/locales/index.blade.php',
        'resources/views/lupita/ubicaciones/maps.blade.php',
        'resources/views/lupita/ubicaciones/titulos.blade.php',
        'resources/views/lupita/ubicaciones/palabras.blade.php',
    ];

    public function test_el_javascript_de_las_vistas_no_tiene_errores_de_sintaxis(): void
    {
        $node = $this->node();

        $errores = [];
        $revisados = 0;

        foreach (self::VISTAS as $vista) {
            $ruta = base_path($vista);
            if (!is_file($ruta)) {
                continue;
            }

            $html = (string) file_get_contents($ruta);
            if (preg_match_all('#<script>(.*?)</script>#is', $html, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $i => $js) {
                $revisados++;

                $tmp = tempnam(sys_get_temp_dir(), 'js') . '.js';
                file_put_contents($tmp, $this->sinBlade($js));

                exec('"' . $node . '" --check ' . escapeshellarg($tmp) . ' 2>&1', $salida, $codigo);
                @unlink($tmp);

                if ($codigo !== 0) {
                    $errores[] = $vista . ' (script #' . $i . "):\n" . implode("\n", array_slice($salida, 0, 6));
                }

                $salida = [];
            }
        }

        $this->assertGreaterThan(0, $revisados, 'no se reviso ningun script');
        $this->assertSame([], $errores, "JavaScript invalido en las vistas:\n" . implode("\n\n", $errores));
    }

    /** Reemplaza las expresiones y directivas Blade por algo valido en JS. */
    private function sinBlade(string $js): string
    {
        $js = (string) preg_replace('/\{!!.*?!!\}/s', '1', $js);
        $js = (string) preg_replace('/\{\{.*?\}\}/s', '1', $js);
        $js = (string) preg_replace('/@\w+\((?:[^()]|\([^()]*\))*\)/s', '[]', $js);

        return (string) preg_replace('/^\s*@\w+\s*$/m', '', $js);
    }

    private function node(): string
    {
        exec('node --version 2>&1', $salida, $codigo);

        if ($codigo !== 0) {
            $this->markTestSkipped('node no esta instalado: no se puede validar el JS de las vistas');
        }

        return 'node';   // resuelve por PATH, igual en Windows que en Linux
    }
}
