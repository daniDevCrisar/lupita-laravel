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
    'pares_recientes_max' => 20,

    // Franjas de no-actividad: días mínimos para creerle al perfil propio y
    // cuántas marcas (hitos) tiene que haber en una franja de 30 min para que
    // cuente como activa. Con 2, una franja donde el camión nunca hizo nada en
    // todo el lookback es descanso o almuerzo.
    'min_dias_actividad' => 5,
    'min_hitos_bin' => 2,
    'granularidad_min' => 30,

    // Franjas de 3 h para la corrección del hueco por hora del ancla.
    'bins_horarios' => 8,

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

    // C2 - El día en que el camión presenta mueve el hueco: medido con 13.387
    // huecos reales, el lunes la mediana es 2,53 veces la global y el domingo 1,45
    // (el fin de semana acumula y el lunes se paga); de martes a sábado, 0,81-0,96.
    // El factor sale de los pares de la propia placa y, si un día no tiene muestra,
    // del prior de la flota (Calibrador::FACTOR_DIA_FLOTA). Un feriado usa el peor
    // factor de la semana. `dia_min_pares` es la muestra mínima por día.
    'dia_activo' => 1,
    'dia_min_pares' => 5,
    'feriado_activo' => 1,

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
    // Los valores de abajo se midieron el 14/11/2026 con 788 filas del tramo
    // 15-oct -> 13-nov: con 70 % de cobertura el radio es 1.550 min en general y
    // 3.175 min cuando no hay pares medidos (evidencia "cadena", que se equivoca
    // mucho más). El botón "Medir ventana" de la pantalla de parámetros lo vuelve
    // a medir con los datos del servidor y lo aplica con "Aplicar ventana".
    //
    // Con `ventana_residuos` = 0 se vuelve a los cuantiles del hueco.
    'ventana_residuos' => 1,
    'ventana_cobertura' => 0.70,
    'ventana_adelanto_min' => 1550,
    'ventana_atraso_min' => 1550,
    'ventana_adelanto_cadena' => 3175,
    'ventana_atraso_cadena' => 3175,
    // filas mínimas del backtest para creerle a la medición
    'ventana_min_filas' => 40,

    // Cuánto puede moverse una predicción para salir de una franja inactiva.
    'max_desplazamiento_min' => 600,

    // Fallbacks cuando no hay dato medido.
    'traslado_defecto_min' => 240,
    'muerto_defecto_min' => 60,
    'espera_carga_defecto_min' => 90,

    // A partir de estas horas la predicción se degrada a la vista.
    'frescura_horizonte_h' => 24,
    'edad_ancla_max_h' => 72,

    // Topes heredados del módulo de tiempos.
    'tope_hueco_min' => 10080,
    'tope_etapa_min' => 2880,
];
