<?php

namespace App\Prediccion;

use Carbon\Carbon;
use stdClass;

/**
 * Calibración empírica del "hueco": lo que tarda el camión desde que queda libre
 * (`fin_descargue` de un viaje) hasta que se presenta para cargar el siguiente.
 *
 * Ese hueco, medido en la propia base, ya contiene el regreso vacío, la
 * preparación y el descanso, así que es el mejor predictor disponible cuando hay
 * historia. Se calcula en tres niveles de evidencia:
 *
 *   medido  el par de rutas (ruta del viaje anterior -> ruta del siguiente)
 *   parcial la entidad (placa o conductor) o, en su defecto, toda la base
 *   cadena  no hay pares suficientes: el motor arma la cadena con las etapas
 *
 * El hueco no es el mismo a cualquier hora: un camión liberado a las 23:00 no
 * vuelve a las 03:00. Por eso encima del nivel elegido se aplica una corrección
 * por franja horaria del ancla, calculada con los residuos de toda la base.
 *
 * Es puro: no consulta nada ni usa el reloj.
 */
final class Calibrador
{
    /** Tope del hueco considerado válido: 7 días en minutos. */
    public const TOPE_HUECO = 10080;

    /**
     * Agrupa los pares y calcula los niveles, la curva horaria y la base global.
     *
     * @param  array<int, object>  $pares  cada uno con ancla, presenta, ruta_ancla, ruta_siguiente, entidad
     */
    public static function calibrar(array $pares, int $min_ruta = 3, int $min_entidad = 3, int $bins = 8): stdClass
    {
        $bins = max(1, min(24, $bins));
        $limpios = [];
        $por_clave = [];
        $por_entidad = [];
        $todos = [];

        foreach ($pares as $par) {
            $ancla = self::carbon($par->ancla ?? null);
            $presenta = self::carbon($par->presenta ?? null);
            if ($ancla === null || $presenta === null) {
                continue;
            }

            $hueco = (int) round(($presenta->getTimestamp() - $ancla->getTimestamp()) / 60);
            if ($hueco <= 0 || $hueco > self::TOPE_HUECO) {
                continue;
            }

            $rutaAncla = self::texto($par->ruta_ancla ?? null);
            $rutaSig = self::texto($par->ruta_siguiente ?? null);
            $entidad = self::texto($par->entidad ?? null) ?: 'sin_entidad';
            $clave = ($rutaAncla !== '' && $rutaSig !== '') ? $rutaAncla . '>' . $rutaSig : null;

            $fila = [
                'hueco' => $hueco,
                'clave' => $clave,
                'entidad' => $entidad,
                'bin' => Estadistica::bin_horario((int) $ancla->format('G'), $bins),
            ];

            $limpios[] = $fila;
            $todos[] = $hueco;
            $por_entidad[$entidad][] = $hueco;
            if ($clave !== null) {
                $por_clave[$clave][] = $hueco;
            }
        }

        $par_rutas = [];
        foreach ($por_clave as $clave => $huecos) {
            $par_rutas[$clave] = self::resumen($huecos);
        }

        $entidad = [];
        foreach ($por_entidad as $nombre => $huecos) {
            $entidad[$nombre] = self::resumen($huecos);
        }

        $global = self::resumen($todos);

        return (object) [
            'min_ruta' => max(1, $min_ruta),
            'min_entidad' => max(1, $min_entidad),
            'bins' => $bins,
            'par_rutas' => $par_rutas,
            'entidad' => $entidad,
            'curva' => self::curva($limpios, $por_clave, $por_entidad, $todos, $min_ruta, $min_entidad, $bins),
            'global' => $global,
        ];
    }

    /**
     * Hueco esperado para una hora de ancla concreta, con su nivel de evidencia.
     * `gap_min` es NULL cuando no hay ni para empezar (nivel `cadena`).
     */
    public static function estimar(stdClass $calibracion, ?string $ruta_ancla, ?string $ruta_siguiente,
                                   string $entidad, int $hora_ancla): stdClass
    {
        $min_ruta = max(1, (int) ($calibracion->min_ruta ?? 3));
        $min_entidad = max(1, (int) ($calibracion->min_entidad ?? 3));
        $bins = max(1, (int) ($calibracion->bins ?? 8));

        $clave = ($ruta_ancla !== null && $ruta_ancla !== '' && $ruta_siguiente !== null && $ruta_siguiente !== '')
            ? $ruta_ancla . '>' . $ruta_siguiente
            : null;

        $fuente = null;
        $nivel = 'cadena';

        $deRuta = $clave !== null ? ($calibracion->par_rutas[$clave] ?? null) : null;
        $deEntidad = $calibracion->entidad[$entidad] ?? null;
        $global = $calibracion->global ?? null;

        if ($deRuta !== null && $deRuta['n'] >= $min_ruta) {
            $fuente = $deRuta;
            $nivel = 'medido';
        } elseif ($deEntidad !== null && $deEntidad['n'] >= $min_entidad) {
            $fuente = $deEntidad;
            $nivel = 'parcial';
        } elseif ($global !== null && ($global['n'] ?? 0) >= $min_entidad) {
            $fuente = $global;
            $nivel = 'parcial';
        }

        $residual = $calibracion->curva[Estadistica::bin_horario($hora_ancla, $bins)]['residual_min'] ?? 0;
        $residual = (int) round((float) $residual);

        if ($fuente === null) {
            return (object) [
                'gap_min' => null, 'p25_min' => null, 'p75_min' => null, 'base_min' => null,
                'residual_min' => $residual, 'n' => 0, 'nivel' => 'cadena',
            ];
        }

        return (object) [
            'gap_min' => max(1, (int) round((float) $fuente['base_min'] + $residual)),
            'p25_min' => $fuente['p25_min'] === null ? null : (int) round((float) $fuente['p25_min'] + $residual),
            'p75_min' => $fuente['p75_min'] === null ? null : (int) round((float) $fuente['p75_min'] + $residual),
            'base_min' => (int) round((float) $fuente['base_min']),
            'residual_min' => $residual,
            'n' => (int) $fuente['n'],
            'nivel' => $nivel,
        ];
    }

    /**
     * Corrección por franja horaria: mediana del residuo (hueco real - base del
     * nivel que le toca) de cada franja, con toda la base.
     *
     * @return array<int, array{n: int, residual_min: ?int}>
     */
    private static function curva(array $limpios, array $por_clave, array $por_entidad, array $todos,
                                  int $min_ruta, int $min_entidad, int $bins): array
    {
        $curva = [];
        for ($bin = 0; $bin < $bins; $bin++) {
            $curva[$bin] = ['n' => 0, 'residual_min' => null];
        }

        $residuos = [];
        foreach ($limpios as $fila) {
            $base = null;
            if ($fila['clave'] !== null && count($por_clave[$fila['clave']] ?? []) >= $min_ruta) {
                $base = Estadistica::mediana($por_clave[$fila['clave']]);
            } elseif (count($por_entidad[$fila['entidad']] ?? []) >= $min_entidad) {
                $base = Estadistica::mediana($por_entidad[$fila['entidad']]);
            } elseif (count($todos) >= $min_entidad) {
                $base = Estadistica::mediana($todos);
            }

            if ($base === null) {
                continue;
            }

            $residuos[$fila['bin']][] = $fila['hueco'] - $base;
        }

        foreach ($residuos as $bin => $valores) {
            $curva[$bin] = [
                'n' => count($valores),
                'residual_min' => (int) round((float) Estadistica::mediana($valores)),
            ];
        }

        return $curva;
    }

    /** @return array{n: int, base_min: ?int, p25_min: ?int, p75_min: ?int} */
    private static function resumen(array $huecos): array
    {
        if ($huecos === []) {
            return ['n' => 0, 'base_min' => null, 'p25_min' => null, 'p75_min' => null];
        }

        return [
            'n' => count($huecos),
            'base_min' => (int) round((float) Estadistica::mediana($huecos)),
            'p25_min' => (int) round((float) Estadistica::cuantil($huecos, 0.25)),
            'p75_min' => (int) round((float) Estadistica::cuantil($huecos, 0.75)),
        ];
    }

    private static function carbon($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }

    private static function texto($valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
