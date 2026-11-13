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
];
