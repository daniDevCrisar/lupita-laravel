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
     * Cuántas refs puede evaluar una corrida como máximo ("todas" = este tope).
     *
     * No es un número de negocio: es MEMORIA. Medido en local (memory_limit 512 M),
     * con el rango 01-sep..16-nov:
     *   500 filas  -> 350 MB de pico
     *   2.000      -> 394 MB
     *   5.000      -> fatal: "Allowed memory size exhausted"
     * El pico lo domina la preparación (20.000 pares de aprendizaje) más el caché
     * de ventanas por fila; el costo marginal es ~30 KB por fila. Por eso el tope
     * es 2.000: pasado eso, el host compartido se cae antes de terminar.
     */
    public const TOPE_EVALUACION = 2000;

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

        // ---- qué posición declaró el usuario (si declaró alguna) -------------
        // Se resuelve ANTES del ancla a propósito: si el pedido trae una ubicación y
        // NO trae ancla, el camión está donde dice el operador y el cálculo arranca
        // ahí. Deducir "el último viaje de la placa" usa un viaje que puede ser de
        // días atrás (ref 1195347: ancla de 144,9 h) y la hora publicada termina
        // siendo el propio corte, con 137 h de "ajuste".
        $ubicacionTipo = (string) ($opciones['ubicacion_tipo'] ?? 'auto');
        $ubicacionId = trim((string) ($opciones['ubicacion_id'] ?? ''));
        $coords = self::coords_declaradas($opciones['ubicacion_coords'] ?? null);
        $ubicacionDeclarada = (in_array($ubicacionTipo, ['local', 'ubigeo'], true) && $ubicacionId !== '')
            || ($ubicacionTipo === 'geopunto' && $coords !== null);
        // lo que escribió el operador, tal cual, y el texto ya resuelto
        $ubicacionPedida = trim((string) ($opciones['ubicacion_pedida'] ?? ''));
        $ubicacionResueltaTexto = trim((string) ($opciones['ubicacion_texto'] ?? ''));

        // ubigeo de la posición declarada (para rutear desde ahí)
        $ubicacionUbigeo = '';
        if ($ubicacionTipo === 'local' && $ubicacionId !== '') {
            $ubicacionUbigeo = (string) (self::ubigeo_local($ubicacionId) ?? '');
        } elseif ($ubicacionTipo === 'ubigeo' && $ubicacionId !== '') {
            $ubicacionUbigeo = $ubicacionId;
        }

        // ---- ancla ----------------------------------------------------------
        // El ancla es el último viaje del camión del que se sabe algo. Se usa:
        //  - si el pedido la pide explícitamente (`ancla`), o
        //  - si NO se declaró ninguna ubicación (el punto de partida se deduce de
        //    los hitos del camión).
        // CON UBICACIÓN DECLARADA Y SIN ANCLA NO SE BUSCA: el operador ya dijo dónde
        // está el camión y deducir un viaje viejo inventa un punto de partida (y una
        // hora recortada contra el corte).
        $plantaCarga = (string) ($fila->origen_id ?? '');
        $plantaUbigeo = (string) ($fila->ubigeo_origen ?? '');

        // ¿el operador declaró que la unidad YA ESTÁ en la planta de carga? Entonces
        // no hay viaje que esperar ni traslado: se presenta ahora y queda la espera
        // en planta. Manda sobre el ancla, incluso si el pedido la mandó explícita:
        // el viaje en curso es un dato viejo al lado de "está acá".
        $enLaPlanta = $ubicacionDeclarada && self::en_planta($ubicacionTipo, $ubicacionId,
            $ubicacionUbigeo, $plantaCarga, $plantaUbigeo);

        $ancla = null;
        $pedida = isset($opciones['ancla_ref']) ? (string) $opciones['ancla_ref'] : '';
        foreach ($candidatos as $viaje) {
            if ($pedida !== '' && $viaje->ref === $pedida) {
                $ancla = $viaje;
                break;
            }
        }
        if ($enLaPlanta) {
            // la unidad ya está en la planta: el viaje no dice nada de dónde está
            $ancla = null;
        } elseif ($ancla === null && $pedida === '' && !$ubicacionDeclarada && $candidatos !== []) {
            $ancla = $candidatos[0];
        }

        // el pedido no trae ancla y sí ubicación: el ancla es la posición declarada
        // (el motor la arma más abajo); se usa para explicarlo en las advertencias
        $anclaPorUbicacion = $enLaPlanta || ($pedida === '' && $ubicacionDeclarada);

        // se pidió un ancla concreta y no es un viaje de este camión antes del
        // corte: mejor decirlo que predecir desde otro lado sin avisar
        $anclaPedidaNoEncontrada = $pedida !== '' && $ancla === null && !$enLaPlanta;

        // ($rutaRef ya quedó arriba: la ref sin ruta no llega hasta acá)
        $etapasPorRuta = DBPrediccion::etapas_por_ruta($desde, $ahora);

        // desde dónde vuelve el camión: su última posición conocida o lo que declare el usuario
        $desdePlanta = $ancla !== null ? (string) ($ancla->destino_id ?? '') : '';
        $desdeUbigeo = $ancla !== null ? (string) ($ancla->ubigeo_destino ?? '') : '';

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

        // ¿Está en medio de un viaje? Entonces la ubicación declarada NO es el
        // punto de partida del regreso: es dónde está el camión AHORA, y sirve
        // para acortar lo que le falta del viaje (la ida hasta el destino).
        // Sin viaje en curso (ya descargó o no hay ancla) es el punto desde el
        // que vuelve a la planta de carga.
        $viajeEnCurso = $ancla !== null && !$anclaDeclarada && !($ancla->cerrado ?? false)
            && in_array((string) ($ancla->hito ?? ''), MotorPresenta::HITOS_CON_VIAJE, true);

        $rutaAncla = $ancla !== null ? (string) ($ancla->ruta_id ?? '') : '';

        // ¿Se puede estimar lo que le falta al viaje desde la posición declarada hasta
        // el DESTINO? Se resuelve ANTES del traslado porque de eso depende si el viaje
        // sirve o no como punto de partida.
        $tramoDeclarado = ($viajeEnCurso && $ubicacionDeclarada)
            ? self::tramo_hacia_destino($coords, $ubicacionUbigeo, $ubicacionResueltaTexto,
                $ancla, $etapasPorRuta, $rutaAncla !== '' ? $rutaAncla : null, $ahora, $p,
                $ubicacionTipo === 'local' ? $ubicacionId : '')
            : null;

        // la posición declarada no se pudo ubicar de ninguna forma: sin punto no hay
        // tramo, ni distancia, ni ruteo. Se corta con error en vez de publicar una hora
        // armada desde el ancla entera como si el camión no se hubiera movido.
        if (is_object($tramoDeclarado) && isset($tramoDeclarado->error)) {
            return (object) [
                'ref' => $ref,
                'error' => (string) $tramoDeclarado->error,
                'placa' => $placa,
                'ahora' => $ahora,
                'presenta' => null,
                'desglose' => [],
                'supuestos' => [],
                'advertencias' => [
                    'ubicacion_no_geolocalizable: el ubigeo ' . (string) $tramoDeclarado->ubigeo
                    . ' (' . (string) $tramoDeclarado->texto . ') no está en el catálogo de coordenadas y '
                    . 'Geoapify no lo encontró. Sin un punto no se puede estimar el tramo que le falta al '
                    . 'camión; no se predice.',
                ],
                'candidatos' => $candidatos,
                'historia' => null,
            ];
        }

        // SIEMPRE SE RESPETA LA UBICACIÓN DECLARADA. Si el camión va en viaje pero no
        // se pudo estimar lo que le falta desde donde dice el operador, el viaje deja
        // de ser el ancla: el cálculo arranca en la posición declarada y desde ahí
        // vuelve a la planta. Antes quedaba `ubicacion_no_usada` y la hora salía del
        // destino del viaje (ref 1196252: presenta 41 h después del corte).
        if ($viajeEnCurso && $ubicacionDeclarada && $tramoDeclarado === null) {
            $ancla = (object) [
                'ref' => '', 'hito' => 'ubicacion_declarada', 'fecha' => $ahora->copy(),
                'cerrado' => true, 'ruta_id' => '', 'destino_id' => '', 'ubigeo_destino' => '',
                'edad_min' => 0,
            ];
            $anclaDeclarada = true;
            $anclaPorUbicacion = true;
            $viajeEnCurso = false;
            $rutaAncla = '';
        }

        if (!$viajeEnCurso) {
            if ($ubicacionTipo === 'local' && $ubicacionId !== '') {
                $desdePlanta = $ubicacionId;
                $desdeUbigeo = $ubicacionUbigeo;
            } elseif ($ubicacionTipo === 'ubigeo' && $ubicacionId !== '') {
                $desdePlanta = '';
                $desdeUbigeo = $ubicacionId;
            } elseif ($ubicacionTipo === 'geopunto' && $coords !== null) {
                // el operador escribió un distrito que no está en el catálogo y se
                // geocodificó con Geoapify: se trabaja con las coordenadas
                $desdePlanta = '';
                $desdeUbigeo = '';
            } elseif ($ubicacionTipo === 'indeterminado') {
                $desdePlanta = '';
                $desdeUbigeo = '';
            }
        }

        // Hora a la que el camión SALE hacia la planta de carga: si va en viaje,
        // cuando termine (ancla + las etapas que le faltan); si ya está libre,
        // ahora. Con eso se decide si el regreso se pide con tráfico o de vía
        // libre (App\Prediccion\HoraPico): no es lo mismo volver a las 20:00 que
        // a las 03:00.
        $salidaRegreso = $ahora->copy();
        if ($viajeEnCurso && $ancla->fecha instanceof Carbon) {
            $restanteAncla = MotorPresenta::faltantes((string) ($ancla->hito ?? ''),
                $etapasPorRuta[$rutaAncla] ?? []) ?? 0;
            $salidaEstimada = $ancla->fecha->copy()->addMinutes($restanteAncla);
            if ($salidaEstimada->greaterThan($ahora)) {
                $salidaRegreso = $salidaEstimada;
            }
        }

        $traslado = $plantaCarga !== ''
            ? DBPrediccion::traslado($plantaCarga, $desdePlanta !== '' ? $desdePlanta : null,
                $desdeUbigeo !== '' ? $desdeUbigeo : null, (string) ($fila->ubigeo_origen ?? ''),
                $etapasPorRuta, $rutaRef !== '' ? $rutaRef : null, (int) $p->traslado_defecto_min,
                $ubicacionDeclarada && !$viajeEnCurso, $salidaRegreso,
                // el regreso se estima con EL MISMO cálculo que el tramo que le
                // falta al camión (ruta → placa → día y hora), desde la hora a la
                // que el camión queda libre
                fn (string $ruta): ?object => self::viaje_ruta($ruta, $etapasPorRuta, $salidaRegreso,
                    $placa, $desde, $ahora, $p),
                // con `$p` el escalón de coordenadas y el factor de camión vacío
                // respetan lo que esté configurado (y lo que pisa la ablación)
                $p)
            : null;
        $traslado = self::limitar_traslado($traslado, $p);

        // Posición geocodificada (texto libre que no estaba en el catálogo): el
        // traslado sale del ruteo real de Geoapify desde ese punto a la planta.
        // Con viaje en curso ese punto no es el origen del regreso (el camión
        // primero llega a su destino), así que no se usa acá.
        if ($coords !== null && $plantaCarga !== '' && !$viajeEnCurso) {
            $geo = Geoapify::ruta_desde_coords($coords->lat, $coords->lng,
                (string) ($fila->ubigeo_origen ?? ''), $salidaRegreso, $ubicacionUbigeo);

            if ($geo !== null) {
                $traslado = (object) [
                    'min' => max(1, (int) $geo->min),
                    'fuente' => 'geoapify',
                    'ruta' => null,
                    'texto' => $geo->desde . ' → ' . $geo->hasta,
                    'km' => $geo->km,
                    'min_medido' => null,
                ];
            }
        }

        // LA UNIDAD YA ESTÁ EN LA PLANTA: el operador lo declaró. No hay traslado
        // que estimar —ni con ruta, ni con coordenadas, ni con Geoapify—: está ahí.
        // Manda sobre cualquier estimación anterior.
        if ($enLaPlanta) {
            $traslado = (object) [
                'min' => 0,
                'fuente' => 'ya_esta_en_planta',
                'ruta' => null,
                'texto' => 'la unidad ya está en '
                    . DBPrediccion::texto_ubicacion($plantaCarga, $plantaUbigeo),
                'km' => 0,
                'min_medido' => null,
                'viaje' => null,
                'sentido' => 'vacio',
                'factor_vacio' => 1.0,
            ];
        }

        // Las piezas con las que se armó el traslado (ruta → placa → día y hora).
        // Van al JSON y a los supuestos, igual que las del viaje: los dos procesos
        // se calculan con la misma cadena y se explican igual.
        $trasladoViaje = is_object($traslado->viaje ?? null) ? $traslado->viaje : null;
        $trasladoCruce = null;
        $trasladoHorario = null;

        if ($trasladoViaje !== null) {
            if ($trasladoViaje->placa !== null && (int) $trasladoViaje->placa_n > 0) {
                $trasladoCruce = (object) [
                    'min' => (int) $trasladoViaje->tras_placa,
                    'compilado' => (int) $trasladoViaje->base,
                    'placa' => (int) $trasladoViaje->placa,
                    'n' => (int) $trasladoViaje->placa_n,
                ];
            }

            if ($trasladoViaje->celda !== null) {
                $trasladoHorario = (object) [
                    'min' => (int) $trasladoViaje->min,
                    'horario' => (int) $trasladoViaje->celda,
                    'viajes' => (int) $trasladoViaje->celda_viajes,
                    'dia' => (int) $trasladoViaje->celda_dia,
                    'hora' => (int) $trasladoViaje->celda_hora,
                ];
            }
        }

        // TIEMPO DEL VIAJE que le falta al camión: lo medido de la ruta, mezclado
        // con lo que esta placa tarda en la misma ruta y con el compilado por día y
        // hora de salida. Es la MISMA cadena que usa el traslado de regreso
        // (`Servicio::viaje_ruta()`), porque los promedios de ida y vuelta son casi
        // los mismos y la incertidumbre está en lo que el conductor descansa.
        $etapasAncla = $etapasPorRuta[$rutaAncla] ?? [];
        $cruceViaje = null;
        $viajeHorario = null;
        $anclaAbierta = $ancla !== null && !($ancla->cerrado ?? false);

        if ($rutaAncla !== '' && ($etapasAncla['e4'] ?? null) !== null) {
            // hora de salida: la del propio hito si ya arrancó, si no la del corte
            $salidaViaje = ((string) ($ancla->hito ?? '') === 'inicio_ruta' && $ancla->fecha instanceof Carbon)
                ? $ancla->fecha
                : $ahora;

            // la placa sólo entra con el viaje abierto: con el ancla cerrada la
            // etapa no se usa para calcular y no vale la pena medirla
            $conPlaca = $anclaAbierta && !$anclaDeclarada;

            $estimado = self::viaje_ruta($rutaAncla, $etapasPorRuta, $salidaViaje,
                $conPlaca ? $placa : '', $conPlaca ? $desde : null, $conPlaca ? $ahora : null, $p);

            if ($estimado !== null) {
                $etapasAncla['e4'] = (int) $estimado->min;

                if ($estimado->placa !== null && (int) $estimado->placa_n > 0) {
                    $cruceViaje = (object) [
                        'min' => (int) $estimado->tras_placa,
                        'compilado' => (int) $estimado->base,
                        'placa' => (int) $estimado->placa,
                        'n' => (int) $estimado->placa_n,
                    ];
                }

                if ($estimado->celda !== null) {
                    $viajeHorario = (object) [
                        'min' => (int) $estimado->min,
                        'horario' => (int) $estimado->celda,
                        'viajes' => (int) $estimado->celda_viajes,
                        'dia' => (int) $estimado->celda_dia,
                        'hora' => (int) $estimado->celda_hora,
                    ];
                }
            }
        }

        // El tramo desde la posición declarada hasta el DESTINO del viaje ya se
        // resolvió arriba (hace falta antes del traslado, para decidir si el viaje
        // sirve como ancla). Acá sólo se completa lo que sigue después de llegar:
        // descarga y vuelta al destino, que no dependen de dónde está el camión.
        $avisoTramo = null;
        if ($tramoDeclarado !== null) {
            $tramoDeclarado->resto_min = MotorPresenta::faltantes_sin_viaje(
                (string) ($ancla->hito ?? ''), $etapasAncla) ?? 0;

            // el tramo parcial se estima como manejo SIN paradas: si el ajuste de
            // distancia implicaba menos de 15 km/h en línea recta, se recortó
            if ((string) ($tramoDeclarado->aviso ?? '') === 'tramo_lento') {
                $avisoTramo = 'tramo_lento: el tramo desde la ubicación declarada hasta el '
                    . 'destino implicaba menos de ' . (int) ($p->coords_parcial_km_h_min ?? 15)
                    . ' km/h en línea recta; se acotó a esa velocidad';
            }
        }

        // calibración: pares de la placa. De quién sale el hueco (placa o chofer)
        // se decide por MUESTRA, después de tener los pares (ver C3 en
        // clave_calibracion()).
        $conductorClave = $fila->conductor_clave !== null ? (int) $fila->conductor_clave : null;

        $pares = DBPrediccion::pares($ref, $desde, $ahora, $placa, null, 5000);

        $nChofer = 0;
        if ($conductorClave !== null) {
            foreach ($pares as $par) {
                if ((int) ($par->conductor ?? 0) === $conductorClave) {
                    $nChofer++;
                }
            }
        }

        [$grupoCalibracion, $claveCalibracion] = self::clave_calibracion(
            $placa,
            $conductorClave,
            $nChofer,
            count($pares),
            (int) ($p->min_pares_entidad ?? 3)
        );

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
                (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max,
                (int) ($p->curva_modo ?? 0), (int) ($p->curva_k ?? 5),
                (int) ($p->entidad_pares_k ?? 0), (int) ($p->hueco_debil ?? 0));
        }

        $horaAncla = self::hora_ancla(
            $ancla?->fecha,
            $ancla?->hito,
            (bool) ($ancla->cerrado ?? false),
            $etapasAncla,
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

        // C2: factor del hueco por día de la semana de la presentación. De dónde
        // sale lo decide `dia_modo` (ver Calibrador::factores_dia()).
        $factoresDia = self::factores_dia_fila($p, $pares, $rutaRef);

        // C1: cola de la planta de carga en este momento (cuántos esperan y cuántos
        // llegaron en la última hora). Se apaga en el backtest: la tabla de
        // aciertos no evalúa el inicio de carga y sería una consulta por fila.
        $cola = null;
        if ((int) ($p->cola_activa ?? 1) === 1 && $plantaCarga !== '') {
            $cola = DBPrediccion::cola_planta($plantaCarga, (string) ($fila->ubigeo_origen ?? ''), $ahora);
        }

        // Ventana rápido/tardado: si está medida (residuos del backtest), se le
        // pasa al motor tal cual; si no, el motor usa los cuantiles del hueco.
        // La medición la guarda el botón "Calcular ventana" (ventana_*_min).
        $ventanaMedida = null;
        if ((int) ($p->ventana_residuos ?? 0) === 1) {
            $adelanto = max(0, (int) ($p->ventana_adelanto_min ?? 0));
            $atraso = max(0, (int) ($p->ventana_atraso_min ?? 0));

            if ($adelanto + $atraso > 0) {
                $ventanaMedida = (object) [
                    'adelanto' => $adelanto,
                    'atraso' => $atraso,
                    // la cadena (sin pares medidos) se equivoca mucho más
                    'adelanto_cadena' => max(0, (int) ($p->ventana_adelanto_cadena ?? 0)),
                    'atraso_cadena' => max(0, (int) ($p->ventana_atraso_cadena ?? 0)),
                    'cobertura' => (float) ($p->ventana_cobertura ?? 0.7),
                ];
            }
        }

        // C1 aplicado: si la planta recibió muchas llegadas en la última hora, la
        // espera para cargar se estira. Medido: mediana 36 min con 1-2 llegadas por
        // hora y 55 con 11 o más.
        $esperaBase = DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
            $rutaRef !== '' ? $rutaRef : null);
        $esperaExtra = 0;
        $esperaCarga = $esperaBase;
        if ($esperaBase !== null && $cola !== null
            && (int) $cola->llegadas_hora >= max(1, (int) ($p->cola_llegadas_min ?? 11))) {
            $esperaExtra = max(0, (int) ($p->cola_espera_extra_min ?? 19));
            $esperaCarga = $esperaBase + $esperaExtra;
        }

        $plantaNombre = DBPrediccion::texto_ubicacion($plantaCarga !== '' ? $plantaCarga : null,
            (string) ($fila->ubigeo_origen ?? ''));

        // texto legible de la posición declarada (local, ubigeo o el texto que se
        // geocodificó); se reusa en el ancla, en el desglose y en `ubicacion`
        $ubicacionTexto = trim((string) ($opciones['ubicacion_texto'] ?? ''));
        if ($ubicacionTexto === '') {
            $ubicacionTexto = DBPrediccion::texto_ubicacion($desdePlanta !== '' ? $desdePlanta : null,
                $desdeUbigeo !== '' ? $desdeUbigeo : null);
        }
        if ($coords !== null && $coords->texto !== '') {
            $ubicacionTexto = $coords->texto;
        }

        if ($anclaDeclarada) {
            $anclaTexto = 'Ubicación declarada: ' . ($plantaNombre !== '' && $desdePlanta === $plantaCarga
                ? $plantaNombre
                : ($ubicacionTexto !== '' ? $ubicacionTexto : 'sin ubicación'));
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
            'etapas' => $etapasAncla,
            'calibrador' => $calibrador,
            'traslado' => $traslado,
            'tramo_en_curso' => $tramoDeclarado,
            'ventana_residuos' => $ventanaMedida,
            'factor_dia' => $factoresDia,
            'dia_modo' => (int) ($p->dia_modo ?? Calibrador::DIA_FLOTA),
            'feriado_activo' => (int) ($p->feriado_activo ?? 1),
            'ventanas' => $ventanas['ventanas'],
            'espera_carga_min' => $esperaCarga,
            // la unidad está declarada en la planta de carga: se presenta ahora
            'en_planta' => $enLaPlanta,
            'parametros' => $p,
        ]);

        $resultado->placa = $placa;
        $resultado->ruta = $rutaRef;
        $resultado->ruta_nombre = self::nombre_ruta($fila);
        $resultado->planta_carga = $plantaCarga;
        $resultado->planta_carga_nombre = $plantaNombre;
        $resultado->candidatos = $candidatos;

        // C1: lo que se miró de la cola de la planta y cuánto se sumó a la espera
        $resultado->planta_cola = $cola !== null
            ? (object) [
                'esperando' => (int) $cola->esperando,
                'llegadas_hora' => (int) $cola->llegadas_hora,
                'espera_base_min' => $esperaBase,
                'espera_extra_min' => $esperaExtra,
            ]
            : null;

        // ¿se usó Geoapify? Sólo pasa cuando NO hay ningún promedio compilado del
        // par; el consumidor tiene que poder verlo sin leer las advertencias.
        // `llamadas` es lo que costó esta predicción y `uso_hoy` el acumulado del
        // día en toda la aplicación (contador de la tabla `geoapify_uso`).
        $usoGeoTraslado = ($traslado->fuente ?? '') === 'geoapify';
        $usoGeoTramo = ($tramoDeclarado->fuente ?? '') === 'geoapify';
        $usoGeo = $usoGeoTraslado || $usoGeoTramo;
        $llamadas = Geoapify::llamadas() - $llamadasGeo;

        // ¿la hora del corte o la salida del regreso caen en hora pico de la zona
        // del viaje? Es lo que decide si el ruteo se pide CON tráfico.
        $zonaPico = HoraPico::zona((string) ($fila->ubigeo_origen ?? ''), (string) ($fila->ubigeo_destino ?? ''));
        $enHoraPico = HoraPico::es_pico($ahora, $zonaPico) || HoraPico::es_pico($salidaRegreso, $zonaPico);

        $resultado->geoapify = (object) [
            'usado' => $usoGeo,
            'para' => $usoGeoTramo && $usoGeoTraslado ? 'tramo y traslado'
                : ($usoGeoTramo ? 'tramo_en_curso' : ($usoGeoTraslado ? 'traslado' : '')),
            'min' => $usoGeoTraslado ? (int) ($traslado->min ?? 0) : null,
            'km' => $traslado->km ?? null,
            'tramo' => $traslado->texto ?? null,
            'tramo_en_curso' => $tramoDeclarado !== null
                ? (object) [
                    'min' => (int) ($tramoDeclarado->min ?? 0),
                    'km' => $tramoDeclarado->km ?? null,
                    'fuente' => (string) ($tramoDeclarado->fuente ?? 'sin_dato'),
                ]
                : null,
            // con qué modo de ruteo y con qué tráfico se pidieron los tiempos
            'modo_ruta' => Geoapify::modo_ruta_usado(),
            'trafico' => Geoapify::trafico_usado(),
            'hora_pico' => $enHoraPico,
            'zona_pico' => $zonaPico,
            'franjas_pico' => HoraPico::texto($zonaPico),
            'motivo' => $usoGeo
                ? ($usoGeoTramo
                    ? 'el tramo que le falta al viaje salió del ruteo desde la ubicación declarada'
                    : 'no había promedio compilado para ese par de ubicaciones')
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
            // cruce del viaje: compilado de la ruta + lo que hace esta placa
            'viaje_cruce' => $cruceViaje,
            // y el ajuste por hora de salida (tiempos_ruta_horario), si se aplicó
            'viaje_horario' => $viajeHorario,
            // el traslado de regreso se arma igual que el viaje: mismas dos mezclas
            'traslado_cruce' => $trasladoCruce,
            'traslado_horario' => $trasladoHorario,
            'ventanas' => $ventanas['ventanas'],
            'ventanas_origen' => $ventanas['origen'],
            'calibrador_usado' => !$anclaDeclarada,
            'geoapify_disponible' => \App\Prediccion\Geoapify::disponible(),
            'geoapify_usado' => $usoGeo,
            'traslado_fuente' => (string) ($traslado->fuente ?? 'sin_dato'),
            // qué ruta se usó para el regreso (auditoría: el traslado acepta la
            // ruta de los dos locales en cualquier sentido)
            'traslado_ruta' => $traslado->ruta ?? null,
            // el traslado siempre es la vuelta SIN carga: si la ruta que se usó es
            // la de ida (sale de la planta), se corrige con `vacio_factor`
            'traslado_sentido' => (string) ($traslado->sentido ?? 'desconocido'),
            'traslado_factor_vacio' => (float) ($traslado->factor_vacio ?? 1.0),
            // sin ningún promedio, el traslado sale de las coordenadas de los locales
            'traslado_km' => $traslado->km ?? null,
            'traslado_ajuste' => $traslado->ajuste ?? null,
        ];

        // De dónde salió la posición del camión y, sobre todo, SI ENTRÓ en el
        // cálculo: local/ubigeo del catálogo, texto libre geocodificado
        // ("geopunto") o deducida del último hito. `modo` es la respuesta a
        // "¿usaste el `ubicacion_texto` que te mandé y para qué?".
        if (!$ubicacionDeclarada) {
            $ubicacionModo = $ancla !== null ? 'deducida' : 'sin_ubicacion';
            $ubicacionUsada = false;
            $ubicacionDetalle = $ancla !== null
                ? 'no se envió ubicación: se usa el último evento real del camión'
                : 'no hay viaje en curso ni ubicación declarada: no hay desde dónde contar';
        } elseif ($tramoDeclarado !== null) {
            $ubicacionModo = 'hacia_destino';
            $ubicacionUsada = true;
            $ubicacionDetalle = 'el camión sigue en viaje: se calculó el tramo que le falta desde la ubicación '
                . 'declarada hasta ' . (string) ($tramoDeclarado->hasta ?? 'el destino del viaje') . ' ('
                . Tiempo::hhmm((int) ($tramoDeclarado->min ?? 0))
                . (isset($tramoDeclarado->km) && $tramoDeclarado->km !== null ? ', ' . $tramoDeclarado->km . ' km' : '')
                . ') y después descarga y vuelta a cargar';
        } elseif ($viajeEnCurso) {
            $ubicacionModo = 'no_usada';
            $ubicacionUsada = false;
            $ubicacionDetalle = 'el camión está en un viaje en curso y no se pudo rutear desde la ubicación '
                . 'declarada hasta el destino: lo que le falta del viaje sale de las medianas de su ruta';
        } else {
            $ubicacionModo = 'hacia_planta';
            $ubicacionUsada = true;
            $ubicacionDetalle = 'sin viaje en curso: el cálculo arranca en la ubicación declarada y suma el '
                . 'traslado hasta la planta de carga';
        }

        $resultado->ubicacion = (object) [
            'pedida' => $ubicacionPedida !== '' ? $ubicacionPedida : null,
            'usada' => $ubicacionUsada,
            'modo' => $ubicacionModo,
            'detalle' => $ubicacionDetalle,
            'tipo' => $ubicacionTipo,
            'id' => $ubicacionId !== '' ? $ubicacionId : null,
            'texto' => $ubicacionTexto !== '' ? $ubicacionTexto : null,
            'fuente' => $coords !== null ? 'geoapify' : ($ubicacionDeclarada ? 'catalogo' : 'deducida'),
            'lat' => $coords?->lat,
            'lng' => $coords?->lng,
            'declarada' => $ubicacionDeclarada,
            // el tramo que se calculó desde esa posición (null si no se pudo)
            'tramo' => $tramoDeclarado !== null
                ? (object) [
                    'min' => (int) ($tramoDeclarado->min ?? 0),
                    'km' => $tramoDeclarado->km ?? null,
                    'desde' => $tramoDeclarado->desde ?? null,
                    'hasta' => $tramoDeclarado->hasta ?? null,
                    'fuente' => $tramoDeclarado->fuente ?? null,
                    'resto_min' => (int) ($tramoDeclarado->resto_min ?? 0),
                ]
                : null,
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

        if ($cruceViaje !== null && $cruceViaje->placa !== null && $cruceViaje->n > 0) {
            $resultado->supuestos[] = 'viaje de la ruta: compilado ' . Tiempo::hhmm((int) $cruceViaje->compilado)
                . ' mezclado con ' . $cruceViaje->n . ' viajes de esta placa ('
                . Tiempo::hhmm((int) $cruceViaje->placa) . ')';
        }

        if ($viajeHorario !== null) {
            $resultado->supuestos[] = 'viaje de la ruta por hora de salida: el compilado de esa franja ('
                . Tiempo::hhmm((int) $viajeHorario->horario) . ', ' . $viajeHorario->viajes . ' viajes)'
                . ' dejó el viaje en ' . Tiempo::hhmm((int) $viajeHorario->min);
        }

        // el regreso se arma igual que el viaje: mismas dos mezclas, mismo texto
        if ($trasladoCruce !== null) {
            $resultado->supuestos[] = 'traslado de regreso: compilado ' . Tiempo::hhmm((int) $trasladoCruce->compilado)
                . ' mezclado con ' . $trasladoCruce->n . ' viajes de esta placa ('
                . Tiempo::hhmm((int) $trasladoCruce->placa) . ')';
        }

        if ($trasladoHorario !== null) {
            $resultado->supuestos[] = 'traslado de regreso por día y hora: el compilado de esa franja ('
                . Tiempo::hhmm((int) $trasladoHorario->horario) . ', ' . $trasladoHorario->viajes . ' viajes)'
                . ' dejó el traslado en ' . Tiempo::hhmm((int) $trasladoHorario->min);
        }

        // sin ningún promedio para ese par, el traslado sale de las coordenadas
        if (is_object($traslado) && (string) ($traslado->fuente ?? '') === 'coordenadas') {
            $resultado->supuestos[] = 'traslado de regreso sin promedios: ' . $traslado->km
                . ' km en línea recta entre los dos locales × el ajuste medido con las rutas que sí tienen '
                . 'promedio (' . (string) ($traslado->ajuste ?? 'global') . ')';
        }

        // la vuelta a cargar va SIN carga: si el promedio usado era el de ida, se corrigió
        if (is_object($traslado) && (string) ($traslado->sentido ?? '') === 'cargado'
            && (float) ($traslado->factor_vacio ?? 1) < 1) {
            $resultado->supuestos[] = 'el regreso va sin carga: el promedio de ida se corrigió ×'
                . number_format((float) $traslado->factor_vacio, 2, ',', '.')
                . ' (un camión vacío tarda menos)';
        }

        if ($avisoTramo !== null) {
            $resultado->advertencias[] = $avisoTramo;
        }

        if ($anclaDeclarada) {
            $resultado->advertencias[] = $anclaPorUbicacion
                ? 'ancla_declarada: el pedido no trae ancla y sí una ubicación declarada, así que el cálculo '
                    . 'arranca en esa posición (ahora) y suma el traslado hasta la planta de carga; no se busca '
                    . 'el último viaje de la placa'
                : 'ancla_declarada: no hay un viaje en curso utilizable (o su último hito '
                    . 'es de hace más de ' . intdiv($topeAncla, 60) . ' h); el cálculo arranca ahora desde la ubicación '
                    . 'declarada y suma el traslado hasta la planta de carga';
        } elseif ($anclaPedidaNoEncontrada) {
            $resultado->advertencias[] = 'ancla_no_encontrada: la ref ' . $pedida . ' no es un viaje de la placa '
                . $placa . ' anterior al corte; sin ancla no hay desde dónde contar';
        } elseif ($ancla === null) {
            $resultado->advertencias[] = 'sin_ubicacion: sin viaje en curso y sin ubicación declarada no hay desde '
                . 'dónde contar; declarando un local o un ubigeo sí se puede estimar';
        }

        if ($viajeEnCurso && $ubicacionDeclarada && $tramoDeclarado === null) {
            $resultado->advertencias[] = 'ubicacion_no_usada: el camión está en un viaje en curso y no se pudo '
                . 'calcular el tramo desde la ubicación declarada hasta el destino (sin Geoapify ni promedio '
                . 'medido para ese par); lo que le falta del viaje sale de las medianas de su ruta';
        }

        if ($traslado !== null && ($traslado->fuente ?? '') === 'geoapify') {
            $resultado->advertencias[] = 'traslado_geoapify: no hay tránsito medido para ese par; el tiempo de '
                . 'manejo sale de Geoapify (' . Tiempo::hhmm((int) $traslado->min) . ')';
        }

        return $resultado;
    }

    /**
     * Mide `cruce_pares_k` donde el parámetro ACTÚA: el viaje al destino.
     *
     * La tabla de aciertos NO sirve para esto: evalúa refs con el viaje anterior
     * CERRADO (`fin_descargue`) y ahí `restante_viaje` ni se usa, así que el cruce
     * compilado/placa no cambia nada y todos los valores empatan (por eso el botón
     * recomendaba siempre 1, el primero de la lista).
     *
     * Acá se mide contra el **e4 real** de cada viaje (inicio_ruta -> llegada al
     * destino): para cada viaje se predice w·(mediana de la placa) + (1−w)·(compilado
     * de la ruta), con w = n/(n+k) y n = los viajes de la placa en esa ruta
     * EXCLUYENDO el que se predice (leave-one-out: sin filtración del futuro ni del
     * propio viaje, que es justo lo que hace trampa).
     *
     * @param  array{desde?: mixed, hasta?: mixed, valores?: array<int, int>}  $opciones
     * @return array<string, mixed>
     */
    public static function calibrar_cruce(array $opciones = []): array
    {
        $fecha = function ($valor, bool $fin = false): ?Carbon {
            if ($valor instanceof Carbon) {
                return $fin ? $valor->copy()->endOfDay() : $valor->copy()->startOfDay();
            }

            $valor = trim((string) $valor);
            if ($valor === '') {
                return null;
            }

            try {
                $carbon = Carbon::parse($valor);
            } catch (\Throwable $e) {
                return null;
            }

            return $fin ? $carbon->endOfDay() : $carbon->startOfDay();
        };

        $hasta = $fecha($opciones['hasta'] ?? null, true) ?? Carbon::now()->endOfDay();
        $desde = $fecha($opciones['desde'] ?? null) ?? $hasta->copy()->subDays(89)->startOfDay();
        $valores = array_values(array_filter(array_map('intval', $opciones['valores'] ?? [1, 2, 3, 5, 8, 12]),
            fn (int $k) => $k >= 0));

        $salida = [
            'ok' => false,
            'error' => null,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'n' => 0,
            'rutas' => 0,
            'placas' => 0,
            'valores' => [],
            'mejor' => null,
            'actual' => null,
            'recomendacion' => null,
        ];

        try {
            $filas = DB::select(
                "SELECT r.placa, r.ruta_id,
                        TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) AS e4
                 FROM referencias r
                 WHERE r.inicio_ruta BETWEEN ? AND ?
                   AND r.qr_llegada_destino > r.inicio_ruta
                   AND TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) BETWEEN 5 AND 720
                   AND r.placa IS NOT NULL AND r.placa <> ''
                   AND r.ruta_id IS NOT NULL AND r.ruta_id <> ''",
                [$desde->toDateTimeString(), $hasta->toDateTimeString()]
            );

            // compilado por ruta (población): mediana del módulo de tiempos
            $compilados = DBPrediccion::promedios_ruta();
        } catch (\Throwable $e) {
            $salida['error'] = 'No se pudieron leer los viajes: ' . $e->getMessage();

            return $salida;
        }

        if ($filas === []) {
            $salida['error'] = 'No hay viajes medibles (inicio_ruta -> llegada) en ese rango.';

            return $salida;
        }

        // por ruta: todos los e4 (para el fallback) y, por placa, la lista de e4
        $porRuta = [];
        $porPlaca = [];
        foreach ($filas as $fila) {
            $ruta = (string) $fila->ruta_id;
            $e4 = (int) $fila->e4;
            $porRuta[$ruta][] = $e4;
            $porPlaca[$ruta . '|' . (string) $fila->placa][] = $e4;
        }

        $poblacion = [];
        foreach ($porRuta as $ruta => $e4s) {
            $mediana = $compilados[$ruta]->mediana_min ?? null;
            $poblacion[$ruta] = $mediana !== null && (float) $mediana > 0
                ? (int) round((float) $mediana)
                : (int) round((float) Estadistica::mediana($e4s));
        }

        // acumuladores por k: |real - prediccion|
        $errores = [];
        foreach ($valores as $k) {
            $errores[$k] = [];
        }

        $n = 0;
        foreach ($porPlaca as $clave => $e4s) {
            [$ruta] = explode('|', $clave, 2);
            $nPlaca = count($e4s);

            // con un solo viaje no hay mediana de la placa: no aporta al cruce
            if ($nPlaca < 2 || !isset($poblacion[$ruta])) {
                continue;
            }

            foreach ($e4s as $i => $real) {
                // leave-one-out: la mediana de los OTROS viajes de la placa
                $otros = $e4s;
                unset($otros[$i]);
                $medianaPlaca = Estadistica::mediana(array_values($otros));
                if ($medianaPlaca === null) {
                    continue;
                }

                $n++;

                foreach ($valores as $k) {
                    $mezcla = self::mezclar_viaje($poblacion[$ruta], (int) round($medianaPlaca),
                        count($otros), $k);

                    if ($mezcla !== null) {
                        $errores[$k][] = abs($real - $mezcla);
                    }
                }
            }
        }

        if ($n === 0) {
            $salida['error'] = 'Ningún viaje tiene al menos 2 viajes de la misma placa en su ruta: '
                . 'sin muestra propia el cruce no se puede medir.';

            return $salida;
        }

        $mae = fn (array $v) => $v === [] ? null : round(array_sum($v) / count($v), 2);

        $mejor = null;
        foreach ($valores as $k) {
            $valor = $mae($errores[$k]);
            $salida['valores'][] = ['k' => $k, 'mae' => $valor, 'n' => count($errores[$k])];

            if ($valor !== null && ($mejor === null || $valor < $mejor['mae'])) {
                $mejor = ['k' => $k, 'mae' => $valor, 'n' => count($errores[$k])];
            }
        }

        $actual = max(0, (int) (Parametros::cargar()->cruce_pares_k ?? 3));
        $maeActual = $mae($errores[$actual] ?? []);

        // si la diferencia entre el mejor y el que está en uso es menos de 1 minuto,
        // es ruido: se recomienda NO cambiarlo (recomendar 1 porque empata en el
        // redondeo es hacerle perder el tiempo al despachador)
        $ruido = $mejor === null || $maeActual === null || ($maeActual - $mejor['mae']) < 1.0;
        $recomendado = $ruido ? $actual : $mejor['k'];

        $salida['n'] = $n;
        $salida['rutas'] = count($porRuta);
        $salida['placas'] = count($porPlaca);
        $salida['mejor'] = $mejor;
        $salida['ruido'] = $ruido;
        $salida['actual'] = ['k' => $actual, 'mae' => $maeActual];
        $salida['recomendacion'] = [
            'clave' => 'cruce_pares_k',
            'actual' => $actual,
            'recomendado' => $recomendado,
            'mae_actual' => $maeActual,
            'mae_recomendado' => $ruido ? $maeActual : $mejor['mae'],
            'mejora' => ($maeActual !== null && $mejor !== null) ? round($maeActual - $mejor['mae'], 2) : null,
        ];
        $salida['ok'] = $mejor !== null;

        if (!$salida['ok']) {
            $salida['error'] = 'Ninguna combinación pudo medirse.';
        }

        return $salida;
    }

    /**
     * Corre una rejilla de parámetros con entrenamiento + validación.
     *
     * Es la mitad "de cálculo" del comando `prediccion:calibrar` y de la pantalla
     * de parámetros: mismos filtros, misma métrica, mismo criterio de desempate.
     * No imprime ni guarda nada: devuelve todo lo medido para que el que llama
     * decida (consola: tabla + `--aplicar`; pantalla: recomendación).
     *
     * @param  array{desde?: mixed, hasta?: mixed, validar_desde?: mixed, validar_hasta?: mixed,
     *               limite?: int, metrica?: string, n_minimo?: int,
     *               rejilla?: array<string, array<int, int|float>>, parametros?: ?stdClass}  $opciones
     * @return array<string, mixed>
     */
    public static function calibrar(array $opciones = []): array
    {
        $fecha = function ($valor, bool $fin = false): ?Carbon {
            if ($valor instanceof Carbon) {
                return $fin ? $valor->copy()->endOfDay() : $valor->copy()->startOfDay();
            }

            $valor = trim((string) $valor);
            if ($valor === '') {
                return null;
            }

            try {
                $carbon = Carbon::parse($valor);
            } catch (\Throwable $e) {
                return null;
            }

            return $fin ? $carbon->endOfDay() : $carbon->startOfDay();
        };

        $hasta = $fecha($opciones['hasta'] ?? null, true) ?? Carbon::now()->endOfDay();
        $desde = $fecha($opciones['desde'] ?? null) ?? $hasta->copy()->subDays(29)->startOfDay();
        $valDesde = $fecha($opciones['validar_desde'] ?? null) ?? $hasta->copy()->subDays(6)->startOfDay();
        $valHasta = $fecha($opciones['validar_hasta'] ?? null, true) ?? $hasta->copy();
        $entHasta = $valDesde->copy()->subDay()->endOfDay();

        $metrica = (string) ($opciones['metrica'] ?? 'mae');

        $salida = [
            'ok' => false,
            'error' => null,
            'metrica' => $metrica,
            'metrica_etiqueta' => Calibracion::METRICAS[$metrica]['etiqueta'] ?? $metrica,
            'limite' => max(10, min(1000, (int) ($opciones['limite'] ?? 200))),
            'n_minimo' => max(1, (int) ($opciones['n_minimo'] ?? 20)),
            'rejilla' => [],
            'combinaciones' => 0,
            'entrenamiento' => ['desde' => $desde->toDateString(), 'hasta' => $entHasta->toDateString()],
            'validacion' => ['desde' => $valDesde->toDateString(), 'hasta' => $valHasta->toDateString()],
            'actual' => null,
            'resultados' => [],
            'mejor' => null,
            'recomendacion' => [],
            'puntaje_actual' => null,
        ];

        if (!isset(Calibracion::METRICAS[$metrica])) {
            $salida['error'] = 'Métrica desconocida: ' . $metrica;

            return $salida;
        }

        if ($desde->greaterThan($entHasta)) {
            $salida['error'] = 'La ventana de entrenamiento quedó vacía: tiene que empezar antes del '
                . $valDesde->toDateString() . '.';

            return $salida;
        }

        if ($valDesde->greaterThan($valHasta)) {
            [$valDesde, $valHasta] = [$valHasta->copy()->startOfDay(), $valDesde->copy()->endOfDay()];
            $salida['validacion'] = ['desde' => $valDesde->toDateString(), 'hasta' => $valHasta->toDateString()];
        }

        $base = $opciones['parametros'] ?? Parametros::cargar();
        $rejilla = $opciones['rejilla'] ?? Calibracion::REJILLA;

        $desconocidas = array_diff(array_keys($rejilla), array_keys((array) $base));
        if ($desconocidas !== []) {
            $salida['error'] = 'Claves desconocidas en la rejilla: ' . implode(', ', $desconocidas);

            return $salida;
        }

        try {
            $combos = Calibracion::combinaciones($rejilla);
        } catch (\Throwable $e) {
            $salida['error'] = $e->getMessage();

            return $salida;
        }

        $salida['rejilla'] = $rejilla;
        $salida['combinaciones'] = count($combos);

        $filtrosEnt = ['desde' => $desde->toDateString(), 'hasta' => $entHasta->toDateString(),
                       'limite' => $salida['limite']];
        $filtrosVal = ['desde' => $valDesde->toDateString(), 'hasta' => $valHasta->toDateString(),
                       'limite' => $salida['limite']];

        try {
            // referencia: los parámetros que están en uso ahora mismo
            $actualEnt = self::backtest($filtrosEnt, $base);
            $actualVal = self::backtest($filtrosVal, $base);
            $salida['actual'] = [
                'entrenamiento' => $actualEnt->resumen_motor,
                'validacion' => $actualVal->resumen_motor,
            ];
            $puntajeActual = Calibracion::puntaje($actualVal->resumen_motor, $metrica);
            $salida['puntaje_actual'] = $puntajeActual === INF ? null : $puntajeActual;
        } catch (\Throwable $e) {
            $salida['error'] = 'No se pudo correr la tabla de aciertos: ' . $e->getMessage();

            return $salida;
        }

        $objetos = [];
        foreach ($combos as $valores) {
            $parametros = Calibracion::con($base, $valores);

            try {
                $ent = self::backtest($filtrosEnt, $parametros);
                $val = self::backtest($filtrosVal, $parametros);
            } catch (\Throwable $e) {
                $salida['resultados'][] = [
                    'valores' => $valores,
                    'etiqueta' => Calibracion::etiqueta($valores),
                    'entrenamiento' => null,
                    'validacion' => null,
                    'puntaje' => null,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $objetos[] = (object) [
                'valores' => $valores,
                'etiqueta' => Calibracion::etiqueta($valores),
                'entrenamiento' => $ent->resumen_motor,
                'validacion' => $val->resumen_motor,
            ];
        }

        $mejor = Calibracion::mejor($objetos, $metrica, true, $salida['n_minimo']);

        $filas = [];
        foreach ($objetos as $objeto) {
            $filas[] = [
                'valores' => $objeto->valores,
                'etiqueta' => $objeto->etiqueta,
                'entrenamiento' => $objeto->entrenamiento,
                'validacion' => $objeto->validacion,
                'puntaje' => Calibracion::puntaje($objeto->validacion, $metrica),
                'error' => null,
                'elegido' => $mejor !== null && $objeto->etiqueta === $mejor->etiqueta,
            ];
        }

        usort($filas, fn (array $a, array $b) => ($a['puntaje'] ?? INF) <=> ($b['puntaje'] ?? INF));

        foreach ($salida['resultados'] as $fallado) {
            $filas[] = $fallado + ['elegido' => false];
        }

        $salida['resultados'] = $filas;

        if ($mejor === null) {
            $salida['error'] = 'Ninguna corrida llegó a ' . $salida['n_minimo'] . ' filas en la validación: '
                . 'no hay con qué elegir. Probá un rango más largo.';

            return $salida;
        }

        $salida['mejor'] = [
            'valores' => $mejor->valores,
            'etiqueta' => $mejor->etiqueta,
            'entrenamiento' => $mejor->entrenamiento,
            'validacion' => $mejor->validacion,
            'puntaje' => Calibracion::puntaje($mejor->validacion, $metrica),
        ];

        foreach (array_keys($rejilla) as $clave) {
            $actual = $base->{$clave} ?? null;
            $recomendado = $mejor->valores[$clave] ?? null;

            $salida['recomendacion'][] = [
                'clave' => (string) $clave,
                'actual' => $actual,
                'recomendado' => $recomendado,
                'difiere' => $actual !== $recomendado,
            ];
        }

        $salida['ok'] = true;

        return $salida;
    }

    /**
     * Mide la ventana rápido/tardado contra los RESIDUOS reales del backtest.
     *
     * El p25-p75 del hueco no es una ventana: contiene el valor real ~35 % de las
     * veces (medido en la tabla de aciertos). Esta medición corre esa misma tabla
     * y calcula el radio `cuantil(|error|, cobertura)` alrededor de la hora
     * publicada, que es lo que pide un despachador: "presenta entre estas dos".
     *
     * Devuelve SIMÉTRICO (adelanto = atraso = radio) a propósito. Se probó la
     * banda asimétrica, sacando cada lado de su cuantil del error firmado, y NO
     * paga: el lado temprano que pide la medición (−2.656 min) no se puede
     * publicar porque el rápido se recorta contra `ahora`, así que la cobertura
     * medida no se logra (56 % en muestra contra 66 % de la simétrica) y fuera de
     * muestra empata. Correr la banda con el sesgo tampoco: la mediana del error
     * salta cientos de minutos entre ventanas y sería calzar ruido. El sesgo se
     * informa aparte y se corrige por CLASE en la cuarta fecha.
     *
     * @param  array<string, mixed>  $filtros  igual que Servicio::backtest()
     * @return array<string, mixed>
     */
    public static function ventana_residuos(array $filtros = [], float $cobertura = 0.70,
                                            int $minimo = 40): array
    {
        $cobertura = max(0.05, min(0.99, $cobertura));

        $salida = [
            'ok' => false,
            'error' => null,
            'adelanto' => 0,
            'atraso' => 0,
            'radio' => 0,
            'cadena_radio' => 0,
            'cadena_adelanto' => 0,
            'cadena_atraso' => 0,
            'cadena_n' => 0,
            'cadena_cobertura' => null,
            'cobertura' => $cobertura,
            'cobertura_lograda' => null,
            'cobertura_actual' => null,
            'n' => 0,
            'mae' => null,
            'sesgo' => null,
            'desde' => null,
            'hasta' => null,
            'minimo' => max(1, $minimo),
            'residuos_de' => 'normal',
            'limite' => (int) ($filtros['limite'] ?? 200),
        ];

        try {
            $corrida = self::backtest($filtros);
        } catch (\Throwable $e) {
            $salida['error'] = 'No se pudo correr la tabla de aciertos: ' . $e->getMessage();

            return $salida;
        }

        $errores = [];
        $erroresCadena = [];

        // La banda se mide contra la fecha que se va a usar. Si la cuarta fecha
        // está aplicada, se mide CONTRA ELLA: si no, quedaría centrada en una hora
        // que ya nadie publica y la cobertura se cae sin que el motor empeore.
        $contraProbable = (bool) ($corrida->sesgo_activo ?? false);

        foreach ($corrida->filas as $fila) {
            $errorFila = $contraProbable && ($fila->error_probable_min ?? null) !== null
                ? (int) $fila->error_probable_min
                : $fila->error_min;

            if ($errorFila === null) {
                continue;
            }

            $error = (int) $errorFila;
            $errores[] = $error;

            if (($fila->evidencia ?? '') === 'cadena') {
                $erroresCadena[] = $error;
            }
        }

        $salida['residuos_de'] = $contraProbable ? 'probable' : 'normal';

        $salida['n'] = count($errores);
        $salida['desde'] = $corrida->desde->toDateString();
        $salida['hasta'] = $corrida->hasta->toDateString();
        $salida['mae'] = $corrida->resumen_motor->mae_min ?? null;
        $salida['cobertura_actual'] = $corrida->resumen_motor->pct_ventana ?? null;

        if (count($errores) < $salida['minimo']) {
            $salida['error'] = 'La corrida tiene ' . count($errores) . ' filas y el mínimo es '
                . $salida['minimo'] . ': alargá el rango antes de creerle a la ventana.';

            return $salida;
        }

        $absolutos = array_map(fn (int $error) => abs($error), $errores);
        $radio = (int) round((float) Estadistica::cuantil($absolutos, $cobertura));
        $dentro = count(array_filter($absolutos, fn (int $error) => $error <= $radio));

        $salida['radio'] = $radio;
        $salida['adelanto'] = $radio;
        $salida['atraso'] = $radio;
        $salida['cobertura_lograda'] = (int) round(100 * $dentro / count($errores));
        $salida['sesgo'] = (int) round((float) Estadistica::mediana($errores));

        // La CADENA (sin pares medidos) se equivoca mucho más: su banda va aparte
        // y sólo si hay muestra suficiente para creerle.
        $minimoCadena = max(10, (int) round($salida['minimo'] / 2));
        $salida['cadena_n'] = count($erroresCadena);
        if (count($erroresCadena) >= $minimoCadena) {
            $absolutosCadena = array_map(fn (int $error) => abs($error), $erroresCadena);
            $radioCadena = (int) round((float) Estadistica::cuantil($absolutosCadena, $cobertura));
            $dentroCadena = count(array_filter($absolutosCadena, fn (int $error) => $error <= $radioCadena));

            $salida['cadena_radio'] = $radioCadena;
            $salida['cadena_adelanto'] = $radioCadena;
            $salida['cadena_atraso'] = $radioCadena;
            $salida['cadena_cobertura'] = (int) round(100 * $dentroCadena / count($erroresCadena));
        }

        $salida['ok'] = true;

        return $salida;
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

        // la cola de la planta (C1) no se evalúa en la tabla de aciertos y sería una
        // consulta por fila: se apaga acá, no en la configuración
        $p = clone $p;
        $p->cola_activa = 0;
        // El rango se cierra por DÍA: si llega "2026-11-13" como hasta, tiene que
        // incluir todo ese día, no la medianoche (con un instante el rango
        // hoy/hoy devolvía 0 filas).
        $desde = (self::carbon($filtros['desde'] ?? null) ?? Carbon::now()->subDays(30))->startOfDay();
        $hasta = (self::carbon($filtros['hasta'] ?? null) ?? Carbon::now())->endOfDay();
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta->copy(), $desde->copy()];
        }
        $placa = trim((string) ($filtros['placa'] ?? ''));

        // `limite` es cuántas refs se EVALÚAN (las más recientes del rango), no
        // cuántas se muestran. 0 = todas, acotado por TOPE_EVALUACION para que la
        // pantalla responda: el rango de un año tiene ~27.000 pares.
        $pedido = (int) ($filtros['limite'] ?? 200);
        $limite = $pedido <= 0 ? self::TOPE_EVALUACION : max(1, min(self::TOPE_EVALUACION, $pedido));

        $aprendizajeDesde = $desde->copy()->subDays(max(1, (int) $p->lookback_dias));

        $etapas = DBPrediccion::etapas_por_ruta($aprendizajeDesde, $desde);
        $rutas = DBPrediccion::rutas();

        // filtro por PLANTA DE CARGA (local donde el camión se presenta). Sólo el
        // lado que se EVALÚA: la calibración de cada placa se aprende de toda su
        // historia, si no el filtro dejaría a las placas sin pares y el motor
        // predeciría con la cadena. Va en la corrida y en la de la ventana.
        $planta = trim((string) ($filtros['planta'] ?? ''));

        $paresAprendizaje = DBPrediccion::pares(null, $aprendizajeDesde, $desde,
            $placa !== '' ? $placa : null, null, 20000);

        $pares = DBPrediccion::pares(null, $desde, $hasta, $placa !== '' ? $placa : null, null, $limite,
            $planta !== '' ? $planta : null);

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

        // Ventana rápido/tardado medida (residuos del backtest). Se le pasa al
        // motor igual que en producción: antes NO se pasaba, así que la tabla de
        // aciertos medía la banda p25-p75 del hueco (~35 % de cobertura) mientras
        // el API publicaba la banda medida. Dos números distintos para la misma
        // pregunta.
        $ventanaMedida = null;
        if ((int) ($p->ventana_residuos ?? 0) === 1) {
            $adelantoVentana = max(0, (int) ($p->ventana_adelanto_min ?? 0));
            $atrasoVentana = max(0, (int) ($p->ventana_atraso_min ?? 0));

            if ($adelantoVentana + $atrasoVentana > 0) {
                $ventanaMedida = (object) [
                    'adelanto' => $adelantoVentana,
                    'atraso' => $atrasoVentana,
                    'adelanto_cadena' => max(0, (int) ($p->ventana_adelanto_cadena ?? 0)),
                    'atraso_cadena' => max(0, (int) ($p->ventana_atraso_cadena ?? 0)),
                    'cobertura' => (float) ($p->ventana_cobertura ?? 0.7),
                ];
            }
        }

        $filas = [];
        $erroresMotor = [];
        $dentroMotor = [];
        $erroresProbable = [];
        $dentroProbable = [];
        $ventanasCache = [];
        $descartados = 0;
        $sinRuta = 0;
        $fueraTope = 0;
        // tope de horizonte para las MÉTRICAS, en HORAS (0 = sin tope): las filas
        // más allá quedan en la tabla pero no arrastran el MAE
        $horizonteMax = max(0, (int) ($filtros['horizonte_max_h'] ?? 0)) * 60;
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
                    $etapas, $rutaSiguiente !== '' ? $rutaSiguiente : null, (int) $p->traslado_defecto_min,
                    // el backtest tiene que medir el MISMO traslado que producción:
                    // ruta → placa → día y hora, desde el ancla (ahí queda libre)
                    false, $par->ancla instanceof Carbon ? $par->ancla : null,
                    fn (string $ruta): ?object => self::viaje_ruta($ruta, $etapas, $par->ancla, $par->placa,
                        $desde, $hasta, $p),
                    $p)
                : null;
            $traslado = self::limitar_traslado($traslado, $p);

            // Calibración as-of, con la MISMA base y la MISMA regla de grupo que
            // producción (ver clave_calibracion()): la placa, salvo que el
            // conductor de esta ref concentre menos de la mitad de los viajes
            // previos de la placa. La base son TODOS los pares previos de la
            // placa, etiquetados por conductor cuando se calibra por chofer: si
            // ese chofer no tiene pares suficientes, el motor cae al par de
            // rutas y después a la mediana de la placa (como en producción).
            $conductorFila = $par->conductor !== null ? (int) $par->conductor : null;
            $previosTodos = self::pares_previos($historialPorPlaca[$par->placa] ?? [], $par->ancla, 10000);

            // C2: el factor por día se mide con TODOS los pares previos (producción
            // usa también los de la placa sin filtrar), no con el subconjunto
            // "cerca" que se arma más abajo
            $factoresFila = self::factores_dia_fila($p, $previosTodos, (string) $rutaSiguiente);

            // C3: el grupo se decide por muestra, con la misma regla que producción
            $nChoferFila = 0;
            if ($conductorFila !== null) {
                foreach ($previosTodos as $previo) {
                    if ((int) ($previo->conductor ?? 0) === $conductorFila) {
                        $nChoferFila++;
                    }
                }
            }

            [$grupoCalibracion, $claveCalibracion] = self::clave_calibracion(
                $par->placa, $conductorFila, $nChoferFila, count($previosTodos),
                (int) ($p->min_pares_entidad ?? 3));

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
                    (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max,
                    (int) ($p->curva_modo ?? 0), (int) ($p->curva_k ?? 5),
                    (int) ($p->entidad_pares_k ?? 0), (int) ($p->hueco_debil ?? 0));

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
                // C2: la tabla de aciertos tiene que predecir con la MISMA regla
                // que producción (si no, la fila y el API dan horas distintas)
                'factor_dia' => $factoresFila,
                'dia_modo' => (int) ($p->dia_modo ?? Calibrador::DIA_FLOTA),
                'feriado_activo' => (int) ($p->feriado_activo ?? 1),
                'ventana_residuos' => $ventanaMedida,
                'ventanas' => $ventanasCache[$llaveVentana]['ventanas'],
                'espera_carga_min' => DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
                    $rutaSiguiente !== '' ? $rutaSiguiente : null),
                'parametros' => $p,
            ]);

            $real = $par->presenta;
            $predicho = $resultado->presenta;
            $error = $predicho !== null ? self::minutos($real, $predicho) : null;

            // la CUARTA fecha: la publicada corregida por el sesgo de su clase
            $probable = $resultado->presenta_probable ?? null;
            $errorProbable = $probable !== null ? self::minutos($real, $probable) : null;

            // la QUINTA: el consenso (promedio de la rápida, la normal y la cuarta)
            $consenso = $resultado->presenta_consenso ?? null;
            $errorConsenso = $consenso !== null ? self::minutos($real, $consenso) : null;

            // ACIERTO de la predicción de tres fechas: la hora real cae dentro de
            // [rápido, tardado]. Es el número que se muestra como "% de acierto".
            $rapido = $resultado->presenta_rapido ?? null;
            $tardado = $resultado->presenta_tardado ?? null;
            $dentroFila = $rapido !== null && $tardado !== null
                && $real->greaterThanOrEqualTo($rapido) && $real->lessThanOrEqualTo($tardado);

            // el error de las cuatro fechas: sin el de la rápida y la tardada la
            // tabla no puede decir cuál quedó más cerca (comparaba sólo dos)
            $errorRapido = $rapido !== null ? self::minutos($real, $rapido) : null;
            $errorTardado = $tardado !== null ? self::minutos($real, $tardado) : null;

            // HORIZONTE: cuánto tiempo pasó entre el corte y la presentación real.
            // Por encima del tope, la fila NO entra en las métricas del motor: son
            // los casos donde el camión quedó días sin volver y ninguna corrección
            // del hueco puede acertar (medido: >24 h = 43 % de las filas y 70 % del
            // error absoluto, con 6 % de acierto a ±3 h). La fila se sigue viendo.
            $horizonteFila = (int) round(($real->getTimestamp() - $par->ancla->getTimestamp()) / 60);
            $fueraDelTope = $horizonteMax > 0 && $horizonteFila > $horizonteMax;

            if ($fueraDelTope) {
                $fueraTope++;
            } elseif ($error !== null) {
                $erroresMotor[] = $error;
                $dentroMotor[] = $dentroFila;

                if ($errorProbable !== null) {
                    $erroresProbable[] = $errorProbable;
                    $dentroProbable[] = $probable->greaterThanOrEqualTo($rapido) && $probable->lessThanOrEqualTo($tardado);
                }
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
                'probable' => $probable,
                'error_probable_min' => $errorProbable,
                'consenso' => $consenso,
                'error_consenso_min' => $errorConsenso,
                'error_rapido_min' => $errorRapido,
                'error_tardado_min' => $errorTardado,
                'sesgo_clase' => $resultado->sesgo_clase ?? null,
                'sesgo_min' => (int) ($resultado->sesgo_min ?? 0),
                'horizonte_min' => $horizonteFila,
                'fuera_tope' => $fueraDelTope,
            ];
        }

        $resumenMotor = Aciertos::resumir($erroresMotor, $dentroMotor);
        // la cuarta fecha se mide aparte para ver si de verdad gana
        $resumenProbable = Aciertos::resumir($erroresProbable, $dentroProbable);

        // el consenso se mide igual: es una fecha publicada más
        $erroresConsenso = [];
        foreach ($filas as $fila) {
            if (!$fila->fuera_tope && $fila->error_consenso_min !== null) {
                $erroresConsenso[] = $fila->error_consenso_min;
            }
        }
        $resumenConsenso = Aciertos::resumir($erroresConsenso);

        \App\Prediccion\Geoapify::activar();

        return (object) [
            'filas' => $filas,
            'resumen_motor' => $resumenMotor,
            'resumen_probable' => $resumenProbable,
            'resumen_consenso' => $resumenConsenso,
            // carrera de las fechas publicadas: cuántas veces gana cada una
            'carrera' => Aciertos::carrera($filas),
            'sesgo_activo' => (int) ($p->sesgo_clase_activo ?? 0) === 1,
            'parametros' => $p,
            'desde' => $desde,
            'hasta' => $hasta,
            'aprendizaje_desde' => $aprendizajeDesde,
            'pares' => count($filas),
            'descartados' => $descartados,
            'sin_ruta' => $sinRuta,
            // cuántas refs se evaluaron y si se topó con TOPE_EVALUACION (0 = todas)
            'limite' => $limite,
            'limite_pedido' => $pedido,
            'truncado' => $limite >= self::TOPE_EVALUACION,
            // filas fuera del tope de horizonte: se ven pero no cuentan en las métricas
            'horizonte_max_h' => $horizonteMax > 0 ? intdiv($horizonteMax, 60) : 0,
            'horizonte_max' => $horizonteMax,
            'fuera_tope' => $fueraTope,
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
     * Resuelve la ubicación que el operador escribió como TEXTO LIBRE.
     *
     * Primero busca en el catálogo (ubigeo por nombre de distrito, o local por
     * nombre). Si no aparece, geocodifica el texto con Geoapify y devuelve un
     * punto (lat/lng) para rutear desde ahí. Si el texto es ambiguo (p. ej. "SAN
     * JOSE", hay tres distritos) devuelve la lista de candidatos: adivinar es peor
     * que preguntar.
     *
     * @return array{encontrada: bool, ambigua: bool, tipo: ?string, id: ?string, texto: string,
     *               fuente: ?string, lat?: float, lng?: float,
     *               candidatos: array<int, array{id: string, texto: string}>}
     */
    public static function resolver_ubicacion_texto(string $texto): array
    {
        $texto = trim($texto);
        $resolucion = DBPrediccion::resolver_ubicacion($texto);

        if (($resolucion['ambigua'] ?? false) === true) {
            $resolucion['detalle'] = 'coincide con varios distritos/locales: usá el ubigeo o el nombre completo';

            return $resolucion;
        }

        if (($resolucion['encontrada'] ?? false) === true) {
            return $resolucion;
        }

        $geos = Geoapify::geocodificar_texto($texto);

        if ($geos === []) {
            return $resolucion;   // no está y no se pudo geocodificar
        }

        // un solo lugar: listo
        $distintos = self::lugares_distintos($geos, $texto);

        if (count($distintos) === 1) {
            return [
                'encontrada' => true,
                'ambigua' => false,
                'tipo' => 'geopunto',
                'id' => null,
                'texto' => $distintos[0]->texto,
                'fuente' => 'geoapify',
                'lat' => $distintos[0]->lat,
                'lng' => $distintos[0]->lng,
                'candidatos' => [],
            ];
        }

        // varios lugares de verdad (a más de 50 km): no se adivina
        $candidatos = [];
        foreach ($distintos as $geo) {
            $candidatos[] = ['id' => '', 'texto' => $geo->texto];
        }

        return [
            'encontrada' => false,
            'ambigua' => true,
            'tipo' => 'geopunto',
            'id' => null,
            'texto' => $texto,
            'fuente' => 'geoapify',
            'detalle' => 'Geoapify encontró varios lugares con ese nombre: agregá la provincia o el departamento',
            'candidatos' => $candidatos,
        ];
    }

    /** Distancia aproximada entre dos puntos, en km (haversine). */
    public static function distancia_km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radio = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $radio * asin(min(1.0, sqrt($a)));
    }

    /**
     * Los resultados de Geoapify que son LUGARES DISTINTOS.
     *
     * Geoapify devuelve varias "variantes" del mismo punto (la carretera, el
     * centro poblado, el distrito): si están a menos de 50 km es el mismo lugar y
     * se toma el primero. Cuando hay dos de verdad lejos (PASAMAYO en Lima y en
     * San Martín) se devuelven los dos. Además se priorizan los resultados cuyo
     * nombre contiene lo que se escribió (para "PASAMAYO, HUARAL" no quedarse con
     * la ciudad de Huaral).
     *
     * @param  array<int, object>  $geos
     * @return array<int, object>
     */
    private static function lugares_distintos(array $geos, string $texto): array
    {
        $termino = trim(explode(',', $texto)[0]);

        if ($termino !== '') {
            $conNombre = array_values(array_filter($geos, function (object $geo) use ($termino): bool {
                return mb_stripos((string) ($geo->nombre ?? ''), $termino) !== false
                    || mb_stripos((string) $geo->texto, $termino) !== false;
            }));

            if ($conNombre !== []) {
                $geos = $conNombre;
            }
        }

        $distintos = [];
        foreach ($geos as $geo) {
            $cerca = false;
            foreach ($distintos as $elegido) {
                if (self::distancia_km($geo->lat, $geo->lng, $elegido->lat, $elegido->lng) <= 50) {
                    $cerca = true;
                    break;
                }
            }

            if (!$cerca) {
                $distintos[] = $geo;
            }
        }

        return $distintos;
    }

    /**
     * Coordenadas declaradas (las devuelve resolver_ubicacion_texto cuando hubo
     * que geocodificar): se aceptan como array u objeto.
     */
    public static function coords_declaradas($valor): ?object
    {
        if (is_object($valor)) {
            $valor = (array) $valor;
        }

        if (!is_array($valor) || !isset($valor['lat'], $valor['lng'])
            || !is_numeric($valor['lat']) || !is_numeric($valor['lng'])) {
            return null;
        }

        return (object) [
            'lat' => (float) $valor['lat'],
            'lng' => (float) $valor['lng'],
            'texto' => (string) ($valor['texto'] ?? ''),
        ];
    }

    /**
     * ¿La posición declarada es la planta de carga?
     *
     * Dos formas de decirlo: por local (`LOC_CARAL`) o por ubigeo (150601). Cuando
     * es así el camión ya está donde tiene que cargar: no hay traslado ni viaje que
     * esperar, sólo la espera en planta.
     *
     * NO se compara por coordenadas a propósito: para eso habría que geocodificar
     * la planta (gasta cuota) y cualquier punto geocodificado "parecido" daría un
     * falso positivo. Un punto declarado sobre la planta ya sale con traslado ~0.
     */
    private static function en_planta(string $tipo, string $id, string $ubigeo,
                                      string $plantaLocal, string $plantaUbigeo): bool
    {
        $plantaLocal = trim($plantaLocal);
        $plantaUbigeo = trim($plantaUbigeo);

        if ($plantaLocal === '' && $plantaUbigeo === '') {
            return false;
        }

        if ($tipo === 'local' && $id !== '' && $id === $plantaLocal) {
            return true;
        }

        return $ubigeo !== '' && $plantaUbigeo !== '' && $ubigeo === $plantaUbigeo;
    }

    /**
     * Lo que le falta al viaje en curso desde la posición declarada del camión
     * hasta el DESTINO de ese viaje (el destino del ancla), en minutos.
     *
     * Es la respuesta a "el camión está en tal lado, ¿cuánto le falta para
     * llegar?". Cuatro fuentes, de la más a la menos específica:
     *  1. punto geocodificado (texto libre) -> ruteo real de Geoapify;
     *  2. local/ubigeo del catálogo -> ruteo real de Geoapify con las lat/lng de los
     *     dos extremos (el ubigeo se busca en `geo_coordenadas` y, si no está, se
     *     GEOCODIFICA con Geoapify);
     *  3. sin ruteo -> distancia RECTA entre los dos puntos con el ajuste de viaje
     *     PARCIAL (manejo sin paradas, velocidad de camión): usar el ajuste del
     *     viaje completo contaba los descansos dos veces y daba 15:50 para un
     *     tramo de 6 h. Se acota a 15 km/h en línea recta y se avisa `tramo_lento`;
     *  4. lo medido entre esos dos ubigeos (compilados) y, si tampoco hay, NULL.
     *
     * Si la posición declarada no se puede ubicar de NINGUNA forma (ni catálogo, ni
     * Geoapify, ni ruta medida), devuelve un objeto con `error`
     * `ubicacion_no_geolocalizable` en vez de inventar el tramo.
     *
     * @param  array<string, array<string, int|null>>  $etapas  salida de etapas_por_ruta()
     */
    private static function tramo_hacia_destino(?object $coords, string $desdeUbigeo, string $desdeTexto,
                                                ?object $ancla, array $etapas, ?string $rutaAncla,
                                                ?Carbon $salida = null, ?object $p = null,
                                                string $desdeLocal = ''): ?object
    {
        if ($ancla === null) {
            return null;
        }

        $p = $p ?? Parametros::cargar();

        $destinoLocal = (string) ($ancla->destino_id ?? '');
        $destinoUbigeo = (string) ($ancla->ubigeo_destino ?? '');
        if ($destinoUbigeo === '' && $destinoLocal !== '') {
            $destinoUbigeo = (string) (self::ubigeo_local($destinoLocal) ?? '');
        }

        if ($destinoLocal === '' && $destinoUbigeo === '') {
            return null;
        }

        $destinoTexto = DBPrediccion::texto_ubicacion($destinoLocal !== '' ? $destinoLocal : null,
            $destinoUbigeo !== '' ? $destinoUbigeo : null);
        $desde = $desdeTexto !== '' ? $desdeTexto : 'la ubicación declarada';

        $respuesta = function (int $min, $km, string $fuente, ?string $aviso = null) use ($desde, $destinoTexto): object {
            return (object) [
                'min' => max(1, $min),
                'km' => $km,
                'desde' => $desde,
                'hasta' => $destinoTexto !== '' ? $destinoTexto : 'el destino del viaje',
                'fuente' => $fuente,
                'aviso' => $aviso,
            ];
        };

        // 1) punto geocodificado: lo que hay que manejar desde ahí. El ubigeo de
        // la punta conocida del viaje (su origen) es lo que dice si toca la
        // capital y, con la hora, si corresponde pedir el tiempo con tráfico.
        if ($coords !== null) {
            $geo = Geoapify::ruta_desde_coords($coords->lat, $coords->lng,
                $destinoUbigeo !== '' ? $destinoUbigeo : null, $salida,
                $desdeUbigeo !== '' ? $desdeUbigeo : (string) ($ancla->ubigeo_origen ?? ''));

            return $geo !== null ? $respuesta((int) $geo->min, $geo->km, 'geoapify') : null;
        }

        if ($desdeUbigeo === '') {
            return null;
        }

        // 2) local/ubigeo declarado: ruteo REAL con las lat/lng de los dos extremos
        // (Geoapify ya resuelve el ubigeo a coordenadas, geocodificándolo si hace falta)
        if ($destinoUbigeo !== '') {
            $geo = Geoapify::ruta($desdeUbigeo, $destinoUbigeo, $salida);
            if ($geo !== null) {
                return $respuesta((int) $geo->min, $geo->km, 'geoapify');
            }
        }

        // 3) sin ruteo: distancia recta con el ajuste de tramo PARCIAL. Es lo que le
        // falta al camión AHORA: manejo casi sin paradas, velocidad de camión. Se
        // pasan las coordenadas del LOCAL declarado cuando lo hay: `locales.lat/lng`
        // es mejor dato que el centroide de su distrito.
        $porCoordenadas = DBPrediccion::tramo_por_coordenadas(
            $desdeLocal !== '' ? $desdeLocal : null,
            $desdeUbigeo !== '' ? $desdeUbigeo : null,
            $destinoLocal !== '' ? $destinoLocal : null,
            $destinoUbigeo !== '' ? $destinoUbigeo : null,
            $p, null, true);

        if ($porCoordenadas !== null) {
            return $respuesta((int) $porCoordenadas->min, $porCoordenadas->km ?? null, 'coordenadas',
                $porCoordenadas->aviso ?? null);
        }

        // 4) lo medido para ese par (o su mediana por departamentos)
        $medido = DBPrediccion::traslado($destinoLocal !== '' ? $destinoLocal : null, null,
            $desdeUbigeo, $destinoUbigeo !== '' ? $destinoUbigeo : null, $etapas, $rutaAncla, null, true,
            $salida, null, $p);

        if ($medido !== null && (string) ($medido->fuente ?? '') !== 'defecto') {
            return $respuesta((int) $medido->min, $medido->km ?? null, (string) $medido->fuente);
        }

        // nada pudo ubicar la posición declarada: ni coordenadas (del local ni del
        // ubigeo), ni ruteo, ni una ruta medida para ese par. Es un problema del
        // pedido, no una predicción rara.
        $puntoDeclarado = DBPrediccion::coord_ubicacion($desdeLocal !== '' ? $desdeLocal : null,
            $desdeUbigeo !== '' ? $desdeUbigeo : null);

        if ($puntoDeclarado === null) {
            return (object) [
                'error' => 'ubicacion_no_geolocalizable',
                'ubigeo' => $desdeUbigeo !== '' ? $desdeUbigeo : $desdeLocal,
                'texto' => $desde,
            ];
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

    /**
     * Mezcla el viaje COMPILADO de la ruta con el de la placa/chofer.
     *
     * `w = n/(n+k)`: con pocos viajes propios manda el compilado (población); con
     * muchos, lo que hace la placa. Así un camión que siempre tarda 10 % más no
     * queda tapado por el promedio de la flota, y dos viajes raros no mueven la
     * aguja.
     */
    public static function mezclar_viaje(?int $compilado, ?int $entidad, int $n, int $k = 3): ?int
    {
        if ($entidad === null || $n <= 0) {
            return $compilado;
        }
        if ($compilado === null) {
            return $entidad;
        }

        $k = max(0, $k);
        $w = $k === 0 ? 1.0 : $n / ($n + $k);

        return (int) round($w * $entidad + (1 - $w) * $compilado);
    }

    /**
     * Tiempo de VIAJE de una ruta: la MISMA cadena para el tramo que le falta al
     * camión y para el traslado de regreso.
     *
     * Antes el traslado miraba sólo el promedio diario de la ruta: no abría el
     * compilado POR DÍA Y HORA, así que un regreso a las 20 h de un viernes se
     * estimaba con la mediana del día. Los dos procesos son el mismo problema (cuánto
     * tarda el camión en un tramo concreto), así que se resuelven igual:
     *
     *   1. lo medido de la ruta (`DBPrediccion::viaje_ruta_base()`: mediana
     *      compilada o, sin compilado, mediana de las referencias);
     *   2. mezclado con lo que ESA placa tarda en esa misma ruta (w = n/(n+k));
     *   3. mezclado con el compilado por día y hora de esa ruta (w = n/(n+k)).
     *
     * Los promedios de ida y vuelta son casi los mismos: la incertidumbre del
     * modelo no está en el tránsito sino en lo que el conductor descansa, come o
     * duerme, que el motor suma aparte como tiempo muerto.
     *
     * Devuelve null si esa ruta no tiene NADA medido: ahí decide el que llama
     * (Geoapify, la flota o el valor por defecto).
     *
     * @param  array<string, array<string, int|null>>  $etapas  salida de etapas_por_ruta()
     * @return object{min: int, base: int, tras_placa: int, placa: ?int, placa_n: int,
     *                celda: ?int, celda_viajes: int, celda_dia: ?int, celda_hora: ?int}|null
     */
    public static function viaje_ruta(string $ruta, array $etapas, ?Carbon $salida = null,
                                      string $placa = '', ?Carbon $desde = null, ?Carbon $hasta = null,
                                      ?object $p = null): ?object
    {
        $ruta = trim($ruta);
        if ($ruta === '') {
            return null;
        }

        $p = $p ?? Parametros::cargar();

        $base = DBPrediccion::viaje_ruta_base($ruta, $etapas);
        if ($base === null) {
            return null;
        }

        $min = $base;
        $placaMediana = null;
        $placaN = 0;

        if ($placa !== '' && $desde !== null && $hasta !== null) {
            $propio = DBPrediccion::viaje_placa($placa, $ruta, $desde, $hasta);
            $placaMediana = $propio['mediana'] ?? null;
            $placaN = (int) ($propio['n'] ?? 0);
            $min = self::mezclar_viaje($min, $placaMediana, $placaN, (int) ($p->cruce_pares_k ?? 3)) ?? $min;
        }

        $trasPlaca = $min;
        $celda = null;

        // El compilado por día y hora de la RUTA (tiempos_ruta_horario): ahí está la
        // demora de la hora pico, que la mediana diaria no tiene. Entra con el peso
        // de la muestra de la celda, igual que la placa: dos viajes no mueven la aguja.
        if ((int) ($p->horario_activo ?? 1) === 1 && $salida !== null) {
            $celda = DBPrediccion::viaje_horario($ruta, $salida, (int) ($p->horario_viajes_min ?? 3));

            if ($celda !== null) {
                $conHorario = self::mezclar_viaje($min, (int) $celda->min,
                    (int) $celda->viajes, (int) ($p->horario_viajes_k ?? 3));

                if ($conHorario !== null) {
                    $min = $conHorario;
                }
            }
        }

        return (object) [
            'min' => (int) $min,
            'base' => (int) $base,
            'tras_placa' => (int) $trasPlaca,
            'placa' => $placaMediana,
            'placa_n' => $placaN,
            'celda' => $celda !== null ? (int) $celda->min : null,
            'celda_viajes' => $celda !== null ? (int) $celda->viajes : 0,
            'celda_dia' => $celda !== null ? (int) $celda->dia : null,
            'celda_hora' => $celda !== null ? (int) $celda->hora : null,
        ];
    }

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
     * De quién sale el hueco: del chofer o de la placa (C3).
     *
     * Se decide por MUESTRA, no por el porcentaje de viajes del chofer. Se usa el
     * chofer cuando tiene al menos `$minimo` pares propios Y al menos la mitad de
     * los pares de la placa; si no, manda la placa. Con la regla anterior (el
     * chofer "habitual" con menos de la mitad de los viajes de la placa) un chofer
     * con 2 pares podía fijar el hueco de un camión con 40, y un camión que cambió
     * de chofer quedaba calibrado por alguien que ya no lo maneja.
     *
     * @return array{0: string, 1: string} [grupo, clave]
     */
    public static function clave_calibracion(?string $placa, ?int $conductorId, int $nChofer = 0,
                                            int $nPlaca = 0, int $minimo = 3): array
    {
        $placa = trim((string) $placa);

        if ($placa === '') {
            return ['placa', ''];
        }

        if ($conductorId !== null && $conductorId > 0 && $nChofer >= max(1, $minimo)
            && $nChofer * 2 >= max(1, $nPlaca)) {
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

    /**
     * Factor del hueco por día de la semana, según `dia_modo`.
     *
     *   0 apagado   -> null (el motor no aplica nada)
     *   1 flota     -> factor de la placa y prior de la flota en los días sin muestra
     *   2 placa     -> sólo factor propio; los días sin muestra quedan en 1
     *   3 compilado -> factor del día medido en `tiempos_ruta_horario` para la RUTA
     *                  (la pantalla "Compilar promedios por día y hora"); null si
     *                  la ruta no tiene celda
     *
     * `$ruta` es la ruta de la referencia que se está prediciendo: el día de la
     * semana afecta cuánto tarda el viaje, y eso está compilado por ruta.
     *
     * @param  array<int, object>  $pares  pares previos de la placa
     * @return array<int, float>|null índice 0 = lunes
     */
    private static function factores_dia_fila(stdClass $p, array $pares, string $ruta): ?array
    {
        if ((int) ($p->dia_activo ?? 1) !== 1) {
            return null;
        }

        $modo = (int) ($p->dia_modo ?? Calibrador::DIA_FLOTA);

        if ($modo === Calibrador::DIA_APAGADO) {
            return null;
        }

        if ($modo === Calibrador::DIA_COMPILADO) {
            $compilado = DBPrediccion::factores_dia_compilado((int) ($p->dia_compilado_k ?? 10));

            return $compilado[$ruta] ?? null;
        }

        return Calibrador::factores_dia($pares, (int) ($p->dia_min_pares ?? 5), $modo);
    }

    /**
     * Recorta el traslado de vuelta cuando el compilado trae un promedio absurdo.
     *
     * El traslado sale de `tiempos_ruta_promedio`; hay tramos con promedios de
     * días (medido: uno de 2.961 min = 49 h). Un regreso de más de un día no es un
     * regreso, es un dato mal compilado, y en la evidencia `cadena` ese número ES
     * el hueco: arrastra la predicción 40 h. Con `traslado_max_min` en 0 no se
     * toca nada.
     */
    private static function limitar_traslado(?object $traslado, stdClass $p): ?object
    {
        $tope = (int) ($p->traslado_max_min ?? 0);

        if ($traslado === null || $tope <= 0) {
            return $traslado;
        }

        if ((int) ($traslado->min ?? 0) <= $tope) {
            return $traslado;
        }

        $traslado->min = $tope;
        $traslado->fuente = 'tope';
        $traslado->recortado_desde = (int) ($traslado->min_original ?? $tope);

        return $traslado;
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
