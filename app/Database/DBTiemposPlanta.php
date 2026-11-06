<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promedios de espera por PLANTA (local), en tabla aparte de los promedios de
 * ruta para no mezclar las dos cosas.
 *
 * Cada viaje toca dos plantas: la de carga (`rutas.origen_id`) y la de descarga
 * (`rutas.destino_id`). Los promedios se calculan sobre "lados": un viaje
 * aporta un lado de carga con E2 (espera fuera) y E3 (dentro) y un lado de
 * descarga con E5 (fuera) y E6 (dentro). Asi una misma planta suma lo que se
 * espera cuando es origen y cuando es destino, y las columnas fusionadas
 * (`prom_fuera_min` / `prom_dentro_min`) quedan ponderadas por viajes, que es
 * lo que se pidio: fusionar los tiempos de la planta en una sola fila.
 *
 * El tope de espera es por planta y se elige entre tres modos:
 *  - `recomendado` (por defecto): el tope propio de cada planta calibrado para
 *    descartar como maximo 18 % de sus lados. Arranca en el p82 y sube el
 *    percentil solo si la union de fuera + dentro descarta mas de 18 %.
 *  - `global`: el mismo tope de fuera y de dentro para todas las plantas.
 *  - `propio`: el escrito en la fila de la tabla, el que quedo guardado al
 *    compilar o, si no hay, el recomendado.
 *
 * Una planta sin lados medibles no se crea ni se toca, igual que las rutas.
 */
class DBTiemposPlanta
{
    /** Descarte maximo que se acepta con el tope recomendado, en porcentaje. */
    public const DESCARTE_MAXIMO = 18.0;

    /** Primer percentil que se prueba: el p82 solo deja 18 % por metrica. */
    public const PERCENTIL_BASE = 82;

    /** Ordenes de la tabla por planta: clave => etiqueta del selector. */
    public const ORDENES = [
        'viajes' => 'Más viajes medibles',
        'espera' => 'Espera total más alta',
        'fuera' => 'Espera fuera más alta',
        'dentro' => 'Espera dentro más alta',
        'descartados' => 'Más descartados',
        'planta' => 'Nombre de la planta',
    ];

    /** Modos validos del tope de espera por planta. */
    public const MODOS = [
        'recomendado' => 'Recomendado (≤18 % descartado)',
        'global' => 'Un tope global para todas',
        'propio' => 'El tope de cada planta',
    ];

    private static $plantas = null;

    private static $tabla_lista = null;

    //------------------- base -------------------

    /**
     * Una fila por "lado" de planta: carga (E2 fuera, E3 dentro) y descarga
     * (E5 fuera, E6 dentro). Reutiliza la base de viajes de
     * `DBTiemposPromedio` para no duplicar las formulas de las etapas.
     *
     * @return array{0: string, 1: array}
     */
    private static function viajes_sql(?string $planta_id = null): array
    {
        [$base, $base_params] = DBTiemposPromedio::base_sql();

        // la base va dos veces (carga y descarga), asi que sus parametros tambien
        $params = array_merge($base_params, $base_params);
        $filtro = '';

        $planta_id = trim((string) $planta_id);
        if ($planta_id !== '') {
            $filtro = 'WHERE u.planta = ?';
            $params[] = $planta_id;
        }

        $sql = "
        SELECT u.planta AS planta,
               u.rol AS rol,
               u.ruta_id AS ruta_id,
               u.fuera AS fuera,
               u.dentro AS dentro
        FROM (
            SELECT ru.origen_id AS planta, 'carga' AS rol, b.ruta_id AS ruta_id,
                   b.e2 AS fuera, b.e3 AS dentro
            FROM ($base) b
            JOIN rutas ru ON ru.id = b.ruta_id
            WHERE ru.origen_id IS NOT NULL AND ru.origen_id <> ''
            UNION ALL
            SELECT ru.destino_id AS planta, 'descarga' AS rol, b.ruta_id AS ruta_id,
                   b.e5 AS fuera, b.e6 AS dentro
            FROM ($base) b
            JOIN rutas ru ON ru.id = b.ruta_id
            WHERE ru.destino_id IS NOT NULL AND ru.destino_id <> ''
        ) u
        $filtro";

        return [$sql, $params];
    }

    /**
     * Lados agrupados por planta. Se traen en una sola consulta y se agregan en
     * PHP: son decenas de miles de filas y asi el percentil y los promedios
     * dentro del tope se calculan sin repetir la base de viajes cuatro veces.
     *
     * @return array<string, object{rutas_carga: array, rutas_descarga: array, lados: array}>
     */
    private static function acumulados(?string $planta_id = null): array
    {
        [$sql, $params] = self::viajes_sql($planta_id);

        $stmt = DB::connection()->getPdo()->prepare($sql);
        $stmt->execute($params);

        $acum = [];

        while (($fila = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            $id = (string) $fila[0];
            $carga = $fila[1] === 'carga';
            $ruta = (string) $fila[2];

            $actual = $acum[$id] ?? (object) [
                'rutas_carga' => [],
                'rutas_descarga' => [],
                'lados' => [],
            ];

            if ($carga) {
                $actual->rutas_carga[$ruta] = true;
            } else {
                $actual->rutas_descarga[$ruta] = true;
            }

            $actual->lados[] = [
                $fila[3] === null ? null : (int) $fila[3],
                $fila[4] === null ? null : (int) $fila[4],
                $carga,
            ];

            $acum[$id] = $actual;
        }

        return $acum;
    }

    /**
     * Valores validos de un campo (hito presente y no invertido), ordenados.
     *
     * @param  array<int, array>  $lados
     * @return array<int, int>
     */
    private static function valores(array $lados, int $indice): array
    {
        $valores = [];

        foreach ($lados as $lado) {
            if ($lado[$indice] !== null && $lado[$indice] >= 0) {
                $valores[] = $lado[$indice];
            }
        }

        sort($valores);

        return $valores;
    }

    /** Posicion (base 0) del percentil pedido sobre un array ya ordenado. */
    private static function posicion(int $percentil, int $total): int
    {
        return min($total - 1, max(0, (int) ceil($percentil / 100 * $total) - 1));
    }

    /**
     * Cuantos lados se pierden con un par de topes: primero fuera y despues
     * dentro, para que los contadores sean excluyentes y sumen los lados.
     *
     * @param  array<int, array>  $lados
     * @return array{0: int, 1: int}
     */
    private static function descartes(array $lados, ?int $tope_fuera, ?int $tope_dentro): array
    {
        $fuera = 0;
        $dentro = 0;

        foreach ($lados as $lado) {
            if (!self::dentro_de_tope($lado[0], $tope_fuera)) {
                $fuera++;
            } elseif (!self::dentro_de_tope($lado[1], $tope_dentro)) {
                $dentro++;
            }
        }

        return [$fuera, $dentro];
    }

    /** Misma regla que `DBTiemposPromedio::dentro_tope()`, en PHP. */
    private static function dentro_de_tope(?int $valor, ?int $tope): bool
    {
        return $valor === null || $tope === null || ($valor >= 0 && $valor <= $tope);
    }

    /**
     * Tope recomendado de la planta: el percentil mas bajo (desde el p82) cuyo
     * descarte conjunto de fuera + dentro no pase de 18 % de sus lados.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int} [fuera, dentro, percentil]
     */
    private static function recomendado(object $planta): array
    {
        $lados = $planta->lados;
        $total = count($lados);

        if ($total === 0) {
            return [null, null, null];
        }

        $fuera = self::valores($lados, 0);
        $dentro = self::valores($lados, 1);
        $total_fuera = count($fuera);
        $total_dentro = count($dentro);

        for ($percentil = self::PERCENTIL_BASE; $percentil <= 100; $percentil++) {
            $tope_fuera = $total_fuera > 0 ? $fuera[self::posicion($percentil, $total_fuera)] : null;
            $tope_dentro = $total_dentro > 0 ? $dentro[self::posicion($percentil, $total_dentro)] : null;

            $descartes = self::descartes($lados, $tope_fuera, $tope_dentro);
            $pct = 100 * ($descartes[0] + $descartes[1]) / $total;

            if ($pct <= self::DESCARTE_MAXIMO) {
                return [$tope_fuera, $tope_dentro, $percentil];
            }
        }

        // el p100 siempre descarta cero, pero se deja el tope mas alto por si acaso
        return [
            $total_fuera > 0 ? $fuera[$total_fuera - 1] : null,
            $total_dentro > 0 ? $dentro[$total_dentro - 1] : null,
            100,
        ];
    }

    /**
     * Promedios y descartes de una planta con el tope ya aplicado.
     *
     * @param  array<int, array>  $lados
     */
    private static function medir(array $lados, ?int $tope_fuera, ?int $tope_dentro): object
    {
        $medida = (object) [
            'viajes' => 0,
            'viajes_carga' => 0,
            'viajes_descarga' => 0,
            'descartados_fuera' => 0,
            'descartados_dentro' => 0,
            'sumas' => array_fill(0, 6, 0.0),
            'cuentas' => array_fill(0, 6, 0),
        ];

        // 0 fuera, 1 fuera_carga, 2 fuera_descarga, 3 dentro, 4 dentro_carga, 5 dentro_descarga
        foreach ($lados as $lado) {
            if (!self::dentro_de_tope($lado[0], $tope_fuera)) {
                $medida->descartados_fuera++;
                continue;
            }
            if (!self::dentro_de_tope($lado[1], $tope_dentro)) {
                $medida->descartados_dentro++;
                continue;
            }

            $medida->viajes++;

            if ($lado[2]) {
                $medida->viajes_carga++;
            } else {
                $medida->viajes_descarga++;
            }

            if ($lado[0] !== null && $lado[0] >= 0) {
                $medida->sumas[0] += $lado[0];
                $medida->cuentas[0]++;
                $indice = $lado[2] ? 1 : 2;
                $medida->sumas[$indice] += $lado[0];
                $medida->cuentas[$indice]++;
            }

            if ($lado[1] !== null && $lado[1] >= 0) {
                $medida->sumas[3] += $lado[1];
                $medida->cuentas[3]++;
                $indice = $lado[2] ? 4 : 5;
                $medida->sumas[$indice] += $lado[1];
                $medida->cuentas[$indice]++;
            }
        }

        return $medida;
    }

    /** @return ?float */
    private static function promedio(object $medida, int $indice)
    {
        return $medida->cuentas[$indice] > 0
            ? round($medida->sumas[$indice] / $medida->cuentas[$indice], 1)
            : null;
    }

    /**
     * Tope que le toca a la planta segun el modo elegido.
     *
     * @param  array{0: ?int, 1: ?int, 2: ?int}  $recomendado
     * @param  array<string, object>  $guardados
     * @param  array<string, int>  $escritos_fuera
     * @param  array<string, int>  $escritos_dentro
     * @return array{0: ?int, 1: ?int}
     */
    private static function tope(string $modo, string $id, array $recomendado, array $guardados, array $escritos_fuera, array $escritos_dentro, ?int $fuera, ?int $dentro): array
    {
        if ($modo === 'global') {
            return [$fuera, $dentro];
        }

        if ($modo === 'propio') {
            // escrito en la fila > guardado al compilar > recomendado > global
            return [
                $escritos_fuera[$id]
                    ?? (isset($guardados[$id]->tope_fuera_min) ? (int) $guardados[$id]->tope_fuera_min : null)
                    ?? $recomendado[0]
                    ?? $fuera,
                $escritos_dentro[$id]
                    ?? (isset($guardados[$id]->tope_dentro_min) ? (int) $guardados[$id]->tope_dentro_min : null)
                    ?? $recomendado[1]
                    ?? $dentro,
            ];
        }

        return [$recomendado[0] ?? $fuera, $recomendado[1] ?? $dentro];
    }

    //------------------- lectura -------------------

    /**
     * Catalogo de plantas (`locales`) con su departamento resuelto.
     *
     * @return array<string, object>
     */
    public static function plantas(): array
    {
        if (self::$plantas !== null) {
            return self::$plantas;
        }

        $mapa = [];
        try {
            foreach (DB::table('locales')->orderBy('nombre')->get() as $local) {
                $mapa[(string) $local->id] = (object) [
                    'id' => (string) $local->id,
                    'nombre' => (string) $local->nombre,
                    'palabra_clave' => (string) $local->palabra_clave,
                    'ubigeo' => $local->distrito_ubigeo,
                    'departamento' => DBTiemposPromedio::nombre_departamento(substr((string) $local->distrito_ubigeo, 0, 2)),
                ];
            }
        } catch (\Throwable $e) {
            $mapa = [];
        }

        return self::$plantas = $mapa;
    }

    public static function nombre($planta_id): string
    {
        return self::plantas()[(string) $planta_id]->nombre ?? (string) $planta_id;
    }

    /**
     * Ficha de la planta: la del catalogo `locales` o, si no esta ahi y su id
     * es un ubigeo de seis digitos, el nombre y el departamento del distrito.
     * Las plantas sin local se guardaban con su ubigeo como nombre.
     */
    private static function ficha(string $id, ?object $actual = null): object
    {
        if ($actual !== null) {
            return $actual;
        }

        $distrito = DBTiemposPromedio::nombre_distrito($id);

        return (object) [
            'id' => $id,
            'nombre' => $distrito ?? $id,
            'palabra_clave' => null,
            'ubigeo' => $distrito !== null ? $id : null,
            'departamento' => DBTiemposPromedio::nombre_departamento(substr($id, 0, 2)),
        ];
    }

    /**
     * Tabla de la pantalla: una fila por planta con sus promedios fusionados,
     * el tope aplicado y el tope recomendado.
     *
     * @param  array<string, int>  $topes_fuera  planta => minutos escritos en la fila
     * @param  array<string, int>  $topes_dentro
     */
    public static function por_planta(
        string $modo = 'recomendado',
        ?int $fuera = null,
        ?int $dentro = null,
        ?string $planta_id = null,
        ?string $departamento = null,
        string $orden = 'viajes',
        array $topes_fuera = [],
        array $topes_dentro = [],
        int $min_viajes = 0,
        bool $ocultar_compiladas = false
    ): object {
        $modo = array_key_exists($modo, self::MODOS) ? $modo : 'recomendado';
        $acumulados = self::acumulados($planta_id);
        $guardados = self::guardados();
        $plantas = self::plantas();

        $salida = [];
        $ocultas = 0;
        $ocultas_min = 0;

        foreach ($acumulados as $id => $planta) {
            $id = (string) $id;
            $lados = count($planta->lados);

            if ($lados === 0) {
                continue;
            }

            $recomendado = self::recomendado($planta);
            [$tope_fuera, $tope_dentro] = self::tope($modo, $id, $recomendado, $guardados, $topes_fuera, $topes_dentro, $fuera, $dentro);

            $medida = self::medir($planta->lados, $tope_fuera, $tope_dentro);

            if ($ocultar_compiladas && isset($guardados[$id])) {
                $ocultas++;
                continue;
            }
            if ($min_viajes > 0 && $medida->viajes < $min_viajes) {
                $ocultas_min++;
                continue;
            }

            $descartados = $medida->descartados_fuera + $medida->descartados_dentro;
            $prom_fuera = self::promedio($medida, 0);
            $prom_dentro = self::promedio($medida, 3);
            $ficha = self::ficha($id, $plantas[$id] ?? null);

            $salida[] = (object) [
                'planta_id' => $id,
                'nombre' => $ficha->nombre,
                'palabra_clave' => $ficha->palabra_clave,
                'departamento' => $ficha->departamento,
                'rutas_carga' => count($planta->rutas_carga),
                'rutas_descarga' => count($planta->rutas_descarga),
                'viajes_carga' => $medida->viajes_carga,
                'viajes_descarga' => $medida->viajes_descarga,
                'viajes' => $medida->viajes,
                'lados' => $lados,
                'descartados_fuera' => $medida->descartados_fuera,
                'descartados_dentro' => $medida->descartados_dentro,
                'descartados' => $descartados,
                'pct_descartado' => $lados > 0 ? round(100 * $descartados / $lados, 1) : null,
                'prom_fuera_carga_min' => self::promedio($medida, 1),
                'prom_dentro_carga_min' => self::promedio($medida, 4),
                'prom_fuera_descarga_min' => self::promedio($medida, 2),
                'prom_dentro_descarga_min' => self::promedio($medida, 5),
                'prom_fuera_min' => $prom_fuera,
                'prom_dentro_min' => $prom_dentro,
                'prom_espera_min' => $prom_fuera !== null && $prom_dentro !== null
                    ? round($prom_fuera + $prom_dentro, 1)
                    : ($prom_fuera ?? $prom_dentro),
                'percentil_rec' => $recomendado[2],
                'p82_fuera_min' => $recomendado[0],
                'p82_dentro_min' => $recomendado[1],
                'tope_fuera_min' => $tope_fuera,
                'tope_dentro_min' => $tope_dentro,
                'guardado' => $guardados[$id] ?? null,
            ];
        }

        // el departamento de la planta se filtra en PHP: son pocas y no hace
        // falta ensuciar el SQL con el ubigeo del local. El filtro llega como
        // codigo de dos digitos, asi que se compara contra el nombre resuelto.
        if ($departamento !== null && $departamento !== '') {
            $nombre_departamento = DBTiemposPromedio::nombre_departamento($departamento) ?? $departamento;
            $salida = array_values(array_filter($salida, fn ($fila) => ($fila->departamento ?? '') === $nombre_departamento));
        }

        $orden = array_key_exists($orden, self::ORDENES) ? $orden : 'viajes';
        usort($salida, function ($a, $b) use ($orden) {
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
                        ?: ($b->lados <=> $a->lados);

                case 'planta':
                    return strcasecmp($a->nombre, $b->nombre)
                        ?: strcasecmp($a->planta_id, $b->planta_id);

                default:
                    return ($b->viajes <=> $a->viajes)
                        ?: ($b->lados <=> $a->lados);
            }
        });

        return (object) [
            'filas' => $salida,
            'resumen' => self::resumen($salida),
            'ocultas' => $ocultas,
            'ocultas_min' => $ocultas_min,
        ];
    }

    /**
     * Resumen de la tabla: cada planta pesa igual, como en el resumen por ruta.
     *
     * @param  array<int, object>  $filas
     */
    private static function resumen(array $filas): object
    {
        $resumen = (object) [
            'plantas' => 0,
            'plantas_afectadas' => 0,
            'lados' => 0,
            'viajes' => 0,
            'descartados' => 0,
            'descartados_fuera' => 0,
            'descartados_dentro' => 0,
            'pct_descartado' => null,
            'prom_fuera_min' => null,
            'prom_dentro_min' => null,
            'prom_espera_min' => null,
            'sin_promedio' => 0,
        ];

        $suma_fuera = 0.0;
        $suma_dentro = 0.0;
        $suma_espera = 0.0;
        $con_fuera = 0;
        $con_dentro = 0;
        $con_espera = 0;

        foreach ($filas as $fila) {
            $resumen->plantas++;
            $resumen->lados += $fila->lados;
            $resumen->viajes += $fila->viajes;
            $resumen->descartados += $fila->descartados;
            $resumen->descartados_fuera += $fila->descartados_fuera;
            $resumen->descartados_dentro += $fila->descartados_dentro;

            if ($fila->descartados > 0) {
                $resumen->plantas_afectadas++;
            }
            if ($fila->viajes === 0) {
                $resumen->sin_promedio++;
            }
            if ($fila->prom_fuera_min !== null) {
                $suma_fuera += (float) $fila->prom_fuera_min;
                $con_fuera++;
            }
            if ($fila->prom_dentro_min !== null) {
                $suma_dentro += (float) $fila->prom_dentro_min;
                $con_dentro++;
            }
            if ($fila->prom_espera_min !== null) {
                $suma_espera += (float) $fila->prom_espera_min;
                $con_espera++;
            }
        }

        $resumen->prom_fuera_min = $con_fuera > 0 ? round($suma_fuera / $con_fuera, 1) : null;
        $resumen->prom_dentro_min = $con_dentro > 0 ? round($suma_dentro / $con_dentro, 1) : null;
        $resumen->prom_espera_min = $con_espera > 0 ? round($suma_espera / $con_espera, 1) : null;
        $resumen->pct_descartado = $resumen->lados > 0
            ? round(100 * $resumen->descartados / $resumen->lados, 2)
            : null;

        return $resumen;
    }

    //------------------- compilacion -------------------

    /**
     * Compila las plantas con los topes elegidos. Solo se reescriben las
     * plantas con lados medibles: una planta que se queda sin viajes dentro del
     * tope conserva lo que ya tenia guardado.
     *
     * Con `$seleccion` (ids de planta) solo se compilan esas: es el boton de
     * las marcadas de la tabla, que usa el tope escrito en la fila de cada una.
     *
     * @param  array<string, int>  $topes_fuera
     * @param  array<string, int>  $topes_dentro
     * @param  array<int, string>  $seleccion
     */
    public static function compilar(
        string $modo = 'recomendado',
        ?int $fuera = null,
        ?int $dentro = null,
        array $topes_fuera = [],
        array $topes_dentro = [],
        ?string $planta_id = null,
        array $seleccion = []
    ): object {
        if (!self::tabla_lista()) {
            throw new \RuntimeException('Falta la tabla tiempos_planta_promedio: aplica la migración.');
        }

        $modo = array_key_exists($modo, self::MODOS) ? $modo : 'recomendado';
        $tabla = self::por_planta($modo, $fuera, $dentro, $planta_id, null, 'planta', $topes_fuera, $topes_dentro, 0, false);

        $marcadas = [];
        foreach ($seleccion as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $marcadas[$id] = true;
            }
        }

        $columnas = [
            'nombre', 'palabra_clave', 'ubigeo', 'departamento', 'modo',
            'rutas_carga', 'rutas_descarga', 'viajes_carga', 'viajes_descarga', 'viajes',
            'descartados_fuera', 'descartados_dentro',
            'prom_fuera_carga_min', 'prom_dentro_carga_min',
            'prom_fuera_descarga_min', 'prom_dentro_descarga_min',
            'prom_fuera_min', 'prom_dentro_min', 'prom_espera_min', 'percentil',
            'p82_fuera_min', 'p82_dentro_min', 'tope_fuera_min', 'tope_dentro_min',
            'actualizado_en',
        ];

        $ahora = now();
        $lotes = [];
        $plantas = 0;
        $viajes = 0;
        $descartados = 0;

        foreach ($tabla->filas as $fila) {
            if ($fila->viajes === 0) {
                continue;
            }
            if ($marcadas !== [] && !isset($marcadas[(string) $fila->planta_id])) {
                continue;
            }

            $actual = self::ficha((string) $fila->planta_id, self::plantas()[$fila->planta_id] ?? null);

            $lotes[] = [
                'planta_id' => $fila->planta_id,
                'nombre' => $actual->nombre,
                'palabra_clave' => $actual->palabra_clave,
                'ubigeo' => $actual->ubigeo,
                'departamento' => $actual->departamento,
                'modo' => $modo,
                'rutas_carga' => $fila->rutas_carga,
                'rutas_descarga' => $fila->rutas_descarga,
                'viajes_carga' => $fila->viajes_carga,
                'viajes_descarga' => $fila->viajes_descarga,
                'viajes' => $fila->viajes,
                'descartados_fuera' => $fila->descartados_fuera,
                'descartados_dentro' => $fila->descartados_dentro,
                'prom_fuera_carga_min' => $fila->prom_fuera_carga_min,
                'prom_dentro_carga_min' => $fila->prom_dentro_carga_min,
                'prom_fuera_descarga_min' => $fila->prom_fuera_descarga_min,
                'prom_dentro_descarga_min' => $fila->prom_dentro_descarga_min,
                'prom_fuera_min' => $fila->prom_fuera_min,
                'prom_dentro_min' => $fila->prom_dentro_min,
                'prom_espera_min' => $fila->prom_espera_min,
                'percentil' => $fila->percentil_rec,
                'p82_fuera_min' => $fila->p82_fuera_min,
                'p82_dentro_min' => $fila->p82_dentro_min,
                'tope_fuera_min' => $fila->tope_fuera_min,
                'tope_dentro_min' => $fila->tope_dentro_min,
                'actualizado_en' => $ahora,
            ];

            $plantas++;
            $viajes += $fila->viajes;
            $descartados += $fila->descartados;
        }

        foreach (array_chunk($lotes, 200) as $lote) {
            DB::table('tiempos_planta_promedio')->upsert($lote, ['planta_id'], $columnas);
        }

        return (object) [
            'plantas' => $plantas,
            'viajes' => $viajes,
            'descartados' => $descartados,
            'modo' => $modo,
            'tope_fuera' => $fuera,
            'tope_dentro' => $dentro,
        ];
    }

    /**
     * Borra el promedio guardado de las plantas indicadas. Sin ids no borra
     * nada: la pantalla pide marcar las plantas a eliminar.
     *
     * @param  array<int, string>  $seleccion
     */
    public static function eliminar(array $seleccion = []): int
    {
        if (!self::tabla_lista()) {
            return 0;
        }

        $ids = [];
        foreach ($seleccion as $id) {
            $id = trim((string) $id);
            // sin claves numericas: `planta_id` es texto y un id numerico
            // comparado como entero romperia la consulta en MySQL
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        $borradas = 0;
        foreach (array_chunk($ids, 500) as $lote) {
            $borradas += DB::table('tiempos_planta_promedio')->whereIn('planta_id', $lote)->delete();
        }

        return $borradas;
    }

    //------------------- estado -------------------

    /**
     * Si la tabla existe (migracion aplicada). Se cachea por request.
     */
    public static function tabla_lista(): bool
    {
        if (self::$tabla_lista !== null) {
            return self::$tabla_lista;
        }

        try {
            self::$tabla_lista = Schema::hasTable('tiempos_planta_promedio');
        } catch (\Throwable $e) {
            self::$tabla_lista = false;
        }

        return self::$tabla_lista;
    }

    /**
     * Plantas ya compiladas, indexadas por planta.
     *
     * @return array<string, object>
     */
    public static function guardados(): array
    {
        if (!self::tabla_lista()) {
            return [];
        }

        $salida = [];
        foreach (DB::select('SELECT * FROM tiempos_planta_promedio') as $fila) {
            $salida[(string) $fila->planta_id] = $fila;
        }

        return $salida;
    }

    /**
     * Estado de la compilacion de plantas.
     */
    public static function estado(): object
    {
        $estado = (object) [
            'tabla' => self::tabla_lista(),
            'plantas' => 0,
            'viajes' => 0,
            'ultima' => null,
        ];

        if (!$estado->tabla) {
            return $estado;
        }

        $fila = DB::selectOne('
            SELECT COUNT(*) AS plantas,
                   COALESCE(SUM(viajes), 0) AS viajes,
                   MAX(actualizado_en) AS ultima
            FROM tiempos_planta_promedio');

        $estado->plantas = (int) ($fila->plantas ?? 0);
        $estado->viajes = (int) ($fila->viajes ?? 0);
        $estado->ultima = $fila->ultima ?? null;

        return $estado;
    }
}
