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
     * `$max_por_entidad` se queda sólo con los pares más recientes de cada placa
     * o chofer. No es un detalle: el hueco mediano de la flota pasó de ~1900 min
     * en abril a ~540 min en noviembre, así que calibrar con 120 días parejos
     * sobreestima la vuelta en casi medio día. Con 0 se usa toda la historia.
     *
     * @param  array<int, object>  $pares  cada uno con ancla, presenta, ruta_ancla, ruta_siguiente, entidad
     */
    public static function calibrar(array $pares, int $min_ruta = 3, int $min_entidad = 3, int $bins = 8,
                                    int $max_por_entidad = 0): stdClass
    {
        $bins = max(1, min(24, $bins));
        $limpios = [];
        $por_clave = [];
        $por_entidad = [];

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

            $limpios[] = [
                'ancla' => $ancla,
                'hueco' => $hueco,
                'clave' => ($rutaAncla !== '' && $rutaSig !== '') ? $rutaAncla . '>' . $rutaSig : null,
                'entidad' => $entidad,
                'bin' => Estadistica::bin_horario((int) $ancla->format('G'), $bins),
            ];
        }

        if ($max_por_entidad > 0) {
            $limpios = self::recientes_por_entidad($limpios, $max_por_entidad);
        }

        $todos = [];
        foreach ($limpios as $fila) {
            $todos[] = $fila['hueco'];
            $por_entidad[$fila['entidad']][] = $fila['hueco'];
            if ($fila['clave'] !== null) {
                $por_clave[$fila['clave']][] = $fila['hueco'];
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
            'recientes_max' => max(0, $max_por_entidad),
            'par_rutas' => $par_rutas,
            'entidad' => $entidad,
            'curva' => self::curva($limpios, $por_entidad, $todos, $min_entidad, $bins),
            'global' => $global,
        ];
    }

    /**
     * Deja los `$max` pares más recientes de cada entidad.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private static function recientes_por_entidad(array $filas, int $max): array
    {
        $porEntidad = [];
        foreach ($filas as $fila) {
            $porEntidad[$fila['entidad']][] = $fila;
        }

        $salida = [];
        foreach ($porEntidad as $grupo) {
            usort($grupo, fn (array $a, array $b) => $b['ancla']->getTimestamp() <=> $a['ancla']->getTimestamp());
            foreach (array_slice($grupo, 0, $max) as $fila) {
                $salida[] = $fila;
            }
        }

        return $salida;
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
     * Corrección por franja horaria: mediana del residuo `hueco real - mediana de
     * la propia entidad` en cada franja.
     *
     * El residuo se mide contra la base de la ENTIDAD (no contra la del par de
     * rutas) para que la corrección se pueda sumar a cualquier base, incluida la
     * de una calibración hecha sólo con los pares recientes de una placa.
     *
     * @return array<int, array{n: int, residual_min: ?int}>
     */
    private static function curva(array $limpios, array $por_entidad, array $todos, int $min_entidad, int $bins): array
    {
        $curva = [];
        for ($bin = 0; $bin < $bins; $bin++) {
            $curva[$bin] = ['n' => 0, 'residual_min' => null];
        }

        $residuos = [];
        foreach ($limpios as $fila) {
            $base = null;
            if (count($por_entidad[$fila['entidad']] ?? []) >= $min_entidad) {
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
