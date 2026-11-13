<?php

namespace App\Prediccion;

use Carbon\Carbon;
use stdClass;

/**
 * Motor de la predicción de `presenta_para_carga`.
 *
 * La cadena es corta y explícita:
 *
 *   ancla (último hito)  ->  t_fin (cuándo queda libre el camión)
 *                        ->  + hueco (regreso, espera y descanso)
 *                        ->  fuera de las franjas inactivas
 *                        ->  presenta   (+ espera en planta = inicio de carga)
 *
 * El hueco sale del calibrador cuando hay pares medidos y, si no, de la cadena
 * física: traslado de vuelta (promedios de la ruta) + un tiempo muerto por
 * defecto. La salida siempre trae el desglose en minutos, los supuestos, las
 * advertencias y el nivel de evidencia, porque una hora sin explicación no sirve
 * para decidir nada.
 *
 * Es puro: no toca la base ni el reloj. El corte entra en `ahora`.
 */
final class MotorPresenta
{
    /** Etapas que faltan para cerrar el viaje según el último hito registrado. */
    private const FALTANTES = [
        'presenta_para_carga' => ['e2', 'e3', 'e4', 'e5', 'e6'],
        'inicio_de_carga' => ['e3', 'e4', 'e5', 'e6'],
        'fin_de_carga' => ['e3', 'e4', 'e5', 'e6'],
        'inicio_ruta' => ['e4', 'e5', 'e6'],
        'qr_llegada_destino' => ['e5', 'e6'],
        'inicio_descargue' => ['e6'],
    ];

    /** Etiqueta legible del estado del camión según su último hito. */
    private const ESTADOS = [
        'presenta_para_carga' => ['en_planta_carga', 'Presentado en la planta de carga'],
        'inicio_de_carga' => ['cargando', 'Cargando en planta'],
        'fin_de_carga' => ['cargando', 'Terminó de cargar'],
        'inicio_ruta' => ['en_ruta', 'En ruta hacia el destino'],
        'qr_llegada_destino' => ['en_planta_descarga', 'Llegó a la planta de descarga'],
        'inicio_descargue' => ['descargando', 'Descargando'],
        'fin_descargue' => ['regresando', 'Libre: vuelve a cargar'],
    ];

    /** Tope por etapa, en minutos (mismo valor que el módulo de tiempos). */
    private const TOPE_ETAPA = 2880;

    /** @see self::predecir() */
    public static function predecir(stdClass $e): stdClass
    {
        $p = $e->parametros ?? (object) [];
        $ahora = self::carbon($e->ahora ?? null) ?? Carbon::now();
        $ancla = $e->ancla ?? null;
        $hito = isset($ancla->hito) && $ancla->hito !== null ? (string) $ancla->hito : null;
        $fechaAncla = self::carbon($ancla->fecha ?? null);
        $cerrada = (bool) ($ancla->cerrada ?? false);
        $ventanas = is_array($e->ventanas ?? null) ? $e->ventanas : [];
        $edadMax = (int) ($p->edad_ancla_max_h ?? 72);

        $salida = self::salida($e, $ahora);
        $salida->estado = self::estado($hito, $fechaAncla, $ahora, $ventanas, $edadMax);
        $salida->ancla = (object) [
            'ref' => (string) ($e->ancla_ref ?? ''),
            'hito' => $hito,
            'fecha' => $fechaAncla,
            'edad_min' => $fechaAncla === null ? null : (int) round($fechaAncla->diffInMinutes($ahora)),
            'cerrada' => $cerrada,
        ];

        // 1) la referencia ya presentó: no hay nada que predecir
        $refPresenta = self::carbon($e->ref_presenta ?? null);
        if ($refPresenta !== null && $refPresenta->lessThanOrEqualTo($ahora)) {
            $salida->ya_presento = true;
            $salida->advertencias[] = 'ya_presento: la referencia ya tiene presenta_para_carga registrado';

            return $salida;
        }

        if ($fechaAncla === null) {
            $salida->advertencias[] = 'sin_ancla: no hay ningún hito previo del camión';

            return $salida;
        }

        // un hito mal cargado con fecha posterior al corte no puede correr la
        // predicción hacia el futuro
        if ($fechaAncla->greaterThan($ahora)) {
            $fechaAncla = $ahora->copy();
            $salida->advertencias[] = 'ancla_futura: el último hito del camión está fechado después del corte; '
                . 'se usa el corte como ancla';
        }

        // un ancla de días no sirve para predecir: devolver "ahora" con la forma de
        // una hora sería mentir, así que se dice que no hay con qué. El límite es
        // el tope del hueco (7 días): más allá de eso el modelo no tiene dominio.
        $topeAncla = max(1, (int) ($p->tope_hueco_min ?? Calibrador::TOPE_HUECO));
        $edadMin = (int) round($fechaAncla->diffInMinutes($ahora));
        if ($edadMin > $topeAncla) {
            $salida->advertencias[] = 'ancla_vieja: el último hito del camión tiene ' . round($edadMin / 60, 1)
                . ' h (máximo ' . intdiv($topeAncla, 60) . ' h); no hay con qué predecir esta referencia';

            return $salida;
        }

        // 2) t_fin: cuándo queda libre el camión
        $etapas = is_array($e->etapas ?? null) ? $e->etapas : (array) ($e->etapas ?? []);
        $restante = null;

        if ($cerrada) {
            $tFin = $fechaAncla->copy();
        } else {
            $restante = self::faltantes($hito, $etapas);
            if ($restante === null) {
                $tFin = $ahora->copy();
                $salida->advertencias[] = 'etapas_incompletas: la ruta del viaje en curso no tiene los promedios E2..E6; se asume que el camión queda libre ahora';
            } else {
                $tFin = $fechaAncla->copy()->addMinutes($restante);
                if ($tFin->lessThanOrEqualTo($ahora)) {
                    $tFin = $ahora->copy();
                    $restante = 0;
                    $salida->advertencias[] = 'etapa_vencida: el promedio de la etapa ya se consumió sin hitos nuevos; se cuenta desde ahora';
                }
            }
        }

        // 3) hueco: regreso + espera + descanso
        $traslado = $e->traslado ?? null;
        $cal = $e->calibrador ?? null;
        $gap = $cal !== null ? ($cal->gap_min ?? null) : null;
        $trasladoMin = $traslado !== null ? max(0, (int) ($traslado->min ?? 0)) : null;

        if ($trasladoMin === null && $gap === null) {
            $salida->advertencias[] = 'sin_traslado: no se puede ubicar el camión ni hay huecos medidos; no se inventa una hora';

            return $salida;
        }

        $muertoDefecto = max(0, (int) ($p->muerto_defecto_min ?? 60));
        $esCadena = $gap === null;

        if ($esCadena) {
            $hueco = ($trasladoMin ?? 0) + $muertoDefecto;
            $fuenteHueco = 'cadena (traslado + muerto por defecto)';
            $evidencia = 'cadena';
        } else {
            // El hueco medido ya contiene el regreso real: manda sobre el promedio
            // de la ruta, aunque sea menor. Sólo se avisa, no se pisa.
            $hueco = (int) $gap;
            $fuenteHueco = 'medido (' . (int) ($cal->n ?? 0) . ' pares)';
            $evidencia = (string) ($cal->nivel ?? 'parcial');

            if ($trasladoMin !== null && $hueco < $trasladoMin) {
                $salida->advertencias[] = 'hueco_menor_que_traslado: el hueco medido (' . $hueco
                    . ' min) es menor que el traslado promedio de la ruta (' . $trasladoMin
                    . ' min); se respeta el medido';
            }
        }

        $hueco = max(1, min(max(1, (int) ($p->tope_hueco_min ?? Calibrador::TOPE_HUECO)), $hueco));

        // 4) fuera de las franjas inactivas del camión
        $t = $tFin->copy()->addMinutes($hueco);
        $desplazado = Ventanas::desplazar($t, $ventanas, max(0, (int) ($p->max_desplazamiento_min ?? 600)));
        $t = $desplazado->fecha;
        $esperaVentana = (int) $desplazado->min;
        if ($desplazado->aviso !== null) {
            $salida->advertencias[] = $desplazado->aviso . ': la predicción cayó en una franja inactiva y se movió '
                . $esperaVentana . ' min';
        }

        $presentaCrudo = $t->copy();

        if ($t->lessThan($ahora)) {
            $t = $ahora->copy();
            $salida->atrasado = true;
            $salida->presenta_crudo = $presentaCrudo;
            $salida->advertencias[] = 'atrasado: la predicción cayó antes de ahora; el camión está fuera de su patrón';
        }

        // 5) inicio de carga = presenta + espera en la planta de carga
        $espera = $e->espera_carga_min !== null && isset($e->espera_carga_min)
            ? max(0, (int) $e->espera_carga_min)
            : max(0, (int) ($p->espera_carga_defecto_min ?? 90));

        $tiempoMuerto = $esCadena ? $muertoDefecto : max(0, $hueco - (int) ($trasladoMin ?? 0));

        $confianza = self::confianza((object) [
            'nivel' => $evidencia,
            'iqr' => ($cal !== null && ($cal->p25_min ?? null) !== null && ($cal->p75_min ?? null) !== null)
                ? (int) $cal->p75_min - (int) $cal->p25_min
                : null,
            'ancla_cerrada' => $cerrada,
            'espera_ventana' => $esperaVentana,
            'traslado_fuente' => (string) ($traslado->fuente ?? ''),
            'horizonte_min' => (int) round($t->diffInMinutes($ahora)),
            'parametros' => $p,
        ]);

        $salida->presenta = $t;
        $salida->inicio_carga = $t->copy()->addMinutes($espera);
        $salida->espera_min = $espera;
        $salida->evidencia = $evidencia;
        $salida->confianza = $confianza->pct;
        $salida->confianza_motivo = $confianza->motivo;
        $salida->desglose = self::desglose($cerrada, $restante, $hueco, $fuenteHueco, $traslado, $trasladoMin,
            $tiempoMuerto, $esCadena, $esperaVentana, $espera);
        $salida->ventana = self::ventana($t, $cal);
        $salida->supuestos = self::supuestos($cerrada, $esCadena, $cal, $traslado, $espera, $p);

        return $salida;
    }

    /**
     * Minutos que le faltan al viaje en curso para quedar cerrado, según el
     * último hito. NULL si falta algún promedio de etapa (no se adivina).
     *
     * @param  array<string, int|float|string|null>  $etapas
     */
    public static function faltantes(?string $hito, array $etapas): ?int
    {
        if ($hito === null) {
            return null;
        }

        $claves = self::FALTANTES[$hito] ?? null;
        if ($claves === null) {
            return null;
        }

        $total = 0;
        foreach ($claves as $clave) {
            $valor = $etapas[$clave] ?? null;
            if ($valor === null || !is_numeric($valor)) {
                return null;
            }
            $total += min(self::TOPE_ETAPA, max(0, (int) round((float) $valor)));
        }

        // el remanente de un viaje tampoco puede superar el tope de una etapa:
        // por encima de eso los promedios son basura, no un viaje largo
        return min(self::TOPE_ETAPA, $total);
    }

    /**
     * Dónde está y qué está haciendo el conductor ahora mismo, derivado de su
     * último hito y de sus franjas inactivas.
     */
    public static function estado(?string $hito, ?Carbon $ancla, Carbon $ahora, array $ventanas, int $edadMaxH): stdClass
    {
        $minuto = (int) $ahora->format('G') * 60 + (int) $ahora->format('i');

        if (Ventanas::ventanaDe($minuto, $ventanas) !== null) {
            $ventana = Ventanas::ventanaDe($minuto, $ventanas);

            return (object) [
                'clave' => 'descansando',
                'etiqueta' => 'En su franja inactiva (descanso o almuerzo)',
                'motivo' => sprintf('ahora cae entre %02d:%02d y %02d:%02d, sin actividad histórica',
                    intdiv($ventana['desde'], 60), $ventana['desde'] % 60,
                    intdiv($ventana['hasta'], 60), $ventana['hasta'] % 60),
            ];
        }

        if ($ancla === null) {
            return (object) ['clave' => 'sin_datos', 'etiqueta' => 'Sin datos', 'motivo' => 'no hay hitos previos'];
        }

        $edadMin = (int) round($ancla->diffInMinutes($ahora));
        if ($edadMin > max(1, $edadMaxH) * 60) {
            return (object) [
                'clave' => 'inactivo_extendido',
                'etiqueta' => 'Sin actividad registrada hace más de ' . $edadMaxH . ' h',
                'motivo' => 'el último hito tiene ' . round($edadMin / 60, 1) . ' h',
            ];
        }

        $definicion = self::ESTADOS[$hito] ?? ['sin_datos', 'Sin datos'];

        return (object) [
            'clave' => $definicion[0],
            'etiqueta' => $definicion[1],
            'motivo' => 'último hito: ' . ($hito ?? 'ninguno'),
        ];
    }

    /**
     * Confianza 0..100 con tope por nivel de evidencia y castigos explícitos.
     */
    public static function confianza(stdClass $d): stdClass
    {
        $topes = ['medido' => 95, 'parcial' => 70, 'cadena' => 45];
        $tope = $topes[(string) ($d->nivel ?? 'cadena')] ?? 45;
        $pct = $tope;
        $motivos = [];

        if (($d->iqr ?? null) !== null && (int) $d->iqr > 240) {
            $pct -= 20;
            $motivos[] = 'dispersión alta (IQR ' . (int) $d->iqr . ' min)';
        }
        if (($d->ancla_cerrada ?? true) === false) {
            $pct -= 15;
            $motivos[] = 'ancla a mitad de viaje';
        }
        if ((int) ($d->espera_ventana ?? 0) > 0) {
            $pct -= 25;
            $motivos[] = 'desplazada por franja inactiva';
        }
        if ((string) ($d->traslado_fuente ?? '') === 'defecto') {
            $pct -= 20;
            $motivos[] = 'traslado estimado por defecto';
        }

        $limite = max(1, (int) ($d->parametros->frescura_horizonte_h ?? 24)) * 60;
        if ((int) ($d->horizonte_min ?? 0) > $limite) {
            $pct -= 15;
            $motivos[] = 'horizonte de más de ' . intdiv($limite, 60) . ' h';
        }

        return (object) [
            'pct' => max(5, min($tope, $pct)),
            'motivo' => $motivos === [] ? 'sin penalizaciones' : implode(', ', $motivos),
        ];
    }

    /** Esqueleto de salida: todas las claves existen siempre. */
    private static function salida(stdClass $e, Carbon $ahora): stdClass
    {
        return (object) [
            'ref' => (string) ($e->ref ?? ''),
            'ahora' => $ahora,
            'presenta' => null,
            'inicio_carga' => null,
            'espera_min' => null,
            'ventana' => ['desde' => null, 'hasta' => null],
            'estado' => null,
            'ancla' => null,
            'desglose' => [],
            'supuestos' => [],
            'advertencias' => [],
            'evidencia' => 'sin_datos',
            'confianza' => 0,
            'confianza_motivo' => '',
            'ya_presento' => false,
            'atrasado' => false,
            'presenta_crudo' => null,
        ];
    }

    /** @return array<int, array{concepto: string, min: int, fuente: string}> */
    private static function desglose(bool $cerrada, ?int $restante, int $hueco, string $fuenteHueco, ?object $traslado,
                                     ?int $trasladoMin, int $tiempoMuerto, bool $esCadena, int $esperaVentana,
                                     int $espera): array
    {
        $filas = [];

        if (!$cerrada) {
            $filas[] = ['concepto' => 'restante_viaje', 'min' => (int) ($restante ?? 0), 'fuente' => 'etapas de la ruta'];
        }

        $filas[] = ['concepto' => 'hueco_total', 'min' => $hueco, 'fuente' => $fuenteHueco];
        $filas[] = ['concepto' => 'traslado_regreso', 'min' => (int) ($trasladoMin ?? 0),
                    'fuente' => (string) ($traslado->fuente ?? 'sin dato')];
        $filas[] = ['concepto' => 'tiempo_muerto', 'min' => $tiempoMuerto,
                    'fuente' => $esCadena ? 'defecto' : 'hueco medido - traslado'];
        $filas[] = ['concepto' => 'espera_ventana', 'min' => $esperaVentana, 'fuente' => 'franjas inactivas'];
        $filas[] = ['concepto' => 'espera_carga', 'min' => $espera, 'fuente' => 'espera en la planta de carga'];

        return $filas;
    }

    /** @return array{desde: ?Carbon, hasta: ?Carbon} */
    private static function ventana(Carbon $t, ?object $cal): array
    {
        if ($cal === null || ($cal->p25_min ?? null) === null || ($cal->p75_min ?? null) === null
            || ($cal->gap_min ?? null) === null) {
            return ['desde' => null, 'hasta' => null];
        }

        return [
            'desde' => $t->copy()->subMinutes(max(0, (int) $cal->gap_min - (int) $cal->p25_min)),
            'hasta' => $t->copy()->addMinutes(max(0, (int) $cal->p75_min - (int) $cal->gap_min)),
        ];
    }

    /** @return array<int, string> */
    private static function supuestos(bool $cerrada, bool $esCadena, ?object $cal, ?object $traslado, int $espera,
                                      object $p): array
    {
        $supuestos = [];

        $supuestos[] = $cerrada
            ? 'el camión ya cerró su viaje anterior: el hueco se cuenta desde su fin de descargue'
            : 'el camión sigue en viaje: se suman las etapas que le faltan con los promedios de su ruta';

        if ($esCadena) {
            $supuestos[] = 'sin pares medidos para esta placa: el regreso se estima con el traslado de la ruta más '
                . max(0, (int) ($p->muerto_defecto_min ?? 60)) . ' min de tiempo muerto';
        } else {
            $supuestos[] = 'hueco real medido con ' . (int) ($cal->n ?? 0) . ' pares de esta placa o de la base';
        }

        $supuestos[] = 'traslado de vuelta: ' . (string) ($traslado->fuente ?? 'sin dato');
        $supuestos[] = 'espera en la planta de carga: ' . $espera . ' min';

        return $supuestos;
    }

    private static function carbon($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
