<?php

/*
|--------------------------------------------------------------------------
| Geoapify (coordenadas y tiempos de manejo)
|--------------------------------------------------------------------------
|
| Se usa SÓLO como respaldo cuando no hay tránsito medido con viajes reales: el
| dato de la flota siempre manda (ver App\Prediccion\Geoapify).
|
| La key NO va en config/prediccion.php a propósito: ese arreglo se publica y se
| guarda en la tabla `prediccion_config`, y una credencial no tiene nada que
| hacer ahí. Vive en el .env del servidor.
|
*/

return [
    'key' => env('GEOAPIFY_API_KEY', ''),

    /*
    | Tope del tránsito medido contra el manejo real.
    |
    | Los E4 medidos incluyen colas y descansos, y el AVG se dispara: Caral ->
    | Chiclayo promedia 1952 min cuando el manejo real son 547. Si el medido
    | supera `manejo real × factor`, se usa el tope y el resto queda como tiempo
    | muerto (el hueco total NO cambia; sólo la explicación deja de mentir).
    | Con 0 el tope se apaga.
    */
    'tope_medido_factor' => env('GEOAPIFY_TOPE_MEDIDO_FACTOR', 1.3),

    /*
    | Desde qué duración medida vale la pena pedir el manejo real.
    |
    | Pedir la ruta cuesta una llamada HTTP en el request: con tramos cortos el
    | promedio medido no se infla tanto y no justifica la espera. Con 0 se pide
    | siempre que haya que topear.
    */
    'tope_desde_min' => env('GEOAPIFY_TOPE_DESDE_MIN', 180),
];
