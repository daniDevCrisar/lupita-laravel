<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tiempo de recorrido origen -> destino (inicio_ruta -> qr_llegada_destino)
 * visto por horario: hora del dia, turno y dia de la semana.
 *
 * La hora que manda es la de PARTIDA (`inicio_ruta`).
 * El valor principal es la MEDIANA porque el promedio se distorsiona con los
 * viajes de varios dias (25% de los registros supera las 24 horas).
 *
 * Todos los tiempos se devuelven en MINUTOS.
 */
class DBTiemposHorario
{
    public static $filtro;

    // Turnos de la operacion segun HOUR(inicio_ruta).
    // Cada franja es [desde, hasta] en horas; si `desde` > `hasta` la franja cruza la medianoche.
    // Se puede ajustar libremente: los graficos, tablas y mapas de calor se recalculan solos.
    public static $turnos = [
        'manana' => ['nombre' => 'Mañana', 'rango' => '06:00 - 13:59', 'desde' => 6, 'hasta' => 13],
        'tarde' => ['nombre' => 'Tarde', 'rango' => '14:00 - 21:59', 'desde' => 14, 'hasta' => 21],
        'noche' => ['nombre' => 'Noche', 'rango' => '22:00 - 05:59', 'desde' => 22, 'hasta' => 5],
    ];

    // WEEKDAY() de mysql: 0 = lunes
    public static $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    // Tope de duracion en minutos (0 = sin tope)
    public static $topes = [
        0 => 'Sin tope',
        1440 => 'Máximo 24 h',
        2880 => 'Máximo 48 h',
    ];

    public static function set_filtro($request): void
    {
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio;
        self::$filtro->fecha_fin = $request->fecha_fin;
        self::$filtro->rutas = self::normalizar_rutas($request->ruta_id);
        self::$filtro->conductor = $request->conductor;
        self::$filtro->granularidad = $request->granularidad;
        self::$filtro->tope = $request->tope;
        self::$filtro->min_viajes = $request->min_viajes;
        self::$filtro->dia_semana = $request->dia_semana;
    }

    /**
     * Acepta tanto una ruta suelta (?ruta_id=X) como varias (?ruta_id[]=X&ruta_id[]=Y).
     * Devuelve siempre un listado limpio y sin duplicados.
     */
    private static function normalizar_rutas($valor): array
    {
        if ($valor === null || $valor === '') {
            return [];
        }

        $rutas = is_array($valor) ? $valor : [$valor];
        $rutas = array_map(fn ($ruta) => trim((string) $ruta), $rutas);
        $rutas = array_filter($rutas, fn ($ruta) => $ruta !== '');

        return array_values(array_unique($rutas));
    }

    //------------------- filtros -------------------

    public static function granularidad(): string
    {
        return (self::$filtro->granularidad ?? '') === 'turno' ? 'turno' : 'hora';
    }

    public static function tope(): int
    {
        $tope = (int) (self::$filtro->tope ?? 0);

        return isset(self::$topes[$tope]) ? $tope : 0;
    }

    public static function min_viajes(): int
    {
        return max(1, (int) (self::$filtro->min_viajes ?? 5));
    }

    // Rutas seleccionadas (vacio = todas)
    public static function rutas(): array
    {
        return self::$filtro->rutas ?? [];
    }

    public static function filtrando_rutas(): bool
    {
        return self::rutas() !== [];
    }

    // Conductor seleccionado (0 = todos)
    public static function conductor(): int
    {
        return max(0, (int) (self::$filtro->conductor ?? 0));
    }

    public static function filtrando_conductor(): bool
    {
        return self::conductor() > 0;
    }

    public static function nombre_conductor(int $id): string
    {
        return (string) (DB::table('conductores')->where('id', $id)->value('nombres') ?? '');
    }

    /**
     * Cambia el filtro de rutas en caliente (lo usa el filtro de conductor,
     * que marca de una vez todas las rutas por las que paso ese conductor).
     */
    public static function set_rutas(array $rutas): void
    {
        self::$filtro->rutas = self::normalizar_rutas($rutas);
    }

    private static function case_turno(): string
    {
        $partes = [];

        foreach (self::$turnos as $clave => $turno) {
            $desde = (int) $turno['desde'];
            $hasta = (int) $turno['hasta'];

            // una franja que cruza la medianoche se parte en dos condiciones
            $condicion = $desde > $hasta
                ? "HOUR(r.inicio_ruta) >= {$desde} OR HOUR(r.inicio_ruta) <= {$hasta}"
                : "HOUR(r.inicio_ruta) BETWEEN {$desde} AND {$hasta}";

            $partes[] = "WHEN ({$condicion}) THEN '{$clave}'";
        }

        return 'CASE ' . implode(' ', $partes) . ' END';
    }

    /**
     * Filas base: una por referencia con la duracion origen -> destino.
     *
     * El filtro de fechas se aplica sobre `inicio_ruta` (no sobre las llamadas)
     * porque el analisis es horario y lo que importa es cuando salio el camion.
     *
     * El conductor se toma de la referencia y, si viene vacio, de sus llamadas
     * (misma regla que el modulo de tiempos por ruta).
     */
    private static function base_sql(bool $aplicar_tope = true, bool $aplicar_rutas = true, bool $aplicar_conductor = true): array
    {
        $sql = '';
        $params = [];

        if (self::$filtro->fecha_inicio ?? '') {
            $sql .= ' AND r.inicio_ruta >= ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_inicio)->startOfDay();
        }

        if (self::$filtro->fecha_fin ?? '') {
            $sql .= ' AND r.inicio_ruta < ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_fin)->addDay()->startOfDay();
        }

        $rutas = $aplicar_rutas ? self::rutas() : [];
        if ($rutas) {
            $sql .= ' AND r.ruta_id IN (' . implode(',', array_fill(0, count($rutas), '?')) . ') ';
            $params = array_merge($params, $rutas);
        }

        $conductor = ($aplicar_conductor && self::filtrando_conductor()) ? self::conductor() : 0;
        if ($conductor > 0) {
            $sql .= ' AND COALESCE(r.conductor_id, lc.conductor_id) = ? ';
            $params[] = $conductor;
        }

        $dia = self::$filtro->dia_semana ?? '';
        if ($dia !== '' && $dia !== null) {
            $sql .= ' AND WEEKDAY(r.inicio_ruta) = ? ';
            $params[] = (int) $dia;
        }

        $tope = self::tope();
        if ($aplicar_tope && $tope > 0) {
            $sql .= ' AND TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) <= ? ';
            $params[] = $tope;
        }

        $sql = "
        SELECT
            r.ruta_id AS ruta_id,
            COALESCE(r.conductor_id, lc.conductor_id) AS conductor_id,
            HOUR(r.inicio_ruta) AS hora,
            WEEKDAY(r.inicio_ruta) AS dia_semana,
            " . self::case_turno() . " AS turno,
            TIMESTAMPDIFF(MINUTE, r.inicio_ruta, r.qr_llegada_destino) AS minutos
        FROM referencias r
        LEFT JOIN (
            SELECT lf.ref AS ref, MAX(lf.conductor_id) AS conductor_id
            FROM llamadas lf
            WHERE lf.ref IS NOT NULL
            GROUP BY lf.ref
        ) lc ON lc.ref = r.ref
        WHERE r.ruta_id IS NOT NULL
          AND r.inicio_ruta IS NOT NULL
          AND r.qr_llegada_destino IS NOT NULL
          AND r.qr_llegada_destino >= r.inicio_ruta " . $sql;

        return [$sql, $params];
    }

    //------------------- consultas -------------------

    /**
     * Totales del filtro actual (mediana real, no promedio de medianas).
     */
    public static function resumen(): stdClass
    {
        [$base, $params] = self::base_sql();

        $sql = "
        SELECT
            COUNT(*) AS viajes,
            COUNT(DISTINCT w.ruta_id) AS rutas,
            ROUND(AVG(w.minutos), 0) AS promedio,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR((w.tot + 1) / 2), CEIL((w.tot + 1) / 2))
                           THEN w.minutos END), 0) AS mediana
        FROM (
            SELECT b.ruta_id, b.minutos,
                   ROW_NUMBER() OVER (ORDER BY b.minutos) AS rn,
                   COUNT(*) OVER () AS tot
            FROM ($base) b
        ) w";

        $result = DB::select($sql, $params);

        return $result[0] ?? (object) [
            'viajes' => 0,
            'rutas' => 0,
            'promedio' => null,
            'mediana' => null,
        ];
    }

    /**
     * Viajes que quedarian fuera del analisis por el tope de duracion.
     */
    public static function descartados(): int
    {
        $tope = self::tope();
        if ($tope <= 0) {
            return 0;
        }

        [$base, $params] = self::base_sql(false);

        $result = DB::select(
            "SELECT COUNT(*) AS total FROM ($base) b WHERE b.minutos > ?",
            array_merge($params, [$tope])
        );

        return (int) ($result[0]->total ?? 0);
    }

    /**
     * Estadisticas por hora del dia o por turno, segun la granularidad.
     */
    public static function por_horario(?string $agrupacion = null): array
    {
        [$base, $params] = self::base_sql();

        // Se puede forzar la agrupacion por hora aunque la vista este en turnos
        $esHora = ($agrupacion ?? self::granularidad()) !== 'turno';
        $eje = $esHora ? 'w.hora' : 'w.turno';
        $particion = $esHora ? 'b.hora' : 'b.turno';
        $orden = $esHora
            ? 'ORDER BY w.hora'
            : 'ORDER BY FIELD(w.turno, ' . implode(', ', array_map(fn ($clave) => "'{$clave}'", array_keys(self::$turnos))) . ')';

        $sql = "
        SELECT
            $eje AS clave,
            COUNT(*) AS viajes,
            ROUND(AVG(w.minutos), 0) AS promedio,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR((w.tot + 1) / 4), CEIL((w.tot + 1) / 4))
                           THEN w.minutos END), 0) AS p25,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR((w.tot + 1) / 2), CEIL((w.tot + 1) / 2))
                           THEN w.minutos END), 0) AS mediana,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR(3 * (w.tot + 1) / 4), CEIL(3 * (w.tot + 1) / 4))
                           THEN w.minutos END), 0) AS p75,
            MIN(w.minutos) AS minimo,
            MAX(w.minutos) AS maximo
        FROM (
            SELECT b.hora, b.turno, b.minutos,
                   ROW_NUMBER() OVER (PARTITION BY $particion ORDER BY b.minutos) AS rn,
                   COUNT(*) OVER (PARTITION BY $particion) AS tot
            FROM ($base) b
        ) w
        GROUP BY $eje
        $orden";

        return DB::select($sql, $params);
    }

    /**
     * Matriz dia de la semana x (hora | turno).
     */
    public static function matriz_dia(): array
    {
        [$base, $params] = self::base_sql();

        $esHora = self::granularidad() === 'hora';
        $eje = $esHora ? 'w.hora' : 'w.turno';
        $particion = $esHora ? 'b.dia_semana, b.hora' : 'b.dia_semana, b.turno';

        $sql = "
        SELECT
            w.dia_semana,
            $eje AS eje,
            COUNT(*) AS viajes,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR((w.tot + 1) / 2), CEIL((w.tot + 1) / 2))
                           THEN w.minutos END), 0) AS mediana
        FROM (
            SELECT b.dia_semana, b.hora, b.turno, b.minutos,
                   ROW_NUMBER() OVER (PARTITION BY $particion ORDER BY b.minutos) AS rn,
                   COUNT(*) OVER (PARTITION BY $particion) AS tot
            FROM ($base) b
        ) w
        GROUP BY w.dia_semana, $eje
        ORDER BY w.dia_semana";

        return DB::select($sql, $params);
    }

    /**
     * Rutas con mas viajes para el mapa de calor ruta x hora.
     */
    public static function rutas_top(int $limite = 12, bool $aplicar_minimo = true): array
    {
        [$base, $params] = self::base_sql();

        $limite = max(1, min(50, $limite));

        $sql = "
        SELECT b.ruta_id, COUNT(*) AS viajes
        FROM ($base) b
        GROUP BY b.ruta_id " . ($aplicar_minimo ? 'HAVING viajes >= ?' : '') . "
        ORDER BY viajes DESC
        LIMIT $limite";

        if ($aplicar_minimo) {
            $params[] = self::min_viajes();
        }

        return DB::select($sql, $params);
    }

    /**
     * Rutas por las que paso el conductor seleccionado, con los mismos filtros
     * de fecha/tope/dia pero sin el filtro de rutas (es justo lo que se quiere averiguar).
     */
    public static function rutas_conductor(): array
    {
        if (!self::filtrando_conductor()) {
            return [];
        }

        [$base, $params] = self::base_sql(true, false);

        $filas = DB::select("SELECT DISTINCT b.ruta_id FROM ($base) b ORDER BY b.ruta_id", $params);

        return array_map(fn ($fila) => (string) $fila->ruta_id, $filas);
    }

    /**
     * Conductores con viajes para el selector. Se ordenan por viajes porque
     * son muchos y asi los que mas operan quedan arriba.
     */
    public static function conductores_lista(): array
    {
        [$base, $params] = self::base_sql(true, false, false);

        $sql = "
        SELECT
            b.conductor_id,
            COALESCE(MAX(co.nombres), 'SIN CONDUCTOR') AS nombre,
            COUNT(*) AS viajes
        FROM ($base) b
        LEFT JOIN conductores co ON co.id = b.conductor_id
        GROUP BY b.conductor_id
        ORDER BY viajes DESC, nombre";

        return DB::select($sql, $params);
    }

    /**
     * Matriz ruta x hora (solo las rutas recibidas).
     */
    public static function matriz_ruta(array $rutas): array
    {
        if (!$rutas) {
            return [];
        }

        [$base, $params] = self::base_sql();
        $marcas = implode(',', array_fill(0, count($rutas), '?'));

        $sql = "
        SELECT
            w.ruta_id,
            w.hora,
            COUNT(*) AS viajes,
            ROUND(AVG(CASE WHEN w.rn IN (FLOOR((w.tot + 1) / 2), CEIL((w.tot + 1) / 2))
                           THEN w.minutos END), 0) AS mediana
        FROM (
            SELECT b.ruta_id, b.hora, b.minutos,
                   ROW_NUMBER() OVER (PARTITION BY b.ruta_id, b.hora ORDER BY b.minutos) AS rn,
                   COUNT(*) OVER (PARTITION BY b.ruta_id, b.hora) AS tot
            FROM ($base) b
            WHERE b.ruta_id IN ($marcas)
        ) w
        GROUP BY w.ruta_id, w.hora
        ORDER BY w.ruta_id, w.hora";

        return DB::select($sql, array_merge($params, array_values($rutas)));
    }

    //------------------- presentacion -------------------

    public static function etiqueta_hora(int $hora): string
    {
        return str_pad((string) $hora, 2, '0', STR_PAD_LEFT) . ':00 - '
            . str_pad((string) $hora, 2, '0', STR_PAD_LEFT) . ':59';
    }

    /**
     * Color de fondo de una celda del mapa de calor.
     */
    public static function color_celda($minutos, $referencia): string
    {
        if ($minutos === null or !$referencia or $referencia <= 0) {
            return 'transparent';
        }

        $ratio = $minutos / $referencia;

        if ($ratio <= 0.7) return 'rgba(25, 135, 84, .75)';
        if ($ratio <= 0.9) return 'rgba(25, 135, 84, .45)';
        if ($ratio <= 1.1) return 'rgba(13, 110, 253, .45)';
        if ($ratio <= 1.35) return 'rgba(255, 193, 7, .55)';
        if ($ratio <= 1.7) return 'rgba(253, 126, 20, .65)';

        return 'rgba(220, 53, 69, .75)';
    }
}
