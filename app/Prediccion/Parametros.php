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
    /**
     * Claves que se castean a float (el resto a int).
     */
    private const FLOTANTES = ['spread_cadena', 'ventana_cobertura', 'vacio_factor', 'coords_min_km'];

    /** Prefijo de las claves cuya corrección sí puede ser negativa. */
    private const SESGO = 'sesgo_clase_';

    /** Claves en minutos que NO terminan en `_min`. */
    private const MINUTOS = ['ventana_adelanto_cadena', 'ventana_atraso_cadena'];

    /** Claves que son interruptores: se leen sí / no, no como número. */
    private const INTERRUPTORES = [
        'sesgo_clase_activo', 'ventana_residuos', 'horario_activo', 'dia_activo',
        'feriado_activo', 'cola_activa', 'descontar_transcurrido', 'coords_activo',
    ];

    /** Claves que se eligen por número: la etiqueta dice qué significa cada uno. */
    private const MODOS = [        'dia_modo' => [
            0 => 'apagado',
            1 => 'flota (prior lunes)',
            2 => 'sólo placa con muestra',
            3 => 'compilado por ruta',
            4 => 'día del ancla',
        ],
        'curva_modo' => [
            0 => 'residuo absoluto (viejo)',
            1 => 'proporcional encogida',
            2 => 'apagada',
        ],
    ];

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

    /**
     * Todas las claves que el motor entiende: las de `config/prediccion.php`.
     *
     * @return array<int, string>
     */
    public static function claves(): array
    {
        return array_keys((array) config('prediccion', []));
    }

    /**
     * Claves que tienen valor propio guardado en `prediccion_config`; el resto
     * corre con el default de fábrica.
     *
     * @return array<int, string>
     */
    public static function guardadas(): array
    {
        try {
            return DB::table('prediccion_config')->orderBy('clave')->pluck('clave')
                ->map(fn ($clave) => (string) $clave)->all();
        } catch (\Throwable $e) {
            // sin tabla (servidor sin la migración) el export sigue sirviendo: se
            // lleva los defaults y la lista de guardadas queda vacía
            return [];
        }
    }

    /**
     * Configuración completa para exportar a un archivo JSON.
     *
     * Se exporta la configuración EFECTIVA (defaults pisados por lo guardado) y no
     * sólo las filas de la tabla: así el archivo importado en otro servidor deja el
     * motor igual, exista o no `prediccion_config` en ese servidor.
     *
     * @return array<string, mixed>
     */
    public static function exportar(): array
    {
        return [
            'formato' => 1,
            'motor' => 'prediccion de la hora de presentacion para carga',
            'exportado' => now()->toIso8601String(),
            'tabla' => 'prediccion_config',
            'guardadas' => self::guardadas(),
            'parametros' => (array) self::cargar(),
        ];
    }

    /**
     * Valida y tipa lo que viene de un archivo de importación.
     *
     * Sólo pasan claves que existen en `config/prediccion.php` y valores numéricos.
     * Los negativos se aceptan únicamente en la corrección de sesgo (ahí el signo ES
     * el dato); en el resto un negativo deja el motor publicando cualquier cosa, así
     * que se rechaza y se informa en pantalla en vez de guardarlo.
     *
     * @param  array<mixed>  $valores
     * @return array{validos: array<string, int|float>, ignoradas: array<int, string>,
     *               rechazadas: array<string, string>}
     */
    public static function normalizar(array $valores): array
    {
        $conocidas = array_flip(self::claves());
        $validos = [];
        $ignoradas = [];
        $rechazadas = [];

        foreach ($valores as $clave => $valor) {
            $clave = (string) $clave;

            if (!isset($conocidas[$clave])) {
                $ignoradas[] = $clave;

                continue;
            }

            if (is_bool($valor)) {
                $valor = $valor ? 1 : 0;
            }

            if (!is_numeric($valor)) {
                $rechazadas[$clave] = 'no es un número';

                continue;
            }

            $numero = self::tipar_valor($clave, $valor);

            if ($numero < 0 && !str_starts_with($clave, self::SESGO)) {
                $rechazadas[$clave] = 'no puede ser negativo';

                continue;
            }

            $validos[$clave] = $numero;
        }

        return ['validos' => $validos, 'ignoradas' => $ignoradas, 'rechazadas' => $rechazadas];
    }

    /**
     * Valores EN USO de una lista de claves, ya formateados para la pantalla.
     *
     * Tres de las calibraciones (cruce, ventana y sesgo) escriben claves sin pasar
     * por un formulario, así que sin esto el único modo de saber qué está
     * gobernando hoy era abrir `prediccion_config` a mano. Cada fila trae el valor
     * en uso, cómo se lee, el de fábrica y si cambió.
     *
     * @param  array<int, string>  $claves
     * @return array<int, array{clave: string, valor: int|float, texto: string,
     *               fabrica: int|float|null, fabrica_texto: ?string, cambiado: bool}>
     */
    public static function en_uso(array $claves, ?stdClass $valores = null,
                                  ?stdClass $fabrica = null): array
    {
        $valores ??= self::cargar();
        $fabrica ??= self::por_defecto();

        $filas = [];
        foreach ($claves as $clave) {
            $clave = (string) $clave;
            if (!property_exists($valores, $clave)) {
                continue;
            }

            $valor = $valores->{$clave};
            $defecto = $fabrica->{$clave} ?? null;

            $filas[] = [
                'clave' => $clave,
                'valor' => $valor,
                'texto' => self::texto($clave, $valor),
                'corto' => self::corto($clave, $valor),
                'fabrica' => $defecto,
                'fabrica_texto' => $defecto === null ? null : self::texto($clave, $defecto),
                'cambiado' => $defecto !== null && (string) $defecto !== (string) $valor,
            ];
        }

        return $filas;
    }

    /**
     * Cómo se lee un valor de configuración: los minutos en `h:mm`, los
     * interruptores en sí/no y los modos con su nombre.
     */
    public static function texto(string $clave, int|float|null $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        if (!self::es_minutos($clave)) {
            return self::suelto($clave, $valor);
        }

        $minutos = (int) $valor;
        $signo = $minutos < 0 ? '−' : '';
        $numero = $signo . number_format(abs($minutos), 0, ',', '.') . ' min';

        return abs($minutos) >= 60
            ? $numero . ' (' . $signo . Tiempo::hhmm(abs($minutos)) . ')'
            : $numero;
    }

    /**
     * La misma duración en corto (`−49:17` o `−19′`), para tablas angostas como la
     * grilla de sesgos; el `texto()` completo va en el `title`.
     */
    public static function corto(string $clave, int|float|null $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        if (!self::es_minutos($clave)) {
            return self::suelto($clave, $valor);
        }

        $minutos = (int) $valor;
        $signo = $minutos < 0 ? '−' : '';

        return abs($minutos) >= 60
            ? $signo . Tiempo::hhmm(abs($minutos))
            : $signo . abs($minutos) . '′';
    }

    /**
     * ¿Este valor está en minutos? Los nombres no son parejos: casi todos terminan
     * en `_min`, la ventana de cadena no, y el sesgo por clase termina en
     * `_corto|_medio|_largo`.
     */
    private static function es_minutos(string $clave): bool
    {
        // `sesgo_clase_activo` es el interruptor de la cuarta fecha, no un sesgo
        if (in_array($clave, self::INTERRUPTORES, true)) {
            return false;
        }

        return str_ends_with($clave, '_min')
            || in_array($clave, self::MINUTOS, true)
            || str_starts_with($clave, self::SESGO);
    }

    /** Formato del valor sin unidades de tiempo (modos, flags, porcentajes, números). */
    private static function suelto(string $clave, int|float $valor): string
    {
        if (isset(self::MODOS[$clave])) {
            return (int) $valor . ' · ' . (self::MODOS[$clave][(int) $valor] ?? 'desconocido');
        }

        if (in_array($clave, self::INTERRUPTORES, true)) {
            return (int) $valor === 1 ? 'sí' : 'no';
        }

        if ($clave === 'ventana_cobertura') {
            return self::porcentaje((float) $valor * 100);
        }

        if ($clave === 'spread_cadena') {
            return '±' . self::porcentaje((float) $valor * 100);
        }

        if (is_float($valor) && floor($valor) !== $valor) {
            return rtrim(rtrim(number_format($valor, 4, ',', '.'), '0'), ',');
        }

        return number_format((float) $valor, 0, ',', '.');
    }

    /** "70" o "70,5" — sin decimales de más ni coma colgando. */
    private static function porcentaje(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 1, ',', '.'), '0'), ',') . ' %';
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
