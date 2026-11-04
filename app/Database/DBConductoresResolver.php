<?php

namespace App\Database;

use App\Database\Tmp\DBTmpLotes;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Resuelve si el conductor de una llamada importada es nuevo, duplicado de uno
 * existente o una actualizacion de sus datos.
 *
 * Devuelve el MISMO contrato que DBConductores::buscar_duplicados()
 * (accion / id / row / comparar / buscado / comparado) para que el controlador
 * solo tenga que cambiar la llamada. DBConductores no se toca.
 *
 * Mejoras sobre el original, cada una con su motivo:
 *
 *  1. Antes del FULLTEXT busca el nombre exacto (WHERE nombres = ?). El FULLTEXT
 *     de InnoDB puntua suma de IDF por palabra y no normaliza por la longitud del
 *     documento, asi que un nombre contenido en otro empata exactamente
 *     (ARMANDO PEREZ y ARMANDO ALTAMIRANO PEREZ daban 8.8413 los dos) y con
 *     LIMIT 1 el registro identico quedaba fuera: se insertaba un duplicado.
 *  2. Si no hay exacto, trae hasta 10 candidatos del FULLTEXT y decide en PHP por
 *     similitud. El desempate es: identico, luego nombre mas completo, luego el
 *     id mas antiguo.
 *  3. Confirma con el telefono (tlf_conductores) cuando la similitud queda entre
 *     90 y 99. Sin esa confirmacion, "JOSE GARCIA" y "REQUEJO GARCIA JOSE
 *     CATALINO" se fusionan como si fueran la misma persona.
 *  4. "Obtengo mas datos" se mide por numero de palabras y despues por longitud,
 *     no solo con strlen (el candidato que devuelve el FULLTEXT suele ser el mas
 *     largo, por eso la rama de actualizacion casi nunca se activaba).
 *  5. Nombre vacio o de menos de 3 caracteres devuelve 'invalido' sin consultar
 *     ni insertar: el lote trae filas con conductor NULL y el original creaba
 *     registros con nombres vacios.
 *  6. No imprime nada (el original hacia echo de cada comparacion dentro del
 *     HTML de la importacion). Lo que antes se veia en pantalla ahora vuelve en
 *     'comparar', 'buscado', 'comparado', 'motivo' y 'candidatos'.
 */
class DBConductoresResolver
{
    /** Cuantos candidatos del FULLTEXT se evalúan por nombre. */
    private const MAX_CANDIDATOS = 10;

    /** Desde aca se considera el mismo conductor (misma regla que el original). */
    private const SIM_ACTUALIZAR = 99.0;

    /** Desde aca, sin llegar a SIM_ACTUALIZAR, se pide confirmacion por telefono. */
    private const SIM_REVISION = 90.0;

    /** Menos caracteres que esto no se busca ni se inserta. */
    private const LARGO_MINIMO = 3;

    /** Telefonos ya consultados en esta request: telefono => [conductor_id, ...] */
    private static $tlf_cache = [];

    /**
     * Punto de entrada. Mismo uso que DBConductores::buscar_duplicados().
     *
     * $item necesita 'conductor' (o 'nombres') y, si se quiere confirmar por
     * telefono, 'telefono'. No se modifica el objeto recibido.
     *
     * Opciones (todas con el comportamiento actual como valor por defecto):
     *  - incluir_inactivos: hoy solo se busca entre activos=1. En true tambien
     *    encuentra desactivados, pero el controlador no los reactiva.
     *  - confirmar_telefono: usa tlf_conductores para confirmar similitudes 90-99.
     *  - max_candidatos / sim_actualizar / sim_revision: umbrales.
     */
    public static function resolver($item, array $opciones = []): array
    {
        $op = array_merge([
            'incluir_inactivos' => false,
            'confirmar_telefono' => true,
            'max_candidatos'    => self::MAX_CANDIDATOS,
            'sim_actualizar'    => self::SIM_ACTUALIZAR,
            'sim_revision'      => self::SIM_REVISION,
        ], $opciones);

        // Clon defensivo: el original usaba serialize/unserialize para lo mismo.
        $row = clone $item;
        $original = trim((string) ($item->conductor ?? $item->nombres ?? ''));
        $nom = self::normalizar($original);
        $telefono = trim((string) ($item->telefono ?? ''));

        // 1) Nombre inutilizable: no hay nada que comparar y no debe crear basura.
        if ($nom === '' || mb_strlen($nom) < self::LARGO_MINIMO) {
            $row->conductor = $nom;
            return self::respuesta('invalido', null, $row, 0.0, $original, '', 'nombre_vacio');
        }

        // 2) Nombre exacto: manda sobre cualquier parecido del FULLTEXT.
        $exactos = self::candidatos_exactos($nom, $op['incluir_inactivos']);
        if ($exactos) {
            $mejor = $exactos[0];
            $row->id = $mejor['id'];
            $row->conductor = $mejor['nombres'];

            return self::respuesta('duplicado', $mejor['id'], $row, 100.0, $original,
                $mejor['nombres'], 'exacto', $exactos);
        }

        // 3) Parecidos: FULLTEXT y, si el nombre tiene palabras muy cortas que el
        //    indice ignora (min_token 3), un LIKE acotado como respaldo.
        $candidatos = self::candidatos_fulltext($nom, $op['max_candidatos'], $op['incluir_inactivos']);
        if (!$candidatos) {
            $candidatos = self::candidatos_like($nom, $op['max_candidatos'], $op['incluir_inactivos']);
        }

        if (!$candidatos) {
            return self::respuesta('nuevo', null, $row, 0.0, $original, '', 'sin_coincidencia');
        }

        $mejor = $candidatos[0];
        $sim = (float) $mejor['sim'];

        // El telefono solo se consulta cuando puede cambiar la decision.
        $ids_tlf = ($op['confirmar_telefono'] && $sim >= $op['sim_revision'] && $telefono !== '')
            ? self::ids_con_telefono($telefono)
            : [];
        $tel_coincide = $ids_tlf ? in_array((int) $mejor['id'], $ids_tlf, true) : null;

        // 4) Es el mismo conductor: parecido suficiente, o parecido medio con el
        //    telefono registrado a su nombre.
        $mismo = $sim >= $op['sim_actualizar'] || ($tel_coincide === true && $sim >= $op['sim_revision']);

        if ($mismo) {
            $row->id = $mejor['id'];

            if (self::mas_completo($nom, $mejor['nombres'])) {
                // Se conservan los ids del sistema que ya tiene el registro.
                $row->vapi_id = $mejor['vapi_id'];
                $row->sis_id  = $mejor['sis_id'];
                $row->conductor = $original;

                return self::respuesta('actualizar', $mejor['id'], $row, $sim, $original,
                    $mejor['nombres'], $tel_coincide === true ? 'telefono' : 'nombre', $candidatos, $tel_coincide);
            }

            $row->conductor = $mejor['nombres'];

            return self::respuesta('duplicado', $mejor['id'], $row, $sim, $original,
                $mejor['nombres'], $tel_coincide === true ? 'telefono' : 'nombre', $candidatos, $tel_coincide);
        }

        // 5) No se puede afirmar que sea la misma persona: se comporta como el
        //    original (nuevo), pero se deja marcado para revisar.
        return self::respuesta('nuevo', null, $row, $sim, $original, $mejor['nombres'],
            'sin_coincidencia', $candidatos, $tel_coincide, $sim >= $op['sim_revision']);
    }

    /** Limpia la cache de telefonos (por si se procesan varios lotes en un request). */
    public static function limpiar_cache(): void
    {
        self::$tlf_cache = [];
    }

    //----------------------- busqueda de candidatos -----------------------

    /**
     * Nombre exacto. La columna es utf8mb4_unicode_ci, asi que '=' tambien
     * encuentra 'MUNOZ' cuando en la tabla quedo 'MUÑOZ'. Se ordena por id para
     * quedarse con el registro mas antiguo cuando la duplicacion ya existe.
     */
    private static function candidatos_exactos(string $nom, bool $incluir_inactivos): array
    {
        $sql = "SELECT id, nombres, activo, sis_id, vapi_id
                FROM conductores
                WHERE nombres = ?" . ($incluir_inactivos ? '' : ' AND activo = 1') . "
                ORDER BY id ASC";

        $filas = [];
        foreach (DB::select($sql, [$nom]) as $r) {
            $filas[] = self::fila_candidato($r, 100.0);
        }

        return $filas;
    }

    /**
     * Candidatos por relevancia. Se traen varios porque la relevancia no
     * desempata por exactitud: se ordena primero por score, luego por el nombre
     * mas corto (el mas parecido al buscado) y por ultimo por id.
     */
    private static function candidatos_fulltext(string $nom, int $max, bool $incluir_inactivos): array
    {
        $sql = "SELECT id, nombres, activo, sis_id, vapi_id,
                       MATCH(nombres) AGAINST(?) AS score
                FROM conductores
                WHERE MATCH(nombres) AGAINST(?)" . ($incluir_inactivos ? '' : ' AND activo = 1') . "
                ORDER BY score DESC, CHAR_LENGTH(nombres) ASC, id ASC
                LIMIT " . (int) $max;

        $filas = [];
        foreach (DB::select($sql, [$nom, $nom]) as $r) {
            $filas[] = self::fila_candidato($r, self::similitud($nom, $r->nombres));
        }

        return self::ordenar($filas);
    }

    /**
     * Respaldo cuando el FULLTEXT no devuelve nada: pasa con nombres de una o dos
     * letras por palabra, que el indice descarta (innodb_ft_min_token_size = 3).
     */
    private static function candidatos_like(string $nom, int $max, bool $incluir_inactivos): array
    {
        if (!preg_match('/(^| )\S{1,2}( |$)/', $nom)) return [];

        $sql = "SELECT id, nombres, activo, sis_id, vapi_id
                FROM conductores
                WHERE nombres LIKE ?" . ($incluir_inactivos ? '' : ' AND activo = 1') . "
                ORDER BY CHAR_LENGTH(nombres) ASC, id ASC
                LIMIT " . (int) $max;

        $filas = [];
        foreach (DB::select($sql, ['%' . $nom . '%']) as $r) {
            $filas[] = self::fila_candidato($r, self::similitud($nom, $r->nombres));
        }

        return self::ordenar($filas);
    }

    private static function fila_candidato($r, float $sim): array
    {
        return [
            'id'      => (int) $r->id,
            'nombres' => (string) $r->nombres,
            'activo'  => (int) $r->activo,
            'sis_id'  => $r->sis_id,
            'vapi_id' => $r->vapi_id,
            'sim'     => round($sim, 2),
        ];
    }

    /**
     * Orden de preferencia: mayor similitud, luego activo, luego el nombre con
     * mas palabras (mas completo) y por ultimo el registro mas antiguo.
     */
    private static function ordenar(array $filas): array
    {
        usort($filas, function ($a, $b) {
            if ($a['sim'] != $b['sim']) return $b['sim'] <=> $a['sim'];
            if ($a['activo'] != $b['activo']) return $b['activo'] <=> $a['activo'];

            $pa = count(self::palabras($a['nombres']));
            $pb = count(self::palabras($b['nombres']));
            if ($pa != $pb) return $pb <=> $pa;

            return $a['id'] <=> $b['id'];
        });

        return $filas;
    }

    //----------------------- utilidades -----------------------

    /**
     * "Obtengo mas datos" = el nombre nuevo tiene mas palabras, o las mismas
     * palabras con un texto mas largo. Antes se comparaba solo strlen, y como el
     * FULLTEXT devuelve el nombre con mas palabras, la rama de actualizacion
     * quedaba practicamente muerta.
     */
    private static function mas_completo(string $nuevo, string $actual): bool
    {
        $pn = self::palabras($nuevo);
        $pa = self::palabras($actual);

        if (count($pn) !== count($pa)) return count($pn) > count($pa);

        return strlen($nuevo) > strlen($actual);
    }

    private static function palabras(string $texto): array
    {
        return preg_split('/\s+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Misma normalizacion que usan crear() y actualizar(), para comparar igual. */
    private static function normalizar(string $texto): string
    {
        return DBTmpLotes::normalizar($texto, false);
    }

    /**
     * Misma funcion de similitud del proyecto (100 = todas las palabras del
     * nombre corto estan dentro del largo), pero acotada a 100: la original
     * devuelve 125 o 111.67 por los bonus y el dato del log parecia un error.
     */
    private static function similitud(string $a, string $b): float
    {
        // DBTmpLotes::similitud() imprime sus avisos de penalizacion con echo; se
        // silencia para no ensuciar la respuesta de la importacion.
        ob_start();
        $sim = (float) DBTmpLotes::similitud($a, $b);
        ob_end_clean();

        return min(100.0, $sim);
    }

    /** Conductores que ya tienen registrado ese telefono (indice idx_telefono). */
    private static function ids_con_telefono(string $telefono): array
    {
        if ($telefono === '') return [];
        if (array_key_exists($telefono, self::$tlf_cache)) return self::$tlf_cache[$telefono];

        $ids = [];
        foreach (DB::select('SELECT conductor_id FROM tlf_conductores WHERE telefono = ?', [$telefono]) as $r) {
            $ids[] = (int) $r->conductor_id;
        }

        return self::$tlf_cache[$telefono] = $ids;
    }

    /**
     * Arma la respuesta con el contrato del original mas el detalle de la
     * decision (motivo, candidatos, telefono, revision).
     */
    private static function respuesta(
        string $accion,
        ?int $id,
        stdClass $row,
        float $comparar,
        string $buscado,
        string $comparado,
        string $motivo,
        array $candidatos = [],
        ?bool $telefono = null,
        bool $revision = false
    ): array {
        return [
            'accion'             => $accion,
            'id'                 => $id,
            'row'                => $row,
            'comparar'           => round($comparar, 2),
            'buscado'            => $buscado,
            'comparado'          => $comparado,
            'motivo'             => $motivo,
            'telefono_coincide'  => $telefono,
            'requiere_revision'  => $revision,
            'candidatos'         => array_map(function ($c) {
                return ['id' => $c['id'], 'nombres' => $c['nombres'], 'sim' => $c['sim']];
            }, $candidatos),
        ];
    }
}
