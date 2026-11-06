<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura de los promedios de espera por PLANTA ya compilados
 * (`tiempos_planta_promedio`), para la pantalla de consulta de lo compilado.
 *
 * No calcula ni escribe: devuelve lo guardado tal cual, separado por el rol de
 * la planta y fusionado:
 *  - carga: `prom_fuera_carga_min` (E2) y `prom_dentro_carga_min` (E3);
 *  - descarga: `prom_fuera_descarga_min` (E5) y `prom_dentro_descarga_min` (E6);
 *  - fusionado: `prom_fuera_min` y `prom_dentro_min` (peso por los lados de
 *    ambos roles) y `prom_espera_min` (fuera + dentro).
 *
 * Los filtros y el orden se aplican en PHP: la tabla tiene una fila por planta
 * (decenas), asi que no hace falta ensuciar el SQL.
 */
class DBTiemposPlantaCompilado
{
    /** Ordenes de la tabla: clave => etiqueta del selector. */
    public const ORDENES = [
        'viajes' => 'Más lados medibles',
        'espera' => 'Espera total más alta',
        'fuera' => 'Espera fuera más alta',
        'dentro' => 'Espera dentro más alta',
        'descartados' => 'Más descartados',
        'planta' => 'Nombre de la planta',
        'actualizado' => 'Última compilación',
    ];

    /** Filas que devuelve la consulta si no se pide otra cosa. */
    public const LIMITE = 300;

    /**
     * Si la migracion de la tabla esta aplicada.
     */
    public static function tabla_lista(): bool
    {
        try {
            return Schema::hasTable('tiempos_planta_promedio');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Nombres de departamento que aparecen en la tabla, para el selector.
     *
     * @return array<int, string>
     */
    public static function departamentos(): array
    {
        if (!self::tabla_lista()) {
            return [];
        }

        $nombres = [];
        foreach (DB::select('SELECT DISTINCT planta_id, departamento FROM tiempos_planta_promedio') as $fila) {
            $nombre = self::departamento_planta($fila);
            if ($nombre !== null) {
                $nombres[$nombre] = true;
            }
        }

        $nombres = array_keys($nombres);
        sort($nombres, SORT_NATURAL | SORT_FLAG_CASE);

        return $nombres;
    }

    /**
     * Promedios de espera por planta ya compilados.
     *
     * Filtros: `buscar` (nombre, id, palabra clave o departamento), `planta_id`,
     * `departamento` (nombre), `min_lados` (lados medibles), `orden` y `limit`.
     */
    public static function por_planta(array $filtros = []): object
    {
        $filtros = self::normalizar($filtros);

        $filas = [];
        if (self::tabla_lista()) {
            foreach (DB::select('SELECT * FROM tiempos_planta_promedio') as $fila) {
                $filas[] = self::formatear($fila);
            }
        }

        $filas = self::filtrar($filas, $filtros);
        $filas = self::ordenar($filas, $filtros['orden']);

        $total = count($filas);

        return (object) [
            'filas' => array_slice($filas, 0, $filtros['limit']),
            'resumen' => self::resumen($filas),
            'limitados' => $total,
        ];
    }

    /**
     * Filtros saneados: textos recortados, numeros en rango y orden valido.
     *
     * @return array{buscar: ?string, planta_id: ?string, departamento: ?string, min_lados: int, orden: string, limit: int}
     */
    private static function normalizar(array $filtros): array
    {
        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        $planta_id = trim((string) ($filtros['planta_id'] ?? ''));
        $departamento = trim((string) ($filtros['departamento'] ?? ''));
        $orden = (string) ($filtros['orden'] ?? 'viajes');
        $min_lados = max(0, (int) ($filtros['min_lados'] ?? 0));
        $limit = (int) ($filtros['limit'] ?? self::LIMITE);
        $limit = max(1, min(1000, $limit));

        return [
            'buscar' => $buscar === '' ? null : mb_strtolower($buscar),
            'planta_id' => $planta_id === '' ? null : $planta_id,
            'departamento' => $departamento === '' ? null : $departamento,
            'min_lados' => $min_lados,
            'orden' => array_key_exists($orden, self::ORDENES) ? $orden : 'viajes',
            'limit' => $limit,
        ];
    }

    /**
     * Aplica los filtros de la pantalla.
     *
     * @param  array<int, object>  $filas
     * @return array<int, object>
     */
    private static function filtrar(array $filas, array $filtros): array
    {
        return array_values(array_filter($filas, function ($fila) use ($filtros) {
            if ($filtros['planta_id'] !== null && $fila->planta_id !== $filtros['planta_id']) {
                return false;
            }
            if ($filtros['departamento'] !== null && $fila->departamento !== $filtros['departamento']) {
                return false;
            }
            if ($filtros['min_lados'] > 0 && $fila->viajes < $filtros['min_lados']) {
                return false;
            }
            if ($filtros['buscar'] !== null) {
                $texto = mb_strtolower(implode(' ', [
                    $fila->nombre,
                    $fila->planta_id,
                    implode(' ', $fila->palabra_clave),
                    (string) $fila->departamento,
                ]));

                if (!str_contains($texto, $filtros['buscar'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Ordena segun el selector; las plantas sin promedio caen al final en los
     * ordenes de tiempo.
     *
     * @param  array<int, object>  $filas
     * @return array<int, object>
     */
    private static function ordenar(array $filas, string $orden): array
    {
        usort($filas, function ($a, $b) use ($orden) {
            switch ($orden) {
                case 'espera':
                    return (($b->prom_espera_min ?? -1) <=> ($a->prom_espera_min ?? -1))
                        ?: ($b->viajes <=> $a->viajes);

                case 'fuera':
                    return (($b->prom_fuera_min ?? -1) <=> ($a->prom_fuera_min ?? -1))
                        ?: ($b->viajes <=> $a->viajes);

                case 'dentro':
                    return (($b->prom_dentro_min ?? -1) <=> ($a->prom_dentro_min ?? -1))
                        ?: ($b->viajes <=> $a->viajes);

                case 'descartados':
                    return ($b->descartados <=> $a->descartados)
                        ?: ($b->viajes <=> $a->viajes);

                case 'planta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: strcasecmp($a->planta_id, $b->planta_id);

                case 'actualizado':
                    return strcmp((string) $b->actualizado_en, (string) $a->actualizado_en)
                        ?: ($b->viajes <=> $a->viajes);

                default:
                    return ($b->viajes <=> $a->viajes)
                        ?: strcasecmp($a->nombre, $b->nombre);
            }
        });

        return $filas;
    }

    /**
     * Fila de la tabla: los promedios guardados, ya redondeados a minutos
     * enteros, y el porcentaje descartado calculado.
     */
    private static function formatear($fila): object
    {
        $viajes = (int) $fila->viajes;
        $descartados_fuera = (int) $fila->descartados_fuera;
        $descartados_dentro = (int) $fila->descartados_dentro;
        $descartados = $descartados_fuera + $descartados_dentro;
        $medidos = $viajes + $descartados;

        return (object) [
            'planta_id' => (string) $fila->planta_id,
            'nombre' => self::nombre_planta($fila),
            'palabra_clave' => self::palabras($fila->palabra_clave),
            'ubigeo' => $fila->ubigeo,
            'departamento' => self::departamento_planta($fila),
            'modo' => (string) $fila->modo,
            'percentil' => isset($fila->percentil) ? (int) $fila->percentil : null,
            'rutas_carga' => (int) $fila->rutas_carga,
            'rutas_descarga' => (int) $fila->rutas_descarga,
            'viajes_carga' => (int) $fila->viajes_carga,
            'viajes_descarga' => (int) $fila->viajes_descarga,
            'viajes' => $viajes,
            'lados' => $medidos,
            'descartados' => $descartados,
            'descartados_fuera' => $descartados_fuera,
            'descartados_dentro' => $descartados_dentro,
            'pct_descartado' => $medidos > 0 ? round(100 * $descartados / $medidos, 1) : null,
            'prom_fuera_carga_min' => self::entero($fila->prom_fuera_carga_min),
            'prom_dentro_carga_min' => self::entero($fila->prom_dentro_carga_min),
            'prom_fuera_descarga_min' => self::entero($fila->prom_fuera_descarga_min),
            'prom_dentro_descarga_min' => self::entero($fila->prom_dentro_descarga_min),
            'prom_fuera_min' => self::entero($fila->prom_fuera_min),
            'prom_dentro_min' => self::entero($fila->prom_dentro_min),
            'prom_espera_min' => self::entero($fila->prom_espera_min),
            'p82_fuera_min' => $fila->p82_fuera_min !== null ? (int) $fila->p82_fuera_min : null,
            'p82_dentro_min' => $fila->p82_dentro_min !== null ? (int) $fila->p82_dentro_min : null,
            'tope_fuera_min' => $fila->tope_fuera_min !== null ? (int) $fila->tope_fuera_min : null,
            'tope_dentro_min' => $fila->tope_dentro_min !== null ? (int) $fila->tope_dentro_min : null,
            'actualizado_en' => $fila->actualizado_en,
        ];
    }

    private static function entero($valor): ?int
    {
        return $valor === null ? null : (int) round((float) $valor);
    }

    /**
     * Palabra clave de la planta como arreglo. En `locales` se guarda una sola
     * cadena con las variantes separadas por `|` (ej. `AMAZON|BABEL AMAZON`);
     * aqui se divide para que el JSON devuelva `["AMAZON", "BABEL AMAZON"]`.
     *
     * @return array<int, string>
     */
    private static function palabras($valor): array
    {
        $partes = explode('|', (string) $valor);
        $partes = array_map('trim', $partes);

        return array_values(array_filter($partes, fn ($p) => $p !== ''));
    }

    /**
     * Nombre de la planta. Las que no estan en `locales` se guardaron con su
     * ubigeo como nombre: se resuelve al nombre del distrito del catalogo.
     */
    private static function nombre_planta($fila): string
    {
        $nombre = trim((string) ($fila->nombre ?? ''));
        $id = (string) $fila->planta_id;

        // el nombre guardado es el mismo codigo de la planta (o esta vacio)
        if ($nombre === '' || $nombre === $id) {
            return DBTiemposPromedio::nombre_distrito($id) ?? ($nombre !== '' ? $nombre : $id);
        }

        return $nombre;
    }

    /**
     * Departamento de la planta. Las plantas sin local lo tienen vacio hasta
     * recompilarlas: se resuelve con los dos primeros digitos de su ubigeo.
     */
    private static function departamento_planta($fila): ?string
    {
        $departamento = trim((string) ($fila->departamento ?? ''));

        if ($departamento !== '') {
            return $departamento;
        }

        $id = (string) $fila->planta_id;

        if (DBTiemposPromedio::nombre_distrito($id) === null) {
            return null;
        }

        return DBTiemposPromedio::nombre_departamento(substr($id, 0, 2));
    }

    /**
     * Resumen de lo consultado: los promedios se ponderan por los lados
     * medibles de cada planta, que es el peso real del conjunto.
     *
     * @param  array<int, object>  $filas
     */
    private static function resumen(array $filas): object
    {
        $resumen = (object) [
            'plantas' => count($filas),
            'lados' => 0,
            'lados_carga' => 0,
            'lados_descarga' => 0,
            'descartados' => 0,
            'descartados_fuera' => 0,
            'descartados_dentro' => 0,
            'pct_descartado' => null,
            'prom_fuera_min' => null,
            'prom_dentro_min' => null,
            'prom_espera_min' => null,
            'con_tope' => 0,
            'modos' => [],
            'ultima' => null,
        ];

        $pesos = ['fuera' => [0.0, 0], 'dentro' => [0.0, 0], 'espera' => [0.0, 0]];

        foreach ($filas as $fila) {
            $resumen->lados += $fila->viajes;
            $resumen->lados_carga += $fila->viajes_carga;
            $resumen->lados_descarga += $fila->viajes_descarga;
            $resumen->descartados += $fila->descartados;
            $resumen->descartados_fuera += $fila->descartados_fuera;
            $resumen->descartados_dentro += $fila->descartados_dentro;

            if ($fila->tope_fuera_min !== null || $fila->tope_dentro_min !== null) {
                $resumen->con_tope++;
            }

            $resumen->modos[$fila->modo] = ($resumen->modos[$fila->modo] ?? 0) + 1;

            if ($resumen->ultima === null || (string) $fila->actualizado_en > (string) $resumen->ultima) {
                $resumen->ultima = $fila->actualizado_en;
            }

            // el peso es el de la planta: sin lados medibles no pondera
            $peso = max(1, $fila->viajes);
            foreach ([
                'fuera' => $fila->prom_fuera_min,
                'dentro' => $fila->prom_dentro_min,
                'espera' => $fila->prom_espera_min,
            ] as $clave => $valor) {
                if ($valor !== null) {
                    $pesos[$clave][0] += $valor * $peso;
                    $pesos[$clave][1] += $peso;
                }
            }
        }

        foreach ($pesos as $clave => [$suma, $peso]) {
            $resumen->{"prom_{$clave}_min"} = $peso > 0 ? (int) round($suma / $peso) : null;
        }

        $medidos = $resumen->lados + $resumen->descartados;
        $resumen->pct_descartado = $medidos > 0
            ? round(100 * $resumen->descartados / $medidos, 1)
            : null;

        return $resumen;
    }
}
