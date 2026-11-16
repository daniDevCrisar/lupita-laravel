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
    /**
     * Hitos en los que el camión TODAVÍA tiene que hacer el viaje de ida al
     * destino (etapa E4). Sólo en esos casos la posición declarada acorta lo que
     * le falta del viaje: en los demás ya llegó, está descargando o vuelve.
     */
    public const HITOS_CON_VIAJE = ['presenta_para_carga', 'inicio_de_carga', 'fin_de_carga', 'inicio_ruta'];

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
        // sin viaje en curso: el usuario declaró dónde está el camión
        'ubicacion_declarada' => ['en_posicion_declarada', 'En la ubicación que se declaró'],
    ];

    /** Tope por etapa, en minutos (mismo valor que el módulo de tiempos). */
    private const TOPE_ETAPA = 2880;

    /** Días de la semana para los textos (índice 0 = lunes). */
    private const DIA = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

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
            $salida->ancla->fecha = $fechaAncla;
            $salida->ancla->edad_min = 0;
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

        // se agrega a los supuestos cuando se descuenta el tiempo transcurrido
        $notaRestante = null;

        // El operador declaró dónde está el camión AHORA y se ruteó desde ahí
        // hasta el destino del viaje. Eso manda sobre las medianas de la ruta:
        // la mediana cuenta el viaje entero desde el último hito, no lo que le
        // falta desde su posición real. `resto_min` es lo que sigue después de
        // llegar (descarga y vuelta), que no depende de dónde esté ahora.
        $tramo = is_object($e->tramo_en_curso ?? null) ? $e->tramo_en_curso : null;

        if ($cerrada) {
            $tFin = $fechaAncla->copy();
        } elseif ($tramo !== null) {
            $restanteAhora = min(self::TOPE_ETAPA,
                max(1, (int) ($tramo->min ?? 0)) + max(0, (int) ($tramo->resto_min ?? 0)));
            $tFin = $ahora->copy()->addMinutes($restanteAhora);
            $restante = max(0, (int) round($fechaAncla->diffInMinutes($tFin)));
        } else {
            $restante = self::faltantes($hito, $etapas);
            $faltaron = [];

            // D1: si falta UNA etapa no se tira todo. Antes, una ruta sin E5 (la cola
            // en el destino) hacía que el motor asumiera "libre ahora" y perdiera E4
            // y E6, que sí estaban medidos.
            if ($restante === null) {
                $parcial = self::faltantes_parcial($hito, $etapas);
                if ($parcial !== null && $parcial['min'] > 0) {
                    $restante = $parcial['min'];
                    $faltaron = $parcial['faltan'];
                }
            }

            if ($faltaron !== [] && $restante !== null) {
                $salida->advertencias[] = 'etapas_incompletas: la ruta del viaje en curso no tiene '
                    . implode(' + ', $faltaron) . '; se sumó lo que hay (' . Tiempo::hhmm($restante) . ')';
            }

            if ($restante === null) {
                $tFin = $ahora->copy();
                $salida->advertencias[] = 'etapas_incompletas: la ruta del viaje en curso no tiene los promedios E2..E6; se asume que el camión queda libre ahora';
            } elseif ((int) ($p->descontar_transcurrido ?? 1) === 1) {
                // Lo que el camión YA lleva manejado se descuenta: lo que le falta
                // es la mediana de la etapa menos lo transcurrido. (Antes se sumaba
                // la etapa entera desde el hito y, si ya estaba vencida, se
                // recortaba a 0, o sea "ya llegó".)
                $edad = max(0, (int) round($fechaAncla->diffInMinutes($ahora)));
                $queda = $restante - $edad;

                if ($queda > 0) {
                    $tFin = $ahora->copy()->addMinutes($queda);
                    $notaRestante = 'al viaje le faltan ' . Tiempo::hhmm($queda) . ': la mediana de su ruta ('
                        . Tiempo::hhmm($restante) . ') menos ' . Tiempo::hhmm($edad) . ' ya transcurridos';
                } else {
                    // la mediana ya se consumió: se espera hasta el p75 medido de la
                    // etapa en vez de dar por hecho que ya descargó
                    $tope = self::faltantes_p75($hito, $etapas) ?? $restante;
                    $espera = max(0, $tope - $edad);
                    $tFin = $ahora->copy()->addMinutes($espera);
                    $salida->advertencias[] = 'etapa_vencida: la mediana de la etapa (' . Tiempo::hhmm($restante)
                        . ') se consumió hace ' . Tiempo::hhmm($edad - $restante)
                        . '; se espera hasta el p75 de la etapa (' . Tiempo::hhmm($tope) . ')'
                        . ($espera > 0 ? ': ' . Tiempo::hhmm($espera) . ' más' : ' y tampoco alcanza, se cuenta desde ahora');
                }

                $restante = max(0, (int) round($fechaAncla->diffInMinutes($tFin)));
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

        // C2 — EL DÍA EN QUE PRESENTA MUEVE EL HUECO. De dónde sale el factor lo
        // decide `dia_modo` (ver Calibrador::factores_dia() y
        // DBPrediccion::factores_dia_compilado()): puede venir de la placa, del
        // prior de la flota o del compilado por día de la ruta. Un feriado usa el
        // peor factor de la semana.
        //
        // GUARDIA DE CIRCULARIDAD: el factor se elige por el día en que CAE la
        // presentación, pero multiplicar el hueco mueve la fecha, y puede caer en
        // otro día — donde ese factor ya no corresponde. En ese caso se deshace la
        // corrección en vez de iterar (un hueco de 800 min que cae lunes con
        // factor 2,68 se iba a 2.144 y terminaba el miércoles).
        $notaDia = null;
        $factoresDia = is_array($e->factor_dia ?? null) ? $e->factor_dia : [];
        if ($factoresDia !== []) {
            // `dia_modo = 4` usa el día del ANCLA: se conoce antes de corregir, así
            // que no hay circularidad y no hace falta el guardia de abajo.
            $porAncla = (int) ($e->dia_modo ?? Calibrador::DIA_FLOTA) === Calibrador::DIA_ANCLA;
            $diaPresenta = $porAncla ? $tFin->copy() : $tFin->copy()->addMinutes($hueco);
            $indice = (int) $diaPresenta->format('N') - 1;
            $factor = (float) ($factoresDia[$indice] ?? 1.0);

            $feriado = (int) ($e->feriado_activo ?? 1) === 1 && Feriados::es_feriado($diaPresenta);
            if ($feriado) {
                $factor = max($factor, (float) ($factoresDia[0] ?? 1.0));
            }

            if ($factor > 0.0 && abs($factor - 1.0) > 0.005) {
                $topeHueco = max(1, (int) ($p->tope_hueco_min ?? Calibrador::TOPE_HUECO));
                $conFactor = max(1, min($topeHueco, (int) round($hueco * $factor)));
                $indiceAplicado = (int) $tFin->copy()->addMinutes($conFactor)->format('N') - 1;

                if ($porAncla || $indiceAplicado === $indice) {
                    $hueco = $conFactor;
                    $fuenteHueco .= ' · día ' . self::DIA[$indice] . ($feriado ? ' (feriado)' : '')
                        . ' ×' . number_format($factor, 2);
                    $notaDia = 'el día en que presenta (' . self::DIA[$indice] . ($feriado ? ', feriado' : '')
                        . ') tiene el hueco ×' . number_format($factor, 2)
                        . ' según los viajes reales de esa placa o de la flota';
                    $salida->factor_dia = (object) [
                        'dia' => $indice,
                        'factor' => $factor,
                        'feriado' => $feriado,
                    ];
                } else {
                    $salida->advertencias[] = 'factor_dia_descartado: el factor del día (×'
                        . number_format($factor, 2) . ') movía la presentación de '
                        . self::DIA[$indice] . ' a ' . self::DIA[$indiceAplicado]
                        . ', donde el factor ya no corresponde; se deja el hueco medido';
                }
            }
        }

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

        // CUARTA FECHA: la hora publicada corregida con la mediana del error de su
        // clase (evidencia x tramo del horizonte). Si `sesgo_clase_activo` está en
        // 0, es igual a la publicada: el motor no cambia.
        //
        // El horizonte es `presenta − ahora` (lo que falta desde el corte), NO desde
        // el ancla: así la clase significa lo mismo en el backtest y en producción, y
        // la tabla de sesgos se puede medir con las predicciones guardadas.
        //
        // Se calcula ANTES de la banda porque la banda rápida/tardado se dibuja
        // sobre la hora que se va a usar, no sobre la que se descartó.
        $horizontePredicho = (int) round(($t->getTimestamp() - $ahora->getTimestamp()) / 60);
        $sesgoMin = Sesgo::desplazamiento($evidencia, $horizontePredicho, $p);
        $salida->presenta_probable = $sesgoMin === 0 ? $t->copy() : $t->copy()->subMinutes($sesgoMin);
        $salida->sesgo_clase = Sesgo::clase($evidencia, $horizontePredicho);
        $salida->sesgo_min = $sesgoMin;

        // las otras dos fechas: rápido y tardado.
        // Fuente preferida: los RESIDUOS medidos del backtest (radio alrededor de
        // la hora publicada), que sí contienen el valor real; si no hay medición,
        // los cuantiles del hueco (p25/p75) o el porcentaje de la cadena.
        $ventanaMedida = is_object($e->ventana_residuos ?? null) ? $e->ventana_residuos : null;
        $radio = self::radio_ventana($ventanaMedida, $evidencia);

        if ($radio !== null) {
            // La banda medida va centrada en la HORA PUBLICADA, que es sobre lo que
            // se midió el residuo. Antes se armaba sobre el hueco y después se
            // recortaba contra el traslado y el propio hueco: con un radio de
            // ±1.349 y un hueco mediano de 620, el "rápido" caía en el traslado y la
            // banda quedaba de 787 min de ancho en vez de 2.698 (medido: 100 % de
            // las filas asimétricas y la cobertura se quedaba en 32 % aunque la
            // medición prometiera 70 %).
            //
            // Centro = la CUARTA fecha cuando el sesgo está aplicado: la banda se
            // MIDE contra ella (`ventana_residuos` usa su residuo), así que
            // dibujarla sobre la normal la deja corrida por toda la corrección de
            // la clase. Medido (1-oct..10-nov, ±1.349/±2.086, sesgo aplicado):
            // sobre la corregida la banda cubre 66 % y las filas que caen después
            // del tardado bajan de 100 a 91.
            $centro = $salida->presenta_probable;

            $rapido = $centro->copy()->subMinutes($radio['adelanto']);
            $tardado = $centro->copy()->addMinutes($radio['atraso']);

            if ($rapido->lessThan($ahora)) {
                $rapido = $ahora->copy();
            }
            // el tardado nunca queda antes de la hora publicada ni del centro
            if ($tardado->lessThan($t)) {
                $tardado = $t->copy();
            }
            if ($tardado->lessThan($centro)) {
                $tardado = $centro->copy();
            }
        } else {
            $extremos = self::huecos_extremos($hueco, $cal, $trasladoMin, $p, null, $evidencia);
            $rapido = self::fecha_con_hueco($tFin, $extremos['rapido'], $ventanas, $p, $ahora);
            $tardado = self::fecha_con_hueco($tFin, $extremos['tardado'], $ventanas, $p, $ahora);
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
            // OJO con el signo: en Carbon 3 `$t->diffInMinutes($ahora)` da
            // $ahora - $t, o sea negativo cuando la predicción está en el futuro.
            // El horizonte es lo que falta desde ahora hasta la presentación.
            'horizonte_min' => (int) round($ahora->diffInMinutes($t)),
            'parametros' => $p,
        ]);

        $salida->presenta = $t;
        $salida->presenta_rapido = $rapido;
        $salida->presenta_tardado = $tardado;

        // QUINTA fecha: el CONSENSO de la rápida, la normal y la cuarta. Medido en
        // las guardadas: acierta más por tolerancia que cualquiera de las tres
        // (octubre ±3 h 27 % contra 24 % de la mejor suelta; noviembre 32 % contra
        // 31 %) porque promediar baja la varianza, y pierde la carrera de "quién
        // quedó más cerca" (16-20 % contra 31-35 %), porque el promedio de tres
        // horas casi nunca es el extremo. Se publica para poder MEDIRLO.
        $salida->presenta_consenso = self::consenso($ahora, $rapido, $t, $salida->presenta_probable);

        $salida->presentaciones = self::presentaciones($ahora, $rapido, $t, $tardado, $salida->presenta_probable,
            $salida->presenta_consenso, $cal, $ventanaMedida !== null ? 'residuos' : null);
        $salida->inicio_carga = $t->copy()->addMinutes($espera);
        $salida->espera_min = $espera;
        $salida->evidencia = $evidencia;
        $salida->confianza = $confianza->pct;
        $salida->confianza_motivo = $confianza->motivo;
        $salida->desglose = self::desglose($cerrada, $restante, $hueco, $fuenteHueco, $traslado, $trasladoMin,
            $tiempoMuerto, $esCadena, $esperaVentana, $espera,
            $tramo !== null ? 'ubicación declarada → destino (' . (string) ($tramo->fuente ?? 'sin dato') . ')'
                : 'etapas de la ruta');

        // el desglose tiene que reconstruir la hora publicada, incluso cuando un
        // recorte (atrasado, etapa vencida) movió el resultado
        $suma = 0;
        foreach ($salida->desglose as $fila) {
            if (in_array($fila['concepto'], ['restante_viaje', 'hueco_total', 'espera_ventana'], true)) {
                $suma += $fila['min'];
            }
        }
        $desdeAncla = (int) round(($t->getTimestamp() - $fechaAncla->getTimestamp()) / 60);
        if ($desdeAncla !== $suma) {
            $salida->desglose[] = [
                'concepto' => 'ajuste',
                'min' => $desdeAncla - $suma,
                'fuente' => 'recorte a la hora de corte',
            ];
        }
        $salida->ventana = $ventanaMedida !== null
            ? ['desde' => $rapido->copy(), 'hasta' => $tardado->copy()]
            : self::ventana($t, $cal);
        // de dónde salió la ventana: se mide la del backtest o el p25-p75 del hueco
        $salida->ventana_fuente = $ventanaMedida !== null ? 'residuos' : 'p25_p75_hueco';
        $salida->ventana_cobertura = $ventanaMedida !== null ? ($ventanaMedida->cobertura ?? null) : null;
        $salida->supuestos = self::supuestos($cerrada, $esCadena, $cal, $traslado, $espera, $p, $tramo);
        if ($notaRestante !== null) {
            $salida->supuestos[] = $notaRestante;
        }
        if ($notaDia !== null) {
            $salida->supuestos[] = $notaDia;
        }
        $salida->pasos = self::pasos($e, $salida, $fechaAncla, $t, $restante, $hueco, $trasladoMin, $tiempoMuerto,
            $esperaVentana, $espera, $cerrada);
        $salida->duracion_total = Tiempo::hhmm((int) round(($t->getTimestamp() - $fechaAncla->getTimestamp()) / 60));

        return $salida;
    }

    /**
     * Radio de la ventana medido con los residuos del backtest, o NULL si no hay
     * medición (o está en cero). La evidencia `cadena` tiene su propia banda.
     *
     * @return array{adelanto: int, atraso: int}|null
     */
    private static function radio_ventana(?object $ventana, string $evidencia): ?array
    {
        if ($ventana === null) {
            return null;
        }

        $adelanto = max(0, (int) ($ventana->adelanto ?? 0));
        $atraso = max(0, (int) ($ventana->atraso ?? 0));

        if ($evidencia === 'cadena') {
            $adelanto = max(0, (int) ($ventana->adelanto_cadena ?? 0)) ?: $adelanto;
            $atraso = max(0, (int) ($ventana->atraso_cadena ?? 0)) ?: $atraso;
        }

        return ($adelanto + $atraso) > 0 ? ['adelanto' => $adelanto, 'atraso' => $atraso] : null;
    }

    /**
     * Los dos huecos extremos (rápido y tardado) cuando NO hay ventana medida.
     *
     * Tres fuentes, en orden de preferencia:
     *  1. `$ventana` (residuos medidos en el backtest): la hora publicada menos el
     *     adelanto medido y más el atraso medido. OJO: la banda medida se aplica
     *     centrada en la hora publicada en el flujo principal (ver
     *     `radio_ventana()`), no acá, porque acá los recortes de abajo la rompen.
     *  2. los cuantiles del hueco de la placa/chofer (p25 y p75);
     *  3. el porcentaje de la cadena (`spread_cadena`) cuando no hay pares.
     *
     * En los tres casos el rápido nunca queda por debajo del traslado (el camión
     * no puede presentarse antes de lo que tarda volver) ni el tardado por debajo
     * del hueco.
     *
     * @return array{rapido: int, tardado: int}
     */
    private static function huecos_extremos(int $hueco, ?object $cal, ?int $trasladoMin, object $p,
                                            ?object $ventana = null, string $evidencia = 'cadena'): array
    {
        $tope = max(1, (int) ($p->tope_hueco_min ?? Calibrador::TOPE_HUECO));
        $radio = self::radio_ventana($ventana, $evidencia);

        if ($radio !== null) {
            $rapido = $hueco - $radio['adelanto'];
            $tardado = $hueco + $radio['atraso'];
        } elseif ($cal !== null && ($cal->p25_min ?? null) !== null && ($cal->p75_min ?? null) !== null) {
            $rapido = (int) $cal->p25_min;
            $tardado = (int) $cal->p75_min;
        } else {
            $spread = (float) ($p->spread_cadena ?? 0.30);
            $spread = max(0.0, min(1.0, $spread));
            $rapido = (int) round($hueco * (1 - $spread));
            $tardado = (int) round($hueco * (1 + $spread));
        }

        $rapido = max(1, min($hueco, max($rapido, (int) ($trasladoMin ?? 0))));
        $tardado = max($hueco, min($tope, $tardado));

        return ['rapido' => $rapido, 'tardado' => $tardado];
    }

    /** Igual que la fecha normal: hueco + franjas inactivas + recorte a ahora. */
    private static function fecha_con_hueco(Carbon $tFin, int $hueco, array $ventanas, object $p, Carbon $ahora): Carbon
    {
        $fecha = $tFin->copy()->addMinutes($hueco);
        $desplazado = Ventanas::desplazar($fecha, $ventanas, max(0, (int) ($p->max_desplazamiento_min ?? 600)));
        $fecha = $desplazado->fecha;

        return $fecha->lessThan($ahora) ? $ahora->copy() : $fecha;
    }

    /**
     * Las CUATRO fechas listas para la pantalla y el JSON, con su etiqueta y su
     * distancia al corte en h:mm.
     *
     * La cuarta (`probable`) es la hora publicada corregida por el sesgo medido de
     * su clase. Cuando el sesgo está apagado es igual a la normal y se marca así,
     * para no prometer una precisión que no existe.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * El CONSENSO: la hora promedio de la rápida, la normal y la cuarta.
     *
     * Se promedia en MINUTOS DESDE EL CORTE (no con timestamps) para no depender
     * de la zona horaria y para que el resultado caiga siempre en el mismo eje
     * que las otras cuatro. Sin sesgo aplicado la cuarta es igual a la normal, así
     * que el consenso queda entre la rápida y la normal.
     */
    public static function consenso(Carbon $ahora, Carbon ...$fechas): Carbon
    {
        $suma = 0;
        foreach ($fechas as $fecha) {
            $suma += (int) round(($fecha->getTimestamp() - $ahora->getTimestamp()) / 60);
        }

        return $ahora->copy()->addMinutes((int) round($suma / max(1, count($fechas))));
    }

    private static function presentaciones(Carbon $ahora, Carbon $rapido, Carbon $normal, Carbon $tardado,
                                          Carbon $probable, ?Carbon $consenso, ?object $cal,
                                          ?string $fuente = null): array
    {
        $desde = fn (Carbon $fecha) => Tiempo::hhmm((int) round(($fecha->getTimestamp() - $ahora->getTimestamp()) / 60));
        $nivel = $fuente === 'residuos'
            ? 'medido con el error real del motor'
            : ($cal !== null ? 'cuantiles medidos (p25 / mediana / p75)' : 'porcentaje sobre la cadena');
        $corregida = $probable->getTimestamp() !== $normal->getTimestamp();

        return [
            [
                'clave' => 'rapido',
                'etiqueta' => 'Rápido',
                'descripcion' => 'si vuelve como sus mejores viajes (' . $nivel . ')',
                'fecha' => $rapido->format('Y-m-d H:i:s'),
                'desde_ahora' => $desde($rapido),
            ],
            [
                'clave' => 'normal',
                'etiqueta' => 'Normal',
                'descripcion' => 'la estimación central, la que se usa para planificar',
                'fecha' => $normal->format('Y-m-d H:i:s'),
                'desde_ahora' => $desde($normal),
            ],
            [
                'clave' => 'tardado',
                'etiqueta' => 'Tardado',
                'descripcion' => 'si se le cruza un descanso largo, una cola o un viaje que se estira'
                    . ($fuente === 'residuos' ? ' (' . $nivel . ')' : ''),
                'fecha' => $tardado->format('Y-m-d H:i:s'),
                'desde_ahora' => $desde($tardado),
            ],
            [
                'clave' => 'probable',
                'etiqueta' => 'Más probable',
                'descripcion' => $corregida
                    ? 'la hora publicada corregida con el sesgo medido de este tipo de caso'
                    : 'igual a la normal: el sesgo por clase todavía no está medido',
                'fecha' => $probable->format('Y-m-d H:i:s'),
                'desde_ahora' => $desde($probable),
            ],
            [
                'clave' => 'consenso',
                'etiqueta' => 'Consenso',
                'descripcion' => 'el promedio de la rápida, la normal y la más probable:'
                    . ' acierta más por tolerancia, pero casi nunca es la más cercana',
                'fecha' => ($consenso ?? $normal)->format('Y-m-d H:i:s'),
                'desde_ahora' => $desde($consenso ?? $normal),
            ],
        ];
    }

    /**
     * Los pasos que arman la predicción, con nombres y tiempos en h:mm.
     *
     * Es lo que se muestra en la pantalla y lo que viaja en el JSON: la hora sin
     * el recorrido no le sirve a nadie para decidir.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pasos(stdClass $e, stdClass $salida, Carbon $fechaAncla, Carbon $t, ?int $restante,
                                  int $hueco, ?int $trasladoMin, int $tiempoMuerto, int $esperaVentana,
                                  int $espera, bool $cerrada): array
    {
        $pasos = [];
        $planta = (string) ($e->planta_nombre ?? 'la planta de carga');
        $traslado = $e->traslado ?? null;
        $acumulado = 0;

        /** Cada paso de duración trae SU fecha (ancla + acumulado), para que una
         *  línea de tiempo se arme sin recalcular nada y siempre cierre. */
        $agregar = function (string $concepto, string $titulo, string $detalle, int $min) use (
            &$pasos, &$acumulado, $fechaAncla
        ): void {
            $acumulado += $min;
            $pasos[] = [
                'concepto' => $concepto,
                'titulo' => $titulo,
                'detalle' => $detalle,
                'fecha' => $fechaAncla->copy()->addMinutes($acumulado)->format('Y-m-d H:i:s'),
                'min' => $min,
                'duracion' => Tiempo::hhmm($min),
                'acumulado_min' => $acumulado,
            ];
        };

        $pasos[] = [
            'concepto' => 'ancla',
            'titulo' => 'Punto de partida',
            'detalle' => (string) ($e->ancla_texto ?? ('Último hito: ' . Tiempo::hito($salida->ancla->hito ?? null))),
            'fecha' => $fechaAncla->format('Y-m-d H:i:s'),
            'min' => 0,
            'duracion' => Tiempo::hhmm(0),
            'acumulado_min' => 0,
        ];

        if (!$cerrada && $restante !== null && $restante > 0) {
            $tramo = is_object($e->tramo_en_curso ?? null) ? $e->tramo_en_curso : null;
            $resto = (int) ($tramo->resto_min ?? 0);

            if ($tramo !== null) {
                $yaTranscurrido = max(0, $restante - max(1, (int) ($tramo->min ?? 0)) - $resto);
                $detalle = 'manejo desde '
                    . (string) ($tramo->desde ?? 'la ubicación declarada') . ' hasta '
                    . (string) ($tramo->hasta ?? 'el destino del viaje') . ': '
                    . Tiempo::hhmm((int) ($tramo->min ?? 0))
                    . (isset($tramo->km) && $tramo->km !== null ? ' · ' . $tramo->km . ' km' : '')
                    . ' · fuente: ' . (string) ($tramo->fuente ?? 'sin dato');
                if ($resto > 0) {
                    $detalle .= ' · después, descarga y vuelta al destino: ' . Tiempo::hhmm($resto);
                }
                if ($yaTranscurrido > 0) {
                    $detalle .= ' · ya transcurrido desde el punto de partida: ' . Tiempo::hhmm($yaTranscurrido);
                }
            } else {
                $detalle = 'le faltan las etapas que siguen a '
                    . Tiempo::hito($salida->ancla->hito ?? null)
                    . ' en su ruta (medianas de la ruta)';
            }

            $agregar('restante_viaje', 'Falta del viaje en curso', $detalle, $restante);
        }

        $agregar('traslado', 'Traslado de regreso a ' . $planta,
            trim((string) ($traslado->texto ?? 'tramo estimado'))
            . (isset($traslado->km) && $traslado->km !== null ? ' · ' . $traslado->km . ' km' : '')
            . ' · fuente: ' . (string) ($traslado->fuente ?? 'sin dato'),
            (int) ($trasladoMin ?? 0));

        $agregar('tiempo_muerto', 'Espera y descanso del conductor',
            'hueco real medido (' . Tiempo::hhmm($hueco) . ') menos el traslado', $tiempoMuerto);

        if ($esperaVentana > 0) {
            $agregar('espera_ventana', 'Corrimiento por franja inactiva',
                'la hora calculada caía en una franja de descanso o almuerzo', $esperaVentana);
        }

        // Si la hora calculada se recortó (atrasado) o la etapa ya estaba vencida,
        // la suma de los pasos no llega sola a `presenta`: sin esta fila la línea
        // de tiempo muestra fechas que no cierran (era el bug del "Se presenta").
        $total = (int) round(($t->getTimestamp() - $fechaAncla->getTimestamp()) / 60);
        $ajuste = $total - $acumulado;
        if ($ajuste !== 0) {
            $agregar('ajuste', 'Recorte a la hora de corte',
                'la hora calculada caía antes de "ahora" (atrasado) o la etapa ya estaba vencida',
                $ajuste);
        }

        $pasos[] = [
            'concepto' => 'presenta',
            'titulo' => 'Se presenta en ' . $planta,
            'detalle' => 'suma de los pasos anteriores desde el punto de partida',
            'fecha' => $t->format('Y-m-d H:i:s'),
            'min' => $total,
            'duracion' => Tiempo::hhmm($total),
            'acumulado_min' => $total,
        ];

        $acumulado = $total;

        $agregar('espera_carga', 'Espera para cargar en ' . $planta,
            'espera en planta (fuera + dentro)', $espera);

        $inicioCarga = $t->copy()->addMinutes($espera);
        $pasos[] = [
            'concepto' => 'inicio_carga',
            'titulo' => 'Empieza a cargar',
            'detalle' => 'presenta + espera en planta',
            'fecha' => $inicioCarga->format('Y-m-d H:i:s'),
            'min' => (int) round(($inicioCarga->getTimestamp() - $fechaAncla->getTimestamp()) / 60),
            'duracion' => Tiempo::hhmm((int) round(($inicioCarga->getTimestamp() - $fechaAncla->getTimestamp()) / 60)),
            'acumulado_min' => (int) round(($inicioCarga->getTimestamp() - $fechaAncla->getTimestamp()) / 60),
        ];

        foreach ($pasos as $indice => $paso) {
            $pasos[$indice]['n'] = $indice + 1;
        }

        return $pasos;
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
     * Lo mismo que `faltantes()` pero sin exigir todas las etapas: suma las que
     * existen y dice cuáles faltaron (D1).
     *
     * Antes, si la ruta no tenía una sola etapa (por ejemplo E5, la cola en el
     * destino), el motor asumía que el camión quedaba libre ahora y perdía las
     * otras cuatro, que sí estaban medidas. Devuelve null sólo si no hay ninguna.
     *
     * @param  array<string, int|float|string|null>  $etapas
     * @return array{min: int, faltan: array<int, string>}|null
     */
    public static function faltantes_parcial(?string $hito, array $etapas): ?array
    {
        if ($hito === null) {
            return null;
        }

        $claves = self::FALTANTES[$hito] ?? null;
        if ($claves === null) {
            return null;
        }

        $total = 0;
        $faltan = [];
        $usadas = 0;

        foreach ($claves as $clave) {
            $valor = $etapas[$clave] ?? null;
            if ($valor === null || !is_numeric($valor)) {
                $faltan[] = strtoupper($clave);

                continue;
            }

            $usadas++;
            $total += min(self::TOPE_ETAPA, max(0, (int) round((float) $valor)));
        }

        if ($usadas === 0) {
            return null;
        }

        return ['min' => min(self::TOPE_ETAPA, $total), 'faltan' => $faltan];
    }

    /**
     * Minutos de las etapas que siguen a la ida al destino (descarga y vuelta),
     * sin contar la etapa E4. Se usan cuando la ida se calcula desde la posición
     * declarada del camión: esa etapa ya no sale de la mediana.
     *
     * NULL si el hito no tiene etapas conocidas o si falta alguna de ellas (no
     * se adivina media descarga).
     *
     * @param  array<string, int|float|string|null>  $etapas
     */
    public static function faltantes_sin_viaje(?string $hito, array $etapas): ?int
    {
        if ($hito === null) {
            return null;
        }

        $claves = self::FALTANTES[$hito] ?? null;
        if ($claves === null) {
            return null;
        }

        $total = 0;
        foreach (array_diff($claves, ['e4']) as $clave) {
            $valor = $etapas[$clave] ?? null;
            if ($valor === null || !is_numeric($valor)) {
                return null;
            }
            $total += min(self::TOPE_ETAPA, max(0, (int) round((float) $valor)));
        }

        return min(self::TOPE_ETAPA, $total);
    }

    /**
     * Lo mismo que `faltantes()` pero con el P75 de cada etapa cuando el
     * compilado lo trae (`e4_p75` del viaje por ruta, `e5_p75`/`e6_p75` de la
     * planta).
     *
     * Es el techo con el que se espera cuando la mediana ya se consumió: un
     * camión que va más lento que la mediana no "ya llegó", va con retraso.
     * NULL si falta algún promedio, igual que `faltantes()`.
     *
     * @param  array<string, int|float|string|null>  $etapas
     */
    public static function faltantes_p75(?string $hito, array $etapas): ?int
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
            $valor = $etapas[$clave . '_p75'] ?? $etapas[$clave] ?? null;
            if ($valor === null || !is_numeric($valor)) {
                return null;
            }
            $total += min(self::TOPE_ETAPA, max(0, (int) round((float) $valor)));
        }

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
            'pasos' => [],
            'duracion_total' => null,
            'presenta_rapido' => null,
            'presenta_tardado' => null,
            'presentaciones' => [],
        ];
    }

    /** @return array<int, array{concepto: string, min: int, fuente: string}> */
    private static function desglose(bool $cerrada, ?int $restante, int $hueco, string $fuenteHueco, ?object $traslado,
                                     ?int $trasladoMin, int $tiempoMuerto, bool $esCadena, int $esperaVentana,
                                     int $espera, string $fuenteRestante = 'etapas de la ruta'): array
    {
        $filas = [];

        if (!$cerrada) {
            $filas[] = ['concepto' => 'restante_viaje', 'min' => (int) ($restante ?? 0), 'fuente' => $fuenteRestante];
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
                                      object $p, ?object $tramo = null): array
    {
        $supuestos = [];

        $supuestos[] = $cerrada
            ? 'el camión ya cerró su viaje anterior: el hueco se cuenta desde su fin de descargue'
            : 'el camión sigue en viaje: se suman las etapas que le faltan con las medianas de su ruta (tiempo transcurrido, incluye paradas)';

        if ($tramo !== null) {
            $supuestos[] = 'lo que le falta del viaje se cuenta desde la ubicación declarada hasta '
                . (string) ($tramo->hasta ?? 'el destino') . ' (' . Tiempo::hhmm((int) ($tramo->min ?? 0))
                . (isset($tramo->km) && $tramo->km !== null ? ', ' . $tramo->km . ' km' : '')
                . '): es manejo de camión por carretera, sin las paradas que incluye la mediana de la flota';
        }

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
