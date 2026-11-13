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

    // Franjas de no-actividad: días mínimos para creerle al perfil propio y
    // cobertura máxima (fracción de tiempo con actividad) para llamarla inactiva.
    'min_dias_actividad' => 5,
    'umbral_inactividad' => 0.10,
    'granularidad_min' => 30,

    // Franjas de 3 h para la corrección del hueco por hora del ancla.
    'bins_horarios' => 8,

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
