<?php

namespace App\ElevenLabs;

/**
 * Port a PHP de `public/js/import/json/procesar_transcripcion.js`.
 *
 * QUE CALCULA (las dos columnas de `tmp_lotes_det` que no son datos sino analisis)
 *   - `analisis_transcripcion`   etiqueta del motivo del conductor
 *                                 ("hay cola", "carga", "descarga", "esperando"...)
 *   - `conductor_contesta_pero_no_habla`
 *
 * POR QUE UN PORT Y NO REUSAR EL JS
 * El flujo de VAPI traducia en el navegador y generaba un Excel. Ahora la
 * traduccion corre en el servidor, asi que las ~400 lineas del JS hay que
 * llevarlas a PHP con el MISMO comportamiento: los umbrales (score >= 2 en
 * buzon, score >= 3 en sintaxis) y las listas de frases estan copiados uno a uno
 * porque de ellos depende que la etiqueta coincida con el historico.
 *
 * DIFERENCIAS DELIBERADAS
 * 1. `normalizar()` no usa iconv: en este entorno translitera "ñ" como "~n" y
 *    "á" como "'a" ("mañana" -> "ma~nana"), lo que romperia las listas de frases.
 *    Se usa un mapa explicito.
 * 2. El JS concatenaba sobre una variable sin inicializar, asi que el texto del
 *    conductor empezaba con la cadena "undefined". Aqui empieza vacio.
 * 3. El JS cortaba cada turno en el segundo ":" y perdia el resto. Aqui se
 *    conserva el texto completo (ver MensajesTranscripcion).
 */
class AnalizadorTranscripcion
{
    public string $analisis_transcripcion = '';
    public bool $conductor_contesta_pero_no_habla = false;

    /** Frases que delatan un buzon de voz directo. */
    private const BUZON_FUERTES = [
        'BUZON DE VOZ', 'DEJE SU MENSAJE', 'DESPUES DEL TONO', 'GRABE SU MENSAJE',
        'CASILLA DE VOZ', 'VOICE MAIL', 'VOICEMAIL', 'TIEMPO LIMITE', 'CON EL BUZON',
    ];

    private const BUZON_MEDIAS = [
        'NO ESTA DISPONIBLE', 'NO SE ENCUENTRA DISPONIBLE', 'NO PUEDE ATENDER',
        'NO PUEDE CONTESTAR', 'NO PUEDE RESPONDER', 'INTENTE MAS TARDE',
        'NO SE PUEDE COMPLETAR SU LLAMADA', 'EL NUMERO AL QUE LLAMA', 'NUMERO DE TELEFONO',
    ];

    private const OPERADORES = [
        'MOVISTAR', 'CLARO', 'TELCEL', 'ENTEL', 'TIGO', 'PERSONAL', 'BITEL', 'WIN', 'DIGITEL',
    ];

    private const BUZON_REFUERZO = [
        'TONO', 'MENSAJE', 'BUZON', 'CASILLA', 'GRABADO', 'GRABE', 'VOZ', 'DESPUES', 'COMUNICADO',
    ];

    private const COLA_MEDIAS = [
        'CARROS ADELANTE', 'CARRO ADELANTE', 'MONTON DE CARRO', 'ARTO CARRO', 'BASTANTES CARRO',
        'BASTANTE CARRO', 'HACIENDO COLA', 'HAY COLA', 'EN LA COLA', 'COLA DE CARRO',
        'MUCHO CARRO', 'MUCHOS CARRO', 'VARIOS CARRO', 'DEMASIADOS CARRO', 'DEMASIADO CARRO',
        'UNIDADES ESPERANDO', 'BASTANTES UNIDADES', 'VARIAS UNIDADES', 'DEMASIADAS UNIDADES',
        'DEMASIADA UNIDAD',
    ];

    private const LLAMAN_MEDIAS = [
        'NO ME LLAMA', 'VAN A LLAMAR', 'NO LLAMA', 'QUE ME LLAME', 'LA LLAMADA',
    ];

    private const ESPERA_MEDIAS = [
        'A LA ESPERA', 'ESTAMOS ESPERANDO', 'ESTAMOS EN ESPERA', 'SIGO ESPERANDO',
        'ESTOY ESPERANDO', 'EN LA ESPERA', 'ACABO DE LLEGAR', 'ESPERANDO PARA', 'MI TURNO',
    ];

    private const DESCARGA_MEDIAS = [
        'YA DESCARG', 'YA DESCARGARON', 'EL DESCARGUE',
        'ESTOY DESCARGANDO', 'ESTAN DESCARGANDO', 'ESTABA DESCARGANDO',
        'VOY A DESCARGAR', 'VOY DESCARGAR', 'VAN DESCARGAR', 'VAN A DESCARGAR',
        'DE DESCARGA', 'PARA DESCARGA',
        'ME DESCARG', 'LA DESCARG',
    ];

    private const CARGA_MEDIAS = [
        'YA CARGUE', 'YA CARGARON', 'LA CARGUE',
        'ESTOY CARGANDO', 'ESTAN CARGANDO', 'ESTABA CARGANDO',
        'VOY A CARGAR', 'VOY CARGAR', 'VAN CARGAR', 'VAN A CARGAR',
        'DE CARGA', 'PARA CARGA', 'ME CARGAN', 'LA CARGA',
        'ME CARGARON', 'LA CARGARON',
    ];

    private const ENTRAR_MEDIAS = [
        'ESTOY ENTRANDO', 'AHORITA ENTRO', 'PARA ENTRAR', 'POR ENTRAR', 'YA ENTRO',
        'YA ENTRE', 'AHORA ENTRO', 'DE ENTRAR', 'INGRESADO', 'YA INGRESE',
    ];

    private const SALIR_MEDIAS = [
        'ESTOY SALIENDO', 'AHORITA SALGO', 'PARA SALIR', 'POR SALIR', 'YA SALGO',
        'YA SALI', 'AHORA SALGO', 'DE SALIR', 'EN RUTA',
    ];

    private const REFUERZO = [
        'TOLDANDO', 'TOLDEANDO', 'TOLDEAR', 'TOLDERA', 'ENCARPANDO', 'ENCARPAR', 'ENCARPE',
        'RAMPA', 'BALANSA', 'BALANZA', 'AMARRARON', 'MONTACARGA', 'PATO', 'PATERO',
        'DOCUMENTO', 'GUIA', 'MANTEO', 'ESTIBA', 'DESPACHARON', 'PRODUCTO', 'SISTEMA',
        'REGISTRE', 'DEMORA', 'MUY LENTO',
        'ESTOY ADENTRO', 'NO SALGO',
    ];

    private const PARCIAL = [' SI ', 'CORRECT', 'AFIRMA', 'CONFIRMA', 'ASI ES'];

    public function reiniciar(): void
    {
        $this->analisis_transcripcion = '';
        $this->conductor_contesta_pero_no_habla = false;
    }

    /**
     * Corre el analisis sobre `mensajes_conten` ya armado.
     *
     * @param  string  $mensajesConten  "//BOT: ... //USER: ..."
     * @param  int  $duracionSeg  duracion de la llamada en segundos
     * @param  string  $razonFinalizacion  name de razones_finalizacion (o el valor crudo)
     */
    public function procesar(string $mensajesConten, int $duracionSeg, string $razonFinalizacion): void
    {
        $this->reiniciar();

        $conversacion = $this->obtenerConversacion($mensajesConten);
        if ($conversacion === null) {
            return;
        }

        $razon = mb_strtolower(trim($razonFinalizacion));

        if ($conversacion['user_total'] <= 0) {
            if ($razon === 'assistant-ended-call') {
                $this->conductor_contesta_pero_no_habla = true;
                $this->analisis_transcripcion = 'NO HABLA';
            }

            if ($razon === 'customer-ended-call') {
                if ($duracionSeg < 20) {
                    $this->analisis_transcripcion = 'CUELGA';
                } else {
                    $this->conductor_contesta_pero_no_habla = true;
                    $this->analisis_transcripcion = 'NO HABLA';
                }
            }

            return;
        }

        $textoUsuario = '';
        foreach ($conversacion['user'] as $texto) {
            $textoUsuario .= $texto . ' ';
        }

        // Mismo orden que el JS: primero buzon, y si no, sintaxis. Ojo: `sintaxis()`
        // va sumando fragmentos a `analisis_transcripcion` aunque al final no llegue
        // al umbral, igual que el original.
        if ($this->esBuzon($textoUsuario)) {
            $this->analisis_transcripcion = 'BUZON';

            return;
        }

        $this->sintaxis($textoUsuario);
    }

    /**
     * @return array{total:int, user_total:int, user: array<int,string>, bot: array<int,string>}|null
     */
    public function obtenerConversacion(string $texto): ?array
    {
        if (trim($texto) === '') {
            return null;
        }

        $texto = $this->normalizar($texto);
        $user = [];
        $bot = [];

        foreach (explode('//', $texto) as $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') {
                continue;
            }

            // Se conserva todo lo que sigue al primer ':' (el JS lo perdia).
            $partes = explode(':', $trozo, 2);
            if (count($partes) !== 2) {
                continue;
            }

            $rol = trim($partes[0]);
            $mensaje = trim($partes[1]);
            if ($mensaje === '') {
                continue;
            }

            if ($rol === 'USER') {
                $user[] = $mensaje;
            } elseif ($rol === 'BOT') {
                $bot[] = $mensaje;
            }
        }

        $total = count($user) + count($bot);
        if ($total === 0) {
            return null;
        }

        return [
            'total' => $total,
            'user_total' => count($user),
            'user' => $user,
            'bot' => $bot,
        ];
    }

    public function normalizar(string $texto): string
    {
        $texto = trim($texto);

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);

        $texto = mb_strtoupper($texto);
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;

        // El JS quitaba los signos de interrogacion porque rompen algunas frases.
        return str_replace(['?', '¿'], '', $texto);
    }

    public function esBuzon(string $texto): bool
    {
        $texto = trim($texto);
        if ($texto === '') {
            return false;
        }

        foreach (self::BUZON_FUERTES as $frase) {
            if (str_contains($texto, $frase)) {
                return true;
            }
        }

        $score = 0;

        foreach (self::BUZON_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $score++;
            }
        }

        foreach (self::OPERADORES as $frase) {
            if (str_contains($texto, $frase)) {
                $score++;
            }
        }

        foreach (self::BUZON_REFUERZO as $frase) {
            if (str_contains($texto, $frase)) {
                $score += 0.7;
            }
        }

        return $score >= 2;
    }

    /**
     * Suma evidencia de lo que dice el conductor. Devuelve true si llega al
     * umbral, pero los fragmentos de `analisis_transcripcion` se acumulan siempre.
     */
    public function sintaxis(string $texto): bool
    {
        $score = 0;

        foreach (self::COLA_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= 'hay cola ';
                $score++;
            }
        }

        foreach (self::LLAMAN_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= 'van a llamar ';
                $score++;
            }
        }

        foreach (self::ESPERA_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= 'esperando ';
                $score++;
            }
        }

        foreach (self::CARGA_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                if ($this->analisis_transcripcion !== '') {
                    $this->analisis_transcripcion .= '- ';
                }
                $this->analisis_transcripcion .= 'carga ';
                $score++;
            }
        }

        foreach (self::DESCARGA_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                if ($this->analisis_transcripcion !== '') {
                    $this->analisis_transcripcion .= '- ';
                }
                $this->analisis_transcripcion .= 'descarga';
                $score++;
            }
        }

        foreach (self::ENTRAR_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= '+ ' . mb_strtolower($frase);
                $score += 1;
            }
        }

        foreach (self::SALIR_MEDIAS as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= '+ ' . mb_strtolower($frase);
                $score += 1;
            }
        }

        foreach (self::REFUERZO as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= '+ ' . mb_strtolower($frase);
                $score += 1;
            }
        }

        foreach (self::PARCIAL as $frase) {
            if (str_contains($texto, $frase)) {
                $this->analisis_transcripcion .= '+ ';
                $score += 0.5;
            }
        }

        return $score >= 3;
    }
}
