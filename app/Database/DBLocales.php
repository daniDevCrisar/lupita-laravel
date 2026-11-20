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
                'l.lat',
                'l.lng',
                'l.geo_texto',
                'l.geo_fuente',
                'l.geo_actualizado_en',
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
                'l.lat',
                'l.lng',
                'l.geo_texto',
                'l.geo_fuente',
                'l.geo_actualizado_en',
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
        // coordenadas: o las dos o ninguna. Si no hay lat/lng, la metadata geo_* se
        // limpia para no quedar con una "fuente" que no describe ninguna coordenada.
        $con_coordenadas = ($datos['lat'] ?? '') !== '' && ($datos['lng'] ?? '') !== '';

        return [
            'cliente_id'      => $datos['cliente_id'],
            'nombre'          => $datos['nombre'],
            'palabra_clave'   => $datos['palabra_clave'],
            'distrito_ubigeo' => ($datos['distrito_ubigeo'] ?? '') !== ''
                ? $datos['distrito_ubigeo']
                : null,
            'lat'             => $con_coordenadas ? $datos['lat'] : null,
            'lng'             => $con_coordenadas ? $datos['lng'] : null,
            'geo_texto'       => $con_coordenadas && ($datos['geo_texto'] ?? '') !== ''
                ? mb_substr((string) $datos['geo_texto'], 0, 200)
                : null,
            'geo_fuente'      => $con_coordenadas && ($datos['geo_fuente'] ?? '') !== ''
                ? mb_substr((string) $datos['geo_fuente'], 0, 20)
                : ($con_coordenadas ? 'manual' : null),
            'geo_actualizado_en' => $con_coordenadas ? now() : null,
            'activo'          => (int) ($datos['activo'] ?? 1),
        ];
    }

    /**
     * Guarda SOLO las coordenadas de un local (lo usa la pantalla "Desde Google Maps",
     * donde el resto del catalogo no se toca).
     */
    public static function actualizarCoordenadas(
        string $id,
        float $lat,
        float $lng,
        ?string $texto = null,
        string $fuente = 'maps'
    ): void {
        DB::table('locales')->where('id', $id)->update([
            'lat'                => $lat,
            'lng'                => $lng,
            'geo_texto'          => $texto !== null ? mb_substr($texto, 0, 200) : null,
            'geo_fuente'         => mb_substr($fuente, 0, 20),
            'geo_actualizado_en' => now(),
        ]);
    }
}
