<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parámetros del motor de predicción de carga
    |--------------------------------------------------------------------------
    |
    | Estos son los valores por defecto. La pantalla de predicción puede
    | sobreescribirlos y se guardan en la tabla `prediccion_config` (clave/valor).
    | Si la tabla no existe todavía en el servidor, el cálculo usa exactamente
    | estos valores y nunca revienta.
    |
    */

    // Ventana de historia que se mira hacia atrás para calibrar y para las franjas.
    'lookback_dias' => 120,

    // Mínimos de observaciones para cada nivel de evidencia del hueco.
    'min_pares_ruta' => 3,
    'min_pares_entidad' => 3,

    // Pares más recientes por placa con los que se calibra el hueco (0 = toda la
    // historia). El hueco mediano de la flota cae mes a mes: calibrar con 120 días
    // parejos sobreestima la vuelta en casi medio día.
    //
    // Medido el 14/11/2026 (día compilado + curva encogida):
    //   pares    AGO-NOV            MAY-AGO
    //    5    1360 · ±3h 29%     1286 · ±3h 31%
    //   10    1303 · ±3h 27%     1192 · ±3h 28%   <- mejor MAE de los dos rangos
    //   20    1294 · ±3h 25%     1206 · ±3h 26%
    //   40    1309 · ±3h 23%     1231 · ±3h 25%
    //   todo  1317 · ±3h 22%     1257 · ±3h 23%
    // Con 10 el MAE está en el mínimo y ±3h queda 2 puntos arriba que con 20.
    'pares_recientes_max' => 10,

    // Franjas de no-actividad: días mínimos para creerle al perfil propio y
    // cuántas marcas (hitos) tiene que haber en una franja de 30 min para que
    // cuente como activa. Con 2, una franja donde el camión nunca hizo nada en
    // todo el lookback es descanso o almuerzo.
    'min_dias_actividad' => 5,
    'min_hitos_bin' => 2,
    'granularidad_min' => 30,

    // Franjas de 3 h para la corrección del hueco por hora del ancla.
    'bins_horarios' => 8,

    // Qué hacer con esa corrección por hora (`Calibrador::curva()`).
    //   0 = como siempre: se SUMA el residuo absoluto (medido contra la mediana
    //       de la placa) a la base del nivel elegido, sin importar la muestra.
    //   1 = encogida y proporcional: el residuo entra como proporción de la base
    //       (`residuo/base_entidad * base_elegida`) y pesa n/(n+curva_k), así una
    //       franja con 3 pares apenas mueve la hora.
    //   2 = apagada.
    //
    // Medido el 14/11/2026 con 788 filas del backtest (ago->nov):
    //   modo 0 (el de antes)  MAE 1568 · +-3h 23 %
    //   modo 2 (apagada)      MAE 1503 · +-6h 36 %   (-65 min)
    // El modo 0 mezcla dos escalas: el residuo se mide contra la mediana de la
    // PLACA y se suma a la base del PAR DE RUTAS, que puede ser 2-3 veces mayor.
    'curva_modo' => 1,
    'curva_k' => 5,

    // "Cerca": si el camión descargó a menos de estos minutos de la planta donde
    // vuelve a cargar (o en el mismo local/distrito), el hueco se calibra sólo con
    // los viajes en que también quedó cerca. Medido con la flota: quedando cerca
    // la mediana es 138 min y carga en ≤3 h el 54 % de las veces; lejos, 1.020 min
    // y 7 %. Sin ese condicionamiento el motor esperaba medio día en un caso que
    // carga enseguida.
    'cerca_min' => 60,

    // Cruce de fuentes para el VIAJE que le falta al camión: el compilado de la
    // ruta (población) se mezcla con lo que esa placa/chofer tarda en la misma
    // ruta (entidad) con peso w = n/(n+k). Con k=3: 1 viaje propio pesa 25 %, 3
    // viajes 50 %, 10 viajes 77 %. Más k = más confianza en el compilado.
    'cruce_pares_k' => 3,

    // Cruce de fuentes para el HUECO (la vuelta): hoy es un cambio DURO — si el
    // par de rutas tiene `min_pares_ruta` pares, manda el par de rutas y la placa
    // no se mira. Medido el 14/11/2026: las filas con evidencia "medido" tienen
    // MAE 1.252 contra 952 de "parcial", o sea el par de rutas NO es mejor que la
    // mediana de la placa. Con `entidad_pares_k` > 0 se mezclan igual que el viaje:
    //     hueco = w·(par de rutas) + (1-w)·(placa)   con w = n/(n+k)
    // Se probó con k = 3, 6 y 12 y NO movió el MAE (1.220 -> 1.218/1.217): el
    // nivel "medido" son pocas filas (31 de 482) y no alcanza para decidir. Queda
    // en 0 (cambio duro) hasta tener más muestra.
    'entidad_pares_k' => 0,

    // Cuando NO hay pares suficientes para el hueco (evidencia "cadena"), el motor
    // arma traslado + tiempo muerto (~300 min). Medido el 14/11/2026: 78 de las
    // 482 filas caen ahí con MAE 2.690 y una mediana de -2.623 min (predice 43 h
    // ANTES de la mediana de su propia placa). Esta clave es la MUESTRA MÍNIMA del
    // respaldo: con ella, si la placa (o la flota) tiene al menos N huecos, se usa
    // esa mediana en vez de traslado+muerto, y el nivel sigue siendo "cadena"
    // (la confianza no sube).
    //   OJO MEDIDO: es una moneda al aire. AGO-NOV con 2: MAE 1.254 -> 1.206 (-48);
    //   MAY-AGO con 2: 1.264 -> 1.313 (+49). Por eso queda APAGADO en 0.
    //   Si en producción querés probarlo: poner 2 y medir con la ablación.
    'hueco_debil' => 0,

    // Una franja necesita al menos `min_pares_entidad` pares para corregir: con
    // menos, la "mediana" de la franja es un par suelto y una noche de 54 h en la
    // franja de las 19 corría la predicción dos días (ver Calibrador::curva()).

    // El viaje compilado tiene versión POR HORA de salida (`tiempos_ruta_horario`,
    // lo que sirve /api/tiempos/promedio/horario): ahí está la demora de la hora
    // pico, que la mediana diaria no tiene. En RUT_CARAL-BABEL_MAXO el martes a
    // las 04 h el promedio es 358 min y a las 23 h 233: más de 2 h de diferencia.
    // Se mezcla con el viaje que ya se calculó igual que el resto (w = n/(n+k)),
    // donde n son los viajes de esa celda día/hora. Con `horario_viajes_min` se
    // exige muestra: una celda con 1 viaje no mueve nada.
    'horario_activo' => 1,
    'horario_viajes_k' => 3,
    'horario_viajes_min' => 3,

    // Descontar el tiempo que el camión ya lleva en el viaje: lo que le falta es
    // la mediana de la etapa MENOS lo transcurrido (antes se sumaba la etapa
    // completa desde el hito y, si ya estaba vencida, se recortaba a 0 = "ya
    // llegó"). Cuando la mediana ya se consumió se espera hasta el p75 medido de
    // esa etapa, que es lo que separa "va con retraso" de "ya descargó".
    'descontar_transcurrido' => 1,

    // C2 - El día en que el camión presenta mueve el hueco: medido con 13.387    // huecos reales, el lunes la mediana es 2,53 veces la global y el domingo 1,45
    // (el fin de semana acumula y el lunes se paga); de martes a sábado, 0,81-0,96.
    // El factor sale de los pares de la propia placa y, si un día no tiene muestra,
    // del prior de la flota (Calibrador::FACTOR_DIA_FLOTA). Un feriado usa el peor
    // factor de la semana. `dia_min_pares` es la muestra mínima por día.
    'dia_activo' => 1,
    'dia_min_pares' => 5,
    'feriado_activo' => 1,

    // De dónde sale el factor del día (`dia_activo` tiene que estar en 1).
    //   0 = apagado, no se toca el hueco.
    //   1 = flota: lo de siempre. Factor de la placa y, si esa placa no tiene
    //       muestra de ese día, el prior hardcodeado FACTOR_DIA_FLOTA (lunes 2,68).
    //   2 = placa: sólo se corrige un día si ESA placa tiene `dia_min_pares` huecos
    //       en ese día; los demás quedan en 1 (no existe el prior de la flota).
    //   3 = compilado: el factor del día sale de `tiempos_ruta_horario`, la tabla
    //       que llena "Compilar promedios por día y hora", o sea de lo que tardan
    //       de verdad las rutas cada día de la semana. Si la ruta no tiene celda,
    //       el factor es 1.
    //
    // Por qué: medido el 14/11/2026 sobre 682 placas, de 4.774 celdas
    // (placa x día) sólo 159 tienen factor propio: el 3 %. O sea que en la
    // práctica el motor multiplica por 2,68 TODOS los lunes a TODOS los camiones.
    // Apagarlo baja el MAE 131 min (1568 -> 1437). Y el compilado por día muestra
    // que una misma ruta casi no cambia entre días (RUT_CARAL-BABEL_MAXO: 268 a
    // 290 min): el efecto día real es 5-8 %, no 168 %. `dia_compilado_k` encoge
    // la diferencia por muestra (una celda con 3 viajes pesa poco).
    'dia_modo' => 3,
    'dia_compilado_k' => 10,

    // C1 - Cola de la planta de carga: cuántos camiones llegaron en la última hora.
    // Medido con 10.293 refs, la espera para cargar tiene mediana 36 min con 1-2
    // llegadas por hora y 55 con 11 o más (p90 139 contra 102): con muchas
    // llegadas se suma `cola_espera_extra_min` a la espera en planta. Se apaga solo
    // en la tabla de aciertos (no evalúa el inicio de carga).
    'cola_activa' => 1,
    'cola_llegadas_min' => 11,
    'cola_espera_extra_min' => 19,

    // Sin pares medidos no hay cuantiles: la fecha "rápido" y "tardado" se abren
    // este porcentaje alrededor de la cadena (0.30 = -30 % / +30 %).
    'spread_cadena' => 0.30,

    // La ventana rápido/tardado se arma con los RESIDUOS del backtest, no con los
    // cuantiles del hueco: el p25-p75 del hueco contiene el valor real sólo el
    // 35 % de las veces (medido en la tabla de aciertos), porque el error viene de
    // todo el modelo y no sólo de la dispersión del hueco.
    //   radio = cuantil(|error|, ventana_cobertura) alrededor de la hora publicada
    //
    // Los valores de abajo se re-midieron el 14/11/2026 DESPUES de recentrar el
    // motor (día compilado + curva encogida): con 70 % de cobertura el radio es
    // 1.349 min en general y 2.086 cuando no hay pares medidos. Antes del cambio
    // eran 1.550 y 3.175, porque la banda estaba abierta contra el centro viejo.
    // El botón "Medir ventana" de la pantalla de parámetros lo vuelve a medir con
    // los datos del servidor y lo aplica con "Aplicar ventana".
    //
    // OJO: cada vez que se cambia el centro (día, curva, pares) hay que volver a
    // medir esta banda; si no, la cobertura cae aunque el motor esté mejor.
    //
    // Con `ventana_residuos` = 0 se vuelve a los cuantiles del hueco.
    'ventana_residuos' => 1,
    'ventana_cobertura' => 0.70,
    'ventana_adelanto_min' => 1349,
    'ventana_atraso_min' => 1349,
    'ventana_adelanto_cadena' => 2086,
    'ventana_atraso_cadena' => 2086,
    // filas mínimas del backtest para creerle a la medición
    'ventana_min_filas' => 40,

    // Cuánto puede moverse una predicción para salir de una franja inactiva.
    'max_desplazamiento_min' => 600,

    // Fallbacks cuando no hay dato medido.
    'traslado_defecto_min' => 240,
    'muerto_defecto_min' => 60,
    'espera_carga_defecto_min' => 90,

    // TOPE del traslado de vuelta. El traslado sale del compilado de la ruta
    // (`tiempos_ruta_promedio`) y hay tramos con promedios de días (medido: uno de
    // 2.961 min = 49 h). Con este tope se recorta y el traslado queda con fuente
    // `tope`. 0 = sin tope.
    //   OJO MEDIDO: recortarlo EMPEORA. AGO-NOV: 1.254 (sin tope) contra 1.261 con
    //   2.880, 1.303 con 1.440 y 1.347 con 720; MAY-AGO igual (1.264 / 1.266 /
    //   1.303 / 1.339). Esos traslados largos son reales: el camión está a dos días.
    //   Queda en 0; la clave existe para poder probarlo en otra flota.
    'traslado_max_min' => 0,

    // A partir de estas horas la predicción se degrada a la vista.
    'frescura_horizonte_h' => 24,
    'edad_ancla_max_h' => 72,

    // CAMIÓN VACÍO: el traslado de regreso a la planta de carga se hace SIN carga,
    // y un camión vacío tarda menos. Medido el 19/11/2026 comparando los dos
    // sentidos de los pares con ambos compilados y con el sentido identificable
    // (el cargado SALE de la planta): vacío/cargado = 0,832 de mediana
    // (p25 0,81 · p75 0,85 · 5 de 6 pares). Ejemplos: CARAL→BABEL AMAZON 277' vs
    // 185' (0,67), CARAL→SMI 247' vs 199' (0,81), CARAL→HUACHIPA 269' vs 246' (0,91).
    //
    // Se aplica SÓLO al traslado y sólo cuando la ruta que se usó va en el sentido
    // CARGADO (sus compilados midieron la ida desde la planta): ese promedio
    // sobreestima la vuelta vacía. Si la ruta ya es la del sentido vacío, no se
    // toca. 1.0 = apagado (comportamiento anterior).
    'vacio_factor' => 0.83,

    // SIN PROMEDIOS, CON COORDENADAS: `locales.lat/lng` (y el centroide del ubigeo
    // como respaldo) dan la distancia RECTA entre la posición del camión y la
    // planta. Con las rutas que sí tienen promedio compilado y coordenadas en los
    // dos extremos se ajusta `minutos = base + pendiente × km` (medido el
    // 19/11/2026 con 46 rutas: 49 + 4,08 × km, R² 0,79). El ajuste se recalcula
    // solo, por par de departamentos cuando hay muestra; estos números son el
    // respaldo cuando no hay ninguna ruta con coordenadas.
    //
    // Va ANTES de Geoapify a propósito: no gasta cuota. Sólo se usa cuando no hay
    // ningún promedio (ni de la ruta, ni de la referencia, ni de los ubigeos).
    'coords_activo' => 1,
    'coords_base_min' => 49,
    'coords_min_km' => 4.08,
    // cordura: el resultado no puede implicar menos de estas horas de viaje
    'coords_km_h_min' => 8,
    'coords_km_h_max' => 90,

    // Topes heredados del módulo de tiempos.
    'tope_hueco_min' => 10080,
    'tope_etapa_min' => 2880,

    // SESGO POR CLASE: una CUARTA fecha (`presenta_probable`) que corrige la hora
    // publicada con la mediana del error de su clase (evidencia x tramo del
    // horizonte predicho).
    //
    // Por qué: el motor no se equivoca parejo. Medido el 14/11/2026 con 803 filas
    // (validación temporal, entrena 15-ago..15-oct y valida 16-oct..14-nov):
    // con evidencia `cadena` y horizonte largo publica DECENAS DE HORAS ANTES de
    // lo que pasa; con `parcial` y horizonte corto, un par de horas DESPUÉS.
    // Corregir por clase baja el MAE de 1.237 a 1.095 min y sube ±3 h de 29,1 a
    // 31,1 %. (Con el horizonte REAL la mejora sería 1.237 -> 873, pero el real no
    // existe al predecir: la tabla se mide con el horizonte PREDICHO.)
    //
    // Lo llena el botón "Medir sesgo por clase" de la pantalla de parámetros (o
    // `prediccion:ablacion` no: es aparte). Nada se aplica hasta apretar Aplicar:
    // con `sesgo_clase_activo` en 0 la cuarta fecha es igual a la publicada.
    'sesgo_clase_activo' => 0,
    'sesgo_clase_cadena_corto' => 0,
    'sesgo_clase_cadena_medio' => 0,
    'sesgo_clase_cadena_largo' => 0,
    'sesgo_clase_parcial_corto' => 0,
    'sesgo_clase_parcial_medio' => 0,
    'sesgo_clase_parcial_largo' => 0,
    'sesgo_clase_medido_corto' => 0,
    'sesgo_clase_medido_medio' => 0,
    'sesgo_clase_medido_largo' => 0,
];
