<?php

namespace App\Prediccion;

use Carbon\Carbon;

/**
 * Horas pico del Perú: a qué hora conviene pedirle a Geoapify el tiempo CON
 * tráfico y a qué hora no cambia nada.
 *
 * El ruteo con `traffic=approximated` devuelve el tiempo con el tráfico estimado
 * para esa hora. A las 03:00 ese número es casi el de vía libre, así que pedirlo
 * a toda hora sólo gasta créditos del plan; en hora pico, en cambio, cambia el
 * resultado: el mismo tramo del Serpentín de Pasamayo son 134 min con tráfico y
 * 51 min sin tráfico (y 30 min como auto).
 *
 * La capital no se mueve como el resto del país, así que hay DOS juegos de
 * franjas:
 *
 *   capital    06:30-09:30 y 17:30-21:00   (departamento de Lima, 15xxxx)
 *   provincia  06:00-09:00 y 17:00-20:00   (el resto del país)
 *
 * Qué se midió con los viajes reales (17.534 con `inicio_ruta` -> `qr_llegada`):
 * el pico que el dato sostiene es el de la TARDE (17:00-23:00, +8 % en Lima y
 * +35 % en provincia), y las 07:00-09:00 aparecen como las horas MÁS RÁPIDAS,
 * porque el E4 medido incluye paradas y casi todas las salidas son de planta,
 * fuera de la ciudad. El pico de la mañana es calendario de la ciudad, no algo
 * que el dato pruebe: queda configurable para moverlo con lo que vea el
 * despachador.
 *
 * Es puro: no toca la base ni el reloj (la hora entra como parámetro), así se
 * puede probar sin fijar el reloj del sistema.
 */
final class HoraPico
{
    /** Zona de la capital: dos picos, mediodía neutro. */
    public const ZONA_CAPITAL = 'capital';

    /** Zona del interior del país. */
    public const ZONA_PROVINCIA = 'provincia';

    /** Departamentos que usan el reloj de la capital, por defecto (ubigeo de 2 dígitos). */
    public const DEPARTAMENTOS_DEFECTO = ['15'];

    /**
     * Franjas por defecto, en MINUTOS desde medianoche y con el final abierto:
     * [390, 570] es 06:30 (390) inclusive hasta 09:30 (570) exclusive.
     *
     * @var array<string, array<int, array{0: int, 1: int}>>
     */
    public const FRANJAS_DEFECTO = [
        self::ZONA_CAPITAL => [[360, 600], [1020, 1260]],     // 06:00-10:00 y 17:00-21:00
        self::ZONA_PROVINCIA => [[360, 540], [1020, 1200]],   // 06:00-09:00 y 17:00-20:00
    ];

    /**
     * Departamentos con reloj de capital (`geoapify.departamentos_capital`).
     *
     * Sale de configuración a propósito: si mañana quieren que el Callao (07)
     * use el mismo reloj que Lima, se agrega con GEOAPIFY_DEPTOS_CAPITAL=15,07
     * sin tocar código.
     *
     * @return array<int, string>
     */
    public static function departamentos(): array
    {
        $config = config('geoapify.departamentos_capital', self::DEPARTAMENTOS_DEFECTO);
        $lista = is_array($config) ? $config : explode(',', (string) $config);

        $salida = [];
        foreach ($lista as $departamento) {
            $departamento = trim((string) $departamento);
            if (preg_match('/^\d{2}$/', $departamento) === 1) {
                $salida[] = $departamento;
            }
        }

        return $salida !== [] ? array_values(array_unique($salida)) : self::DEPARTAMENTOS_DEFECTO;
    }

    /** ¿Ese ubigeo es de un departamento que se maneja con el reloj de la capital? */
    public static function es_capital(?string $ubigeo): bool
    {
        $ubigeo = trim((string) $ubigeo);

        if (preg_match('/^\d{6}$/', $ubigeo) !== 1) {
            return false;
        }

        return in_array(substr($ubigeo, 0, 2), self::departamentos(), true);
    }

    /**
     * Qué reloj manda para este viaje: si cualquiera de las dos puntas es de la
     * capital, manda la capital (la congestión está en la ciudad, no en la ruta).
     */
    public static function zona(?string $ubigeoOrigen, ?string $ubigeoDestino): string
    {
        return (self::es_capital($ubigeoOrigen) || self::es_capital($ubigeoDestino))
            ? self::ZONA_CAPITAL
            : self::ZONA_PROVINCIA;
    }

    /**
     * Franjas de esa zona (`geoapify.franjas_pico`), ya validadas.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function franjas(string $zona): array
    {
        $config = config('geoapify.franjas_pico', []);
        $franjas = is_array($config) ? ($config[$zona] ?? null) : null;
        $franjas = is_array($franjas) ? $franjas : (self::FRANJAS_DEFECTO[$zona] ?? []);

        $salida = [];
        foreach ($franjas as $franja) {
            if (!is_array($franja) || count($franja) < 2) {
                continue;
            }

            [$desde, $hasta] = array_values($franja);
            if (!is_numeric($desde) || !is_numeric($hasta)) {
                continue;
            }

            $desde = max(0, min(1440, (int) $desde));
            $hasta = max(0, min(1440, (int) $hasta));
            if ($hasta > $desde) {
                $salida[] = [$desde, $hasta];
            }
        }

        return $salida;
    }

    /** Minutos desde medianoche de ese momento. */
    public static function minuto(Carbon $momento): int
    {
        return (int) $momento->format('G') * 60 + (int) $momento->format('i');
    }

    /** ¿Esa hora cae en hora pico de esa zona? (el extremo final no entra) */
    public static function es_pico(Carbon $momento, string $zona): bool
    {
        $minuto = self::minuto($momento);

        foreach (self::franjas($zona) as [$desde, $hasta]) {
            if ($minuto >= $desde && $minuto < $hasta) {
                return true;
            }
        }

        return false;
    }

    /** Las franjas de esa zona en texto: "06:30-09:30 y 17:30-21:00". */
    public static function texto(string $zona = self::ZONA_CAPITAL): string
    {
        $partes = [];
        foreach (self::franjas($zona) as [$desde, $hasta]) {
            $hora = fn (int $min): string => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
            $partes[] = $hora($desde) . '-' . $hora($hasta);
        }

        return $partes === [] ? 'sin franjas' : implode(' y ', $partes);
    }
}
