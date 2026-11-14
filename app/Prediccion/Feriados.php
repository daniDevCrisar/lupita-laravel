<?php

namespace App\Prediccion;

use Carbon\Carbon;

/**
 * Feriados del Perú: un feriado no se mueve como un día hábil.
 *
 * Se usa para la ventana del hueco (ver MotorPresenta y `factor_dia`): en un
 * feriado las plantas no cargan igual y el camión espera más. Medido con los
 * viajes reales, el lunes la mediana del hueco es 2,5 veces la del resto de la
 * semana; para un feriado no hay muestra suficiente (son pocos días al año), así
 * que se usa el peor factor de la semana como aproximación conservadora. Va
 * detrás de `feriado_activo` para poder apagarlo.
 *
 * Los móviles (Semana Santa, etc.) están cargados para 2025-2027: si cambia el
 * calendario oficial, se agregan acá (es una lista de mes-día, sin año).
 */
final class Feriados
{
    /** Feriados de fecha fija (formato m-d). */
    private const FIJOS = [
        '01-01',   // Año Nuevo
        '05-01',   // Día del Trabajo
        '06-07',   // Batalla de Arica y Día de la Bandera
        '06-29',   // San Pedro y San Pablo
        '07-23',   // Día de la Fuerza Aérea
        '07-28',   // Fiestas Patrias
        '07-29',   // Fiestas Patrias
        '08-06',   // Batalla de Junín
        '08-30',   // Santa Rosa de Lima
        '10-08',   // Combate de Angamos
        '11-01',   // Todos los Santos
        '12-08',   // Inmaculada Concepción
        '12-09',   // Batalla de Ayacucho
        '12-25',   // Navidad
    ];

    /** Móviles por año (Semana Santa y los trasladables). */
    private const MOVILES = [
        2025 => ['04-17', '04-18', '06-30', '10-09'],
        2026 => ['04-02', '04-03', '06-30', '10-09'],
        2027 => ['03-25', '03-26', '06-30', '10-09'],
    ];

    /** ¿Ese día es feriado? */
    public static function es_feriado(Carbon $fecha): bool
    {
        $clave = $fecha->format('m-d');

        if (in_array($clave, self::FIJOS, true)) {
            return true;
        }

        $moviles = self::MOVILES[(int) $fecha->format('Y')] ?? [];

        return in_array($clave, $moviles, true);
    }

    /** ¿Es fin de semana? (sábado o domingo) */
    public static function es_fin_de_semana(Carbon $fecha): bool
    {
        return (int) $fecha->format('N') >= 6;
    }

    /** Cuántos feriados hay cargados (para el diagnóstico). */
    public static function cantidad(): int
    {
        $moviles = 0;
        foreach (self::MOVILES as $lista) {
            $moviles += count($lista);
        }

        return count(self::FIJOS) + $moviles;
    }
}
