<?php

namespace App\Prediccion;

use App\Database\DBPrediccion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Orquestador del módulo: junta la capa de datos con el motor puro y arma lo que
 * consumen la pantalla, la API y la tabla de aciertos.
 *
 * Reglas que se respetan acá (y no en el motor, que no sabe de base de datos):
 *  - el ancla es el último viaje del camión ANTERIOR a la referencia a predecir;
 *  - la calibración del hueco se aprende sólo con datos anteriores al corte:
 *    en la tabla de aciertos eso es el inicio del rango evaluado, para que el
 *    motor nunca vea el `presenta_para_carga` que tiene que adivinar;
 *  - las franjas de no-actividad son del camión, y si su historia es muy corta o
 *    el perfil sale absurdo (más de 60 % del día inactivo) se usa la noche por
 *    defecto en vez de un perfil que empujaría todo al día siguiente.
 */
class Servicio
{
    /** Ventanas de respaldo cuando la placa no tiene historia creíble. */
    public const VENTANAS_DEFECTO = [
        ['desde' => 1320, 'hasta' => 1440],   // 22:00 - 24:00
        ['desde' => 0, 'hasta' => 360],       // 00:00 - 06:00
    ];

    /**
     * Hitos que no sirven para anclar la predicción: `compromiso_carga` es una
     * cita del plan (puede ser posterior a la presentación real), no un evento
     * del camión.
     */
    public const HITOS_NO_ANCLA = ['compromiso_carga'];

    /**
     * Predicción de una referencia concreta.
     *
     * Opciones: ahora (Carbon|string), ancla_ref (string), ubicacion_tipo
     * (auto|local|ubigeo|indeterminado), ubicacion_id (string).
     */
    public static function predecir(string $ref, array $opciones = []): stdClass
    {
        $p = Parametros::cargar();
        // cuántas peticiones a Geoapify gasta ESTA predicción (normalmente 0:
        // sólo se llama cuando no hay ningún promedio compilado del par)
        $llamadasGeo = Geoapify::llamadas();
        $ahora = self::carbon($opciones['ahora'] ?? null) ?? Carbon::now();
        $ref = trim($ref);
        $fila = $ref !== '' ? DBPrediccion::ref($ref) : null;

        if ($fila === null) {
            return (object) [
                'ref' => $ref,
                'error' => 'ref_no_encontrada',
                'presenta' => null,
                'desglose' => [],
                'supuestos' => [],
                'advertencias' => ['ref_no_encontrada: la referencia no existe en la base'],
                'candidatos' => [],
                'historia' => null,
            ];
        }

        // La ref ya pasó por la planta de carga pero no tiene
        // `presenta_para_carga`: el hito que habría que adivinar ya ocurrió (o
        // nunca se registró). Predecirlo sería inventar una hora que ya pasó;
        // se dice y se corta. Pasa en el dato real: ~1.400 refs con inicio_ruta
        // y posteriores pero sin presenta.
        $hitoPosterior = null;
        foreach (['inicio_de_carga', 'fin_de_carga', 'inicio_ruta', 'qr_llegada_destino',
                  'inicio_descargue', 'fin_descargue'] as $hito) {
            if (!empty($fila->{$hito})) {
                $hitoPosterior = $hito;
                break;
            }
        }

        if (empty($fila->presenta_para_carga) && $hitoPosterior !== null) {
            return (object) [
                'ref' => $ref,
                'error' => 'ya_en_curso',
                'placa' => (string) ($fila->placa ?? ''),
                'ahora' => $ahora,
                'presenta' => null,
                'desglose' => [],
                'supuestos' => [],
                'advertencias' => [
                    'ya_en_curso: la referencia no tiene `presenta_para_carga` pero sí hitos posteriores ('
                    . Tiempo::hito($hitoPosterior) . ' del '
                    . Carbon::parse((string) $fila->{$hitoPosterior})->format('d/m H:i')
                    . '): el camión ya cargó y va camino del destino, así que no hay una presentación que '
                    . 'adivinar. Para estimar la próxima carga, usá este viaje como ancla y la ref siguiente.',
                ],
                'candidatos' => [],
                'historia' => null,
            ];
        }

        // Sin ruta no hay nada que predecir: de la ruta salen la planta de carga
        // (origen), su ubigeo y la espera. Predecir sólo con el hueco medido
        // sería inventar el tramo que falta; se rechaza antes de calcular.
        $rutaRef = (string) ($fila->ruta_id ?? '');
        if ($rutaRef === '') {
            return (object) [
                'ref' => $ref,
                'error' => 'sin_ruta',
                'placa' => (string) ($fila->placa ?? ''),
                'ahora' => $ahora,
                'presenta' => null,
                'desglose' => [],
                'supuestos' => [],
                'advertencias' => [
                    'sin_ruta: la referencia no tiene ruta asignada; sin planta de carga no hay traslado, '
                    . 'ni espera en planta, ni forma de saber adónde vuelve el camión. No se predice.',
                ],
                'candidatos' => [],
                'historia' => null,
            ];
        }

        $placa = (string) $fila->placa;
        $desde = $ahora->copy()->subDays(max(1, (int) $p->lookback_dias));

        $candidatos = self::candidatos_viaje($placa, $ahora, 40);
        $candidatos = array_values(array_filter($candidatos, fn (stdClass $viaje) => $viaje->ref !== $ref));

        $ancla = null;
        $pedida = isset($opciones['ancla_ref']) ? (string) $opciones['ancla_ref'] : '';
        foreach ($candidatos as $viaje) {
            if ($pedida !== '' && $viaje->ref === $pedida) {
                $ancla = $viaje;
                break;
            }
        }
        if ($ancla === null && $pedida === '' && $candidatos !== []) {
            $ancla = $candidatos[0];
        }

        // se pidió un ancla concreta y no es un viaje de este camión antes del
        // corte: mejor decirlo que predecir desde otro lado sin avisar
        $anclaPedidaNoEncontrada = $pedida !== '' && $ancla === null;

        // ($rutaRef ya quedó arriba: la ref sin ruta no llega hasta acá)
        $plantaCarga = (string) ($fila->origen_id ?? '');
        $etapasPorRuta = DBPrediccion::etapas_por_ruta($desde, $ahora);

        // desde dónde vuelve el camión: su última posición conocida o lo que declare el usuario
        $desdePlanta = $ancla !== null ? (string) ($ancla->destino_id ?? '') : '';
        $desdeUbigeo = $ancla !== null ? (string) ($ancla->ubigeo_destino ?? '') : '';

        $ubicacionTipo = (string) ($opciones['ubicacion_tipo'] ?? 'auto');
        $ubicacionId = trim((string) ($opciones['ubicacion_id'] ?? ''));
        $ubicacionDeclarada = in_array($ubicacionTipo, ['local', 'ubigeo'], true) && $ubicacionId !== '';

        if ($ubicacionTipo === 'local' && $ubicacionId !== '') {
            $desdePlanta = $ubicacionId;
            $desdeUbigeo = self::ubigeo_local($ubicacionId);
        } elseif ($ubicacionTipo === 'ubigeo' && $ubicacionId !== '') {
            $desdePlanta = '';
            $desdeUbigeo = $ubicacionId;
        } elseif ($ubicacionTipo === 'indeterminado') {
            $desdePlanta = '';
            $desdeUbigeo = '';
        }

        // ¿el ancla sirve? Si el camión no tiene viaje en curso (o su último hito
        // es más viejo que el tope del hueco) se arranca desde la ubicación
        // declarada: el cálculo pasa a ser traslado + tiempo muerto desde ahora.
        $topeAncla = max(1, (int) $p->tope_hueco_min);
        $edadAncla = ($ancla !== null && $ancla->fecha instanceof Carbon)
            ? (int) round($ancla->fecha->diffInMinutes($ahora))
            : null;
        $anclaUtil = $edadAncla !== null && $edadAncla <= $topeAncla;

        $anclaDeclarada = false;
        if (!$anclaUtil) {
            if ($ubicacionDeclarada) {
                $ancla = (object) [
                    'ref' => '', 'hito' => 'ubicacion_declarada', 'fecha' => $ahora->copy(),
                    'cerrado' => true, 'ruta_id' => '', 'destino_id' => '', 'ubigeo_destino' => '',
                    'edad_min' => 0,
                ];
                $anclaDeclarada = true;
            } else {
                $ancla = null;
            }
        }

        $traslado = $plantaCarga !== ''
            ? DBPrediccion::traslado($plantaCarga, $desdePlanta !== '' ? $desdePlanta : null,
                $desdeUbigeo !== '' ? $desdeUbigeo : null, (string) ($fila->ubigeo_origen ?? ''),
                $etapasPorRuta, $rutaRef !== '' ? $rutaRef : null, (int) $p->traslado_defecto_min,
                $ubicacionDeclarada)
            : null;

        // calibración: pares de la placa (o del chofer si concentra sus viajes)
        $conductorClave = $fila->conductor_clave !== null ? (int) $fila->conductor_clave : null;
        [$grupoCalibracion, $claveCalibracion] = self::clave_calibracion(
            $placa,
            $conductorClave,
            self::pct_chofer_habitual($fila, $desde, $ahora)
        );

        $pares = DBPrediccion::pares($ref, $desde, $ahora, $placa, null, 5000);

        // Si el camión quedó CERCA de la planta de carga (mismo local, mismo
        // distrito o traslado medido corto), el hueco se calibra con los viajes en
        // que también quedó cerca: ahí carga enseguida mucho más seguido (mediana
        // 138 min y 54 % en ≤3 h, contra 1.020 min y 7 % lejos). Si esa placa no
        // tiene muestra propia, se usa el mismo régimen medido en la flota (por
        // chofer si tiene pares, si no toda la flota).
        $cerca = self::cerca($traslado, $p);
        $indiceCerca = self::indice_cerca($etapasPorRuta, DBPrediccion::rutas(), self::cerca_minutos($p));
        $paresCerca = 0;
        $paresCalibracion = $pares;
        $condicionado = false;
        $regimenUsado = null;
        $calibracion = null;
        $claveParaEstimar = $claveCalibracion;

        if ($cerca) {
            $paresCerca = array_values(array_filter($pares, fn (stdClass $par) => self::es_cerca($par, $indiceCerca)));

            if (count($paresCerca) >= max(1, (int) $p->min_pares_entidad)) {
                $paresCalibracion = $paresCerca;
                $condicionado = true;
            } else {
                $regimen = self::resumen_cerca_fila($conductorClave);
                if ($regimen !== null) {
                    $calibracion = Calibrador::de_resumen($regimen['resumen']);
                    $claveParaEstimar = 'regimen';
                    $regimenUsado = $regimen['origen'];
                }
            }
        }

        if ($calibracion === null) {
            if ($grupoCalibracion === 'conductor') {
                foreach ($paresCalibracion as $par) {
                    $par->entidad = $par->conductor !== null ? (string) $par->conductor : 'sin_conductor';
                }
            }

            $calibracion = Calibrador::calibrar($paresCalibracion, (int) $p->min_pares_ruta,
                (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max);
        }

        $rutaAncla = $ancla !== null ? (string) ($ancla->ruta_id ?? '') : '';
        $horaAncla = self::hora_ancla(
            $ancla?->fecha,
            $ancla?->hito,
            (bool) ($ancla->cerrado ?? false),
            $etapasPorRuta[$rutaAncla] ?? [],
            $ahora
        );

        // con ancla declarada no se aplica el hueco medido: ese hueco se mide
        // desde un fin de descargue, y acá no hay viaje del que volver
        $calibrador = $anclaDeclarada ? null : Calibrador::estimar($calibracion,
            $rutaAncla !== '' ? $rutaAncla : null,
            $rutaRef !== '' ? $rutaRef : null,
            $claveParaEstimar,
            $horaAncla);

        $ventanas = self::ventanas($placa, $desde, $ahora, $p);

        $plantaNombre = DBPrediccion::texto_ubicacion($plantaCarga !== '' ? $plantaCarga : null,
            (string) ($fila->ubigeo_origen ?? ''));

        if ($anclaDeclarada) {
            $anclaTexto = 'Ubicación declarada: ' . ($plantaNombre !== '' && $desdePlanta === $plantaCarga
                ? $plantaNombre
                : DBPrediccion::texto_ubicacion($desdePlanta !== '' ? $desdePlanta : null,
                    $desdeUbigeo !== '' ? $desdeUbigeo : null));
        } elseif ($ancla !== null) {
            $anclaTexto = 'Viaje ' . $ancla->ref . ' · ' . Tiempo::hito($ancla->hito)
                . ($ancla->fecha !== null ? ' del ' . $ancla->fecha->format('d/m H:i') : '');
        } elseif ($anclaPedidaNoEncontrada) {
            $anclaTexto = 'Ancla ' . $pedida . ' no encontrada para la placa ' . $placa;
        } else {
            $anclaTexto = 'Sin ancla';
        }

        $resultado = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ref' => $ref,
            'planta_carga' => $plantaCarga !== '' ? $plantaCarga : null,
            'planta_nombre' => $plantaNombre !== '' ? $plantaNombre : 'la planta de carga',
            'ancla_texto' => $anclaTexto,
            'ref_presenta' => $fila->presenta_para_carga,
            'ancla_ref' => $ancla->ref ?? '',
            'ancla' => (object) [
                'hito' => $ancla?->hito,
                'fecha' => $ancla?->fecha,
                'cerrada' => (bool) ($ancla->cerrado ?? false),
            ],
            'etapas' => $etapasPorRuta[$rutaAncla] ?? [],
            'calibrador' => $calibrador,
            'traslado' => $traslado,
            'ventanas' => $ventanas['ventanas'],
            'espera_carga_min' => DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
                $rutaRef !== '' ? $rutaRef : null),
            'parametros' => $p,
        ]);

        $resultado->placa = $placa;
        $resultado->ruta = $rutaRef;
        $resultado->ruta_nombre = self::nombre_ruta($fila);
        $resultado->planta_carga = $plantaCarga;
        $resultado->planta_carga_nombre = $plantaNombre;
        $resultado->candidatos = $candidatos;

        // ¿se usó Geoapify? Sólo pasa cuando NO hay ningún promedio compilado del
        // par; el consumidor tiene que poder verlo sin leer las advertencias.
        // `llamadas` es lo que costó esta predicción y `uso_hoy` el acumulado del
        // día en toda la aplicación (contador de la tabla `geoapify_uso`).
        $usoGeo = ($traslado->fuente ?? '') === 'geoapify';
        $llamadas = Geoapify::llamadas() - $llamadasGeo;
        $resultado->geoapify = (object) [
            'usado' => $usoGeo,
            'min' => $usoGeo ? (int) ($traslado->min ?? 0) : null,
            'km' => $traslado->km ?? null,
            'tramo' => $traslado->texto ?? null,
            'motivo' => $usoGeo
                ? 'no había promedio compilado para ese par de ubicaciones'
                : 'se usó el promedio medido de la flota',
            'llamadas' => $llamadas,
            'uso_hoy' => \App\Database\DBGeoapify::hoy(),
        ];

        $resultado->historia = (object) [
            'pares' => count($pares),
            'nivel' => $calibrador->nivel ?? 'cadena',
            'n' => $calibrador->n ?? 0,
            'grupo' => $grupoCalibracion,
            'clave' => $claveCalibracion,
            'conductor' => $fila->conductor_nombre ?? null,
            // quedó cerca de la planta de carga y se calibró con los pares "cerca"
            'cerca' => $cerca,
            'pares_cerca' => $paresCerca,
            'calibrado_cerca' => $condicionado,
            'cerca_regimen' => $regimenUsado,
            'ventanas' => $ventanas['ventanas'],
            'ventanas_origen' => $ventanas['origen'],
            'calibrador_usado' => !$anclaDeclarada,
            'geoapify_disponible' => \App\Prediccion\Geoapify::disponible(),
            'geoapify_usado' => $usoGeo,
            'traslado_fuente' => (string) ($traslado->fuente ?? 'sin_dato'),
        ];

        if ($condicionado) {
            $resultado->supuestos[] = 'el camión quedó cerca de la planta de carga (traslado '
                . (int) ($traslado->min ?? 0) . ' min): el hueco se calibró sólo con los '
                . count($paresCalibracion) . ' pares en que también quedó cerca';
        } elseif ($regimenUsado !== null) {
            $resultado->supuestos[] = 'el camión quedó cerca de la planta de carga (traslado '
                . (int) ($traslado->min ?? 0) . ' min): el hueco sale del régimen medido en la flota para '
                . 'los viajes en que queda cerca (' . $regimenUsado . ', '
                . (int) ($calibrador->n ?? 0) . ' pares)';
        }

        if ($anclaDeclarada) {
            $resultado->advertencias[] = 'ancla_declarada: no hay un viaje en curso utilizable (o su último hito '
                . 'es de hace más de ' . intdiv($topeAncla, 60) . ' h); el cálculo arranca ahora desde la ubicación '
                . 'declarada y suma el traslado hasta la planta de carga';
        } elseif ($anclaPedidaNoEncontrada) {
            $resultado->advertencias[] = 'ancla_no_encontrada: la ref ' . $pedida . ' no es un viaje de la placa '
                . $placa . ' anterior al corte; sin ancla no hay desde dónde contar';
        } elseif ($ancla === null) {
            $resultado->advertencias[] = 'sin_ubicacion: sin viaje en curso y sin ubicación declarada no hay desde '
                . 'dónde contar; declarando un local o un ubigeo sí se puede estimar';
        }

        if ($traslado !== null && ($traslado->fuente ?? '') === 'geoapify') {
            $resultado->advertencias[] = 'traslado_geoapify: no hay tránsito medido para ese par; el tiempo de '
                . 'manejo sale de Geoapify (' . Tiempo::hhmm((int) $traslado->min) . ')';
        }

        return $resultado;
    }

    /**
     * Tabla de aciertos: corre el motor sobre las referencias ya cerradas del
     * rango y compara contra el `presenta_para_carga` real.
     *
     * La calibración, las etapas y las franjas se aprenden con datos ANTERIORES
     * al rango evaluado, así ninguna fila ve su propia presentación. El grupo de
     * calibración (placa o conductor) se decide por fila con la misma regla de
     * producción.
     */
    public static function backtest(array $filtros = [], ?stdClass $parametros = null): stdClass
    {
        // $parametros permite calibrar: correr la misma ventana con distintos
        // valores sin tocar `prediccion_config` (ver App\Prediccion\Calibracion)
        $p = $parametros ?? Parametros::cargar();
        // El rango se cierra por DÍA: si llega "2026-11-13" como hasta, tiene que
        // incluir todo ese día, no la medianoche (con un instante el rango
        // hoy/hoy devolvía 0 filas).
        $desde = (self::carbon($filtros['desde'] ?? null) ?? Carbon::now()->subDays(30))->startOfDay();
        $hasta = (self::carbon($filtros['hasta'] ?? null) ?? Carbon::now())->endOfDay();
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta->copy(), $desde->copy()];
        }
        $placa = trim((string) ($filtros['placa'] ?? ''));
        $limite = max(1, min(1000, (int) ($filtros['limite'] ?? 200)));

        $aprendizajeDesde = $desde->copy()->subDays(max(1, (int) $p->lookback_dias));

        $etapas = DBPrediccion::etapas_por_ruta($aprendizajeDesde, $desde);
        $rutas = DBPrediccion::rutas();

        $paresAprendizaje = DBPrediccion::pares(null, $aprendizajeDesde, $desde,
            $placa !== '' ? $placa : null, null, 20000);

        $pares = DBPrediccion::pares(null, $desde, $hasta, $placa !== '' ? $placa : null, null, $limite);

        // historia completa por placa (aprendizaje + rango): cada fila calibra con
        // SUS propios pares anteriores, así el motor no ve el futuro ni arrastra
        // una calibración vieja
        $historialPorPlaca = [];
        foreach ($paresAprendizaje as $par) {
            $historialPorPlaca[$par->placa][] = $par;
        }
        foreach ($pares as $par) {
            $historialPorPlaca[$par->placa][] = $par;
        }

        $placas = array_values(array_unique(array_map(fn (stdClass $par) => $par->placa, $pares)));
        // los hitos de TODO el tramo se traen una vez; cada fila filtra los suyos
        // anteriores a su ancla (ver el cálculo de $ventanasCache en el bucle)
        $hitosPorPlaca = DBPrediccion::hitos_por_placa($aprendizajeDesde, $hasta, $placas);

        // pares de locales que están "cerca" (para calibrar la fila con su grupo)
        $indiceCerca = self::indice_cerca($etapas, $rutas, self::cerca_minutos($p));

        // La tabla corre cientos de filas: nada de llamadas HTTP adentro. Geoapify
        // queda apagado durante el backtest (cada fila caería a la red y son
        // ~1 segundo por fila: 300 filas = 5 minutos).
        \App\Prediccion\Geoapify::desactivar();

        $filas = [];
        $erroresMotor = [];
        $dentroMotor = [];
        $ventanasCache = [];
        $descartados = 0;
        $sinRuta = 0;
        $topeHueco = max(1, (int) $p->tope_hueco_min);

        foreach ($pares as $par) {
            // refs SIN RUTA: no se predice ni se evalúa (la API las rechaza con
            // 409). Sin ruta no hay planta de carga ni traslado, así que
            // cualquier hora que saliera sería el hueco medido sin tramo físico.
            if ((string) ($par->ruta_siguiente ?? '') === '') {
                $sinRuta++;
                continue;
            }

            // fuera del dominio del modelo (hueco de más de 7 días o negativo):
            // no es predecible y métricamente sólo aporta ruido
            if ($par->hueco_min <= 0 || $par->hueco_min > $topeHueco) {
                $descartados++;
                continue;
            }

            $rutaSiguiente = (string) ($par->ruta_siguiente ?? '');
            $rutaAncla = (string) ($par->ruta_ancla ?? '');

            $plantaCarga = (string) ($rutas[$rutaSiguiente]->origen_id ?? '');
            $desdePlanta = (string) ($rutas[$rutaAncla]->destino_id ?? '');
            $desdeUbigeo = (string) ($rutas[$rutaAncla]->ubigeo_destino ?? '');
            $plantaUbigeo = (string) ($rutas[$rutaSiguiente]->ubigeo_origen ?? '');

            $traslado = $plantaCarga !== ''
                ? DBPrediccion::traslado($plantaCarga, $desdePlanta !== '' ? $desdePlanta : null,
                    $desdeUbigeo !== '' ? $desdeUbigeo : null, $plantaUbigeo,
                    $etapas, $rutaSiguiente !== '' ? $rutaSiguiente : null, (int) $p->traslado_defecto_min)
                : null;

            // Calibración as-of, con la MISMA base y la MISMA regla de grupo que
            // producción (ver clave_calibracion()): la placa, salvo que el
            // conductor de esta ref concentre menos de la mitad de los viajes
            // previos de la placa. La base son TODOS los pares previos de la
            // placa, etiquetados por conductor cuando se calibra por chofer: si
            // ese chofer no tiene pares suficientes, el motor cae al par de
            // rutas y después a la mediana de la placa (como en producción).
            $conductorFila = $par->conductor !== null ? (int) $par->conductor : null;
            $previosTodos = self::pares_previos($historialPorPlaca[$par->placa] ?? [], $par->ancla, 10000);

            [$grupoCalibracion, $claveCalibracion] = self::clave_calibracion(
                $par->placa, $conductorFila, self::pct_conductor($previosTodos, $conductorFila));

            // misma regla de cercanía que producción: si esta fila quedó cerca de
            // la planta de carga, se calibra con los pares en que también quedó
            // cerca; si no alcanzan, con el régimen medido en la flota
            $cercaFila = self::cerca($traslado, $p);
            $regimenFila = null;
            $regimenResumen = null;
            $claveParaEstimar = $claveCalibracion;

            if ($cercaFila) {
                $soloCerca = array_values(array_filter($previosTodos,
                    fn (stdClass $previo) => self::es_cerca($previo, $indiceCerca)));

                if (count($soloCerca) >= max(1, (int) $p->min_pares_entidad)) {
                    $previosTodos = $soloCerca;
                } else {
                    $regimen = self::resumen_cerca_fila($conductorFila);
                    if ($regimen !== null) {
                        $regimenFila = $regimen['origen'];
                        $regimenResumen = $regimen['resumen'];
                        $claveParaEstimar = 'regimen';
                    }
                }
            }

            $previos = [];
            foreach ($previosTodos as $previo) {
                $copia = clone $previo;   // no tocar los objetos compartidos con otras filas
                if ($grupoCalibracion === 'conductor') {
                    $copia->entidad = $copia->conductor !== null ? (string) $copia->conductor : 'sin_conductor';
                }
                $previos[] = $copia;
            }

            // La corrección horaria es la de ESTA fila (la que calcula calibrar()
            // con sus pares). Antes se reemplazaba por la curva global de todo el
            // tramo: el backtest predecía con otra corrección que producción y la
            // fila no coincidía con lo que muestra "Predecir" (5 h de diferencia).
            $calFila = $regimenResumen !== null
                ? Calibrador::de_resumen($regimenResumen)
                : Calibrador::calibrar($previos, (int) $p->min_pares_ruta,
                    (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max);

            $calibrador = Calibrador::estimar($calFila,
                $rutaAncla !== '' ? $rutaAncla : null,
                $rutaSiguiente !== '' ? $rutaSiguiente : null,
                $claveParaEstimar,
                (int) $par->ancla->format('G'));

            // franjas inactivas as-of: sólo los hitos anteriores al ancla EXACTA de
            // esta fila (la clave lleva el timestamp, no el día: cachear por día
            // dejaba ver hasta 24 h de futuro dentro de la misma jornada)
            $llaveVentana = $par->placa . '|' . $par->ancla->format('Y-m-d H:i:s');
            if (!isset($ventanasCache[$llaveVentana])) {
                $hitosPrevios = array_values(array_filter(
                    $hitosPorPlaca[$par->placa] ?? [],
                    fn (Carbon $hito) => $hito->lessThan($par->ancla)
                ));
                $ventanasCache[$llaveVentana] = self::ventanas_con_hitos(
                    $hitosPrevios, $aprendizajeDesde, $par->ancla, $p);
            }

            $resultado = MotorPresenta::predecir((object) [
                'ahora' => $par->ancla,
                'ref' => $par->ref,
                'planta_carga' => $plantaCarga !== '' ? $plantaCarga : null,
                'ref_presenta' => null,
                'ancla_ref' => $par->ref_ancla,
                'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $par->ancla, 'cerrada' => true],
                'etapas' => $etapas[$rutaAncla] ?? [],
                'calibrador' => $calibrador,
                'traslado' => $traslado,
                'ventanas' => $ventanasCache[$llaveVentana]['ventanas'],
                'espera_carga_min' => DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
                    $rutaSiguiente !== '' ? $rutaSiguiente : null),
                'parametros' => $p,
            ]);

            $real = $par->presenta;
            $predicho = $resultado->presenta;
            $error = $predicho !== null ? self::minutos($real, $predicho) : null;

            // ACIERTO de la predicción de tres fechas: la hora real cae dentro de
            // [rápido, tardado]. Es el número que se muestra como "% de acierto".
            $rapido = $resultado->presenta_rapido ?? null;
            $tardado = $resultado->presenta_tardado ?? null;
            $dentroFila = $rapido !== null && $tardado !== null
                && $real->greaterThanOrEqualTo($rapido) && $real->lessThanOrEqualTo($tardado);

            if ($error !== null) {
                $erroresMotor[] = $error;
                $dentroMotor[] = $dentroFila;
            }

            $filas[] = (object) [
                'ref' => $par->ref,
                'placa' => $par->placa,
                'ancla_ref' => $par->ref_ancla,
                'ancla' => $par->ancla,
                'hueco_min' => $par->hueco_min,
                'rapido' => $rapido,
                'predicho' => $predicho,
                'tardado' => $tardado,
                'real' => $real,
                'error_min' => $error,
                'ventana_desde' => $resultado->ventana['desde'] ?? null,
                'ventana_hasta' => $resultado->ventana['hasta'] ?? null,
                'dentro' => $dentroFila,
                'evidencia' => $resultado->evidencia,
                'confianza' => $resultado->confianza,
                'estado' => $resultado->estado->clave ?? null,
                'grupo' => $grupoCalibracion,
                'pares_calibracion' => (int) ($calibrador->n ?? 0),
                'cerca' => $cercaFila,
                'cerca_regimen' => $regimenFila,
            ];
        }

        $resumenMotor = Aciertos::resumir($erroresMotor, $dentroMotor);
        \App\Prediccion\Geoapify::activar();

        return (object) [
            'filas' => $filas,
            'resumen_motor' => $resumenMotor,
            'parametros' => $p,
            'desde' => $desde,
            'hasta' => $hasta,
            'aprendizaje_desde' => $aprendizajeDesde,
            'pares' => count($filas),
            'descartados' => $descartados,
            'sin_ruta' => $sinRuta,
        ];
    }

    /**
     * Viajes del camión que pueden servir de ancla, del más reciente al más viejo.
     *
     * El hito del ancla es el último EVENTO real del viaje (`inicio_ruta`,
     * `qr_llegada_destino`, `inicio_descargue`, ...). El `compromiso_carga` no
     * cuenta: es una cita planificada, no algo que haya pasado, y puede quedar
     * con fecha posterior a la presentación. Un viaje sin ningún evento real no
     * es ancla y no se lista.
     *
     * @return array<int, stdClass>
     */
    public static function candidatos_viaje(string $placa, ?Carbon $antes = null, int $limite = 40): array
    {
        $placa = trim($placa);
        if ($placa === '') {
            return [];
        }

        $salida = [];
        foreach (DBPrediccion::refs_por_placa($placa, $antes, $limite) as $fila) {
            $hito = DBPrediccion::hito_mas_reciente($fila, self::HITOS_NO_ANCLA);
            if ($hito === null) {
                continue;   // sólo tiene compromiso/plan: no sirve de ancla
            }

            $fecha = self::carbon($fila->{$hito} ?? null);

            $salida[] = (object) [
                'ref' => (string) $fila->ref,
                'ruta_id' => (string) ($fila->ruta_id ?? ''),
                'ruta' => self::nombre_ruta($fila),
                'origen_id' => (string) ($fila->origen_id ?? ''),
                'destino_id' => (string) ($fila->destino_id ?? ''),
                'ubigeo_origen' => (string) ($fila->ubigeo_origen ?? ''),
                'ubigeo_destino' => (string) ($fila->ubigeo_destino ?? ''),
                'hito' => $hito,
                'fecha' => $fecha,
                'cerrado' => $hito === 'fin_descargue',
                'edad_min' => ($fecha !== null && $antes !== null) ? (int) round($fecha->diffInMinutes($antes)) : null,
                'presenta' => $fila->presenta_para_carga ?? null,
                'inicio_ruta' => $fila->inicio_ruta ?? null,
                'fin_descargue' => $fila->fin_descargue ?? null,
                'conductor' => $fila->conductor_nombre ?? null,
            ];
        }

        return $salida;
    }

    /** Catálogos y parámetros que comparten las dos pantallas. */
    public static function catalogos(): stdClass
    {
        $locales = [];
        try {
            $locales = DB::table('locales')->orderBy('nombre')->get(['id', 'nombre', 'palabra_clave', 'distrito_ubigeo'])->all();
        } catch (\Throwable $e) {
            // sin catálogo de locales el formulario sigue con ubigeo a mano
        }

        $departamentos = [];
        try {
            $departamentos = DB::table('ubigeo_departamentos')->orderBy('departamento')->get(['id', 'departamento'])->all();
        } catch (\Throwable $e) {
        }

        return (object) [
            'rutas' => DBPrediccion::rutas(),
            'plantas' => DBPrediccion::promedios_planta(),
            'locales' => $locales,
            'departamentos' => $departamentos,
            'parametros' => Parametros::cargar(),
        ];
    }

    /**
     * Pares que se pueden usar para calibrar una fila: sólo los anteriores a su
     * ancla (nada de futuro), del más reciente al más viejo, y como máximo `$max`.
     *
     * Es la guarda anti-fuga de la tabla de aciertos, sacada a un método puro
     * para poder probarla sin base de datos.
     *
     * @param  array<int, stdClass>  $historial  pares de la placa (cada uno con ->presenta Carbon)
     * @return array<int, stdClass>
     */
    public static function pares_previos(array $historial, Carbon $ancla, int $max = 20): array
    {
        $previos = [];
        foreach ($historial as $parcial) {
            if (isset($parcial->presenta) && $parcial->presenta instanceof Carbon
                && $parcial->presenta->lessThan($ancla)) {
                $previos[] = $parcial;
            }
        }

        usort($previos, fn (stdClass $a, stdClass $b) => $b->presenta->getTimestamp() <=> $a->presenta->getTimestamp());

        return array_slice($previos, 0, max(1, $max));
    }

    /**
     * Valida la ubicación declarada por el usuario: si el local no existe o el
     * ubigeo no es de 6 dígitos, el traslado caería a otro nivel y devolvería
     * una hora plausible sin decir por qué. Devuelve el mensaje de error o NULL.
     */
    public static function ubicacion_invalida(string $tipo, string $id): ?string
    {
        $tipo = trim($tipo) !== '' ? trim($tipo) : 'auto';
        $id = trim($id);

        if ($id === '') {
            return null;   // sin ubicación declarada: se deduce del último hito
        }

        if ($tipo === 'local') {
            try {
                return DB::table('locales')->where('id', $id)->exists()
                    ? null
                    : 'la ubicación "' . $id . '" no existe en el catálogo de locales';
            } catch (\Throwable $e) {
                return null;   // sin catálogo no se puede juzgar: no se bloquea
            }
        }

        if ($tipo === 'ubigeo') {
            return preg_match('/^\d{6}$/', $id) === 1
                ? null
                : 'el ubigeo tiene que ser de 6 dígitos (ej. 140101)';
        }

        return null;
    }

    /**
     * Qué porcentaje de esos pares previos los hizo ese conductor.
     *
     * Versión as-of de la regla de grupo: en producción se cuenta sobre las
     * referencias de la placa en la ventana (`pct_chofer_habitual`); en el
     * backtest, sobre los pares anteriores al ancla, que son los que calibran.
     *
     * @param  array<int, stdClass>  $paresPrevios
     */
    public static function pct_conductor(array $paresPrevios, ?int $conductorId): ?float
    {
        if ($conductorId === null || $conductorId <= 0 || $paresPrevios === []) {
            return null;
        }

        $propios = 0;
        foreach ($paresPrevios as $par) {
            if ((int) ($par->conductor ?? 0) === $conductorId) {
                $propios++;
            }
        }

        return round(100 * $propios / count($paresPrevios), 1);
    }

    /** Minutos de traslado a partir de los cuales el camión ya no quedó "cerca". */
    public const CERCA_MIN_DEFECTO = 60;

    public static function cerca_minutos(?object $p = null): int
    {
        return max(1, (int) ($p->cerca_min ?? self::CERCA_MIN_DEFECTO));
    }

    /**
     * ¿El camión quedó cerca de la planta donde tiene que cargar?
     *
     * Se mira el TRASLADO de vuelta que ya calculó el motor: 0 (misma planta o
     * mismo distrito) o un traslado medido corto. Es lo que hace más probable que
     * cargue enseguida (ver `pares_cerca()`).
     */
    public static function cerca(?object $traslado, ?object $p = null): bool
    {
        if ($traslado === null) {
            return false;
        }

        return (int) ($traslado->min ?? self::CERCA_MIN_DEFECTO + 1) <= self::cerca_minutos($p);
    }

    /**
     * Índice de pares de locales que están "cerca": existe una ruta medida entre
     * ellos con un viaje (e4) de hasta `$umbral` minutos.
     *
     * @param  array<string, array<string, int|null>>  $etapas  salida de etapas_por_ruta()
     * @param  array<string, object>  $rutas  salida de DBPrediccion::rutas()
     * @return array<string, bool> claves "LOC_A|LOC_B"
     */
    public static function indice_cerca(array $etapas, array $rutas, int $umbral): array
    {
        $indice = [];

        foreach ($rutas as $id => $ruta) {
            $origen = (string) ($ruta->origen_id ?? '');
            $destino = (string) ($ruta->destino_id ?? '');
            if ($origen === '' || $destino === '') {
                continue;
            }

            $e4 = $etapas[(string) $id]['e4'] ?? null;
            if ($e4 === null || (int) $e4 <= 0 || (int) $e4 > $umbral) {
                continue;
            }

            $indice[$origen . '|' . $destino] = true;
            $indice[$destino . '|' . $origen] = true;
        }

        return $indice;
    }

    /**
     * ¿Ese par histórico también quedó cerca? Mismo local, mismo distrito o una
     * ruta medida corta entre los dos.
     */
    public static function es_cerca(object $par, array $indice): bool
    {
        $desde = (string) ($par->ancla_destino ?? '');
        $hasta = (string) ($par->carga_origen ?? '');

        if ($desde !== '' && $desde === $hasta) {
            return true;
        }
        if ($desde !== '' && $hasta !== '' && (isset($indice[$desde . '|' . $hasta])
                || isset($indice[$hasta . '|' . $desde]))) {
            return true;
        }

        $desdeUbigeo = (string) ($par->ancla_ubigeo ?? '');
        $hastaUbigeo = (string) ($par->carga_ubigeo ?? '');

        return $desdeUbigeo !== '' && $desdeUbigeo === $hastaUbigeo;
    }

    /** Memo por request del régimen "cerca" de la flota. */
    private static ?object $regimenCerca = null;

    /**
     * Régimen "cerca" medido en TODA la flota: los huecos de los viajes en que el
     * camión descargó en el mismo local o el mismo distrito donde volvió a cargar.
     *
     * Es lo que usa una placa sin muestra propia en ese régimen. Se memoiza por
     * request y se cachea unos minutos porque recorre 120 días de pares.
     */
    public static function regimen_cerca(): ?object
    {
        if (self::$regimenCerca !== null) {
            return self::$regimenCerca;
        }

        // ojo: un resultado vacío NO se cachea (si la base falla o no hay datos,
        // el próximo request lo reintenta en vez de quedarse 15 min en cero)
        $llave = 'prediccion:regimen_cerca:' . now()->format('YmdH');

        try {
            $datos = Cache::get($llave);

            if (!is_array($datos) || ($datos['global'] ?? null) === null) {
                $hasta = Carbon::now();
                $datos = DBPrediccion::resumen_cerca($hasta->copy()->subDays(120)->startOfDay(), $hasta);

                if (($datos['global'] ?? null) !== null) {
                    Cache::put($llave, $datos, now()->addMinutes(15));
                }
            }
        } catch (\Throwable $e) {
            return self::$regimenCerca = null;
        }

        if (!is_array($datos) || ($datos['global'] ?? null) === null) {
            return self::$regimenCerca = null;
        }

        return self::$regimenCerca = (object) [
            'global' => $datos['global'],
            'conductores' => (array) ($datos['conductores'] ?? []),
        ];
    }

    /**
     * Resumen del régimen "cerca" para una fila: el de ese conductor si tiene
     * muestra propia (así manda su comportamiento), si no el de la flota.
     *
     * @return array{resumen: array, origen: string}|null
     */
    public static function resumen_cerca_fila(?int $conductor): ?array
    {
        $regimen = self::regimen_cerca();
        if ($regimen === null) {
            return null;
        }

        if ($conductor !== null && isset($regimen->conductores[(string) $conductor])) {
            return ['resumen' => $regimen->conductores[(string) $conductor], 'origen' => 'chofer'];
        }

        return $regimen->global !== null ? ['resumen' => $regimen->global, 'origen' => 'flota'] : null;
    }

    /**
     * Con qué clave se calibra el hueco: la placa manda, salvo que un conductor
     * concentre menos de la mitad de sus viajes (misma regla que
     * `ComportamientoController::chofer_habitual`), en cuyo caso los promedios de
     * la placa no describen a nadie.
     *
     * @return array{0: string, 1: string} [grupo, clave]
     */
    public static function clave_calibracion(?string $placa, ?int $conductorId, ?float $pctHabitual): array
    {
        $placa = trim((string) $placa);

        if ($placa === '') {
            return ['placa', ''];
        }

        if ($conductorId !== null && $conductorId > 0 && $pctHabitual !== null && $pctHabitual < 50.0) {
            return ['conductor', (string) $conductorId];
        }

        return ['placa', $placa];
    }

    //------------------- ayudas internas -------------------

    /** Qué porcentaje de los viajes de la placa hizo el conductor de esta ref. */
    private static function pct_chofer_habitual(object $fila, Carbon $desde, Carbon $hasta): ?float
    {
        $conductor = (int) ($fila->conductor_clave ?? 0);
        $placa = (string) ($fila->placa ?? '');
        if ($conductor <= 0 || $placa === '') {
            return null;
        }

        try {
            $total = (int) DB::table('referencias')
                ->where('placa', $placa)
                ->whereBetween('presenta_para_carga', [$desde, $hasta])
                ->count();
            if ($total === 0) {
                return null;
            }

            $propios = (int) DB::table('referencias')
                ->where('placa', $placa)
                ->whereBetween('presenta_para_carga', [$desde, $hasta])
                ->where('conductor_id', $conductor)
                ->count();
        } catch (\Throwable $e) {
            return null;
        }

        return round(100 * $propios / $total, 1);
    }

    /** Hora del día en la que queda libre el camión: la que corrige el hueco. */
    private static function hora_ancla(?Carbon $fechaAncla, ?string $hito, bool $cerrada, array $etapas, Carbon $ahora): int
    {
        if ($fechaAncla === null) {
            return (int) $ahora->format('G');
        }

        if ($cerrada) {
            return (int) $fechaAncla->format('G');
        }

        $faltante = MotorPresenta::faltantes($hito, $etapas);
        $tFin = $fechaAncla->copy()->addMinutes($faltante ?? 0);
        if ($tFin->lessThan($ahora)) {
            $tFin = $ahora->copy();
        }

        return (int) $tFin->format('G');
    }

    /** @return array{ventanas: array<int, array{desde: int, hasta: int}>, origen: string} */
    private static function ventanas(string $placa, Carbon $desde, Carbon $hasta, stdClass $p): array
    {
        $hitos = DBPrediccion::hitos_entidad('placa', $placa, $desde, $hasta);

        return self::ventanas_con_hitos($hitos, $desde, $hasta, $p);
    }

    /**
     * Franjas inactivas a partir de las marcas ya cargadas, con las dos guardas:
     * historia mínima y perfil creíble (menos del 60 % del día quieto).
     *
     * Devuelve también el ORIGEN (perfil del camión o una de las dos caídas al
     * perfil por defecto): la pantalla tiene que poder decir de dónde salen.
     *
     * @param  array<int, \Carbon\Carbon>  $hitos
     * @return array{ventanas: array<int, array{desde: int, hasta: int}>, origen: string}
     */
    private static function ventanas_con_hitos(array $hitos, Carbon $desde, Carbon $hasta, stdClass $p): array
    {
        if ($hitos === []) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_sin_hitos'];
        }

        $primero = $hitos[0];
        $ultimo = $hitos[array_key_last($hitos)];
        $dias = max(1, (int) round($primero->copy()->startOfDay()->diffInDays($ultimo->copy()->startOfDay())) + 1);

        if ($dias < max(1, (int) $p->min_dias_actividad)) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_historia_corta'];
        }

        $ventanas = Ventanas::inactivas($hitos, $desde, $hasta,
            (int) $p->min_hitos_bin, (int) $p->granularidad_min);

        if (Ventanas::minutosInactivos($ventanas) > (int) (0.6 * Ventanas::MINUTOS_DIA)) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_perfil_absurdo'];
        }

        return ['ventanas' => $ventanas, 'origen' => 'perfil_del_camion'];
    }

    /** Minutos de `$hasta - $desde` (positivo si la predicción llegó tarde). */
    private static function minutos(Carbon $referencia, Carbon $otra): int
    {
        return (int) round(($otra->getTimestamp() - $referencia->getTimestamp()) / 60);
    }

    /** Si la fecha cae dentro de la ventana p25-p75 de la predicción. */
    private static function enVentana(?Carbon $fecha, array $ventana): bool
    {
        $desde = $ventana['desde'] ?? null;
        $hasta = $ventana['hasta'] ?? null;

        if ($fecha === null || !($desde instanceof Carbon) || !($hasta instanceof Carbon)) {
            return false;
        }

        return $fecha->greaterThanOrEqualTo($desde) && $fecha->lessThanOrEqualTo($hasta);
    }

    /** "PLANTA X → PLANTA Y" o el id de la ruta si no hay catálogo. */
    private static function nombre_ruta($fila): string
    {
        $origen = trim((string) ($fila->origen_nombre ?? ''));
        $destino = trim((string) ($fila->destino_nombre ?? ''));

        if ($origen !== '' && $destino !== '') {
            return $origen . ' → ' . $destino;
        }

        return (string) ($fila->ruta_id ?? '');
    }

    /** Ubigeo (distrito) de un local del catálogo. */
    private static function ubigeo_local(string $local): ?string
    {
        try {
            $ubigeo = DB::table('locales')->where('id', $local)->value('distrito_ubigeo');

            return $ubigeo !== null && $ubigeo !== '' ? (string) $ubigeo : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function carbon($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor->copy();
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
