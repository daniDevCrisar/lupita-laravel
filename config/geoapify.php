<?php

/*
|--------------------------------------------------------------------------
| Geoapify (coordenadas y tiempos de manejo)
|--------------------------------------------------------------------------
|
| Se usa SÓLO cuando NO existe ningún promedio compilado de la ruta: si hay dato
| de la flota (por ruta, por par de ubigeos o por la ruta de la propia
| referencia), ese dato manda y no se llama a la API (ver
| App\Database\DBPrediccion::traslado() y App\Prediccion\Geoapify).
|
| La key NO va en config/prediccion.php a propósito: ese arreglo se publica y se
| guarda en la tabla `prediccion_config`, y una credencial no tiene nada que
| hacer ahí. Vive en el .env del servidor.
|
*/

return [
    'key' => env('GEOAPIFY_API_KEY', ''),

    /*
    | Contador de llamadas (tabla `geoapify_uso`).
    |
    | Cada petición HTTP a Geoapify suma uno en el contador del día, para poder
    | responder "¿cuántas veces se usó el API?" en /lupita/prediccion/geoapify.
    | La suite de pruebas lo apaga (`config(['geoapify.contar' => false])`) para
    | no ensuciar el contador real con llamadas simuladas.
    */
    'contar' => env('GEOAPIFY_CONTAR', true),

    /*
    | Modo de ruteo de Geoapify.
    |
    | Toda la flota son camiones, así que el tiempo de manejo sale con
    | `mode=truck`: respeta las restricciones de vehículo pesado (y por eso puede
    | dar MÁS km y MÁS minutos que `drive`; en el Serpentín de Pasamayo, por
    | ejemplo, manda al camión por la ruta de Pachacútec: 36 km / 51 min contra
    | 32,6 km / 30 min de un auto).
    |
    | Valores: truck | light_truck | medium_truck | heavy_truck | long_truck |
    | truck_dangerous_goods | truck_traffic_approximated | drive.
    | Si el plan del proveedor no incluye el modo elegido (responde 400 "mode
    | must be one of [...]"), la primera respuesta con error hace que se
    | reintente con `drive` y que el resto del proceso use ese modo
    | (ver App\Prediccion\Geoapify::routing()). Un 500 o una caída de red no
    | bajan el modo: no son un rechazo del camión.
    */
    'modo_ruta' => env('GEOAPIFY_MODO_RUTA', 'truck'),

    /*
    | Tráfico en el ruteo.
    |
    | `approximated` pide el tiempo de manejo CON el tráfico estimado para esa
    | hora (histórico del proveedor), no el de la vía libre: en el Serpentín de
    | Pasamayo el mismo tramo pasa de 51 min a 134 min. Se pide SÓLO en hora pico
    | (ver `franjas_pico`): a las 03:00 el tráfico aproximado es casi vía libre y
    | la llamada no aporta nada.
    |
    | Valores: approximated (por defecto) | vacío para pedir siempre vía libre.
    | Si el plan no incluye el tráfico, la API reintenta sin él y sigue así el
    | resto del proceso (nunca se queda sin tiempo de manejo).
    */
    'trafico' => env('GEOAPIFY_TRAFICO', 'approximated'),

    /*
    | Pedir tráfico SIEMPRE, sin mirar la hora (ignora las franjas pico).
    */
    'trafico_siempre' => env('GEOAPIFY_TRAFICO_SIEMPRE', false),

    /*
    | Departamentos que se manejan con el reloj de la capital. Por defecto sólo
    | Lima (15). Para sumar el Callao: GEOAPIFY_DEPTOS_CAPITAL=15,07.
    */
    'departamentos_capital' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('GEOAPIFY_DEPTOS_CAPITAL', '15'))))),

    /*
    | Franjas de hora pico, en MINUTOS desde medianoche y con el final abierto
    | ([390, 570] = 06:30 inclusive a 09:30 exclusive). Se editan acá para
    | ajustarlas a lo que vea el despachador, sin tocar código.
    */
    'franjas_pico' => [
        // Lima: pico de la mañana largo y pico de la tarde-noche
        'capital' => [[390, 570], [1050, 1260]],      // 06:30-09:30 y 17:30-21:00
        // Interior del país: sale más temprano y se corta más temprano
        'provincia' => [[360, 540], [1020, 1200]],    // 06:00-09:00 y 17:00-20:00
    ],
];
