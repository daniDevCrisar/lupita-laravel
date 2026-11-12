<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * DBLocales
 *
 * Capa de datos del catalogo de locales/plantas (`locales`): listado, alta y
 * los catalogos que alimentan el formulario (clientes + ubigeo).
 *
 * El id del local es un texto armado a mano (ej: LOC_BABEL_MAXO) y la
 * `palabra_clave` es la que se busca dentro del titulo del viaje para saber a
 * que local pertenece la llamada. Los distritos salen de `ubigeo_distritos`
 * (6 digitos) y se guardan en `distrito_ubigeo`.
 */
class DBLocales
{
    /**
     * Clientes a los que se puede colgar un local.
     */
    public static function clientes(): array
    {
        return DB::table('clientes')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo', 'palabra_clave'])
            ->all();
    }

    /**
     * Locales con su distrito / provincia / departamento resueltos.
     */
    public static function listar(): array
    {
        return DB::table('locales as l')
            ->leftJoin('ubigeo_distritos as d', 'd.ubigeo', '=', 'l.distrito_ubigeo')
            ->leftJoin('ubigeo_provincias as p', 'p.id', '=', 'd.provincia_id')
            ->leftJoin('ubigeo_departamentos as dep', 'dep.id', '=', 'd.departamento_id')
            ->orderBy('l.cliente_id')
            ->orderBy('l.nombre')
            ->get([
                'l.id',
                'l.cliente_id',
                'l.nombre',
                'l.palabra_clave',
                'l.distrito_ubigeo',
                'l.activo',
                'd.distrito',
                'p.provincia',
                'dep.departamento',
            ])
            ->all();
    }

    /**
     * Arbol departamento > provincia > distrito para los selects en cascada.
     *
     * @return array<int, array{id:int, nombre:string, provincias:array}>
     */
    public static function ubigeo(): array
    {
        $arbol = [];

        foreach (DB::table('ubigeo_departamentos')->orderBy('departamento')->get() as $dep) {
            $arbol[(int) $dep->id] = [
                'id'      => (int) $dep->id,
                'nombre'  => (string) $dep->departamento,
                'provincias' => [],
            ];
        }

        foreach (DB::table('ubigeo_provincias')->orderBy('provincia')->get() as $prov) {
            $dep = (int) $prov->departamento_id;
            if (!isset($arbol[$dep])) {
                continue;
            }

            $arbol[$dep]['provincias'][(int) $prov->id] = [
                'id'         => (int) $prov->id,
                'nombre'     => (string) $prov->provincia,
                'distritos'  => [],
            ];
        }

        foreach (DB::table('ubigeo_distritos')->orderBy('distrito')->get() as $dist) {
            $dep = (int) $dist->departamento_id;
            $prov = (int) $dist->provincia_id;

            if (!isset($arbol[$dep]['provincias'][$prov])) {
                continue;
            }

            $arbol[$dep]['provincias'][$prov]['distritos'][] = [
                'ubigeo' => (string) $dist->ubigeo,
                'nombre' => (string) $dist->distrito,
            ];
        }

        // se reindexan las provincias para que viajen como lista JSON
        return array_values(array_map(static function (array $dep) {
            $dep['provincias'] = array_values($dep['provincias']);
            return $dep;
        }, $arbol));
    }

    /**
     * Un local con su distrito / provincia / departamento resueltos.
     */
    public static function buscar(string $id): ?object
    {
        return DB::table('locales as l')
            ->leftJoin('ubigeo_distritos as d', 'd.ubigeo', '=', 'l.distrito_ubigeo')
            ->leftJoin('ubigeo_provincias as p', 'p.id', '=', 'd.provincia_id')
            ->leftJoin('ubigeo_departamentos as dep', 'dep.id', '=', 'd.departamento_id')
            ->where('l.id', $id)
            ->first([
                'l.id',
                'l.cliente_id',
                'l.nombre',
                'l.palabra_clave',
                'l.distrito_ubigeo',
                'l.activo',
                'd.distrito',
                'p.provincia',
                'dep.departamento',
            ]);
    }

    /**
     * Alta de un local.
     */
    public static function crear(array $datos): void
    {
        DB::table('locales')->insert(self::fila($datos) + [
            'id' => $datos['id'],
        ]);
    }

    /**
     * Edicion de un local. El id no se toca: es la clave con la que apuntan los
     * viajes (rutas.origen_id / rutas.destino_id), asi que no se renombra.
     */
    public static function actualizar(string $id, array $datos): void
    {
        DB::table('locales')
            ->where('id', $id)
            ->update(self::fila($datos));
    }

    /**
     * Columnas editables del local, normalizadas tal como se guardan.
     */
    private static function fila(array $datos): array
    {
        return [
            'cliente_id'      => $datos['cliente_id'],
            'nombre'          => $datos['nombre'],
            'palabra_clave'   => $datos['palabra_clave'],
            'distrito_ubigeo' => ($datos['distrito_ubigeo'] ?? '') !== ''
                ? $datos['distrito_ubigeo']
                : null,
            'activo'          => (int) ($datos['activo'] ?? 1),
        ];
    }
}
