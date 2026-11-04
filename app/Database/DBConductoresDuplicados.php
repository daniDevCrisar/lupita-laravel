<?php

namespace App\Database;

use App\Database\Tmp\DBTmpLotes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deteccion y fusion de conductores duplicados.
 *
 * El caso que resuelve es el que dejo la importacion: varias filas de
 * `conductores` con el mismo nombre normalizado (46 grupos, 181 filas de mas al
 * momento de escribir esto). Las llamadas quedaron repartidas entre esos ids,
 * asi que reportes y promedios por conductor salen partidos.
 *
 * Alcance a proposito: agrupa por nombre IDENTICO (lo que ya esta mal) y no por
 * similitud. Los parecidos ("JOSE GARCIA" dentro de "REQUEJO GARCIA JOSE
 * CATALINO") pueden ser personas distintas, asi que solo se listan como
 * referencia con su porcentaje y nunca se fusionan desde aqui.
 *
 * La fusion no borra filas: mueve las llamadas, referencias y telefonos al id
 * elegido y deja los absorbidos con activo = 0. Asi se puede auditar y, si algo
 * salio mal, reactivar el id original.
 */
class DBConductoresDuplicados
{
    /** Tope de grupos que se listan de una vez. */
    public const LIMITE_GRUPOS = 300;

    /**
     * Grupos de conductores que comparten exactamente el mismo nombre.
     *
     * Devuelve, por grupo: el nombre, una fila por conductor con sus conteos
     * (llamadas, referencias, telefonos, logs) y el id sugerido como destino.
     *
     *  - $q: filtro por nombre (se pasa a mayusculas como esta guardado).
     *  - $solo_varios_activos: muestra unicamente los grupos con mas de un
     *    conductor activo. Por defecto se muestran todos, porque los sobrantes
     *    suelen estar inactivos y aun asi tienen las llamadas.
     */
    public static function grupos(string $q = '', bool $solo_varios_activos = false, int $limite = self::LIMITE_GRUPOS): array
    {
        $params = [];
        $where = "nombres <> ''";
        if ($q !== '') {
            $where .= " AND nombres LIKE ?";
            $params[] = '%' . mb_strtoupper(trim($q)) . '%';
        }

        $having = $solo_varios_activos
            ? 'HAVING COUNT(*) > 1 AND SUM(activo = 1) > 1'
            : 'HAVING COUNT(*) > 1';

        $nombres = [];
        foreach (DB::select(
            "SELECT nombres
             FROM conductores
             WHERE $where
             GROUP BY nombres
             $having
             ORDER BY COUNT(*) DESC, nombres ASC
             LIMIT " . (int) $limite,
            $params
        ) as $r) {
            $nombres[] = $r->nombres;
        }

        if (!$nombres) return [];

        $in = implode(',', array_fill(0, count($nombres), '?'));
        $filas = DB::select(
            "SELECT id, nombres, activo, sis_id, vapi_id, last_id_log, created_at
             FROM conductores
             WHERE nombres IN ($in)
             ORDER BY nombres ASC, id ASC",
            $nombres
        );

        $ids = array_map(fn($f) => (int) $f->id, $filas);
        $cont = [
            'llamadas'    => self::agregado('llamadas', 'conductor_id', $ids, 'COUNT(*) c, MAX(created_at) ultima'),
            'referencias' => self::agregado('referencias', 'conductor_id', $ids),
            'telefonos'   => self::agregado('tlf_conductores', 'conductor_id', $ids),
            'logs'        => self::agregado('log_conductores', 'id_conductor', $ids),
        ];

        $grupos = [];
        foreach ($filas as $f) {
            $id = (int) $f->id;

            if (!isset($grupos[$f->nombres])) {
                $grupos[$f->nombres] = [
                    'nombres'      => $f->nombres,
                    'filas'        => [],
                    'conductores'  => 0,
                    'activos'      => 0,
                    'llamadas'     => 0,
                    'referencias'  => 0,
                    'telefonos'    => 0,
                    'logs'         => 0,
                    'sugerido'     => $id,
                    'sugerido_por' => '',
                    'parecidos'    => [],
                ];
            }

            $llamadas = (int) ($cont['llamadas'][$id]->c ?? 0);
            $activo = (int) $f->activo;

            $grupos[$f->nombres]['filas'][] = [
                'id'          => $id,
                'nombres'     => $f->nombres,
                'activo'      => $activo,
                'sis_id'      => $f->sis_id,
                'vapi_id'     => $f->vapi_id,
                'last_id_log' => (int) $f->last_id_log,
                'creado'      => (string) $f->created_at,
                'llamadas'    => $llamadas,
                'ultima'      => $cont['llamadas'][$id]->ultima ?? null,
                'referencias' => (int) ($cont['referencias'][$id]->c ?? 0),
                'telefonos'   => (int) ($cont['telefonos'][$id]->c ?? 0),
                'logs'        => (int) ($cont['logs'][$id]->c ?? 0),
            ];

            $grupos[$f->nombres]['conductores']++;
            $grupos[$f->nombres]['activos'] += $activo;
            $grupos[$f->nombres]['llamadas'] += $llamadas;
            $grupos[$f->nombres]['referencias'] += (int) ($cont['referencias'][$id]->c ?? 0);
            $grupos[$f->nombres]['telefonos'] += (int) ($cont['telefonos'][$id]->c ?? 0);
            $grupos[$f->nombres]['logs'] += (int) ($cont['logs'][$id]->c ?? 0);
        }

        // Un grupo solo necesita fusion si hay mas de un id "vivo": activo o con
        // datos (llamadas, referencias, telefonos). Despues de fusionar quedan los
        // absorbidos inactivos y vacios, asi que el grupo deja de aparecer en vez
        // de repetirse como si no se hubiera hecho nada.
        foreach ($grupos as $k => $g) {
            $vivos = 0;
            foreach ($g['filas'] as $f) {
                if ($f['activo'] || $f['llamadas'] || $f['referencias'] || $f['telefonos']) {
                    $vivos++;
                }
            }
            if ($vivos < 2) unset($grupos[$k]);
        }

        // Destino sugerido: el activo con mas llamadas; si empatan, el mas antiguo.
        foreach ($grupos as $k => $g) {
            $orden = $g['filas'];
            usort($orden, function ($a, $b) {
                if ($a['activo'] !== $b['activo']) return $b['activo'] <=> $a['activo'];
                if ($a['llamadas'] !== $b['llamadas']) return $b['llamadas'] <=> $a['llamadas'];
                return $a['id'] <=> $b['id'];
            });
            $grupos[$k]['sugerido'] = $orden[0]['id'];
            $grupos[$k]['sugerido_por'] = $orden[0]['activo']
                ? ($orden[0]['llamadas'] ? 'más llamadas entre los activos' : 'activo más antiguo')
                : 'sin activos: es el que más llamadas tiene';

            // Nombres de otros grupos que se parecen a este: es informacion de
            // lectura, no se fusionan aqui.
            $grupos[$k]['parecidos'] = self::parecidos($g['nombres']);
        }

        return array_values($grupos);
    }

    /**
     * Id que absorbe al resto del grupo.
     *
     * Aplica la misma regla que `grupos()`: el activo con mas llamadas y, si
     * empatan, el mas antiguo; si no hay activos, el que mas llamadas tiene.
     * Se excluyen los ids de `$excluir` (los parecidos, que se absorben).
     */
    public static function elegido(string $nombres, array $excluir = []): ?int
    {
        $excluir = array_values(array_filter(array_map('intval', $excluir), fn($id) => $id > 0));
        $filtro = $excluir ? ' AND c.id NOT IN (' . implode(',', array_fill(0, count($excluir), '?')) . ')' : '';

        $f = DB::selectOne(
            "SELECT c.id
             FROM conductores c
             WHERE c.nombres = ?$filtro
             ORDER BY c.activo DESC,
                      (SELECT COUNT(*) FROM llamadas l WHERE l.conductor_id = c.id) DESC,
                      c.id ASC
             LIMIT 1",
            array_merge([$nombres], $excluir)
        );

        return $f ? (int) $f->id : null;
    }

    /** Totales para la cabecera de la pantalla. */
    public static function totales(array $grupos): array
    {
        $t = ['grupos' => count($grupos), 'conductores' => 0, 'sobrantes' => 0, 'llamadas' => 0, 'referencias' => 0, 'telefonos' => 0];

        foreach ($grupos as $g) {
            $t['conductores'] += $g['conductores'];
            $t['llamadas'] += $g['llamadas'];
            $t['referencias'] += $g['referencias'];
            $t['telefonos'] += $g['telefonos'];
        }
        $t['sobrantes'] = max(0, $t['conductores'] - $t['grupos']);

        return $t;
    }

    /**
     * Fusiona los grupos recibidos del formulario.
     *
     * $fusion = [
     *     'nombres'   => nombre del grupo,
     *     'aplicar'   => 1 si se marca el grupo,
     *     'parecidos' => [id con nombre parecido confirmado, ...],
     * ]
     *
     * El destino no lo elige el usuario: sale de `elegido()` con el nombre del
     * grupo, y el resto de los que comparten ese nombre se absorbe. Tambien
     * acepta 'principal' + 'ids' para uso programatico.
     *
     * Cada grupo va en su propia transaccion: si uno falla (por ejemplo porque
     * los ids ya no comparten el nombre) los demas siguen y el error se informa.
     */
    public static function fusionar_varios(array $fusion, bool $mover_logs = false): array
    {
        $res = [
            'grupos' => 0, 'conductores' => 0, 'parecidos' => 0, 'llamadas' => 0,
            'referencias' => 0, 'telefonos' => 0, 'logs' => 0,
            'renombrados' => [], 'errores' => [],
        ];

        foreach ($fusion as $g) {
            $grupo = trim((string) ($g['nombres'] ?? ''));
            $principal = (int) ($g['principal'] ?? 0);

            // Modo pantalla: solo llega el nombre del grupo marcado, el destino
            // lo decide el servidor con la regla de `elegido()`.
            if (!$principal && $grupo !== '' && !empty($g['aplicar'])) {
                $principal = (int) self::elegido($grupo, (array) ($g['parecidos'] ?? []));
            }
            if (!$principal) continue;

            $ids = self::limpiar_ids($g['ids'] ?? [], $principal);
            $pares = array_values(array_diff(self::limpiar_ids($g['parecidos'] ?? [], $principal), $ids));
            $principal_declarado = $principal > 0;
            $elegido_grupo = 0;

            try {
                $nombre = $grupo !== ''
                    ? $grupo
                    : (string) DB::table('conductores')->where('id', $principal)->value('nombres');

                if ($nombre === '') {
                    throw new \RuntimeException('el grupo no existe');
                }

                $elegido_grupo = (int) self::elegido($nombre, $pares);

                // Modo pantalla: se marca el grupo entero y el servidor deduce los
                // sobrantes (todos los que comparten el nombre). Asi no depende de
                // la lista de ids que mande el navegador.
                if (!$ids && !empty($g['aplicar'])) {
                    $ids = self::limpiar_ids(
                        DB::table('conductores')
                            ->where('nombres', $nombre)
                            ->where('id', '<>', $principal)
                            ->pluck('id')->all(),
                        $principal
                    );
                    $pares = array_values(array_diff($pares, $ids));
                }

                if (!$ids && !$pares) continue;

                // El destino puede ser un parecido: en ese caso absorbe al grupo
                // entero y tiene que venir marcado a mano y seguir siendo parecido.
                if ($principal_declarado && $principal !== $elegido_grupo) {
                    if (!in_array($principal, array_map('intval', (array) ($g['parecidos'] ?? [])), true)) {
                        throw new \RuntimeException("destino #{$principal} sin confirmar como parecido");
                    }
                    $validos = array_column(self::parecidos($nombre, 60.0, 20), 'id');
                    if (!in_array($principal, $validos, true)) {
                        throw new \RuntimeException("#{$principal} ya no es un parecido del grupo");
                    }
                }

                if ($pares) {
                    // El formulario no manda el permiso de fusionar nombres
                    // distintos: se recalcula aqui con el mismo criterio de la
                    // pantalla y solo se aceptan los que siguen siendo parecidos.
                    $validos = array_column(self::parecidos($nombre, 60.0, 20), 'id');
                    $sobran = array_diff($pares, $validos);
                    if ($sobran) {
                        throw new \RuntimeException('parecido sin confirmar: ' . implode(', ', array_map(fn($i) => "#$i", $sobran)));
                    }
                }

                $r = self::fusionar_grupo($principal, $ids, $pares, $mover_logs, $nombre);

                $res['grupos']++;
                $res['parecidos'] += count($pares);
                foreach (['conductores', 'llamadas', 'referencias', 'telefonos', 'logs'] as $k) {
                    $res[$k] += $r[$k];
                }
                if ($r['renombrado']) {
                    $res['renombrados'][] = $r['renombrado'];
                }
            } catch (Throwable $e) {
                $etiqueta = array_merge([$principal], $ids, $pares);
                $res['errores'][] = 'ids ' . implode(' / ', array_map(fn($i) => "#$i", $etiqueta)) . ': ' . $e->getMessage();
            }
        }

        return $res;
    }

    /** Normaliza la lista de ids que llega del formulario. */
    private static function limpiar_ids($ids, int $principal): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $ids),
            fn($id) => $id > 0 && $id !== $principal
        )));
    }

    /** True si $nuevo tiene mas palabras que $actual (o mas largo si empatan). */
    private static function mas_completo(string $nuevo, string $actual): bool
    {
        $pn = preg_split('/\s+/', trim($nuevo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pa = preg_split('/\s+/', trim($actual), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($pn) !== count($pa)) return count($pn) > count($pa);

        return strlen($nuevo) > strlen($actual);
    }

    /**
     * Fusion de un grupo dentro de una transaccion.
     *
     * Orden: se bloquean las filas de conductores, se valida que los ids del
     * mismo nombre lo compartan, se reapuntan llamadas, referencias, telefonos e
     * (opcional) historial, y recien al final se desactivan los absorbidos.
     *
     * Los `parecidos` son nombres distintos que el usuario confirmo a mano: el
     * nombre resultante es el mas completo de todos los implicados, para no
     * perder el apellido que traia el duplicado. Si el principal es uno de esos
     * parecidos, `$nombre_grupo` indica el nombre que comparten los `$ids`.
     */
    private static function fusionar_grupo(int $principal, array $ids, array $parecidos, bool $mover_logs, string $nombre_grupo = ''): array
    {
        $absorbidos = array_merge($ids, $parecidos);
        $todos = array_merge([$principal], $absorbidos);
        $in_todos = implode(',', array_fill(0, count($todos), '?'));
        $in_ids = implode(',', array_fill(0, count($absorbidos), '?'));

        return DB::transaction(function () use ($principal, $ids, $parecidos, $absorbidos, $todos, $in_todos, $in_ids, $mover_logs, $nombre_grupo) {
            $filas = DB::select(
                "SELECT id, nombres, activo FROM conductores WHERE id IN ($in_todos) FOR UPDATE",
                $todos
            );

            if (count($filas) !== count($todos)) {
                throw new \RuntimeException('alguno de los conductores ya no existe');
            }

            $nombres = [];
            foreach ($filas as $f) {
                $nombres[(int) $f->id] = $f->nombres;
            }

            // Salvaguarda: los ids del mismo grupo deben compartir el nombre del
            // grupo (que es el del principal salvo que absorba un parecido). Los
            // de `parecidos` son otra cosa y ya se validaron contra la BD.
            $base = $nombres[$principal] ?? '';
            $base_grupo = $nombre_grupo !== '' ? $nombre_grupo : $base;
            foreach ($ids as $id) {
                if (($nombres[$id] ?? null) !== $base_grupo) {
                    throw new \RuntimeException('no comparten el mismo nombre, no se fusiona');
                }
            }

            // Nombre resultante: el mas completo de todos los implicados.
            $final = $base;
            foreach ($absorbidos as $id) {
                if (self::mas_completo($nombres[$id], $final)) $final = $nombres[$id];
            }

            $llamadas = DB::update(
                "UPDATE llamadas SET conductor_id = ? WHERE conductor_id IN ($in_ids)",
                array_merge([$principal], $absorbidos)
            );
            $referencias = DB::update(
                "UPDATE referencias SET conductor_id = ? WHERE conductor_id IN ($in_ids)",
                array_merge([$principal], $absorbidos)
            );

            // Los telefonos tienen PK (conductor_id, telefono): INSERT IGNORE copia
            // los que falten y luego se borran los del duplicado.
            DB::statement(
                "INSERT IGNORE INTO tlf_conductores (conductor_id, telefono, activo, created_at)
                 SELECT ?, telefono, activo, created_at
                 FROM tlf_conductores
                 WHERE conductor_id IN ($in_ids)",
                array_merge([$principal], $absorbidos)
            );
            $telefonos = DB::delete(
                "DELETE FROM tlf_conductores WHERE conductor_id IN ($in_ids)",
                $absorbidos
            );

            $logs = 0;
            if ($mover_logs) {
                $logs = DB::update(
                    "UPDATE log_conductores SET id_conductor = ? WHERE id_conductor IN ($in_ids)",
                    array_merge([$principal], $absorbidos)
                );
                DB::update(
                    "UPDATE conductores
                     SET last_id_log = COALESCE((SELECT MAX(id_log_conductor) FROM log_conductores WHERE id_conductor = ?), 0)
                     WHERE id = ?",
                    [$principal, $principal]
                );
            }

            // El destino conserva su estado, salvo que este inactivo y algun
            // absorbido siga activo: en ese caso hereda la vigencia. Si todo el
            // grupo estaba inactivo se queda inactivo (no se resucita solo).
            $habia_activo = false;
            foreach ($filas as $f) {
                if ((int) $f->activo === 1) { $habia_activo = true; break; }
            }
            if ($habia_activo) {
                DB::update("UPDATE conductores SET activo = 1 WHERE id = ?", [$principal]);
            }
            if ($final !== $base) {
                DB::update("UPDATE conductores SET nombres = ? WHERE id = ?", [$final, $principal]);
            }
            DB::update("UPDATE conductores SET activo = 0 WHERE id IN ($in_ids)", $absorbidos);

            Log::info('conductores fusionados', [
                'principal'   => $principal,
                'absorbidos'  => $absorbidos,
                'parecidos'   => $parecidos,
                'nombre'      => $final,
                'llamadas'    => $llamadas,
                'referencias' => $referencias,
                'telefonos'   => $telefonos,
                'logs'        => $logs,
                'usuario'     => auth()->id(),
            ]);

            return [
                'conductores'  => count($absorbidos),
                'llamadas'     => $llamadas,
                'referencias'  => $referencias,
                'telefonos'    => $telefonos,
                'logs'         => $logs,
                'renombrado'   => $final !== $base ? "#{$principal} {$base} -> {$final}" : null,
            ];
        });
    }

    /**
     * Nombres de otros conductores que se parecen al del grupo.
     *
     * Es informacion de lectura: sirve para que el usuario vea que tan parecido
     * es lo que quedo fuera del grupo. Usa el mismo `similitud()` de DBTmpLotes
     * que la importacion, para que el porcentaje sea comparable.
     */
    private static function parecidos(string $nombres, float $minimo = 60.0, int $tope = 5): array
    {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nombres);

        $filas = DB::select(
            "SELECT id, nombres, activo
             FROM conductores
             WHERE nombres <> ''
               AND nombres <> ?
               AND (nombres LIKE ? OR ? LIKE CONCAT('%', nombres, '%'))
             ORDER BY activo DESC, CHAR_LENGTH(nombres) ASC
             LIMIT 20",
            [$nombres, '%' . $esc . '%', $nombres]
        );

        $base = self::normalizar($nombres);
        $res = [];
        foreach ($filas as $f) {
            $otro = self::normalizar($f->nombres);
            $sim = self::similitud($base, $otro);
            if ($sim < $minimo) continue;

            $res[] = [
                'id'        => (int) $f->id,
                'nombres'   => $f->nombres,
                'activo'    => (int) $f->activo,
                // similitud() premia la contencion de palabras y puede dar 100
                // aunque sobren apellidos; el % de texto es el que se muestra.
                'similitud' => round($sim),
                'texto'     => (int) round(self::texto($base, $otro)),
            ];
        }

        usort($res, fn($a, $b) => [$b['similitud'], $b['texto']] <=> [$a['similitud'], $a['texto']]);

        return array_slice($res, 0, $tope);
    }

    /** Porcentaje de texto compartido (caracteres), sin los bonus de palabras. */
    private static function texto(string $a, string $b): float
    {
        similar_text($a, $b, $pct);

        return (float) $pct;
    }

    private static function normalizar(string $texto): string
    {
        return DBTmpLotes::normalizar($texto, false);
    }

    /**
     * `similitud()` de DBTmpLotes imprime avisos con echo en algunas de sus
     * penalizaciones; se silencian para no romper el HTML de la pantalla.
     */
    private static function similitud(string $a, string $b): float
    {
        ob_start();
        $sim = (float) DBTmpLotes::similitud($a, $b);
        ob_end_clean();

        return min(100.0, $sim);
    }

    /** Conteo por conductor para una tabla dada. */
    private static function agregado(string $tabla, string $columna, array $ids, string $select = 'COUNT(*) c'): array
    {
        if (!$ids) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $res = [];
        foreach (DB::select(
            "SELECT $columna id, $select FROM $tabla WHERE $columna IN ($in) GROUP BY $columna",
            $ids
        ) as $r) {
            $res[(int) $r->id] = $r;
        }

        return $res;
    }
}
