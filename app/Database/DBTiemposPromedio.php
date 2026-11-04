<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Promedios compilados de la etapa de ruta: inicio de ruta -> llegada a destino
 * (etapa 4 de `DBTiemposRuta`), sin mirar los estados de planta: es data cruda
 * de la referencia.
 *
 * Reglas de la compilacion (pedidas asi):
 *  - La ventana es TODA la data, sin rango de fechas.
 *  - El tope se expresa en minutos y puede ser uno global para todas las rutas
 *    o el p97 de cada ruta (`modo = ruta`).
 *  - Se compila por partes: al compilar un subconjunto solo se reescriben esas
 *    rutas; una ruta sin viajes medibles no se crea ni se toca.
 *  - El promedio "fusionado" (ida + vuelta) NO se recalcula sobre los viajes:
 *    es el promedio de los dos promedios ya guardados del mismo par de
 *    ubicaciones.
 */
class DBTiemposPromedio
{
    /** Tope por defecto de la pantalla: 72 horas. */
    public const TOPE_DEFECTO = 4320;

    /** Un tope menor a 1 hora no deja medir ningun recorrido real. */
    public const TOPE_MINIMO = 60;

    /** Percentil que se propone como tope propio de cada ruta. */
    public const PERCENTIL_TOPE = 0.97;

    /** Piso del tope propio: por debajo de 4 horas solo entran rutas urbanas. */
    public const TOPE_PISO = 240;

    /** Los hitos con fecha anterior a esto son basura de carga, no viajes. */
    private const HITO_MINIMO = '2000-01-01 00:00:00';

    /** Ordenes de la tabla por ruta: clave => etiqueta del selector. */
    public const ORDENES = [
        'viajes' => 'Más viajes medibles',
        'perdido' => 'Mayor % perdido',
        'perdido_asc' => 'Menor % perdido',
        'descartados' => 'Más viajes descartados',
        'promedio' => 'Promedio más alto',
        'ruta' => 'Nombre de la ruta',
    ];

    private static $rutas_por_id = null;

    private static $tablas_listas = null;

    private static $departamentos = null;

    //------------------- base -------------------

    /**
     * Una fila por viaje medible: ruta, dia y hora de inicio de ruta, y los
     * minutos del recorrido. `qr_llegada_destino >= inicio_ruta` deja fuera los
     * hitos invertidos (no hay viajes negativos en la data, pero el filtro
     * protege la compilacion).
     *
     * Los departamentos se resuelven contra el catalogo de ubigeo de cada
     * ruta (`rutas.ubigeo_origen` / `rutas.ubigeo_destino`): los dos primeros
     * digitos son el departamento.
     *
     * @return array{0: string, 1: array}
     */
    private static function base_sql(?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null): array
    {
        $filtros = [];
        $params = [self::HITO_MINIMO, self::HITO_MINIMO];

        $ruta_id = trim((string) $ruta_id);
        if ($ruta_id !== '') {
            $filtros[] = 'AND r.ruta_id = ?';
            $params[] = $ruta_id;
        }

        $dep_origen = self::departamento($dep_origen);
        if ($dep_origen !== null) {
            $filtros[] = 'AND r.ruta_id IN (SELECT ru.id FROM rutas ru WHERE LEFT(ru.ubigeo_origen, 2) = ?)';
            $params[] = $dep_origen;
        }

        $dep_destino = self::departamento($dep_destino);
        if ($dep_destino !== null) {
            $filtros[] = 'AND r.ruta_id IN (SELECT ru.id FROM rutas ru WHERE LEFT(ru.ubigeo_destino, 2) = ?)';
            $params[] = $dep_destino;
        }

        $sql = "
        SELECT
            r.ruta_id AS ruta_id,
            WEEKDAY(r.inicio_ruta) AS dia,
            HOUR(r.inicio_ruta) AS hora,
            TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) AS e4
        FROM referencias r
        WHERE r.ruta_id IS NOT NULL
          AND r.ruta_id <> ''
          AND r.inicio_ruta >= ?
          AND r.qr_llegada_destino >= ?
          AND r.qr_llegada_destino >= r.inicio_ruta
          " . implode(' ', $filtros);

        return [$sql, $params];
    }

    /**
     * Derivado con el tope que le toca a cada ruta: una fila por ruta con
     * viajes. En modo global todas las filas llevan el mismo tope; en modo ruta
     * cada una lleva su p97 (con piso); en modo propio cada una lleva, por
     * orden: el tope escrito en la tabla (`$topes`, ruta => minutos), el tope
     * con el que se compilo, o el global. El JOIN con la base deja fuera las
     * rutas que no tienen tope, que son justamente las que no se compilan.
     *
     * @param  array<string, int>  $topes  topes escritos en la pantalla
     * @return array{0: string, 1: array}
     */
    private static function topes_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);

        // el DISTINCT deja una sola fila por ruta: sin el, el JOIN de los
        // agregados compara cada viaje contra cada viaje de la ruta
        if ($modo === 'propio') {
            $tablas = self::tablas_listas();
            $join_guardado = $tablas ? 'LEFT JOIN tiempos_ruta_promedio p ON p.ruta_id = v.ruta_id' : '';

            // los topes escritos entran como parametros, nunca interpolados
            $bloques = [];
            $params_escritos = [];
            foreach ($topes as $id => $minutos) {
                $bloques[] = 'SELECT ? AS ruta_id, ? AS tope';
                $params_escritos[] = (string) $id;
                $params_escritos[] = (int) $minutos;
            }
            $join_escritos = $params_escritos !== []
                ? 'LEFT JOIN (' . implode(' UNION ALL ', $bloques) . ') e ON e.ruta_id = v.ruta_id'
                : '';

            $expr = $join_escritos !== '' ? 'COALESCE(e.tope, ' : 'COALESCE(';
            $expr .= ($tablas ? 'p.tope_min' : 'NULL') . ', ?)';

            $sql = "
            SELECT v.ruta_id AS ruta_id, $expr AS tope
            FROM (SELECT DISTINCT ruta_id FROM ($base) d) v
            $join_guardado
            $join_escritos";

            return [$sql, array_merge([$tope], $base_params, $params_escritos)];
        }

        if ($modo !== 'ruta') {
            return ['SELECT DISTINCT v.ruta_id AS ruta_id, ? AS tope FROM (' . $base . ') v', array_merge([$tope], $base_params)];
        }

        // p97 por ruta, con piso: el tope propio nunca baja de 4 horas
        $sql = "
        SELECT ruta_id, GREATEST(?, MAX(CASE WHEN rn = CEIL(? * cnt) THEN e4 END)) AS tope
        FROM (
            SELECT b.ruta_id AS ruta_id, b.e4 AS e4,
                   ROW_NUMBER() OVER (PARTITION BY b.ruta_id ORDER BY b.e4) AS rn,
                   COUNT(*) OVER (PARTITION BY b.ruta_id) AS cnt
            FROM ($base) b
        ) w
        GROUP BY ruta_id";

        return [$sql, array_merge([self::TOPE_PISO, self::PERCENTIL_TOPE], $base_params)];
    }

    /**
     * Agregados por ruta con el tope aplicado. `descartados` es exactamente lo
     * que se pierde con ese tope y `prom_sin_tope` sirve de contraste.
     *
     * @return array{0: string, 1: array}
     */
    private static function agregados_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);
        [$topes_sql, $topes_params] = self::topes_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes);

        $sql = "
        SELECT b.ruta_id AS ruta_id,
               COUNT(*) AS viajes_total,
               SUM(CASE WHEN b.e4 <= t.tope THEN 1 ELSE 0 END) AS viajes,
               SUM(CASE WHEN b.e4 >  t.tope THEN 1 ELSE 0 END) AS descartados,
               AVG(CASE WHEN b.e4 <= t.tope THEN b.e4 END) AS prom_min,
               AVG(b.e4) AS prom_sin_tope_min,
               MIN(b.e4) AS min_min,
               MAX(b.e4) AS max_min,
               MAX(t.tope) AS tope_min
        FROM ($base) b
        JOIN ($topes_sql) t ON t.ruta_id = b.ruta_id
        GROUP BY b.ruta_id";

        return [$sql, array_merge($base_params, $topes_params)];
    }

    /**
     * Percentiles por ruta de los viajes que caen dentro del tope.
     */
    private static function percentiles_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);
        [$topes_sql, $topes_params] = self::topes_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes);

        $sql = "
        SELECT ruta_id,
               MAX(CASE WHEN rn = CEIL(0.25 * cnt) THEN e4 END) AS p25_min,
               MAX(CASE WHEN rn = CEIL(0.50 * cnt) THEN e4 END) AS mediana_min,
               MAX(CASE WHEN rn = CEIL(0.75 * cnt) THEN e4 END) AS p75_min,
               STDDEV_SAMP(e4) AS desv_min
        FROM (
            SELECT v.ruta_id AS ruta_id, v.e4 AS e4,
                   ROW_NUMBER() OVER (PARTITION BY v.ruta_id ORDER BY v.e4) AS rn,
                   COUNT(*) OVER (PARTITION BY v.ruta_id) AS cnt
            FROM ($base) v
            JOIN ($topes_sql) t ON t.ruta_id = v.ruta_id
            WHERE v.e4 <= t.tope
        ) w
        GROUP BY ruta_id";

        return [$sql, array_merge($base_params, $topes_params)];
    }

    //------------------- lectura -------------------

    /**
     * Rutas con nombre resuelto, igual que el selector de /lupita/tiempos.
     */
    public static function rutas(): array
    {
        if (self::$rutas_por_id !== null) {
            return self::$rutas_por_id;
        }

        $mapa = [];
        foreach (DBTiemposRuta::rutas_lista() as $ruta) {
            $nombre = DBTiemposRuta::nombre_ruta($ruta);
            $ruta->nombre = $nombre !== '' ? $nombre : (string) $ruta->id;
            $mapa[(string) $ruta->id] = $ruta;
        }

        return self::$rutas_por_id = $mapa;
    }

    public static function nombre(string $ruta_id): string
    {
        return self::rutas()[$ruta_id]->nombre ?? $ruta_id;
    }

    /**
     * Catalogo de departamentos del ubigeo: `ubigeo` => nombre. Es el que
     * alimenta los combos de origen y destino de la pantalla.
     *
     * @return array<string, string>
     */
    public static function departamentos(): array
    {
        if (self::$departamentos !== null) {
            return self::$departamentos;
        }

        try {
            self::$departamentos = DB::table('ubigeo_departamentos')
                ->orderBy('departamento')
                ->pluck('departamento', 'ubigeo')
                ->all();
        } catch (\Throwable $e) {
            self::$departamentos = [];
        }

        return self::$departamentos;
    }

    /**
     * Nombre del departamento de un codigo de dos digitos.
     */
    public static function nombre_departamento($codigo): ?string
    {
        $codigo = self::departamento($codigo);

        return $codigo !== null ? (self::departamentos()[$codigo] ?? null) : null;
    }

    /**
     * Normaliza el filtro de departamento al codigo de dos digitos del ubigeo.
     */
    private static function departamento($codigo): ?string
    {
        $codigo = trim((string) $codigo);

        return preg_match('/^\d{1,2}$/', $codigo) === 1 ? str_pad($codigo, 2, '0', STR_PAD_LEFT) : null;
    }

    /**
     * Lo que se pierde con un tope, en toda la data (o en una sola ruta).
     *
     * @param  array<string, int>  $topes  topes escritos en la tabla (ruta => minutos)
     */
    public static function perdida(int $tope, string $modo = 'global', ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = []): object
    {
        [$sql, $params] = self::agregados_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes);
        $filas = DB::select($sql, $params);

        $resumen = (object) [
            'rutas' => 0,
            'rutas_afectadas' => 0,
            'viajes_total' => 0,
            'viajes' => 0,
            'descartados' => 0,
            'pct_perdido' => null,
            'pct_afectadas' => null,
            'prom_min' => null,
            'prom_sin_tope_min' => null,
            'min_min' => null,
            'max_min' => null,
            'sin_promedio' => 0,
        ];

        $suma_prom = 0.0;
        $suma_prom_bruto = 0.0;
        $con_prom = 0;

        foreach ($filas as $fila) {
            $resumen->rutas++;
            $resumen->viajes_total += (int) $fila->viajes_total;
            $resumen->viajes += (int) $fila->viajes;
            $resumen->descartados += (int) $fila->descartados;

            if ((int) $fila->descartados > 0) {
                $resumen->rutas_afectadas++;
            }
            if ((int) $fila->viajes === 0) {
                $resumen->sin_promedio++;
            }
            if ($fila->prom_min !== null) {
                $suma_prom += (float) $fila->prom_min;
                $con_prom++;
            }
            if ($fila->prom_sin_tope_min !== null) {
                $suma_prom_bruto += (float) $fila->prom_sin_tope_min;
            }
            if ($fila->min_min !== null && ($resumen->min_min === null || (int) $fila->min_min < $resumen->min_min)) {
                $resumen->min_min = (int) $fila->min_min;
            }
            if ($fila->max_min !== null && ($resumen->max_min === null || (int) $fila->max_min > $resumen->max_min)) {
                $resumen->max_min = (int) $fila->max_min;
            }
        }

        // promedio de promedios por ruta: cada ruta pesa igual, no cada viaje
        $resumen->prom_min = $con_prom > 0 ? round($suma_prom / $con_prom, 1) : null;
        $resumen->prom_sin_tope_min = $resumen->rutas > 0 ? round($suma_prom_bruto / $resumen->rutas, 1) : null;
        $resumen->pct_perdido = $resumen->viajes_total > 0
            ? round(100 * $resumen->descartados / $resumen->viajes_total, 2)
            : null;
        $resumen->pct_afectadas = $resumen->rutas > 0
            ? round(100 * $resumen->rutas_afectadas / $resumen->rutas, 1)
            : null;

        return $resumen;
    }

    /**
     * Tabla de la pantalla: por ruta, cuanto aporta y cuanto se pierde con el
     * tope elegido, mas el p97 que se le propondria como tope propio.
     *
     * `$orden` es una de las claves de `ORDENES`; por defecto las rutas con mas
     * viajes medibles, que son las que mandan en el promedio global.
     */
    public static function por_ruta(
        int $tope,
        string $modo = 'global',
        ?string $ruta_id = null,
        ?string $dep_origen = null,
        ?string $dep_destino = null,
        string $orden = 'viajes',
        array $topes = []
    ): array {
        [$sql, $params] = self::agregados_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes);
        $filas = DB::select($sql, $params);

        $p97 = self::topes_p97($ruta_id);
        $guardados = self::guardados_por_ruta();
        $rutas = self::rutas();

        $salida = [];
        foreach ($filas as $fila) {
            $id = (string) $fila->ruta_id;
            $total = (int) $fila->viajes_total;
            $descartados = (int) $fila->descartados;

            $salida[] = (object) [
                'ruta_id' => $id,
                'nombre' => self::nombre($id),
                'origen_id' => $rutas[$id]->origen_id ?? null,
                'destino_id' => $rutas[$id]->destino_id ?? null,
                'dep_origen' => self::nombre_departamento(substr((string) ($rutas[$id]->ubigeo_origen ?? ''), 0, 2)),
                'dep_destino' => self::nombre_departamento(substr((string) ($rutas[$id]->ubigeo_destino ?? ''), 0, 2)),
                'viajes_total' => $total,
                'viajes' => (int) $fila->viajes,
                'descartados' => $descartados,
                'pct_perdido' => $total > 0 ? round(100 * $descartados / $total, 1) : null,
                'prom_min' => $fila->prom_min !== null ? round((float) $fila->prom_min, 1) : null,
                'prom_sin_tope_min' => $fila->prom_sin_tope_min !== null ? round((float) $fila->prom_sin_tope_min, 1) : null,
                'min_min' => $fila->min_min !== null ? (int) $fila->min_min : null,
                'max_min' => $fila->max_min !== null ? (int) $fila->max_min : null,
                'tope_min' => (int) $fila->tope_min,
                'p97_min' => $p97[$id] ?? null,
                'guardado' => $guardados[$id] ?? null,
            ];
        }

        // el orden se elige en la pantalla; las rutas sin promedio (no quedan
        // viajes dentro del tope) siempre van al final en los ordenes de tiempo
        $orden = array_key_exists($orden, self::ORDENES) ? $orden : 'viajes';

        usort($salida, function ($a, $b) use ($orden) {
            switch ($orden) {
                case 'perdido':
                    return (($b->pct_perdido ?? -1) <=> ($a->pct_perdido ?? -1))
                        ?: ($b->descartados <=> $a->descartados)
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'perdido_asc':
                    return (($a->pct_perdido ?? 101) <=> ($b->pct_perdido ?? 101))
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'descartados':
                    return ($b->descartados <=> $a->descartados)
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'promedio':
                    return (($b->prom_min ?? -1) <=> ($a->prom_min ?? -1))
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'ruta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: strcasecmp($a->ruta_id, $b->ruta_id);

                default:
                    return ($b->viajes_total <=> $a->viajes_total)
                        ?: ($b->pct_perdido ?? -1) <=> ($a->pct_perdido ?? -1);
            }
        });

        return $salida;
    }

    /**
     * p97 de cada ruta (con piso), que es el tope propio sugerido.
     *
     * @return array<string, int>
     */
    public static function topes_p97(?string $ruta_id = null): array
    {
        [$sql, $params] = self::topes_sql(0, 'ruta', $ruta_id);
        $salida = [];
        foreach (DB::select($sql, $params) as $fila) {
            $salida[(string) $fila->ruta_id] = (int) $fila->tope;
        }

        return $salida;
    }

    /**
     * Promedios ya compilados, indexados por ruta.
     *
     * @return array<string, object>
     */
    public static function guardados_por_ruta(): array
    {
        if (!self::tablas_listas()) {
            return [];
        }

        $salida = [];
        foreach (DB::select('SELECT * FROM tiempos_ruta_promedio') as $fila) {
            $salida[(string) $fila->ruta_id] = $fila;
        }

        return $salida;
    }

    /**
     * Estado de la compilacion: cuantas rutas hay guardadas y con que tope.
     */
    public static function estado(): object
    {
        $estado = (object) [
            'tablas' => self::tablas_listas(),
            'rutas' => 0,
            'viajes' => 0,
            'descartados' => 0,
            'celdas' => 0,
            'topes' => [],
            'ultima' => null,
            'fusionadas' => 0,
        ];

        if (!$estado->tablas) {
            return $estado;
        }

        $fila = DB::selectOne("
            SELECT COUNT(*) AS rutas,
                   COALESCE(SUM(viajes), 0) AS viajes,
                   COALESCE(SUM(descartados), 0) AS descartados,
                   COUNT(DISTINCT tope_min) AS topes,
                   SUM(CASE WHEN prom_fusion_min IS NOT NULL THEN 1 ELSE 0 END) AS fusionadas,
                   MAX(actualizado_en) AS ultima
            FROM tiempos_ruta_promedio");

        $estado->rutas = (int) ($fila->rutas ?? 0);
        $estado->viajes = (int) ($fila->viajes ?? 0);
        $estado->descartados = (int) ($fila->descartados ?? 0);
        $estado->topes = (int) ($fila->topes ?? 0);
        $estado->fusionadas = (int) ($fila->fusionadas ?? 0);
        $estado->ultima = $fila->ultima ?? null;
        $estado->celdas = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM tiempos_ruta_horario')->n ?? 0);

        return $estado;
    }

    /**
     * La pantalla tiene que aguantar que las tablas todavia no existan en el
     * servidor (el CREATE se aplica a mano): en ese caso solo previsualiza.
     */
    public static function tablas_listas(): bool
    {
        if (self::$tablas_listas !== null) {
            return self::$tablas_listas;
        }

        try {
            $tablas = DB::select("SHOW TABLES LIKE 'tiempos_ruta_%'");
            return self::$tablas_listas = count($tablas) >= 2;
        } catch (\Throwable $e) {
            return self::$tablas_listas = false;
        }
    }

    //------------------- compilacion -------------------

    /**
     * Compila y guarda los promedios del tope elegido.
     *
     * `$solo_faltantes` deja intactas las rutas que ya tienen promedio: sirve
     * para compilar por partes, agregando tramos nuevos sin reescribir lo que
     * ya estaba. Las rutas sin viajes dentro del tope no se crean.
     *
     * `$solo_rutas` compila solo los ids marcados en la tabla (los checks de
     * la pantalla); vacio significa todas las que entren en el filtro.
     */
    public static function compilar(
        int $tope,
        string $modo = 'global',
        ?string $ruta_id = null,
        bool $solo_faltantes = false,
        array $solo_rutas = [],
        ?string $dep_origen = null,
        ?string $dep_destino = null
    ): object {
        $tope = max(self::TOPE_MINIMO, $tope);
        $modo = in_array($modo, ['ruta', 'propio'], true) ? $modo : 'global';

        // En una sola ruta manda el tope escrito en la fila de la tabla; el
        // tope ya guardado solo se usa al recompilar lotes en modo propio.
        $modo_calculo = ($modo === 'propio' && $ruta_id !== null) ? 'global' : $modo;

        $resultado = (object) [
            'tope' => $tope,
            'modo' => $modo,
            'rutas' => 0,
            'omitidas' => 0,
            'viajes' => 0,
            'descartados' => 0,
            'celdas' => 0,
            'fusionadas' => 0,
        ];

        if (!self::tablas_listas()) {
            return $resultado;
        }

        $guardados = self::guardados_por_ruta();

        [$sql, $params] = self::agregados_sql($tope, $modo_calculo, $ruta_id, $dep_origen, $dep_destino);
        $filas = DB::select($sql, $params);

        [$sql_pct, $params_pct] = self::percentiles_sql($tope, $modo_calculo, $ruta_id, $dep_origen, $dep_destino);
        $percentiles = [];
        foreach (DB::select($sql_pct, $params_pct) as $fila) {
            $percentiles[(string) $fila->ruta_id] = $fila;
        }

        $rutas = self::rutas();
        $compiladas = [];
        $lotes = [];
        $ahora = now();

        foreach ($filas as $fila) {
            $id = (string) $fila->ruta_id;

            // la seleccion de la tabla manda sobre el resto del filtro
            if ($solo_rutas !== [] && !in_array($id, $solo_rutas, true)) {
                $resultado->omitidas++;
                continue;
            }

            // sin viajes dentro del tope no hay promedio que guardar: la ruta
            // queda como estaba
            if ((int) $fila->viajes === 0 || $fila->prom_min === null) {
                $resultado->omitidas++;
                continue;
            }
            if ($solo_faltantes && isset($guardados[$id])) {
                $resultado->omitidas++;
                continue;
            }

            $pct = $percentiles[$id] ?? null;

            $lotes[] = [
                'ruta_id' => $id,
                'origen_id' => $rutas[$id]->origen_id ?? null,
                'destino_id' => $rutas[$id]->destino_id ?? null,
                'modo' => $modo,
                'tope_min' => (int) $fila->tope_min,
                'viajes_total' => (int) $fila->viajes_total,
                'viajes' => (int) $fila->viajes,
                'descartados' => (int) $fila->descartados,
                'prom_min' => round((float) $fila->prom_min, 1),
                'prom_sin_tope_min' => $fila->prom_sin_tope_min !== null ? round((float) $fila->prom_sin_tope_min, 1) : null,
                'mediana_min' => $pct?->mediana_min !== null ? (int) round((float) $pct->mediana_min) : null,
                'p25_min' => $pct?->p25_min !== null ? (int) round((float) $pct->p25_min) : null,
                'p75_min' => $pct?->p75_min !== null ? (int) round((float) $pct->p75_min) : null,
                'desv_min' => $pct?->desv_min !== null ? round((float) $pct->desv_min, 1) : null,
                'actualizado_en' => $ahora,
            ];

            $compiladas[] = $id;
            $resultado->rutas++;
            $resultado->viajes += (int) $fila->viajes;
            $resultado->descartados += (int) $fila->descartados;
        }

        foreach (array_chunk($lotes, 200) as $lote) {
            DB::table('tiempos_ruta_promedio')->upsert($lote, ['ruta_id'], [
                'origen_id', 'destino_id', 'modo', 'tope_min', 'viajes_total', 'viajes',
                'descartados', 'prom_min', 'prom_sin_tope_min', 'mediana_min', 'p25_min',
                'p75_min', 'desv_min', 'actualizado_en',
            ]);
        }

        if ($compiladas !== []) {
            $resultado->celdas = self::compilar_horario($compiladas, $tope, $modo_calculo);
            $resultado->fusionadas = self::recalcular_fusion();
        }

        return $resultado;
    }

    /**
     * Matriz ruta x dia x hora de las rutas compiladas. Se borran solo las
     * celdas de esas rutas: el resto de la tabla no se toca.
     */
    private static function compilar_horario(array $rutas, int $tope, string $modo): int
    {
        if ($rutas === []) {
            return 0;
        }

        [$base, $base_params] = self::base_sql();
        [$topes, $topes_params] = self::topes_sql($tope, $modo);

        // la lista de rutas entra como parametros, nunca interpolada
        $placeholders = implode(',', array_fill(0, count($rutas), '?'));

        $sql = "
        SELECT v.ruta_id AS ruta_id,
               v.dia AS dia,
               v.hora AS hora,
               MAX(t.tope) AS tope_min,
               COUNT(*) AS viajes,
               AVG(v.e4) AS prom_min
        FROM ($base) v
        JOIN ($topes) t ON t.ruta_id = v.ruta_id
        WHERE v.e4 <= t.tope
          AND v.ruta_id IN ($placeholders)
        GROUP BY v.ruta_id, v.dia, v.hora";

        $celdas = DB::select($sql, array_merge($base_params, $topes_params, $rutas));

        DB::table('tiempos_ruta_horario')->whereIn('ruta_id', $rutas)->delete();

        $filas = [];
        foreach ($celdas as $celda) {
            $filas[] = [
                'ruta_id' => (string) $celda->ruta_id,
                'dia' => (int) $celda->dia,
                'hora' => (int) $celda->hora,
                'tope_min' => (int) $celda->tope_min,
                'viajes' => (int) $celda->viajes,
                'prom_min' => round((float) $celda->prom_min, 1),
            ];
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('tiempos_ruta_horario')->upsert($lote, ['ruta_id', 'dia', 'hora'], ['tope_min', 'viajes', 'prom_min']);
        }

        return count($filas);
    }

    /**
     * Ids de las rutas que tienen al menos un viaje dentro del tope. Son las
     * unicas que se compilan: una ruta que se queda sin viajes no se crea.
     *
     * @return string[]
     */
    private static function rutas_con_datos(int $tope, string $modo, ?string $ruta_id = null): array
    {
        [$base, $base_params] = self::base_sql($ruta_id);
        [$topes, $topes_params] = self::topes_sql($tope, $modo, $ruta_id);

        $filas = DB::select("
        SELECT DISTINCT v.ruta_id AS ruta_id
        FROM ($base) v
        JOIN ($topes) t ON t.ruta_id = v.ruta_id
        WHERE v.e4 <= t.tope", array_merge($base_params, $topes_params));

        return array_map(fn ($fila) => (string) $fila->ruta_id, $filas);
    }

    /**
     * Compila SOLO la matriz dia x hora, sin tocar los promedios de ruta.
     *
     * Sirve para recompilar el horario con otro tope sin perder lo que ya
     * estaba compilado en `tiempos_ruta_promedio`: cada celda guarda el tope
     * con el que se calculo, asi que ambos niveles pueden convivir.
     */
    public static function compilar_solo_horario(int $tope, string $modo = 'global', ?string $ruta_id = null): object
    {
        $tope = max(self::TOPE_MINIMO, $tope);
        $modo = in_array($modo, ['ruta', 'propio'], true) ? $modo : 'global';

        $resultado = (object) ['tope' => $tope, 'modo' => $modo, 'rutas' => 0, 'celdas' => 0];

        if (!self::tablas_listas()) {
            return $resultado;
        }

        $rutas = self::rutas_con_datos($tope, $modo, $ruta_id);
        $resultado->rutas = count($rutas);
        $resultado->celdas = self::compilar_horario($rutas, $tope, $modo);

        return $resultado;
    }

    /**
     * Lo que se pierde por dia de la semana y hora, celda por celda.
     *
     * `total` son los viajes que arrancaron en esa hora y `dentro` los que
     * pasan el tope; la diferencia es lo que se pierde en esa celda. Con
     * `$min_viajes` se filtran las celdas con muestra insuficiente (solo afecta
     * a la vista, no a la compilacion).
     */
    public static function horario(int $tope, string $modo = 'global', ?string $ruta_id = null, int $min_viajes = 1): object
    {
        [$base, $base_params] = self::base_sql($ruta_id);
        [$topes, $topes_params] = self::topes_sql($tope, $modo, $ruta_id);

        $sql = "
        SELECT v.ruta_id AS ruta_id,
               v.dia AS dia,
               v.hora AS hora,
               COUNT(*) AS total,
               SUM(CASE WHEN v.e4 <= t.tope THEN 1 ELSE 0 END) AS dentro,
               AVG(CASE WHEN v.e4 <= t.tope THEN v.e4 END) AS prom_min,
               AVG(v.e4) AS prom_sin_tope,
               MAX(t.tope) AS tope_min
        FROM ($base) v
        JOIN ($topes) t ON t.ruta_id = v.ruta_id
        GROUP BY v.ruta_id, v.dia, v.hora";

        $celdas = DB::select($sql, array_merge($base_params, $topes_params));

        $resumen = (object) [
            'celdas' => count($celdas),
            'viajes' => 0,
            'dentro' => 0,
            'descartados' => 0,
            'con_1' => 0,
            'con_2' => 0,
            'con_3' => 0,
            'con_5' => 0,
            'mostradas' => 0,
            'pct_perdido' => null,
            'prom_min' => null,
        ];

        $suma_prom = 0.0;
        $con_prom = 0;
        $filas = [];
        $min_viajes = max(1, $min_viajes);

        foreach ($celdas as $celda) {
            $total = (int) $celda->total;
            $dentro = (int) $celda->dentro;
            $descartados = $total - $dentro;

            $resumen->viajes += $total;
            $resumen->dentro += $dentro;
            $resumen->descartados += $descartados;

            if ($dentro === 1) $resumen->con_1++;
            if ($dentro === 2) $resumen->con_2++;
            if ($dentro >= 3) $resumen->con_3++;
            if ($dentro >= 5) $resumen->con_5++;

            if ($celda->prom_min !== null) {
                $suma_prom += (float) $celda->prom_min;
                $con_prom++;
            }

            if ($total < $min_viajes) {
                continue;
            }

            $filas[] = (object) [
                'ruta_id' => (string) $celda->ruta_id,
                'nombre' => self::nombre((string) $celda->ruta_id),
                'dia' => (int) $celda->dia,
                'hora' => (int) $celda->hora,
                'total' => $total,
                'dentro' => $dentro,
                'descartados' => $descartados,
                'pct_perdido' => $total > 0 ? round(100 * $descartados / $total, 1) : null,
                'prom_min' => $celda->prom_min !== null ? round((float) $celda->prom_min, 1) : null,
                'prom_sin_tope' => $celda->prom_sin_tope !== null ? round((float) $celda->prom_sin_tope, 1) : null,
                'tope_min' => (int) $celda->tope_min,
            ];
        }

        // primero las celdas con mas viajes: son las que sostienen el promedio
        usort($filas, function ($a, $b) {
            if ($a->total !== $b->total) {
                return $b->total <=> $a->total;
            }
            if ($a->ruta_id !== $b->ruta_id) {
                return strcmp($a->ruta_id, $b->ruta_id);
            }
            return [$a->dia, $a->hora] <=> [$b->dia, $b->hora];
        });

        $resumen->mostradas = count($filas);
        $resumen->prom_min = $con_prom > 0 ? round($suma_prom / $con_prom, 1) : null;
        $resumen->pct_perdido = $resumen->viajes > 0
            ? round(100 * $resumen->descartados / $resumen->viajes, 2)
            : null;

        return (object) ['resumen' => $resumen, 'filas' => $filas];
    }

    /**
     * Estado de la matriz horaria ya guardada.
     */
    public static function estado_horario(): object
    {
        $estado = (object) [
            'tablas' => self::tablas_listas(),
            'celdas' => 0,
            'rutas' => 0,
            'topes' => 0,
            'con_1' => 0,
            'con_5' => 0,
            'viajes' => 0,
            'min' => null,
            'max' => null,
        ];

        if (!$estado->tablas) {
            return $estado;
        }

        $fila = DB::selectOne("
            SELECT COUNT(*) AS celdas,
                   COUNT(DISTINCT ruta_id) AS rutas,
                   COUNT(DISTINCT tope_min) AS topes,
                   SUM(viajes = 1) AS con_1,
                   SUM(viajes >= 5) AS con_5,
                   COALESCE(SUM(viajes), 0) AS viajes,
                   MIN(prom_min) AS min,
                   MAX(prom_min) AS max
            FROM tiempos_ruta_horario");

        $estado->celdas = (int) ($fila->celdas ?? 0);
        $estado->rutas = (int) ($fila->rutas ?? 0);
        $estado->topes = (int) ($fila->topes ?? 0);
        $estado->con_1 = (int) ($fila->con_1 ?? 0);
        $estado->con_5 = (int) ($fila->con_5 ?? 0);
        $estado->viajes = (int) ($fila->viajes ?? 0);
        $estado->min = $fila->min ?? null;
        $estado->max = $fila->max ?? null;

        return $estado;
    }

    /**
     * Promedio fusionado de sentidos: se toman los dos promedios YA guardados
     * del mismo par de ubicaciones y se promedian. No se vuelve a mirar la
     * data cruda, y una ruta sin pareja compilada se queda sin fusionado.
     */
    public static function recalcular_fusion(): int
    {
        if (!self::tablas_listas()) {
            return 0;
        }

        $filas = DB::select('SELECT ruta_id, origen_id, destino_id, viajes, prom_min, prom_fusion_min FROM tiempos_ruta_promedio');

        $por_par = [];
        foreach ($filas as $fila) {
            if ($fila->origen_id === null || $fila->destino_id === null) {
                continue;
            }
            $por_par[$fila->origen_id . '|' . $fila->destino_id] = $fila;
        }

        $actualizadas = 0;
        foreach ($filas as $fila) {
            if ($fila->origen_id === null || $fila->destino_id === null) {
                continue;
            }

            $vuelta = $por_par[$fila->destino_id . '|' . $fila->origen_id] ?? null;
            $fusion = null;
            $viajes_fusion = null;

            if ($vuelta !== null && $vuelta->prom_min !== null && $fila->prom_min !== null) {
                $fusion = round(((float) $fila->prom_min + (float) $vuelta->prom_min) / 2, 1);
                $viajes_fusion = (int) $fila->viajes + (int) $vuelta->viajes;
            }

            // solo se escriben las filas compiladas que ya existen
            DB::table('tiempos_ruta_promedio')->where('ruta_id', $fila->ruta_id)->update([
                'prom_fusion_min' => $fusion,
                'viajes_fusion' => $viajes_fusion,
            ]);
            $actualizadas++;
        }

        return $actualizadas;
    }
}
