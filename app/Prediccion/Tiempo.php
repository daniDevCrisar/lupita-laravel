<?php

namespace App\Prediccion;

/**
 * Formato de duraciones en minutos para la pantalla y el JSON.
 *
 * El API publica los minutos crudos (`desglose`) y, además, la misma duración en
 * `h:mm` para que un consumidor no tenga que dividir: 5787 min son "96:27".
 * Las esperas de un camión pasan de 24 h seguido, así que las horas NO se cortan
 * en el día; `dias()` queda para el texto largo.
 *
 * Es puro.
 */
final class Tiempo
{
    /** "96:27" — horas totales y minutos, sin días. */
    public static function hhmm(?int $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }

        $signo = $minutos < 0 ? '-' : '';
        $minutos = abs($minutos);

        return $signo . intdiv($minutos, 60) . ':' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "4d 08:27" — para textos explicativos. */
    public static function dias(?int $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }

        $signo = $minutos < 0 ? '-' : '';
        $minutos = abs($minutos);
        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);
        $min = $minutos % 60;

        return $signo . $dias . 'd ' . str_pad((string) $horas, 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) $min, 2, '0', STR_PAD_LEFT);
    }

    /** Nombre legible de un hito del ciclo. */
    public static function hito(?string $hito): string
    {
        $etiquetas = [
            'compromiso_carga' => 'compromiso de carga',
            'presenta_para_carga' => 'presentación para carga',
            'inicio_de_carga' => 'inicio de carga',
            'fin_de_carga' => 'fin de carga',
            'inicio_ruta' => 'inicio de ruta',
            'qr_llegada_destino' => 'llegada a destino',
            'inicio_descargue' => 'inicio de descargue',
            'fin_descargue' => 'fin de descargue',
            'ubicacion_declarada' => 'ubicación declarada',
        ];

        return $etiquetas[$hito ?? ''] ?? ($hito ?? 'sin hito');
    }
}
