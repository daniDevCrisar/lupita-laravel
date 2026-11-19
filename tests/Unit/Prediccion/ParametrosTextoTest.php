<?php

namespace Tests\Unit\Prediccion;

use App\Prediccion\Parametros;
use Tests\TestCase;

/**
 * Cómo se leen los valores de configuración en la pantalla de parámetros.
 *
 * La pantalla tiene tres calibraciones que escriben claves sin pasar por un
 * formulario (cruce, ventana y sesgo), así que el valor en uso se muestra con
 * estas funciones. Lo que fijan los tests:
 *  - los minutos se leen en `h:mm` (una banda de 1.349 min no dice nada);
 *  - los nombres de las claves NO son parejos: `ventana_adelanto_cadena` está en
 *    minutos y no termina en `_min`, y el sesgo termina en `_corto|_medio|_largo`;
 *  - un interruptor se lee sí/no y un modo con su nombre, no como número.
 *
 * Es puro: se le pasan objetos armados a mano, sin tocar la base.
 */
class ParametrosTextoTest extends TestCase
{
    public function test_los_minutos_se_leen_en_horas_y_minutos(): void
    {
        $this->assertSame('1.349 min (22:29)', Parametros::texto('ventana_adelanto_min', 1349));
        $this->assertSame('45 min', Parametros::texto('muerto_defecto_min', 45));
        $this->assertSame('0 min', Parametros::texto('muerto_defecto_min', 0));

        // la ventana de cadena NO termina en _min y también son minutos
        $this->assertSame('2.086 min (34:46)', Parametros::texto('ventana_adelanto_cadena', 2086));
        $this->assertSame('2.086 min (34:46)', Parametros::texto('ventana_atraso_cadena', 2086));

        // el sesgo por clase termina en _corto/_medio/_largo: signo y h:mm
        $this->assertSame('−19 min', Parametros::texto('sesgo_clase_parcial_corto', -19));
        $this->assertSame('−2.957 min (−49:17)', Parametros::texto('sesgo_clase_cadena_largo', -2957));
        $this->assertSame('251 min (4:11)', Parametros::texto('sesgo_clase_parcial_largo', 251));
    }

    public function test_el_corto_es_para_tablas_angostas(): void
    {
        $this->assertSame('22:29', Parametros::corto('ventana_adelanto_min', 1349));
        $this->assertSame('−19′', Parametros::corto('sesgo_clase_parcial_corto', -19));
        $this->assertSame('−49:17', Parametros::corto('sesgo_clase_cadena_largo', -2957));
        $this->assertSame('4:11', Parametros::corto('sesgo_clase_parcial_largo', 251));

        // lo que no es tiempo se lee igual que en el formato largo
        $this->assertSame('3 · compilado por ruta', Parametros::corto('dia_modo', 3));
        $this->assertSame('sí', Parametros::corto('ventana_residuos', 1));
    }

    public function test_los_interruptores_los_modos_y_los_porcentajes(): void
    {
        $this->assertSame('sí', Parametros::texto('ventana_residuos', 1));
        $this->assertSame('no', Parametros::texto('sesgo_clase_activo', 0));
        $this->assertSame('no', Parametros::texto('dia_activo', 0));

        // cada modo dice qué significa el número elegido
        $this->assertSame('3 · compilado por ruta', Parametros::texto('dia_modo', 3));
        $this->assertSame('0 · apagado', Parametros::texto('dia_modo', 0));
        $this->assertSame('1 · proporcional encogida', Parametros::texto('curva_modo', 1));
        $this->assertSame('2 · apagada', Parametros::texto('curva_modo', 2));

        // porcentajes sin decimales de más
        $this->assertSame('70 %', Parametros::texto('ventana_cobertura', 0.7));
        $this->assertSame('75,5 %', Parametros::texto('ventana_cobertura', 0.755));
        $this->assertSame('±30 %', Parametros::texto('spread_cadena', 0.3));

        // y un número suelto no inventa unidades (salvo que la clave esté en minutos)
        $this->assertSame('10', Parametros::texto('pares_recientes_max', 10));
        $this->assertSame('2.000 min (33:20)', Parametros::texto('max_desplazamiento_min', 2000));
    }

    public function test_en_uso_marca_lo_que_se_aparto_de_fabrica_y_saltea_lo_que_no_existe(): void
    {
        $valores = (object) ['min_pares_ruta' => 6, 'muerto_defecto_min' => 60, 'ventana_cobertura' => 0.7];
        $fabrica = (object) ['min_pares_ruta' => 3, 'muerto_defecto_min' => 60, 'ventana_cobertura' => 0.7];

        $filas = Parametros::en_uso(
            ['min_pares_ruta', 'muerto_defecto_min', 'ventana_cobertura', 'clave_inventada'],
            $valores, $fabrica);

        $this->assertCount(3, $filas, 'la clave que el motor no conoce se saltea');

        $this->assertSame('min_pares_ruta', $filas[0]['clave']);
        $this->assertSame(6, $filas[0]['valor']);
        $this->assertSame('6', $filas[0]['texto']);
        $this->assertSame('3', $filas[0]['fabrica_texto']);
        $this->assertTrue($filas[0]['cambiado']);

        $this->assertFalse($filas[1]['cambiado'], 'igual a fábrica no se marca');
        $this->assertSame('60 min (1:00)', $filas[1]['texto']);

        $this->assertSame('70 %', $filas[2]['texto']);
        $this->assertFalse($filas[2]['cambiado']);
    }

    public function test_sin_valor_no_inventa_cero(): void
    {
        $this->assertSame('—', Parametros::texto('ventana_adelanto_min', null));
        $this->assertSame('—', Parametros::corto('sesgo_clase_cadena_largo', null));
    }
}
