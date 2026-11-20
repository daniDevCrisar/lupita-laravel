<?php

namespace App\ElevenLabs;

use DateTimeImmutable;

/**
 * Convierte las fechas en texto natural de ElevenLabs a una fecha utilizable.
 *
 * QUE FORMATO LLEGA (verificado en llamadas reales)
 *   "el sábado 21 de noviembre a las 7 y 28 de la mañana"
 *   "el jueves 26 de noviembre a las 6 y 28 de la tarde"
 *   "el miércoles 18 de noviembre a las 3 y 53 de la tarde"
 *   "el viernes 20 de noviembre a las 10 y 53 de la mañana"
 *
 * Tres cosas que el parser viejo de VAPI (public/js/import/json/procesar_json.js,
 * `convertirFechaTextoAFecha`) NO aguanta y por eso no se reutiliza:
 *   1. Exige el anio escrito en letras ("... de dos mil veintiseis"). Estas fechas
 *      no traen anio: se usa el de la llamada.
 *   2. Exige la hora como "19:00"; aqui viene "a las 7 y 28".
 *   3. Devuelve dd/mm/aaaa, y el pipeline espera justamente eso, pero solo si
 *      logra parsear; si no, devuelve el texto crudo y `fecha_string_o_numero`
 *      acaba en null.
 *
 * POR QUE DEVUELVE dd/mm/aaaa HH:mm
 * `DBTmpLlamadas::fecha_string_o_numero()` solo acepta 'd/m/Y H:i' y
 * 'd/m/Y H:i:s', y le suma 5 horas asumiendo que el texto viene en hora local
 * (America/Lima). Devolver ISO o timestamp directo romperia esa conversion.
 */
class FechaTexto
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /**
     * @param  string  $texto  fecha en texto natural
     * @param  int  $anioReferencia  anio a usar cuando el texto no lo trae (normalmente el de la llamada)
     * @return string|null  "dd/mm/aaaa HH:mm", o null si no se pudo interpretar
     */
    public static function parsear(string $texto, int $anioReferencia): ?string
    {
        $texto = self::normalizar($texto);
        if ($texto === '') {
            return null;
        }

        // --- dia y mes: "18 de noviembre"
        if (! preg_match('/(\d{1,2})\s+de\s+([a-z]+)/', $texto, $m)) {
            return null;
        }

        $dia = (int) $m[1];
        $mes = self::MESES[$m[2]] ?? null;
        if ($mes === null) {
            return null;
        }

        // --- anio: "... de 2026" o "... de dos mil veintiseis" (por si algun dia lo mandan)
        $anio = $anioReferencia;
        if (preg_match('/de\s+(\d{4})/', $texto, $ma)) {
            $anio = (int) $ma[1];
        } else {
            $anio = self::anioEnLetras($texto) ?? $anio;
        }

        // --- hora: "a las 3 y 53 de la tarde" | "a las 7 de la mañana" | "a las 15:30"
        $hora = 0;
        $minuto = 0;
        $tieneHora = false;

        if (preg_match('/a\s+las\s+(\d{1,2})\s+y\s+(\d{1,2})\s+de\s+la\s+(manana|tarde|noche)/', $texto, $mh)) {
            $hora = (int) $mh[1];
            $minuto = (int) $mh[2];
            $hora = self::ajustarPeriodo($hora, $mh[3]);
            $tieneHora = true;
        } elseif (preg_match('/a\s+las\s+(\d{1,2})\s+de\s+la\s+(manana|tarde|noche)/', $texto, $mh)) {
            $hora = self::ajustarPeriodo((int) $mh[1], $mh[2]);
            $minuto = 0;
            $tieneHora = true;
        } elseif (preg_match('/a\s+las\s+(\d{1,2}):(\d{2})/', $texto, $mh)) {
            $hora = (int) $mh[1];
            $minuto = (int) $mh[2];
            $tieneHora = true;
        } elseif (preg_match('/(\d{1,2}):(\d{2})/', $texto, $mh)) {
            $hora = (int) $mh[1];
            $minuto = (int) $mh[2];
            $tieneHora = true;
        }

        // Sin hora no se inventa una: la fecha quedaria a medianoche y el pipeline
        // la interpretaria como un compromiso a las 00:00, que es peor que null.
        if (! $tieneHora) {
            return null;
        }

        if (! checkdate($mes, $dia, $anio) || $hora > 23 || $minuto > 59) {
            return null;
        }

        return sprintf('%02d/%02d/%04d %02d:%02d', $dia, $mes, $anio, $hora, $minuto);
    }

    private static function ajustarPeriodo(int $hora, string $periodo): int
    {
        if (($periodo === 'tarde' || $periodo === 'noche') && $hora < 12) {
            return $hora + 12;
        }

        // "12 de la mañana" son las 00:xx
        if ($periodo === 'manana' && $hora === 12) {
            return 0;
        }

        return $hora;
    }

    /** "dos mil veintiseis" -> 2026 (solo por si algun dia lo mandan asi). */
    private static function anioEnLetras(string $texto): ?int
    {
        $mapa = [
            'dos mil veintiseis' => 2026,
            'dos mil veintisiete' => 2027,
            'dos mil veintiocho' => 2028,
        ];

        foreach ($mapa as $letras => $anio) {
            if (str_contains($texto, $letras)) {
                return $anio;
            }
        }

        return null;
    }

    /**
     * Minusculas y sin tildes, para que "sábado" y "sabado" den igual.
     *
     * NO se usa iconv('UTF-8','ASCII//TRANSLIT'): en el build de Windows de este
     * entorno translitera "ñ" como "~n" y "á" como "'a" ("mañana" -> "ma~nana"),
     * asi que todos los textos con "de la mañana" fallaban. El mapa explicito da
     * el mismo resultado en cualquier plataforma.
     */
    private static function normalizar(string $texto): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return '';
        }

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);

        return mb_strtolower(preg_replace('/\s+/', ' ', $texto) ?? $texto);
    }

    /**
     * Fecha de referencia (anio) que se usa cuando el texto no la trae.
     * Se calcula desde el inicio de la llamada en la zona de la app.
     */
    public static function anioDeLaLlamada(array $detalle): int
    {
        $inicio = (int) ($detalle['metadata']['start_time_unix_secs'] ?? 0);
        if ($inicio <= 0) {
            return (int) date('Y');
        }

        return (int) (new DateTimeImmutable('@' . $inicio))
            ->setTimezone(new \DateTimeZone(config('app.timezone')))
            ->format('Y');
    }
}
