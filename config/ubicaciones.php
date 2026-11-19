<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ubicaciones
    |--------------------------------------------------------------------------
    |
    | Parametros del modulo /lupita/ubicaciones, que diagnostica por que una ref
    | quedo sin `ruta_id` y propone locales nuevos.
    |
    | La lista de palabras ignoradas NO intenta ser completa: existe para que el
    | ranking no muestre "DE", "PT" ni "CARGA", que no son locales. Si aparece
    | ruido nuevo, se agrega aca sin tocar codigo.
    |
    */

    /*
    | Palabras que nunca son un local: conectores y vocabulario del formato del
    | titulo (tipo de movimiento, documentos, condiciones de la carreta).
    | Se comparan en mayusculas y sin tildes.
    */
    'palabras_ignoradas' => [
        // conectores y articulos
        'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y', 'A', 'EN', 'CON', 'SIN', 'POR', 'PARA', 'UN',
        // tipo de movimiento / formato del titulo
        'PT', 'PTA', 'PLANTA', 'CARGA', 'CARGAR', 'DESCARGA', 'ENVIO', 'DESPACHO', 'RETORNO',
        'RECOJO', 'INSUMOS', 'MULTIPARADA', 'REDI', 'TRASLADO', 'NN', 'CEDI', 'ALM', 'ALMACEN',
        // documentos y condiciones del servicio
        'EIRL', 'SAC', 'SRL', 'SA', 'RUC', 'PH', 'GPS', 'SCTR', 'UTCON', 'UT', 'TN',
        'CARRETA', 'CORTINERO', 'ESTIBAS', 'PALLETS', 'PALETS', 'REQUIERE', 'MERCADERIA',
        'VALIDAR', 'AUXILIAR', 'ANTIGUO', 'ACTIVO', 'KM',
    ],

    /*
    | Largo minimo de una palabra para que cuente como candidata, y largo maximo
    | de una frase candidata. El segundo es el que garantiza que el prellenado de
    | `palabra_clave` nunca choque con el VARCHAR(100) ni con el `max:100` de la
    | validacion de locales.
    */
    'minimo_caracteres' => 3,
    'maximo_candidato' => 60,

    /*
    | Hasta cuantas palabras se combinan para armar una frase ("MAQUILA AGUA" es
    | un 2-grama).
    */
    'maximo_ngrama' => 3,

    /*
    | Tope del fragmento que se muestra en la solapa "Fragmentos". Es solo para
    | leer; no se ofrece como palabra clave.
    */
    'maximo_fragmento' => 120,

    /*
    | Umbrales del veredicto `typo`: parecido por porcentaje (similar_text) o por
    | distancia de edicion (levenshtein). Con 85 % y 2, HUCHIPA cae en HUACHIPA.
    */
    'parecido_porcentaje' => 85,
    'parecido_distancia' => 2,

];
