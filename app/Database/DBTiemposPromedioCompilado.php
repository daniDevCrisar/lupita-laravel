<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Lectura (solo consulta) de los promedios YA COMPILADOS:
 *
 *  - `tiempos_ruta_promedio`  un promedio por ruta y sentido (etapa 4)
 *  - `tiempos_ruta_horario`   promedio por ruta, dia de la semana y hora
 *
 * Esta clase no calcula ni escribe nada: las pantallas de compilacion
 * (`DBTiemposPromedio`) son las unicas que generan estas filas. Aqui solo se
 * filtran, se ordenan y se resumen para las vistas de consulta.
 */
class DBTiemposPromedioCompilado
{
    /** Ordenes de la tabla por ruta: clave => etiqueta del selector. */
    public const ORDENES = [
        'viajes' => 'Más viajes dentro del tope',
        'promedio' => 'Promedio más alto',
        'promedio_asc' => 'Promedio más bajo',
        'perdido' => 'Mayor % descartado',
        'perdido_asc' => 'Menor % descartado',
        'mediana' => 'Mediana más alta',
        'ruta' => 'Nombre de la ruta',
        'actualizado' => 'Compilación más reciente',
    ];

    /** Ordenes de la matriz dia x hora. */
    public const ORDENES_HORARIO = [
        'viajes' => 'Más viajes en la celda',
        'promedio' => 'Promedio más alto',
        'promedio_asc' => 'Promedio más bajo',
        'dia' => 'Día y hora',
        'ruta' => 'Ruta, día y hora',
    ];

    /** Filas de celda dia/hora que devuelve el endpoint como maximo. */
    public const LIMITE_HORARIO = 1000;

    private static $tablas = null;

    //------------------- tablas -------------------

    /**
     * La consulta tiene que aguantar un servidor sin las tablas compiladas.
     */
    public static function tablas_listas(): bool
    {
        if (self::$tablas !== null) {
            return self::$tablas;
        }

        try {
            $tablas = DB::select("SHOW TABLES LIKE 'tiempos_ruta_promedio'");
            $columnas = $tablas ? DB::select("SHOW TABLES LIKE 'tiempos_ruta_horario'") : [];

            return self::$tablas = $tablas && $columnas;
        } catch (\Throwable $e) {
            return self::$tablas = false;
        }
    }

    /**
     * Normaliza el filtro de departamento al codigo de dos digitos del ubigeo.
     */
    private static function departamento($codigo): ?string
    {
        $codigo = trim((string) $codigo);

        return preg_match('/^\d{1,2}$/', $codigo) === 1 ? str_pad($codigo, 2, '0', STR_PAD_LEFT) : null;
    }

    //------------------- promedios por ruta -------------------

    /**
     * Promedios compilados por ruta, filtrados y ordenados en memoria.
     *
     * `buscar` solo se resuelve aqui (nombre de la ruta, id, origen, destino o
     * departamento): son pocas filas (una por ruta) y asi el buscador tambien
     * encuentra por el texto legible del tramo.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function por_ruta(array $filtros = []): object
    {
        $salida = (object) [
            'resumen' => (object) [
                'rutas' => 0,
                'viajes' => 0,
                'viajes_total' => 0,
                'descartados' => 0,
                'pct_perdido' => null,
                'prom_ponderado' => null,
                'prom_sin_tope' => null,
                'mediana_ponderada' => null,
                'fusionadas' => 0,
                'topes' => 0,
                'ultima' => null,
                'tablas' => self::tablas_listas(),
            ],
            'filas' => [],
            'limitados' => 0,
        ];

        if (!self::tablas_listas()) {
            return $salida;
        }

        [$where, $params] = self::where_ruta($filtros);

        $rutas = DBTiemposPromedio::rutas();
        $filas = [];
        foreach (DB::select("SELECT p.* FROM tiempos_ruta_promedio p $where", $params) as $fila) {
            $filas[] = self::formatear_ruta($fila, $rutas);
        }

        // busqueda libre: id, nombre del tramo, origen/destino y departamentos
        $buscar = mb_strtolower(trim((string) ($filtros['buscar'] ?? '')));
        if ($buscar !== '') {
            $filas = array_values(array_filter($filas, function ($fila) use ($buscar) {
                $texto = mb_strtolower(implode(' ', [
                    (string) $fila->ruta_id,
                    (string) $fila->nombre,
                    (string) $fila->origen_nombre,
                    (string) $fila->destino_nombre,
                    (string) $fila->dep_origen,
                    (string) $fila->dep_destino,
                ]));

                return mb_strpos($texto, $buscar) !== false;
            }));
        }

        // resumen sobre el conjunto ya filtrado (no sobre toda la tabla)
        $resumen = $salida->resumen;
        $recipiente = [];
        $suma_prom = 0.0;
        $suma_sin_tope = 0.0;
        $suma_mediana = 0.0;
        $peso_prom = 0;
        $peso_sin_tope = 0;
        $peso_mediana = 0;
        $topes = [];

        foreach ($filas as $fila) {
            $resumen->rutas++;
            $resumen->viajes += $fila->viajes;
            $resumen->viajes_total += $fila->viajes_total;
            $resumen->descartados += $fila->descartados;
            $resumen->fusionadas += $fila->prom_fusion_min !== null ? 1 : 0;
            $topes[(int) $fila->tope_min] = true;
            $recipiente[] = $fila;

            if ($fila->prom_min !== null && $fila->viajes > 0) {
                $suma_prom += $fila->prom_min * $fila->viajes;
                $peso_prom += $fila->viajes;
            }
            if ($fila->prom_sin_tope_min !== null && $fila->viajes_total > 0) {
                $suma_sin_tope += $fila->prom_sin_tope_min * $fila->viajes_total;
                $peso_sin_tope += $fila->viajes_total;
            }
            if ($fila->mediana_min !== null && $fila->viajes > 0) {
                $suma_mediana += $fila->mediana_min * $fila->viajes;
                $peso_mediana += $fila->viajes;
            }
        }

        $resumen->pct_perdido = $resumen->viajes_total > 0
            ? round(100 * $resumen->descartados / $resumen->viajes_total, 1)
            : null;
        $resumen->prom_ponderado = $peso_prom > 0 ? round($suma_prom / $peso_prom, 1) : null;
        $resumen->prom_sin_tope = $peso_sin_tope > 0 ? round($suma_sin_tope / $peso_sin_tope, 1) : null;
        $resumen->mediana_ponderada = $peso_mediana > 0 ? round($suma_mediana / $peso_mediana, 1) : null;
        $resumen->topes = count($topes);

        // ultima compilacion de las rutas que entraron en el filtro
        $ultima = null;
        foreach ($filas as $fila) {
            if ($fila->actualizado_en !== null && ($ultima === null || $fila->actualizado_en > $ultima)) {
                $ultima = $fila->actualizado_en;
            }
        }
        $resumen->ultima = $ultima;

        // el orden se elige en la pantalla; las rutas sin promedio siempre al final
        usort($recipiente, function ($a, $b) use ($filtros) {
            $orden = (string) ($filtros['orden'] ?? 'viajes');
            $orden = array_key_exists($orden, self::ORDENES) ? $orden : 'viajes';

            switch ($orden) {
                case 'promedio':
                    return (($b->prom_efectivo ?? -1) <=> ($a->prom_efectivo ?? -1))
                        ?: ($b->viajes <=> $a->viajes);

                case 'promedio_asc':
                    return (($a->prom_efectivo ?? PHP_INT_MAX) <=> ($b->prom_efectivo ?? PHP_INT_MAX))
                        ?: ($b->viajes <=> $a->viajes);

                case 'perdido':
                    return (($b->pct_perdido ?? -1) <=> ($a->pct_perdido ?? -1))
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'perdido_asc':
                    return (($a->pct_perdido ?? 101) <=> ($b->pct_perdido ?? 101))
                        ?: ($b->viajes_total <=> $a->viajes_total);

                case 'mediana':
                    return (($b->mediana_min ?? -1) <=> ($a->mediana_min ?? -1))
                        ?: ($b->viajes <=> $a->viajes);

                case 'ruta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: strcasecmp($a->ruta_id, $b->ruta_id);

                case 'actualizado':
                    return strcmp((string) $b->actualizado_en, (string) $a->actualizado_en);

                default:
                    return ($b->viajes <=> $a->viajes)
                        ?: ($b->viajes_total <=> $a->viajes_total);
            }
        });

        $limite = self::limite($filtros['limit'] ?? 300);
        $salida->limitados = count($recipiente);
        $salida->filas = array_slice($recipiente, 0, $limite);

        return $salida;
    }

    /**
     * WHERE comun de la tabla por ruta (la busqueda libre se aplica despues).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: string, 1: array}
     */
    private static function where_ruta(array $filtros): array
    {
        $condiciones = [];
        $params = [];

        $ruta_id = trim((string) ($filtros['ruta_id'] ?? ''));
        if ($ruta_id !== '') {
            $condiciones[] = 'p.ruta_id = ?';
            $params[] = $ruta_id;
        }

        foreach (['dep_origen' => 'ubigeo_origen', 'dep_destino' => 'ubigeo_destino'] as $clave => $columna) {
            $codigo = self::departamento($filtros[$clave] ?? null);
            if ($codigo !== null) {
                $condiciones[] = "p.ruta_id IN (SELECT ru.id FROM rutas ru WHERE LEFT(ru.$columna, 2) = ?)";
                $params[] = $codigo;
            }
        }

        $min_viajes = max(0, (int) ($filtros['min_viajes'] ?? 0));
        if ($min_viajes > 0) {
            $condiciones[] = 'p.viajes >= ?';
            $params[] = $min_viajes;
        }

        if (!empty($filtros['con_fusion'])) {
            $condiciones[] = 'p.prom_fusion_min IS NOT NULL';
        }

        return [$condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones), $params];
    }

    /**
     * Una fila de `tiempos_ruta_promedio` a la representacion de la vista.
     */
    private static function formatear_ruta($fila, array $rutas): object
    {
        $id = (string) $fila->ruta_id;
        $ruta = $rutas[$id] ?? null;

        $viajes_total = (int) $fila->viajes_total;
        $descartados = (int) $fila->descartados;
        $prom = $fila->prom_min !== null ? round((float) $fila->prom_min, 1) : null;
        $fusion = $fila->prom_fusion_min !== null ? round((float) $fila->prom_fusion_min, 1) : null;
        $ubicacion = fn ($lado) => self::nombre_ubicacion($ruta, $lado);

        return (object) [
            'ruta_id' => $id,
            'nombre' => DBTiemposPromedio::nombre($id),
            'origen_id' => $fila->origen_id,
            'destino_id' => $fila->destino_id,
            'origen_nombre' => $ubicacion('origen'),
            'destino_nombre' => $ubicacion('destino'),
            'dep_origen' => DBTiemposPromedio::nombre_departamento(substr((string) ($ruta->ubigeo_origen ?? ''), 0, 2)),
            'dep_destino' => DBTiemposPromedio::nombre_departamento(substr((string) ($ruta->ubigeo_destino ?? ''), 0, 2)),
            'modo' => (string) ($fila->modo ?? 'global'),
            'tope_min' => (int) $fila->tope_min,
            'viajes_total' => $viajes_total,
            'viajes' => (int) $fila->viajes,
            'descartados' => $descartados,
            'pct_perdido' => $viajes_total > 0 ? round(100 * $descartados / $viajes_total, 1) : null,
            'prom_min' => $prom,
            'prom_sin_tope_min' => $fila->prom_sin_tope_min !== null ? round((float) $fila->prom_sin_tope_min, 1) : null,
            'mediana_min' => $fila->mediana_min !== null ? (int) $fila->mediana_min : null,
            'p25_min' => $fila->p25_min !== null ? (int) $fila->p25_min : null,
            'p75_min' => $fila->p75_min !== null ? (int) $fila->p75_min : null,
            'desv_min' => $fila->desv_min !== null ? round((float) $fila->desv_min, 1) : null,
            'prom_fusion_min' => $fusion,
            'viajes_fusion' => $fila->viajes_fusion !== null ? (int) $fila->viajes_fusion : null,
            // tiempo que se usa para planificar: fusionado si existe, si no el del sentido
            'prom_efectivo' => $fusion ?? $prom,
            'actualizado_en' => $fila->actualizado_en,
        ];
    }

    /**
     * Nombre legible de un lado del tramo: local, distrito o ubigeo.
     */
    private static function nombre_ubicacion($ruta, string $lado): string
    {
        if ($ruta === null) {
            return '';
        }

        $local = $lado === 'destino' ? ($ruta->loc_destino_nombre ?? '') : ($ruta->loc_origen_nombre ?? '');
        if ($local !== '') {
            return (string) $local;
        }

        $distrito = $lado === 'destino' ? ($ruta->ubg_destino_nombre ?? '') : ($ruta->ubg_origen_nombre ?? '');
        if ($distrito !== '') {
            return (string) $distrito;
        }

        return (string) ($lado === 'destino' ? ($ruta->ubigeo_destino ?? '') : ($ruta->ubigeo_origen ?? ''));
    }

    //------------------- promedios por dia y hora -------------------

    /**
     * Celdas compiladas de la matriz ruta x dia x hora, con la matriz 7x24 ya
     * armada para pintar el mapa de calor.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function horario(array $filtros = []): object
    {
        $dias = DBTiemposHorario::$dias;

        $salida = (object) [
            'dias' => $dias,
            'resumen' => (object) [
                'celdas' => 0,
                'rutas' => 0,
                'viajes' => 0,
                'prom_ponderado' => null,
                'min' => null,
                'max' => null,
                'topes' => 0,
                'tablas' => self::tablas_listas(),
            ],
            'matriz' => [],
            'por_dia' => [],
            'por_hora' => [],
            'celdas' => [],
            'limitados' => 0,
        ];

        // la matriz siempre tiene 7 x 24 posiciones, aunque no haya data
        $salida->matriz = array_fill(0, 7, array_fill(0, 24, null));
        $salida->por_dia = array_fill(0, 7, (object) ['viajes' => 0, 'prom_min' => null]);
        $salida->por_hora = array_fill(0, 24, (object) ['viajes' => 0, 'prom_min' => null]);

        if (!self::tablas_listas()) {
            return $salida;
        }

        [$where, $params] = self::where_horario($filtros);
        $filas = DB::select("SELECT h.* FROM tiempos_ruta_horario h $where", $params);

        $suma_dia = array_fill(0, 7, 0.0);
        $suma_hora = array_fill(0, 24, 0.0);
        $suma_total = 0.0;
        $peso_total = 0;
        $topes = [];
        $celdas = [];
        $rutas = [];

        foreach ($filas as $fila) {
            $dia = (int) $fila->dia;
            $hora = (int) $fila->hora;
            if ($dia < 0 || $dia > 6 || $hora < 0 || $hora > 23) {
                continue;
            }

            $viajes = (int) $fila->viajes;
            $prom = round((float) $fila->prom_min, 1);
            $id = (string) $fila->ruta_id;

            $rutas[$id] = true;
            $topes[(int) $fila->tope_min] = true;
            $suma_total += $prom * $viajes;
            $peso_total += $viajes;
            $suma_dia[$dia] += $prom * $viajes;
            $suma_hora[$hora] += $prom * $viajes;

            $celda = (object) [
                'ruta_id' => $id,
                'nombre' => DBTiemposPromedio::nombre($id),
                'dia' => $dia,
                'hora' => $hora,
                'viajes' => $viajes,
                'prom_min' => $prom,
                'tope_min' => (int) $fila->tope_min,
            ];
            $celdas[] = $celda;

            $salida->matriz[$dia][$hora] = $celda;
            $salida->por_dia[$dia]->viajes += $viajes;
            $salida->por_hora[$hora]->viajes += $viajes;
        }

        foreach ($salida->por_dia as $dia => $resumen_dia) {
            $resumen_dia->prom_min = $resumen_dia->viajes > 0
                ? round($suma_dia[$dia] / $resumen_dia->viajes, 1)
                : null;
        }
        foreach ($salida->por_hora as $hora => $resumen_hora) {
            $resumen_hora->prom_min = $resumen_hora->viajes > 0
                ? round($suma_hora[$hora] / $resumen_hora->viajes, 1)
                : null;
        }

        $resumen = $salida->resumen;
        $resumen->celdas = count($celdas);
        $resumen->rutas = count($rutas);
        $resumen->viajes = $peso_total;
        $resumen->topes = count($topes);
        $resumen->prom_ponderado = $peso_total > 0 ? round($suma_total / $peso_total, 1) : null;

        $promedios = array_filter(array_map(fn ($c) => $c->prom_min, $celdas), fn ($p) => $p !== null);
        $resumen->min = $promedios === [] ? null : min($promedios);
        $resumen->max = $promedios === [] ? null : max($promedios);

        usort($celdas, function ($a, $b) use ($filtros) {
            $orden = (string) ($filtros['orden'] ?? 'viajes');
            $orden = array_key_exists($orden, self::ORDENES_HORARIO) ? $orden : 'viajes';

            switch ($orden) {
                case 'promedio':
                    return ($b->prom_min <=> $a->prom_min) ?: ($b->viajes <=> $a->viajes);

                case 'promedio_asc':
                    return ($a->prom_min <=> $b->prom_min) ?: ($b->viajes <=> $a->viajes);

                case 'dia':
                    return [$a->dia, $a->hora] <=> [$b->dia, $b->hora];

                case 'ruta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: ([$a->dia, $a->hora] <=> [$b->dia, $b->hora]);

                default:
                    return ($b->viajes <=> $a->viajes)
                        ?: ([$a->dia, $a->hora] <=> [$b->dia, $b->hora]);
            }
        });

        $limite = self::limite($filtros['limit'] ?? self::LIMITE_HORARIO, self::LIMITE_HORARIO);
        $salida->limitados = count($celdas);
        $salida->celdas = array_slice($celdas, 0, $limite);

        return $salida;
    }

    /**
     * WHERE de la matriz dia/hora.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: string, 1: array}
     */
    private static function where_horario(array $filtros): array
    {
        $condiciones = [];
        $params = [];

        $ruta_id = trim((string) ($filtros['ruta_id'] ?? ''));
        if ($ruta_id !== '') {
            $condiciones[] = 'h.ruta_id = ?';
            $params[] = $ruta_id;
        }

        $dia = filter_var($filtros['dia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        if ($dia !== false && $dia !== null) {
            $condiciones[] = 'h.dia = ?';
            $params[] = $dia;
        }

        $hora_desde = filter_var($filtros['hora_desde'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
        if ($hora_desde !== false && $hora_desde !== null) {
            $condiciones[] = 'h.hora >= ?';
            $params[] = $hora_desde;
        }

        $hora_hasta = filter_var($filtros['hora_hasta'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
        if ($hora_hasta !== false && $hora_hasta !== null) {
            $condiciones[] = 'h.hora <= ?';
            $params[] = $hora_hasta;
        }

        $min_viajes = max(0, (int) ($filtros['min_viajes'] ?? 0));
        if ($min_viajes > 0) {
            $condiciones[] = 'h.viajes >= ?';
            $params[] = $min_viajes;
        }

        return [$condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones), $params];
    }

    //------------------- comun -------------------

    /**
     * Limite de filas del endpoint: 1..1000, con un valor por defecto.
     */
    private static function limite($valor, int $defecto = 300): int
    {
        $limite = (int) $valor;

        if ($limite <= 0) {
            return $defecto;
        }

        return min(1000, $limite);
    }
}
