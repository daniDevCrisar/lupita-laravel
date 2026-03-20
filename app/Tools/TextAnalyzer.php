<?php

namespace App\Tools;

class TextAnalyzer
{

    /**
     * Detecta si un texto es buzón de voz
     */
    public static function esBuzonDeVoz($texto)
    {
        if (!$texto || trim($texto) === '') {
            return false;
        }

        //$texto = self::normalizar($texto);

        // Frases FUERTES (1 sola basta)
        $fuertes = [
            'BUZON DE VOZ',
            'DEJE SU MENSAJE',
            'DESPUES DEL TONO',
            'GRABE SU MENSAJE',
            'CASILLA DE VOZ',
            'VOICE MAIL',
            'VOICEMAIL'
        ];

        foreach ($fuertes as $f) {
            if (strpos($texto, $f) !== false) {
                return true;
            }
        }

        // Frases MEDIAS
        $medias = [
            'NO ESTA DISPONIBLE',
            'NO SE ENCUENTRA DISPONIBLE',
            'NO PUEDE ATENDER',
            'NO PUEDE CONTESTAR',
            'NO PUEDE RESPONDER',
            'INTENTE MAS TARDE',
            'NO SE PUEDE COMPLETAR SU LLAMADA',
            'EL NUMERO AL QUE LLAMA'
        ];

        // Operadores
        $operadores = [
            'MOVISTAR',
            'CLARO',
            'TELCEL',
            'ENTEL',
            'TIGO',
            'PERSONAL',
            'BITEL',
            'WIM',
            'DIGITEL'
        ];

        // Palabras de refuerzo
        $refuerzo = [
            'TONO',
            'MENSAJE',
            'BUZON',
            'CASILLA'
        ];

        $score = 0;

        foreach ($medias as $m) {
            if (strpos($texto, $m) !== false) {
                $score++;
            }
        }

        foreach ($operadores as $op) {
            if (strpos($texto, $op) !== false) {
                $score++;
            }
        }

        foreach ($refuerzo as $r) {
            if (strpos($texto, $r) !== false) {
                $score += 0.5;
            }
        }

        return $score >= 2;
    }
}
