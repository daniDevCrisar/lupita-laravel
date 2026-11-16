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
     * Factor del hueco por día de la semana en que el camión PRESENTA (C2).
     *
     * Medido dos veces con huecos reales (refs consecutivas) y es estable:
     *
     *   día    jun→sep (n=8.391)   oct→nov (n=4.681)   prior
     *   lun        2,38                2,98            2,68
     *   mar        0,89                1,04            0,97
     *   mié        0,78                1,04            0,91
     *   jue        0,85                0,83            0,84
     *   vie        0,82                0,79            0,81
     *   sáb        0,99                0,86            0,93
     *   dom        1,45                1,36            1,41
     *
     * O sea: el fin de semana acumula y el lunes se paga (casi el triple). Sirve
     * de prior de la flota cuando la placa no tiene muestra de ese día
     * (`factores_dia()`), y el motor lo aplica al hueco de producción.
     *
     * Índice 0 = lunes (igual que MySQL WEEKDAY y Carbon->format('N') - 1).
     *
     * @var array<int, float>
     */
    public const FACTOR_DIA_FLOTA = [2.68, 0.97, 0.91, 0.84, 0.81, 0.93, 1.41];

    /**
     * Modos del factor de día (`dia_modo`).
     *
     * DIA_APAGADO    no se toca el hueco.
     * DIA_FLOTA      factor de la placa y, si ese día no tiene muestra, el prior
     *                hardcodeado (lo único que había hasta la v1.20).
     * DIA_PLACA      sólo factor propio; los días sin muestra quedan neutros.
     * DIA_COMPILADO  el factor sale de `tiempos_ruta_horario`, la tabla que llena
     *                "Compilar promedios por día y hora"; lo arma
     *                DBPrediccion::factores_dia_compilado(), no este método.
     */
    public const DIA_APAGADO = 0;
    public const DIA_FLOTA = 1;
    public const DIA_PLACA = 2;
    public const DIA_COMPILADO = 3;
    /** Factor del día del ANCLA (el día en que el camión quedó libre), no del de presentación. */
    public const DIA_ANCLA = 4;

    /**
     * Factor del hueco por día de la semana de ESTA placa.
     *
     * De los pares que ya están en memoria (los de la placa) se saca la mediana de
     * cada día y se divide por la mediana general: si un camión siempre presenta
     * los lunes, su lunes ya está en su mediana y no hay que inflarla. Con menos
     * de `$minimo` pares en ese día se usa el prior de la flota, salvo en modo
     * DIA_PLACA, donde el día queda neutro (1.0).
     *
     * POR QUÉ EXISTEN LOS MODOS: medido con 682 placas, de 4.774 celdas
     * (placa x día) sólo 159 quedaron con factor propio, el 3 %. El 97 % restante
     * terminaba aplicando el prior de la flota, o sea que el motor multiplicaba
     * por 2,68 TODOS los lunes a TODOS los camiones. Apagarlo bajó el MAE 131 min
     * (1568 -> 1437) sobre 788 filas del backtest.
     *
     * @param  array<int, object>  $pares  con ->presenta (Carbon) y ->hueco_min
     * @return array<int, float> índice 0 = lunes
     */
    public static function factores_dia(array $pares, int $minimo = 5, int $modo = self::DIA_FLOTA): array
    {
        $neutro = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0];

        if ($modo === self::DIA_APAGADO) {
            return $neutro;
        }

        $todos = [];
        $porDia = [];

        foreach ($pares as $par) {
            $presenta = $par->presenta ?? null;
            $hueco = $par->hueco_min ?? null;

            if (!$presenta instanceof Carbon || !is_numeric($hueco)) {
                continue;
            }

            $dia = (int) $presenta->format('N') - 1;
            $todos[] = (int) $hueco;
            $porDia[$dia][] = (int) $hueco;
        }

        // sin muestra no hay factor: en modo placa el día queda neutro
        $sinMuestra = $modo === self::DIA_PLACA ? $neutro : self::FACTOR_DIA_FLOTA;

        $base = Estadistica::mediana($todos);
        if ($base === null || $base <= 0 || count($todos) < $minimo) {
            return $sinMuestra;
        }

        $factores = $sinMuestra;
        foreach ($porDia as $dia => $valores) {
            if (count($valores) < $minimo) {
                continue;
            }

            $mediana = Estadistica::mediana($valores);
            if ($mediana === null || $mediana <= 0) {
                continue;
            }

            // se acota: un día con pocos pares no puede mover el hueco 5 veces
            $factores[$dia] = round(max(0.5, min(3.0, $mediana / $base)), 2);
        }

        return $factores;
    }

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
                                    int $max_por_entidad = 0, int $curva_modo = 0, int $curva_k = 5,
                                    int $entidad_pares_k = 0, int $hueco_debil = 0): stdClass
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
            'curva_modo' => max(0, min(2, $curva_modo)),
            'curva_k' => max(1, $curva_k),
            // cruce par de rutas / placa y respaldo flojo: los pone Servicio desde
            // la configuración, así `estimar()` no necesita más argumentos
            'entidad_pares_k' => max(0, $entidad_pares_k),
            'hueco_debil' => max(0, $hueco_debil),
            'par_rutas' => $par_rutas,
            'entidad' => $entidad,
            'curva' => self::curva($limpios, $por_entidad, $todos, $min_entidad, $bins, $curva_modo),
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
        $mezcla = null;

        $deRuta = $clave !== null ? ($calibracion->par_rutas[$clave] ?? null) : null;
        $deEntidad = $calibracion->entidad[$entidad] ?? null;
        $global = $calibracion->global ?? null;
        // la mediana de la placa: la que se usa para mezclar y como respaldo flojo
        $dePlaca = ($deEntidad !== null && ($deEntidad['n'] ?? 0) >= 1) ? $deEntidad : $global;

        if ($deRuta !== null && $deRuta['n'] >= $min_ruta) {
            $fuente = $deRuta;
            $nivel = 'medido';

            // CRUCE: el par de rutas solo no es mejor que la mediana de la placa
            // (medido: "medido" MAE 1.252 contra 952 de "parcial"). Con
            // `entidad_pares_k` se mezclan con w = n/(n+k), igual que el viaje.
            $k = max(0, (int) ($calibracion->entidad_pares_k ?? 0));
            if ($k > 0 && $dePlaca !== null && ($dePlaca['base_min'] ?? null) !== null) {
                $peso = $deRuta['n'] / ($deRuta['n'] + $k);
                $mezcla = (object) [
                    'peso' => $peso,
                    'par' => (int) round((float) $deRuta['base_min']),
                    'placa' => (int) round((float) $dePlaca['base_min']),
                ];
            }
        } elseif ($deEntidad !== null && $deEntidad['n'] >= $min_entidad) {
            $fuente = $deEntidad;
            $nivel = 'parcial';
        } elseif ($global !== null && ($global['n'] ?? 0) >= $min_entidad) {
            $fuente = $global;
            $nivel = 'parcial';
        }

        $residual = self::correccion_horaria($calibracion, $hora_ancla, $bins,
            $fuente !== null ? (float) ($fuente['base_min'] ?? 0) : null);

        if ($fuente === null) {
            // RESPALDO FLOJO: sin muestra para los mínimos, antes se devolvía null y
            // el motor armaba traslado + tiempo muerto (~300 min) aunque la placa
            // tuviera historia. La mediana de la placa sirve igual: el nivel sigue
            // siendo "cadena" (la confianza no sube) pero la hora deja de estar 43 h
            // antes de lo real. `hueco_debil` es la MUESTRA MÍNIMA que se le exige
            // (0 = apagado): con un solo par la mediana es ese viaje y no sirve.
            $minimoDebil = max(0, (int) ($calibracion->hueco_debil ?? 0));
            if ($minimoDebil > 0 && $dePlaca !== null
                && (int) ($dePlaca['n'] ?? 0) >= $minimoDebil
                && ($dePlaca['base_min'] ?? null) !== null && (int) $dePlaca['base_min'] > 0) {
                $base = (int) round((float) $dePlaca['base_min']);
                $residualDebil = self::correccion_horaria($calibracion, $hora_ancla, $bins, (float) $base);

                return (object) [
                    'gap_min' => max(1, $base + $residualDebil),
                    'p25_min' => $dePlaca['p25_min'] === null ? null : (int) round((float) $dePlaca['p25_min'] + $residualDebil),
                    'p75_min' => $dePlaca['p75_min'] === null ? null : (int) round((float) $dePlaca['p75_min'] + $residualDebil),
                    'base_min' => $base,
                    'residual_min' => $residualDebil,
                    'n' => (int) $dePlaca['n'],
                    'nivel' => 'cadena',
                    'flojo' => true,
                ];
            }

            return (object) [
                'gap_min' => null, 'p25_min' => null, 'p75_min' => null, 'base_min' => null,
                'residual_min' => $residual, 'n' => 0, 'nivel' => 'cadena', 'flojo' => false,
            ];
        }

        $gap = (float) $fuente['base_min'] + $residual;
        $salida = ['peso_par' => null, 'par_min' => null, 'placa_min' => null];

        // mezcla par de rutas / placa (sólo cuando el nivel es "medido")
        if ($mezcla !== null) {
            $gap = $mezcla->peso * $mezcla->par + (1 - $mezcla->peso) * $mezcla->placa + $residual;
            $salida = ['peso_par' => round($mezcla->peso, 3), 'par_min' => $mezcla->par,
                       'placa_min' => $mezcla->placa];
        }

        return (object) array_merge([
            'gap_min' => max(1, (int) round($gap)),
            'p25_min' => $fuente['p25_min'] === null ? null : (int) round((float) $fuente['p25_min'] + $residual),
            'p75_min' => $fuente['p75_min'] === null ? null : (int) round((float) $fuente['p75_min'] + $residual),
            'base_min' => (int) round((float) $fuente['base_min']),
            'residual_min' => $residual,
            'n' => (int) $fuente['n'],
            'nivel' => $nivel,
            'flojo' => false,
        ], $salida);
    }

    /**
     * Corrección por franja horaria: mediana del residuo `hueco real - mediana de
     * la propia entidad` en cada franja.
     *
     * El residuo se mide contra la base de la ENTIDAD (no contra la del par de
     * rutas) para que la corrección se pueda sumar a cualquier base, incluida la
     * de una calibración hecha sólo con los pares recientes de una placa.
     *
     * GUARDA IMPORTANTE: una franja necesita al menos `$min_entidad` pares para
     * corregir. Con menos, esa franja no es una tendencia horaria sino un par
     * suelto, y su mediana ES ese par: una placa con 11 pares repartidos en 8
     * franjas puede tener una sola noche de 54 h en la franja de las 19 y la
     * predicción se iba 48 h al futuro (caso real AHV874). Sin muestra, el
     * residuo es 0 y manda la mediana de la entidad.
     *
     * Modos (`curva_modo`): 0 = residuo absoluto (comportamiento viejo), 1 =
     * proporcional + encogido, 2 = apagada. En el modo 0 el residuo se mide contra
     * la mediana de la PLACA y se sumaba a la base del PAR DE RUTAS, que puede ser
     * 2-3 veces mayor: sumar el desvío de una distribución a la base de otra no es
     * correcto cuando la distribución es asimétrica. Por eso el modo 1 lo pasa a
     * proporción (`residuo / base_entidad * base_elegida`) y lo pesa con `curva_k`.
     *
     * @return array<int, array{n: int, residual_min: ?int, base_min: ?int}>
     */
    private static function curva(array $limpios, array $por_entidad, array $todos, int $min_entidad, int $bins,
                                  int $modo = 0): array
    {
        $curva = [];
        for ($bin = 0; $bin < $bins; $bin++) {
            $curva[$bin] = ['n' => 0, 'residual_min' => null, 'base_min' => null];
        }

        if ($modo === 2) {
            return $curva;      // apagada: no se calcula nada
        }

        $minimo = max(2, $min_entidad);
        $residuos = [];
        $bases = [];
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
            $bases[$fila['bin']][] = $base;
        }

        foreach ($residuos as $bin => $valores) {
            if (count($valores) < $minimo) {
                continue;   // franja sin muestra: residual 0 (null)
            }

            $curva[$bin] = [
                'n' => count($valores),
                'residual_min' => (int) round((float) Estadistica::mediana($valores)),
                'base_min' => (int) round((float) Estadistica::mediana($bases[$bin] ?? [])),
            ];
        }

        return $curva;
    }

    /**
     * Cuántos minutos se le suman al hueco por la hora del ancla.
     *
     * `$baseElegida` es la base del nivel que se va a usar (par de rutas o placa);
     * con el modo proporcional el residuo se escala a esa base. Si no hay nivel
     * elegido se usa la propia base del residuo, así lo que se informa sigue
     * siendo el residuo medido (encogido por muestra).
     */
    private static function correccion_horaria(stdClass $calibracion, int $horaAncla, int $bins,
                                               ?float $baseElegida): int
    {
        $modo = (int) ($calibracion->curva_modo ?? 0);
        if ($modo === 2) {
            return 0;
        }

        $fila = $calibracion->curva[Estadistica::bin_horario($horaAncla, $bins)] ?? null;
        if (!is_array($fila)) {
            return 0;
        }

        $residual = (int) round((float) ($fila['residual_min'] ?? 0));
        if ($modo === 0) {
            return $residual;
        }

        $baseCurva = (float) ($fila['base_min'] ?? 0);
        $n = (int) ($fila['n'] ?? 0);
        $base = $baseElegida ?? $baseCurva;

        if ($baseCurva <= 0 || $base <= 0 || $n <= 0) {
            return 0;
        }

        $k = max(1, (int) ($calibracion->curva_k ?? 5));
        $peso = $n / ($n + $k);

        return (int) round(($residual / $baseCurva) * $base * $peso);
    }

    /**
     * Calibración de un solo nivel a partir de un resumen ya calculado
     * (`n`, `base_min`, `p25_min`, `p75_min`).
     *
     * Sirve para aplicar un RÉGIMEN medido en otra población sin recalibrar todo:
     * por ejemplo "los viajes en que el camión quedó cerca de la planta de carga",
     * que son los que cargan enseguida (ver Servicio::regimen_cerca()).
     */
    public static function de_resumen(array $resumen, string $etiqueta = 'regimen'): stdClass
    {
        $resumen = [
            'n' => (int) ($resumen['n'] ?? 0),
            'base_min' => isset($resumen['base_min']) ? (int) round((float) $resumen['base_min']) : null,
            'p25_min' => isset($resumen['p25_min']) ? (int) round((float) $resumen['p25_min']) : null,
            'p75_min' => isset($resumen['p75_min']) ? (int) round((float) $resumen['p75_min']) : null,
        ];

        return (object) [
            'min_ruta' => 1,
            'min_entidad' => 1,
            'bins' => 1,
            'recientes_max' => 0,
            'curva_modo' => 0,      // el régimen ya viene con su propia base
            'curva_k' => 1,
            'entidad_pares_k' => 0,
            'hueco_debil' => 0,
            'par_rutas' => [],
            'entidad' => [$etiqueta => $resumen],
            'global' => $resumen,
            'curva' => [0 => ['n' => $resumen['n'], 'residual_min' => 0, 'base_min' => $resumen['base_min']]],
        ];
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
