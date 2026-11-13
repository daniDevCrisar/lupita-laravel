<?php

namespace App\Prediccion;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Parámetros del motor: los de `config/prediccion.php` pisados por lo que el
 * usuario haya guardado en `prediccion_config`.
 *
 * Misma convención que `scoring_config` (ver
 * `database/migrations/2026_11_02_160000_create_scoring_config_table.php`): la
 * tabla puede no existir en el servidor, y en ese caso se usan los defaults sin
 * lanzar excepción, porque el cálculo nunca debe romperse por configuración.
 */
final class Parametros
{
    /** Claves que se castean a float (el resto a int). */
    private const FLOTANTES = ['umbral_inactividad'];

    private static ?stdClass $cache = null;

    /** Valores de `config/prediccion.php`, ya tipados. */
    public static function por_defecto(): stdClass
    {
        return self::tipar((array) config('prediccion', []));
    }

    /** Defaults + overrides de la base, memoizado por request. */
    public static function cargar(): stdClass
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $salida = self::por_defecto();

        try {
            foreach (DB::table('prediccion_config')->pluck('valor', 'clave') as $clave => $valor) {
                $clave = (string) $clave;
                if (!property_exists($salida, $clave)) {
                    continue;
                }
                $salida->{$clave} = self::tipar_valor($clave, $valor);
            }
        } catch (\Throwable $e) {
            // sin tabla (servidor sin la migración aplicada) o sin conexión:
            // se trabaja con los defaults y se sigue
        }

        return self::$cache = $salida;
    }

    /** Guarda overrides en clave/valor; las claves desconocidas se ignoran. */
    public static function guardar(array $valores): void
    {
        $conocidas = self::por_defecto();
        $filas = [];

        foreach ($valores as $clave => $valor) {
            $clave = (string) $clave;
            if (!property_exists($conocidas, $clave) || !is_scalar($valor)) {
                continue;
            }
            $filas[] = ['clave' => $clave, 'valor' => (string) $valor, 'updated_at' => now()];
        }

        if ($filas === []) {
            return;
        }

        DB::table('prediccion_config')->upsert($filas, ['clave'], ['valor', 'updated_at']);
        self::$cache = null;
    }

    /** Limpia la memoización (pruebas y después de guardar). */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    private static function tipar(array $valores): stdClass
    {
        $salida = new stdClass();
        foreach ($valores as $clave => $valor) {
            $salida->{$clave} = self::tipar_valor((string) $clave, $valor);
        }

        return $salida;
    }

    private static function tipar_valor(string $clave, $valor): int|float
    {
        return in_array($clave, self::FLOTANTES, true) ? (float) $valor : (int) $valor;
    }
}
