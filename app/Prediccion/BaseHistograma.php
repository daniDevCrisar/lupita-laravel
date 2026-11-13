<?php

namespace App\Prediccion;

use Carbon\Carbon;

/**
 * Réplica exacta del método que hay hoy en la ficha de comportamiento
 * (`DBComportamiento::veredicto_carga`): la hora más frecuente de las presentas
 * pasadas del camión que todavía no pasó, con el minuto promedio de esa hora.
 *
 * Existe para medir la línea base en la misma tabla que el motor nuevo, con el
 * mismo corte de tiempo y los mismos datos: sin eso, comparar manzanas con
 * naranjas.
 *
 * Es puro: recibe las presentas y la hora de corte.
 */
final class BaseHistograma
{
    /**
     * @param  array<int, Carbon|string>  $presentas  presentas históricas del camión
     */
    public static function estimar(array $presentas, Carbon $ahora): ?Carbon
    {
        $calendario = [];

        foreach ($presentas as $presenta) {
            $fecha = $presenta instanceof Carbon ? $presenta : (is_string($presenta) && $presenta !== '' ? Carbon::parse($presenta) : null);
            if ($fecha === null) {
                continue;
            }

            $dia = (int) $fecha->format('N') - 1;   // 0 = lunes, igual que WEEKDAY()
            $hora = (int) $fecha->format('G');

            if (!isset($calendario[$dia][$hora])) {
                $calendario[$dia][$hora] = ['n' => 0, 'minutos' => 0];
            }
            $calendario[$dia][$hora]['n']++;
            $calendario[$dia][$hora]['minutos'] += (int) $fecha->format('i');
        }

        if ($calendario === []) {
            return null;
        }

        $hoy = (int) $ahora->format('N') - 1;
        $horaActual = (int) $ahora->format('G');

        for ($salto = 0; $salto <= 7; $salto++) {
            $dia = ($hoy + $salto) % 7;
            $horas = $calendario[$dia] ?? [];
            if ($horas === []) {
                continue;
            }

            // hoy sólo cuentan las horas que todavía no empezaron
            $futuras = [];
            foreach ($horas as $hora => $dato) {
                if ($salto > 0 || $hora > $horaActual) {
                    $futuras[(int) $hora] = $dato;
                }
            }
            if ($futuras === []) {
                continue;
            }

            uasort($futuras, fn (array $a, array $b) => $b['n'] <=> $a['n']);
            $hora = (int) array_key_first($futuras);
            $dato = $futuras[$hora];
            $minuto = $dato['n'] > 0 ? (int) round($dato['minutos'] / $dato['n']) : 0;

            return $ahora->copy()->startOfDay()->addDays($salto)->setTime($hora, 0)->addMinutes($minuto);
        }

        return null;
    }
}
