<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Promedios compilados de la etapa de ruta: inicio de ruta -> llegada a destino
 * (etapa 4 de `DBTiemposRuta`), sin mirar los estados de planta: es data cruda
 * de la referencia. Ademas compila el ciclo completo (E2..E6).
 *
 * Reglas de la compilacion (pedidas asi):
 *  - La ventana es TODA la data, sin rango de fechas.
 *  - El tope de recorrido se expresa en minutos y puede ser uno global para
 *    todas las rutas o el p82 de cada ruta (`modo = ruta`), que deja como
 *    maximo 18 % de los viajes por encima y es el tope recomendado.
 *  - La clase sigue aceptando dos topes de espera opcionales, en minutos:
 *    FUERA de planta (E2 presenta -> inicio de carga y E5 llegada a destino ->
 *    inicio de descargue) y DENTRO de planta (E3 inicio de carga -> inicio de
 *    ruta y E6 inicio de descargue -> fin de descargue). La pantalla de rutas
 *    ya no los pide: los topes por planta viven en `DBTiemposPlanta`.
 *  - El ciclo promedio es la SUMA de los promedios de E2..E6 con los topes
 *    aplicados, y solo se guarda cuando las cinco etapas aportan promedio.
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

    /**
     * Tope con el que abre la pantalla de compilacion: 0 significa "no mando
     * un tope fijo, usa el tope a aplicar del combo". En modo global 0 es
     * literalmente sin tope (no se descarta nada); al compilar se cae al p82
     * por ruta para no guardar promedios sin ningun corte.
     */
    public const TOPE_PANTALLA = 0;

    /** Tope guardado que significa "sin tope": la columna no es nullable. */
    public const TOPE_SIN_TOPE = 0;

    /** Tope maximo aceptado para los topes de espera (minutos). */
    public const ESPERA_MAXIMO = 100000;

    /** Etapas de espera FUERA de planta: E2 (carga) y E5 (descarga). */
    private static $etapas_fuera = [2, 5];

    /** Etapas que corren DENTRO de planta: E3 (carga) y E6 (descargue). */
    private static $etapas_dentro = [3, 6];

    /** Un tope menor a media hora no deja medir ningun recorrido real. */
    public const TOPE_MINIMO = 30;

    /** Percentil que se propone como tope propio de cada ruta: deja fuera, como
     *  maximo, el 18 % de los viajes de esa ruta. */
    public const PERCENTIL_TOPE = 0.82;

    /** Piso del tope propio: por debajo de 4 horas solo entran rutas urbanas. */
    public const TOPE_PISO = 240;

    /** Percentil del tope de espera: descarta como maximo el 18 % de los viajes
     *  con la cadena E2..E6 completa. */
    public const PERCENTIL_ESPERA = 0.82;

    /** Percentil del ciclo rapido: deja fuera la mitad de los viajes con mas
     *  espera, para contrastar con el promedio (p82) y el tardado (sin tope). */
    public const PERCENTIL_RAPIDO = 0.50;

    /** Piso del tope de espera: bajo media hora no hay tardanza que medir. */
    public const ESPERA_PISO = 30;

    /** Techo de una espera creible (7 dias). Por encima es un hito mal
     *  capturado y arruinaria el promedio del ciclo tardado. */
    public const ESPERA_VALIDA_MAXIMO = 10080;

    /** Los hitos con fecha anterior a esto son basura de carga, no viajes. */
    private const HITO_MINIMO = '2000-01-01 00:00:00';

    /**
     * Modos validos del tope de recorrido (E4) que ofrece la pantalla, en el
     * mismo formato de combo que las plantas. El tope de espera fuera y dentro
     * no se elige aqui: siempre usa el percentil que deja <=18 % de perdida.
     */
    public const MODOS = [
        'global' => 'Un tope global para todas',
        'ruta' => 'Recomendado (p82 por ruta, ≤18 % descartado)',
        'propio' => 'El tope de cada ruta',
    ];

    /** Modo con el que abre la pantalla: el recomendado, porque el tope viene en 0. */
    public const MODO_DEFECTO = 'ruta';

    /** Ordenes de la tabla por ruta: clave => etiqueta del selector. */
    public const ORDENES = [
        'viajes' => 'Más viajes medibles',
        'perdido' => 'Mayor % perdido',
        'perdido_asc' => 'Menor % perdido',
        'descartados' => 'Más viajes descartados',
        'promedio' => 'Promedio más alto',
        'ciclo' => 'Ciclo más alto',
        'ruta' => 'Nombre de la ruta',
    ];

    private static $rutas_por_id = null;

    private static $tablas_listas = null;

    private static $columnas_ciclo = null;

    private static $columnas_espera = null;

    private static $departamentos = null;

    private static $distritos = null;

    //------------------- base -------------------

    /**
     * Expresiones TIMESTAMPDIFF de las etapas del ciclo (E2..E6) con el alias
     * `r` de `referencias`. Son la unica fuente de las formulas: alimentan las
     * columnas `eN`, el ciclo observado y los topes de espera.
     *
     * @return array<int, string>
     */
    public static function expresiones_etapas(): array
    {
        return [
            2 => 'TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga)',
            3 => 'TIMESTAMPDIFF(MINUTE, r.inicio_de_carga, r.inicio_ruta)',
            4 => 'TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino)',
            5 => 'TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue)',
            6 => 'TIMESTAMPDIFF(MINUTE, r.inicio_descargue, r.fin_descargue)',
        ];
    }

    /**
     * Columnas `e2 ... e6` de la vista base, en minutos.
     */
    private static function select_etapas(): string
    {
        $columnas = [];
        foreach (self::expresiones_etapas() as $etapa => $expresion) {
            $columnas[] = "$expresion AS e$etapa";
        }

        return implode(",\n            ", $columnas);
    }

    /**
     * Ciclo observado de un viaje: solo es calculable con la cadena E2..E6
     * completa y en orden. Si falta un hito, la diferencia entre extremos
     * abarca el hueco (dias enteros) y no es un viaje real.
     */
    private static function ciclo_total_sql(): string
    {
        $etapas = array_values(self::expresiones_etapas());

        return 'CASE WHEN LEAST(' . implode(', ', $etapas) . ') >= 0'
            . ' THEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue)'
            . ' END AS ciclo_total';
    }

    /**
     * Una fila por viaje medible: ruta, dia y hora de inicio de ruta, los
     * minutos de cada etapa (E2..E6) y el ciclo observado.
     * `qr_llegada_destino >= inicio_ruta` deja fuera los hitos invertidos (no
     * hay viajes negativos en la data, pero el filtro protege la compilacion).
     *
     * Los departamentos se resuelven contra el catalogo de ubigeo de cada
     * ruta (`rutas.ubigeo_origen` / `rutas.ubigeo_destino`): los dos primeros
     * digitos son el departamento.
     *
     * Es publica porque `DBTiemposPlanta` la reutiliza para no duplicar las
     * formulas de las etapas.
     *
     * @return array{0: string, 1: array}
     */
    public static function base_sql(?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null): array
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
            " . self::select_etapas() . ",
            " . self::ciclo_total_sql() . "
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
     * Condicion "etapa dentro del tope": sin tope (NULL) o sin hito (NULL) la
     * etapa no descarta el viaje; un hito invertido (negativo) si lo descarta.
     *
     * Tambien la usa `DBTiemposPlanta` para los topes de espera de cada planta.
     */
    public static function dentro_tope(string $columna, string $tope): string
    {
        return "($columna IS NULL OR $tope IS NULL OR $columna BETWEEN 0 AND $tope)";
    }

    /**
     * Condiciones de los topes de espera de una fila de viaje: la primera es
     * FUERA de planta (E2 y E5) y la segunda DENTRO de planta (E3 y E6).
     *
     * @return array{0: string, 1: string}
     */
    private static function condiciones_espera(string $viaje, string $topes): array
    {
        $fuera = [];
        foreach (self::$etapas_fuera as $etapa) {
            $fuera[] = self::dentro_tope("$viaje.e$etapa", "$topes.tope_fuera");
        }

        $dentro = [];
        foreach (self::$etapas_dentro as $etapa) {
            $dentro[] = self::dentro_tope("$viaje.e$etapa", "$topes.tope_dentro");
        }

        return [implode(' AND ', $fuera), implode(' AND ', $dentro)];
    }

    /**
     * Columna de un tope de espera dentro de `topes_sql`.
     *
     * El tope escrito en la fila de la ruta manda; si no hay, se usa
     * `$respaldo` (el guardado en la tabla, solo en modo propio) y, por
     * ultimo, el valor de la pantalla. Devuelve el JOIN con los escritos, la
     * expresion y sus parametros; el valor de la pantalla lo agrega el que
     * llama, porque va en el SELECT y no en el JOIN.
     *
     * @param  array<string, int>  $escritos  ruta => minutos
     * @return array{0: string, 1: string, 2: array}
     */
    private static function espera_sql(string $alias, string $alias_ruta, string $columna, array $escritos, ?string $respaldo): array
    {
        if ($escritos === []) {
            return ['', $respaldo !== null ? "COALESCE($respaldo, ?)" : '?', []];
        }

        $bloques = [];
        $params = [];
        foreach ($escritos as $id => $minutos) {
            $bloques[] = 'SELECT ? AS ruta_id, ? AS ' . $columna;
            $params[] = (string) $id;
            $params[] = (int) $minutos;
        }

        $join = 'LEFT JOIN (' . implode(' UNION ALL ', $bloques) . ") $alias ON $alias.ruta_id = $alias_ruta";

        $expr = "COALESCE($alias.$columna";
        if ($respaldo !== null) {
            $expr .= ", $respaldo";
        }
        $expr .= ', ?)';

        return [$join, $expr, $params];
    }

    /**
     * Derivado con el tope que le toca a cada ruta: una fila por ruta con
     * viajes. En modo global todas las filas llevan el mismo tope; en modo ruta
     * cada una lleva su p82 (con piso); en modo propio cada una lleva, por
     * orden: el tope escrito en la tabla (`$topes`, ruta => minutos), el tope
     * con el que se compilo, o el global. El JOIN con la base deja fuera las
     * rutas que no tienen tope, que son justamente las que no se compilan.
     *
     * `$espera` mantiene los topes de espera fuera y dentro de planta (los usa
     * la pantalla de plantas a traves de `DBTiemposPlanta`); la pantalla de
     * rutas ya no los pide y los deja vacios. NULL = sin tope.
     *
     * `$tope` en 0 no es un corte de cero minutos: se manda como NULL, asi que
     * en modo global no se descarta nada y en modo propio el respaldo solo
     * cubre a las rutas que no tienen tope escrito ni guardado.
     *
     * @param  array<string, int>  $topes  topes de recorrido escritos en la pantalla
     * @param  array{fuera?: ?int, dentro?: ?int, fuera_rutas?: array<string, int>, dentro_rutas?: array<string, int>}  $espera
     * @return array{0: string, 1: array}
     */
    private static function topes_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = [], array $espera = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);

        $tope_fuera = $espera['fuera'] ?? null;
        $tope_dentro = $espera['dentro'] ?? null;

        // tope 0 = no hay corte global: viaja NULL para que mande lo del modo
        // (el p82 de la ruta o su tope escrito/guardado) y, si tampoco hay,
        // no se descarta ningun viaje
        $tope_global = $tope > 0 ? $tope : null;

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
            // NULLIF: un tope guardado en 0 es "sin tope", no un corte de cero
            // minutos que descartaria todos los viajes de la ruta
            $expr .= ($tablas ? 'NULLIF(p.tope_min, ' . self::TOPE_SIN_TOPE . ')' : 'NULL') . ', ?)';

            [$join_fuera, $expr_fuera, $params_fuera] = self::espera_sql('ef', 'v.ruta_id', 'tope_fuera', $espera['fuera_rutas'] ?? [], null);
            [$join_dentro, $expr_dentro, $params_dentro] = self::espera_sql('ed', 'v.ruta_id', 'tope_dentro', $espera['dentro_rutas'] ?? [], null);

            $sql = "
            SELECT v.ruta_id AS ruta_id, $expr AS tope, $expr_fuera AS tope_fuera, $expr_dentro AS tope_dentro
            FROM (SELECT DISTINCT ruta_id FROM ($base) d) v
            $join_guardado
            $join_escritos
            $join_fuera
            $join_dentro";

            return [$sql, array_merge(
                [$tope_global, $tope_fuera, $tope_dentro],
                $base_params,
                $params_escritos,
                $params_fuera,
                $params_dentro
            )];
        }

        if ($modo !== 'ruta') {
            [$join_fuera, $expr_fuera, $params_fuera] = self::espera_sql('ef', 'v.ruta_id', 'tope_fuera', $espera['fuera_rutas'] ?? [], null);
            [$join_dentro, $expr_dentro, $params_dentro] = self::espera_sql('ed', 'v.ruta_id', 'tope_dentro', $espera['dentro_rutas'] ?? [], null);

            $sql = "
            SELECT v.ruta_id AS ruta_id, CAST(? AS SIGNED) AS tope, $expr_fuera AS tope_fuera, $expr_dentro AS tope_dentro
            FROM (SELECT DISTINCT ruta_id FROM ($base) d) v
            $join_fuera
            $join_dentro";

            return [$sql, array_merge([$tope_global, $tope_fuera, $tope_dentro], $base_params, $params_fuera, $params_dentro)];
        }

        // p82 por ruta, con piso: el tope propio nunca baja de 4 horas
        [$join_fuera, $expr_fuera, $params_fuera] = self::espera_sql('ef', 't.ruta_id', 'tope_fuera', $espera['fuera_rutas'] ?? [], null);
        [$join_dentro, $expr_dentro, $params_dentro] = self::espera_sql('ed', 't.ruta_id', 'tope_dentro', $espera['dentro_rutas'] ?? [], null);

        $sql = "
        SELECT t.ruta_id AS ruta_id,
               t.tope AS tope,
               $expr_fuera AS tope_fuera,
               $expr_dentro AS tope_dentro
        FROM (
            SELECT ruta_id, GREATEST(?, MAX(CASE WHEN rn = CEIL(? * cnt) THEN e4 END)) AS tope
            FROM (
                SELECT b.ruta_id AS ruta_id, b.e4 AS e4,
                       ROW_NUMBER() OVER (PARTITION BY b.ruta_id ORDER BY b.e4) AS rn,
                       COUNT(*) OVER (PARTITION BY b.ruta_id) AS cnt
                FROM ($base) b
            ) w
            GROUP BY ruta_id
        ) t
        $join_fuera
        $join_dentro";

        return [$sql, array_merge(
            [$tope_fuera, $tope_dentro, self::TOPE_PISO, self::PERCENTIL_TOPE],
            $base_params,
            $params_fuera,
            $params_dentro
        )];
    }

    /**
     * Promedios por etapa del ciclo para cada escenario. `$condiciones` lleva
     * prefijo => condicion (null = sin filtro): `p` es el promedio (recorrido
     * dentro de su tope y espera dentro del p82), `r` el rapido (espera dentro
     * del p50) y `s` el tardado (toda la data, sin tope). Una etapa sin dato o
     * con hito invertido no aporta promedio.
     */
    private static function promedios_etapa_sql(string $viaje, array $condiciones): string
    {
        $columnas = [];
        foreach ($condiciones as $prefijo => $condicion) {
            $filtro = $condicion !== null ? " AND $condicion" : '';
            foreach (array_keys(self::expresiones_etapas()) as $etapa) {
                $columnas[] = "AVG(CASE WHEN $viaje.e$etapa >= 0$filtro THEN $viaje.e$etapa END) AS $prefijo$etapa";
            }
        }

        return implode(",\n               ", $columnas);
    }

    /**
     * Ciclo promedio = suma de los promedios de las etapas E2..E6, solo si
     * TODAS aportan promedio; si a alguna le falta data el ciclo queda NULL.
     * `$prefijo` es `p` (con topes) o `s` (sin tope).
     */
    private static function ciclo_sql(string $prefijo): string
    {
        $suma = [];
        $tiene = [];
        foreach (array_keys(self::expresiones_etapas()) as $etapa) {
            $suma[] = "a.$prefijo$etapa";
            $tiene[] = "a.$prefijo$etapa IS NOT NULL";
        }

        return 'CASE WHEN ' . implode(' AND ', $tiene) . ' THEN ' . implode(' + ', $suma) . ' END';
    }

    /**
     * Tope de espera de cada ruta: el p82 y el p50 de la espera total del
     * viaje (E2 + E3 + E5 + E6, fuera y dentro de planta). Solo entran los
     * viajes con la cadena completa y sin hitos invertidos. El piso evita que
     * una ruta sin espera quede con tope 0 y descarte viajes normales.
     *
     * @return array{0: string, 1: array}
     */
    private static function esperas_sql(string $base, array $base_params): array
    {
        $sql = "
        SELECT ruta_id,
               GREATEST(?, MAX(CASE WHEN rn = CEIL(? * cnt) THEN espera END)) AS tope_espera,
               GREATEST(?, MAX(CASE WHEN rn = CEIL(? * cnt) THEN espera END)) AS tope_rapido
        FROM (
            SELECT v.ruta_id AS ruta_id,
                   v.e2 + v.e3 + v.e5 + v.e6 AS espera,
                   ROW_NUMBER() OVER (PARTITION BY v.ruta_id ORDER BY v.e2 + v.e3 + v.e5 + v.e6) AS rn,
                   COUNT(*) OVER (PARTITION BY v.ruta_id) AS cnt
            FROM ($base) v
            WHERE v.e2 IS NOT NULL
              AND v.e3 IS NOT NULL
              AND v.e5 IS NOT NULL
              AND v.e6 IS NOT NULL
              AND LEAST(v.e2, v.e3, v.e5, v.e6) >= 0
              AND GREATEST(v.e2, v.e3, v.e5, v.e6) <= " . self::ESPERA_VALIDA_MAXIMO . "
        ) w
        GROUP BY ruta_id";

        return [$sql, array_merge(
            [self::ESPERA_PISO, self::PERCENTIL_ESPERA, self::ESPERA_PISO, self::PERCENTIL_RAPIDO],
            $base_params
        )];
    }

    /**
     * Agregados por ruta del recorrido y de la espera.
     *
     * El recorrido (`prom_min`, `viajes`, `descartados` y los percentiles) se
     * mide solo con el tope de recorrido: la espera nunca lo descarta. La
     * espera tiene UN tope propio por ruta, el p82 de la espera total del
     * viaje, asi que pierde como maximo el 18 % de los viajes con la cadena
     * completa; con ese corte se guardan `prom_fuera_min` (E2 + E5) y
     * `prom_dentro_min` (E3 + E6).
     *
     * El ciclo se publica en tres escenarios: rapido (espera <= p50), promedio
     * (espera <= p82) y tardado (sin tope).
     *
     * Si `$espera` trae topes de pantalla o escritos se usan esos en lugar del
     * p82 automatico; la pantalla de rutas lo deja vacio.
     *
     * @param  array<string, int>  $topes
     * @param  array{fuera?: ?int, dentro?: ?int, fuera_rutas?: array<string, int>, dentro_rutas?: array<string, int>}  $espera
     * @return array{0: string, 1: array}
     */
    private static function agregados_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = [], array $espera = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);
        [$topes_sql, $topes_params] = self::topes_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);

        [$ok_fuera, $ok_dentro] = self::condiciones_espera('b', 't');

        // sin topes de espera de pantalla manda el p82 de la propia ruta
        $auto = $espera === [];
        [$esperas_sql, $esperas_params] = self::esperas_sql($base, $base_params);
        $espera_total = '(b.e2 + b.e3 + b.e5 + b.e6)';

        // la espera solo se mide con la cadena completa y sin hitos invertidos:
        // un hito negativo con otro enorme daria una suma enganosa
        $espera_valida = '(b.e2 IS NOT NULL AND b.e3 IS NOT NULL AND b.e5 IS NOT NULL'
            . ' AND b.e6 IS NOT NULL AND LEAST(b.e2, b.e3, b.e5, b.e6) >= 0'
            . ' AND GREATEST(b.e2, b.e3, b.e5, b.e6) <= ' . self::ESPERA_VALIDA_MAXIMO . ')';

        $ok_espera = $auto
            ? "($espera_valida AND $espera_total <= w.tope_espera)"
            : "(($ok_fuera) AND ($ok_dentro))";
        $ok_rapido = $auto
            ? "($espera_valida AND $espera_total <= w.tope_rapido)"
            : $ok_espera;

        // el tope de recorrido es el unico que manda sobre el promedio del tramo;
        // con tope NULL (pantalla en 0) no se descarta ningun viaje
        $ok = self::dentro_tope('b.e4', 't.tope');
        $ok_promedio = "($ok AND $ok_espera)";
        $ok_rapido_ciclo = "($ok AND $ok_rapido)";

        $promedios = self::promedios_etapa_sql('b', [
            'p' => $ok_promedio,
            'r' => $ok_rapido_ciclo,
            // el tardado es toda la espera real, pero solo de viajes creibles
            's' => $espera_valida,
        ]);

        $sql = "
        SELECT a.*,
               " . self::ciclo_sql('r') . " AS prom_ciclo_rapido_min,
               " . self::ciclo_sql('p') . " AS prom_ciclo_min,
               " . self::ciclo_sql('s') . " AS prom_ciclo_sin_tope_min
        FROM (
            SELECT b.ruta_id AS ruta_id,
                   COUNT(*) AS viajes_total,
                   SUM(CASE WHEN $ok THEN 1 ELSE 0 END) AS viajes,
                   SUM(CASE WHEN $ok THEN 0 ELSE 1 END) AS descartados,
                   SUM(CASE WHEN NOT ($ok_fuera) THEN 1 ELSE 0 END) AS descartados_fuera,
                   SUM(CASE WHEN ($ok_fuera) AND NOT ($ok_dentro) THEN 1 ELSE 0 END) AS descartados_dentro,
                   SUM(CASE WHEN $ok_promedio AND b.e4 > t.tope THEN 1 ELSE 0 END) AS descartados_ruta,
                   SUM(CASE WHEN $espera_valida AND $espera_total > w.tope_espera THEN 1 ELSE 0 END) AS espera_descartados,
                   AVG(CASE WHEN $ok THEN b.e4 END) AS prom_min,
                   AVG(b.e4) AS prom_sin_tope_min,
                   AVG(CASE WHEN $ok_espera THEN b.e2 + b.e5 END) AS prom_fuera_min,
                   AVG(CASE WHEN $ok_espera THEN b.e3 + b.e6 END) AS prom_dentro_min,
                   MIN(b.e4) AS min_min,
                   MAX(b.e4) AS max_min,
                   MAX(t.tope) AS tope_min,
                   MAX(t.tope_fuera) AS tope_fuera_min,
                   MAX(t.tope_dentro) AS tope_dentro_min,
                   MAX(w.tope_espera) AS tope_espera_min,
                   SUM(CASE WHEN $ok_promedio AND b.ciclo_total IS NOT NULL THEN 1 ELSE 0 END) AS ciclo_viajes,
                   $promedios
            FROM ($base) b
            JOIN ($topes_sql) t ON t.ruta_id = b.ruta_id
            LEFT JOIN ($esperas_sql) w ON w.ruta_id = b.ruta_id
            GROUP BY b.ruta_id
        ) a";

        return [$sql, array_merge($base_params, $topes_params, $esperas_params)];
    }

    /**
     * Percentiles por ruta de los viajes que caen dentro de los topes.
     */
    private static function percentiles_sql(int $tope, string $modo, ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = [], array $espera = []): array
    {
        [$base, $base_params] = self::base_sql($ruta_id, $dep_origen, $dep_destino);
        [$topes_sql, $topes_params] = self::topes_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);

        [$ok_fuera, $ok_dentro] = self::condiciones_espera('v', 't');
        $ok_recorrido = self::dentro_tope('v.e4', 't.tope');

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
            WHERE $ok_recorrido AND $ok_fuera AND $ok_dentro
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
     * Catalogo de distritos del ubigeo: `ubigeo` (6 digitos) => distrito. Es el
     * que le pone nombre a las plantas que no estan en `locales` y quedaron
     * guardadas con su ubigeo como nombre.
     *
     * @return array<string, string>
     */
    public static function distritos(): array
    {
        if (self::$distritos !== null) {
            return self::$distritos;
        }

        try {
            self::$distritos = DB::table('ubigeo_distritos')
                ->pluck('distrito', 'ubigeo')
                ->all();
        } catch (\Throwable $e) {
            self::$distritos = [];
        }

        return self::$distritos;
    }

    /**
     * Nombre del distrito de un codigo de seis digitos. Devuelve null cuando el
     * codigo no es un ubigeo o no esta en el catalogo.
     */
    public static function nombre_distrito($codigo): ?string
    {
        $codigo = trim((string) $codigo);

        if (preg_match('/^\d{6}$/', $codigo) !== 1) {
            return null;
        }

        $nombre = trim((string) (self::distritos()[$codigo] ?? ''));

        return $nombre !== '' ? $nombre : null;
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
     * Con $ocultar_compiladas se excluyen las rutas que ya tienen promedio
     * guardado: el resumen queda midiendo solo lo que falta compilar, igual que
     * la tabla de la pantalla. Con $min_viajes se excluyen las rutas con menos
     * viajes medibles que ese minimo (0 = sin minimo), para que los promedios
     * no se apoyen en muestras diminutas.
     *
     * @param  array<string, int>  $topes  topes escritos en la tabla (ruta => minutos)
     * @param  array{fuera?: ?int, dentro?: ?int, fuera_rutas?: array<string, int>, dentro_rutas?: array<string, int>}  $espera
     */
    public static function perdida(int $tope, string $modo = 'global', ?string $ruta_id = null, ?string $dep_origen = null, ?string $dep_destino = null, array $topes = [], bool $ocultar_compiladas = false, int $min_viajes = 0, array $espera = []): object
    {
        [$sql, $params] = self::agregados_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);
        $filas = DB::select($sql, $params);

        if ($ocultar_compiladas || $min_viajes > 0) {
            $guardados = $ocultar_compiladas ? self::guardados_por_ruta() : [];
            $filas = array_values(array_filter(
                $filas,
                fn ($fila) => !isset($guardados[(string) $fila->ruta_id])
                    && (int) $fila->viajes_total >= $min_viajes
            ));
        }

        $resumen = (object) [
            'rutas' => 0,
            'rutas_afectadas' => 0,
            'viajes_total' => 0,
            'viajes' => 0,
            'descartados' => 0,
            'descartados_fuera' => 0,
            'descartados_dentro' => 0,
            'pct_perdido' => null,
            'pct_afectadas' => null,
            'prom_min' => null,
            'prom_sin_tope_min' => null,
            'prom_ciclo_min' => null,
            'prom_ciclo_rapido_min' => null,
            'prom_ciclo_sin_tope_min' => null,
            'con_ciclo' => 0,
            'con_ciclo_rapido' => 0,
            'prom_fuera_min' => null,
            'prom_dentro_min' => null,
            'espera_descartados' => 0,
            'min_min' => null,
            'max_min' => null,
            'sin_promedio' => 0,
        ];

        $suma_prom = 0.0;
        $suma_prom_bruto = 0.0;
        $suma_ciclo = 0.0;
        $suma_ciclo_rapido = 0.0;
        $suma_ciclo_bruto = 0.0;
        $suma_fuera = 0.0;
        $suma_dentro = 0.0;
        $con_fuera = 0;
        $con_dentro = 0;
        $con_prom = 0;
        $con_ciclo_bruto = 0;

        foreach ($filas as $fila) {
            $resumen->rutas++;
            $resumen->viajes_total += (int) $fila->viajes_total;
            $resumen->viajes += (int) $fila->viajes;
            $resumen->descartados += (int) $fila->descartados;
            $resumen->descartados_fuera += (int) ($fila->descartados_fuera ?? 0);
            $resumen->descartados_dentro += (int) ($fila->descartados_dentro ?? 0);
            $resumen->espera_descartados += (int) ($fila->espera_descartados ?? 0);

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
            if ($fila->prom_ciclo_min !== null) {
                $suma_ciclo += (float) $fila->prom_ciclo_min;
                $resumen->con_ciclo++;
            }
            if (($fila->prom_ciclo_rapido_min ?? null) !== null) {
                $suma_ciclo_rapido += (float) $fila->prom_ciclo_rapido_min;
                $resumen->con_ciclo_rapido++;
            }
            if (($fila->prom_fuera_min ?? null) !== null) {
                $suma_fuera += (float) $fila->prom_fuera_min;
                $con_fuera++;
            }
            if (($fila->prom_dentro_min ?? null) !== null) {
                $suma_dentro += (float) $fila->prom_dentro_min;
                $con_dentro++;
            }
            if ($fila->prom_ciclo_sin_tope_min !== null) {
                $suma_ciclo_bruto += (float) $fila->prom_ciclo_sin_tope_min;
                $con_ciclo_bruto++;
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
        $resumen->prom_ciclo_min = $resumen->con_ciclo > 0 ? round($suma_ciclo / $resumen->con_ciclo, 1) : null;
        $resumen->prom_ciclo_rapido_min = $resumen->con_ciclo_rapido > 0
            ? round($suma_ciclo_rapido / $resumen->con_ciclo_rapido, 1)
            : null;
        $resumen->prom_fuera_min = $con_fuera > 0 ? round($suma_fuera / $con_fuera, 1) : null;
        $resumen->prom_dentro_min = $con_dentro > 0 ? round($suma_dentro / $con_dentro, 1) : null;
        $resumen->prom_ciclo_sin_tope_min = $con_ciclo_bruto > 0 ? round($suma_ciclo_bruto / $con_ciclo_bruto, 1) : null;
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
     * tope elegido, mas el p82 que se le propondria como tope propio.
     *
     * `$orden` es una de las claves de `ORDENES`; por defecto las rutas con mas
     * viajes medibles, que son las que mandan en el promedio global.
     *
     * Con $ocultar_compiladas se omiten las rutas que ya tienen promedio
     * guardado y con $min_viajes las que no llegan a ese minimo de viajes
     * medibles; `ocultas` y `ocultas_min` dicen cuantas se dejaron fuera por
     * cada motivo, para poder avisarlo en pantalla (sin eso, la tabla pareceria
     * haber perdido rutas).
     *
     * $espera lleva los topes de espera fuera y dentro de planta (globales y
     * los escritos en cada fila); la pantalla de rutas lo deja vacio y los
     * topes de espera viven en la pantalla de plantas.
     *
     * @param  array{fuera?: ?int, dentro?: ?int, fuera_rutas?: array<string, int>, dentro_rutas?: array<string, int>}  $espera
     */
    public static function por_ruta(
        int $tope,
        string $modo = 'global',
        ?string $ruta_id = null,
        ?string $dep_origen = null,
        ?string $dep_destino = null,
        string $orden = 'viajes',
        array $topes = [],
        bool $ocultar_compiladas = false,
        int $min_viajes = 0,
        array $espera = []
    ): object {
        [$sql, $params] = self::agregados_sql($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);
        $filas = DB::select($sql, $params);

        $p82 = self::topes_p82($ruta_id);
        $guardados = self::guardados_por_ruta();
        $rutas = self::rutas();

        $salida = [];
        $ocultas = 0;
        $ocultas_min = 0;
        foreach ($filas as $fila) {
            $id = (string) $fila->ruta_id;
            $total = (int) $fila->viajes_total;
            $descartados = (int) $fila->descartados;

            // compiladas: se dejan fuera de la tabla cuando el filtro esta activo
            if ($ocultar_compiladas && isset($guardados[$id])) {
                $ocultas++;
                continue;
            }

            // poca muestra: la ruta no entra cuando hay un minimo de viajes
            if ($min_viajes > 0 && $total < $min_viajes) {
                $ocultas_min++;
                continue;
            }

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
                'descartados_fuera' => (int) ($fila->descartados_fuera ?? 0),
                'descartados_dentro' => (int) ($fila->descartados_dentro ?? 0),
                'pct_perdido' => $total > 0 ? round(100 * $descartados / $total, 1) : null,
                'prom_min' => $fila->prom_min !== null ? round((float) $fila->prom_min, 1) : null,
                'prom_sin_tope_min' => $fila->prom_sin_tope_min !== null ? round((float) $fila->prom_sin_tope_min, 1) : null,
                'prom_ciclo_min' => $fila->prom_ciclo_min !== null ? round((float) $fila->prom_ciclo_min, 1) : null,
                'prom_ciclo_rapido_min' => ($fila->prom_ciclo_rapido_min ?? null) !== null
                    ? round((float) $fila->prom_ciclo_rapido_min, 1)
                    : null,
                'prom_ciclo_sin_tope_min' => $fila->prom_ciclo_sin_tope_min !== null ? round((float) $fila->prom_ciclo_sin_tope_min, 1) : null,
                'prom_fuera_min' => ($fila->prom_fuera_min ?? null) !== null ? round((float) $fila->prom_fuera_min, 1) : null,
                'prom_dentro_min' => ($fila->prom_dentro_min ?? null) !== null ? round((float) $fila->prom_dentro_min, 1) : null,
                'tope_espera_min' => ($fila->tope_espera_min ?? null) !== null ? (int) $fila->tope_espera_min : null,
                'espera_descartados' => (int) ($fila->espera_descartados ?? 0),
                'ciclo_viajes' => (int) ($fila->ciclo_viajes ?? 0),
                'min_min' => $fila->min_min !== null ? (int) $fila->min_min : null,
                'max_min' => $fila->max_min !== null ? (int) $fila->max_min : null,
                // null = sin tope (la pantalla abrio en 0), y la fila de la
                // tabla se deja vacia en vez de escribir un tope de 0 minutos
                'tope_min' => $fila->tope_min !== null ? (int) $fila->tope_min : null,
                'tope_fuera_min' => $fila->tope_fuera_min !== null ? (int) $fila->tope_fuera_min : null,
                'tope_dentro_min' => $fila->tope_dentro_min !== null ? (int) $fila->tope_dentro_min : null,
                'p82_min' => $p82[$id] ?? null,
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

                case 'ciclo':
                    return (($b->prom_ciclo_min ?? -1) <=> ($a->prom_ciclo_min ?? -1))
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'ruta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: strcasecmp($a->ruta_id, $b->ruta_id);

                default:
                    return ($b->viajes_total <=> $a->viajes_total)
                        ?: ($b->pct_perdido ?? -1) <=> ($a->pct_perdido ?? -1);
            }
        });

        return (object) ['filas' => $salida, 'ocultas' => $ocultas, 'ocultas_min' => $ocultas_min];
    }

    /**
     * p82 de cada ruta (con piso), que es el tope propio sugerido: por encima
     * de el queda, como maximo, 3 % de los viajes.
     *
     * @return array<string, int>
     */
    public static function topes_p82(?string $ruta_id = null): array
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
            'con_ciclo' => 0,
            'descartados_fuera' => 0,
            'descartados_dentro' => 0,
        ];

        if (!$estado->tablas) {
            return $estado;
        }

        // las columnas del ciclo y los topes de espera llegan con su migracion
        $extra = self::columnas_ciclo()
            ? ", SUM(CASE WHEN prom_ciclo_min IS NOT NULL THEN 1 ELSE 0 END) AS con_ciclo,
                 COALESCE(SUM(descartados_fuera), 0) AS fuera,
                 COALESCE(SUM(descartados_dentro), 0) AS dentro"
            : '';

        $fila = DB::selectOne("
            SELECT COUNT(*) AS rutas,
                   COALESCE(SUM(viajes), 0) AS viajes,
                   COALESCE(SUM(descartados), 0) AS descartados,
                   COUNT(DISTINCT tope_min) AS topes,
                   SUM(CASE WHEN prom_fusion_min IS NOT NULL THEN 1 ELSE 0 END) AS fusionadas,
                   MAX(actualizado_en) AS ultima
                   $extra
            FROM tiempos_ruta_promedio");

        $estado->rutas = (int) ($fila->rutas ?? 0);
        $estado->viajes = (int) ($fila->viajes ?? 0);
        $estado->descartados = (int) ($fila->descartados ?? 0);
        $estado->topes = (int) ($fila->topes ?? 0);
        $estado->fusionadas = (int) ($fila->fusionadas ?? 0);
        $estado->con_ciclo = (int) ($fila->con_ciclo ?? 0);
        $estado->descartados_fuera = (int) ($fila->fuera ?? 0);
        $estado->descartados_dentro = (int) ($fila->dentro ?? 0);
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

    /**
     * La migracion del ciclo y los topes de espera puede no estar aplicada en
     * el servidor: sin esas columnas la pantalla compila igual, solo que sin
     * guardar el ciclo ni los topes de espera.
     */
    public static function columnas_ciclo(): bool
    {
        if (self::$columnas_ciclo !== null) {
            return self::$columnas_ciclo;
        }

        if (!self::tablas_listas()) {
            return self::$columnas_ciclo = false;
        }

        try {
            return self::$columnas_ciclo = DB::select("SHOW COLUMNS FROM tiempos_ruta_promedio LIKE 'prom_ciclo_min'") !== [];
        } catch (\Throwable $e) {
            return self::$columnas_ciclo = false;
        }
    }

    /**
     * La migracion de esperas agrega `prom_ciclo_rapido_min` junto con
     * `tope_espera_min`, `espera_descartados` y los promedios de espera; sin
     * ella la compilacion sigue guardando el ciclo pero no las esperas.
     */
    public static function columnas_espera(): bool
    {
        if (self::$columnas_espera !== null) {
            return self::$columnas_espera;
        }

        if (!self::tablas_listas()) {
            return self::$columnas_espera = false;
        }

        try {
            return self::$columnas_espera = DB::select("SHOW COLUMNS FROM tiempos_ruta_promedio LIKE 'prom_ciclo_rapido_min'") !== [];
        } catch (\Throwable $e) {
            return self::$columnas_espera = false;
        }
    }

    //------------------- compilacion -------------------

    /**
     * Tope con el que se guarda una compilacion. El 0 es valido y significa
     * "sin tope global": ya no se sube a `TOPE_MINIMO`, porque el input de la
     * pantalla abre en 0 para que mande el modo elegido.
     */
    private static function tope_guardado(int $tope): int
    {
        return max(0, min(100000, $tope));
    }

    /**
     * Modo realmente aplicado al guardar. Con tope 0 el modo global no tiene
     * ningun corte que aplicar, y dejarlo pasar guardaria el promedio bruto de
     * todos los viajes (incluidos los de dias enteros), asi que se cae al p82
     * recomendado de cada ruta.
     */
    private static function modo_guardado(int $tope, string $modo): string
    {
        return ($tope <= 0 && $modo === 'global') ? 'ruta' : $modo;
    }

    /**
     * Compila y guarda los promedios del tope elegido.
     *
     * `$solo_faltantes` deja intactas las rutas que ya tienen promedio: sirve
     * para compilar por partes, agregando tramos nuevos sin reescribir lo que
     * ya estaba. Las rutas sin viajes dentro del tope no se crean.
     *
     * `$solo_rutas` compila solo los ids marcados en la tabla (los checks de
     * la pantalla); vacio significa todas las que entren en el filtro.
     *
     * `$topes` son los topes escritos en la tabla (ruta => minutos). En modo
     * propio mandan sobre el tope guardado y sobre el global, asi que la
     * pantalla puede compilar cada ruta con el tope de su propia fila.
     *
     * `$min_viajes` omite las rutas que no llegan a ese minimo de viajes
     * medibles (0 = compilar todas las que tengan algun viaje dentro del tope).
     *
     * `$espera` lleva los topes de espera fuera y dentro de planta: se aplican
     * al promedio de recorrido, a los percentiles y al ciclo. La matriz dia/hora
     * sigue midiendo solo el tope de recorrido.
     *
     * @param  array{fuera?: ?int, dentro?: ?int, fuera_rutas?: array<string, int>, dentro_rutas?: array<string, int>}  $espera
     */
    public static function compilar(
        int $tope,
        string $modo = 'global',
        ?string $ruta_id = null,
        bool $solo_faltantes = false,
        array $solo_rutas = [],
        ?string $dep_origen = null,
        ?string $dep_destino = null,
        array $topes = [],
        int $min_viajes = 0,
        array $espera = []
    ): object {
        $tope = self::tope_guardado($tope);
        $modo = self::modo_guardado($tope, in_array($modo, ['ruta', 'propio'], true) ? $modo : 'global');

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

        [$sql, $params] = self::agregados_sql($tope, $modo_calculo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);
        $filas = DB::select($sql, $params);

        [$sql_pct, $params_pct] = self::percentiles_sql($tope, $modo_calculo, $ruta_id, $dep_origen, $dep_destino, $topes, $espera);
        $percentiles = [];
        foreach (DB::select($sql_pct, $params_pct) as $fila) {
            $percentiles[(string) $fila->ruta_id] = $fila;
        }

        $rutas = self::rutas();
        $compiladas = [];
        $lotes = [];
        $ahora = now();

        // el ciclo y los topes de espera solo se guardan con la migracion nueva
        $con_ciclo = self::columnas_ciclo();
        $con_espera = self::columnas_espera();
        $columnas_extra = $con_ciclo
            ? ['tope_fuera_min', 'tope_dentro_min', 'descartados_fuera', 'descartados_dentro',
                'prom_ciclo_min', 'prom_ciclo_sin_tope_min', 'ciclo_viajes']
            : [];
        if ($con_espera) {
            $columnas_extra = array_merge($columnas_extra, [
                'tope_espera_min', 'espera_descartados', 'prom_fuera_min', 'prom_dentro_min',
                'prom_ciclo_rapido_min',
            ]);
        }

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

            // poca muestra: con un minimo de viajes la ruta no se compila
            if ($min_viajes > 0 && (int) $fila->viajes_total < $min_viajes) {
                $resultado->omitidas++;
                continue;
            }
            if ($solo_faltantes && isset($guardados[$id])) {
                $resultado->omitidas++;
                continue;
            }

            $pct = $percentiles[$id] ?? null;

            $registro = [
                'ruta_id' => $id,
                'origen_id' => $rutas[$id]->origen_id ?? null,
                'destino_id' => $rutas[$id]->destino_id ?? null,
                'modo' => $modo,
                // la columna no es nullable: sin tope se guarda 0, que al leer
                // se vuelve NULL con NULLIF(p.tope_min, 0)
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

            if ($con_ciclo) {
                $registro['tope_fuera_min'] = $fila->tope_fuera_min !== null ? (int) $fila->tope_fuera_min : null;
                $registro['tope_dentro_min'] = $fila->tope_dentro_min !== null ? (int) $fila->tope_dentro_min : null;
                $registro['descartados_fuera'] = (int) ($fila->descartados_fuera ?? 0);
                $registro['descartados_dentro'] = (int) ($fila->descartados_dentro ?? 0);
                $registro['prom_ciclo_min'] = $fila->prom_ciclo_min !== null ? round((float) $fila->prom_ciclo_min, 1) : null;
                $registro['prom_ciclo_sin_tope_min'] = $fila->prom_ciclo_sin_tope_min !== null ? round((float) $fila->prom_ciclo_sin_tope_min, 1) : null;
                $registro['ciclo_viajes'] = (int) ($fila->ciclo_viajes ?? 0);
            }

            if ($con_espera) {
                $registro['tope_espera_min'] = $fila->tope_espera_min !== null ? (int) $fila->tope_espera_min : null;
                $registro['espera_descartados'] = (int) ($fila->espera_descartados ?? 0);
                $registro['prom_fuera_min'] = $fila->prom_fuera_min !== null ? round((float) $fila->prom_fuera_min, 1) : null;
                $registro['prom_dentro_min'] = $fila->prom_dentro_min !== null ? round((float) $fila->prom_dentro_min, 1) : null;
                $registro['prom_ciclo_rapido_min'] = $fila->prom_ciclo_rapido_min !== null
                    ? round((float) $fila->prom_ciclo_rapido_min, 1)
                    : null;
            }

            $lotes[] = $registro;

            $compiladas[] = $id;
            $resultado->rutas++;
            $resultado->viajes += (int) $fila->viajes;
            $resultado->descartados += (int) $fila->descartados;
        }

        $columnas_actualizar = array_merge([
            'origen_id', 'destino_id', 'modo', 'tope_min', 'viajes_total', 'viajes',
            'descartados', 'prom_min', 'prom_sin_tope_min', 'mediana_min', 'p25_min',
            'p75_min', 'desv_min', 'actualizado_en',
        ], $columnas_extra);

        foreach (array_chunk($lotes, 200) as $lote) {
            DB::table('tiempos_ruta_promedio')->upsert($lote, ['ruta_id'], $columnas_actualizar);
        }

        if ($compiladas !== []) {
            // la matriz dia/hora mide solo el tope de recorrido: los topes de
            // espera son del promedio por ruta
            $resultado->celdas = self::compilar_horario($compiladas, $tope, $modo_calculo, $topes);
            $resultado->fusionadas = self::recalcular_fusion();
        }

        return $resultado;
    }

    /**
     * Matriz ruta x dia x hora de las rutas compiladas. Se borran solo las
     * celdas de esas rutas: el resto de la tabla no se toca.
     */
    private static function compilar_horario(array $rutas, int $tope, string $modo, array $topes = []): int
    {
        if ($rutas === []) {
            return 0;
        }

        [$base, $base_params] = self::base_sql();
        // los topes escritos en la tabla tambien manda aqui: las celdas dia/hora
        // se guardan con el mismo tope con el que se guardo el promedio
        [$topes_sql, $topes_params] = self::topes_sql($tope, $modo, null, null, null, $topes);

        // la lista de rutas entra como parametros, nunca interpolada
        $placeholders = implode(',', array_fill(0, count($rutas), '?'));

        // tope NULL (pantalla en 0) = todas las celdas cuentan, sin corte
        $ok_recorrido = self::dentro_tope('v.e4', 't.tope');

        $sql = "
        SELECT v.ruta_id AS ruta_id,
               v.dia AS dia,
               v.hora AS hora,
               MAX(t.tope) AS tope_min,
               COUNT(*) AS viajes,
               AVG(v.e4) AS prom_min
        FROM ($base) v
        JOIN ($topes_sql) t ON t.ruta_id = v.ruta_id
        WHERE $ok_recorrido
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
                // la columna no es nullable: 0 = celda calculada sin tope
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
        $ok_recorrido = self::dentro_tope('v.e4', 't.tope');

        $filas = DB::select("
        SELECT DISTINCT v.ruta_id AS ruta_id
        FROM ($base) v
        JOIN ($topes) t ON t.ruta_id = v.ruta_id
        WHERE $ok_recorrido", array_merge($base_params, $topes_params));

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
        $tope = self::tope_guardado($tope);
        $modo = self::modo_guardado($tope, in_array($modo, ['ruta', 'propio'], true) ? $modo : 'global');

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
        $ok_recorrido = self::dentro_tope('v.e4', 't.tope');

        $sql = "
        SELECT v.ruta_id AS ruta_id,
               v.dia AS dia,
               v.hora AS hora,
               COUNT(*) AS total,
               SUM(CASE WHEN $ok_recorrido THEN 1 ELSE 0 END) AS dentro,
               AVG(CASE WHEN $ok_recorrido THEN v.e4 END) AS prom_min,
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
                'tope_min' => $celda->tope_min !== null ? (int) $celda->tope_min : null,
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
