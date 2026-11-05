<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Capa de datos de la verificacion MASIVA de referencias.
 *
 * Pantalla: pegar una lista de refs (5k entra sobrada) y saber SOLO:
 *   - que refs NO existen en `referencias` (nunca se importaron)
 *   - que refs existen pero NO tienen fin_descargue (ciclo abierto)
 * Las cerradas solo se cuentan, no se listan.
 *
 * No reutiliza DBConsultarReferencias::lista_de_refs porque esa corta en 500
 * tokens y esta pensada para el buscador; aqui la lista viaja por lotes de
 * CHUNK y el tope es MAX_REFS. No escribe nada: solo lee `referencias`.
 */
class DBVerificarReferencias
{
    /** Tope defensivo de refs por pega (una lista de 5k entra sobrada). */
    public const MAX_REFS = 20000;

    /** Refs por consulta: 5k refs son 5 consultas. */
    private const CHUNK = 1000;

    public const ESTADO_NO_EXISTE = 'no_existe';
    public const ESTADO_SIN_FIN = 'sin_fin';
    public const ESTADO_CERRADA = 'cerrada';

    public const ESTADOS_LABELS = [
        self::ESTADO_NO_EXISTE => 'No existe',
        self::ESTADO_SIN_FIN => 'Sin fin de descargue',
        self::ESTADO_CERRADA => 'Cerrada',
    ];

    /** Encabezados que suelen venir con la lista (hoja de Excel o .csv). */
    private const ENCABEZADOS = ['REF', 'REFS', 'REFERENCIA', 'REFERENCIAS', 'GUIA'];

    /**
     * Convierte el texto pegado (o el contenido de un .txt/.csv) en tokens de ref.
     *
     * Acepta coma, punto y coma, tabulador, pipe, espacios y saltos de linea. Con
     * $primeraColumna toma solo la primera columna de cada linea, que es como llega
     * una hoja de Excel pegada. Devuelve la lista depurada junto con lo descartado,
     * para no perder nada en silencio.
     */
    public static function parsear(string $texto, bool $primeraColumna = false): stdClass
    {
        // Excel/Windows: saltos normalizados y BOM del .csv fuera
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        if ($primeraColumna) {
            $tokens = [];
            foreach (explode("\n", $texto) as $linea) {
                $partes = preg_split('/[\t;,|]/', $linea);
                $tokens[] = (string) ($partes[0] ?? '');
            }
        } else {
            $tokens = preg_split('/[\s,;|]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
        }

        $res = new stdClass();
        $res->refs = [];
        $res->duplicadas = 0;
        $res->ignorados = [];
        $res->ignorados_total = 0;
        $res->truncadas = 0;

        $vistos = [];
        foreach ($tokens as $token) {
            // Excel suele envolver las celdas en comillas
            $token = trim((string) $token, " \t\n\r\0\x0B\"'");
            if ($token === '') {
                continue;
            }

            // `referencias.ref` es varchar(10): lo que no quepa ahi no puede existir
            if (!preg_match('/^[A-Za-z0-9\-_.]{1,10}$/', $token)) {
                $res->ignorados[] = $token;
                continue;
            }

            // el encabezado de la hoja ("REF", "GUIA"...) no es una ref a verificar
            if (in_array(mb_strtoupper($token), self::ENCABEZADOS, true)) {
                $res->ignorados[] = $token;
                continue;
            }

            $clave = mb_strtoupper($token);
            if (isset($vistos[$clave])) {
                $res->duplicadas++;
                continue;
            }
            if (count($res->refs) >= self::MAX_REFS) {
                $res->truncadas++;
                continue;
            }
            $vistos[$clave] = true;
            $res->refs[] = $token;
        }

        // si se pego una hoja entera el detalle puede ser enorme: se acota la lista
        // mostrada, pero el contador conserva el total
        $res->ignorados_total = count($res->ignorados);
        $res->ignorados = array_slice($res->ignorados, 0, 50);

        return $res;
    }

    /**
     * Verifica la lista contra `referencias` en lotes.
     *
     * Devuelve las refs en el ORDEN EN QUE SE PEGARON y, separadas, las listas de
     * los dos casos que interesan: no existen / sin fin de descargue. De las
     * cerradas solo queda el conteo en los KPIs.
     */
    public static function verificar(array $tokens): array
    {
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        if (empty($tokens)) {
            return [
                'filas' => [],
                'no_existen' => [],
                'sin_fin' => [],
                'cerradas' => [],
                'kpis' => self::kpis([]),
            ];
        }

        // solo se necesita ref y si el ciclo esta cerrado: sin joins, 5k refs
        // se resuelven en ~5 consultas livianas
        $encontradas = [];
        foreach (array_chunk($tokens, self::CHUNK) as $lote) {
            $filas = DB::table('referencias')
                ->whereIn('ref', $lote)
                ->selectRaw('ref, fin_descargue')
                ->get();

            foreach ($filas as $f) {
                // la collation de `ref` ignora mayusculas; la clave normalizada evita
                // que una ref se pierda por como vino escrita en la lista
                $encontradas[mb_strtoupper(trim((string) $f->ref))] = self::fecha_valida($f->fin_descargue) !== null;
            }
        }

        $filas = [];
        $noExisten = [];
        $sinFin = [];
        $cerradas = [];

        foreach ($tokens as $token) {
            $clave = mb_strtoupper($token);

            if (!array_key_exists($clave, $encontradas)) {
                $estado = self::ESTADO_NO_EXISTE;
                $noExisten[] = $token;
            } elseif ($encontradas[$clave]) {
                $estado = self::ESTADO_CERRADA;
                $cerradas[] = $token;
            } else {
                $estado = self::ESTADO_SIN_FIN;
                $sinFin[] = $token;
            }

            $fila = new stdClass();
            $fila->ref = $token; // se conserva tal como lo escribio el usuario
            $fila->estado = $estado;
            $filas[] = $fila;
        }

        return [
            'filas' => $filas,
            'no_existen' => $noExisten,
            'sin_fin' => $sinFin,
            'cerradas' => $cerradas,
            'kpis' => self::kpis($filas),
        ];
    }

    /** Solo las refs que fallan (no existen + sin fin), en el orden de la lista. */
    public static function solo_fallas(array $filas): array
    {
        return array_values(array_filter($filas, fn($f) => $f->estado !== self::ESTADO_CERRADA));
    }

    /** Totales del conjunto verificado. */
    private static function kpis(array $filas): stdClass
    {
        $total = count($filas);
        $noExisten = 0;
        $sinFin = 0;

        foreach ($filas as $f) {
            if ($f->estado === self::ESTADO_NO_EXISTE) {
                $noExisten++;
            } elseif ($f->estado === self::ESTADO_SIN_FIN) {
                $sinFin++;
            }
        }

        $k = new stdClass();
        $k->total = $total;
        $k->no_existen = $noExisten;
        $k->sin_fin = $sinFin;
        $k->cerradas = $total - $noExisten - $sinFin;

        return $k;
    }

    /** Normaliza una fecha de `referencias`: vacia o fecha cero de MySQL es null. */
    private static function fecha_valida($valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        if ($valor === '' || str_starts_with($valor, '0000-00-00')) {
            return null;
        }

        return $valor;
    }
}
