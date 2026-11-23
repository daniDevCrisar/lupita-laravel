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
        // OJO: el orden importa. `select()` fija las columnas y `selectSub()` agrega la
        // subconsulta; si se pasan columnas a `get()` despues de un selectSub, Laravel
        // las ignora y la consulta devuelve solo la subconsulta.
        return DB::table('locales as l')
            ->leftJoin('ubigeo_distritos as d', 'd.ubigeo', '=', 'l.distrito_ubigeo')
            ->leftJoin('ubigeo_provincias as p', 'p.id', '=', 'd.provincia_id')
            ->leftJoin('ubigeo_departamentos as dep', 'dep.id', '=', 'd.departamento_id')
            ->select([
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
            // cuantas rutas usan este local: la vista lo necesita para saber si se
            // puede borrar (un local usado por una ruta no se borra nunca)
            ->selectSub(
                DB::table('rutas')
                    ->selectRaw('COUNT(*)')
                    ->whereRaw('rutas.origen_id = l.id OR rutas.destino_id = l.id'),
                'rutas_count'
            )
            ->orderBy('l.cliente_id')
            ->orderBy('l.nombre')
            ->get()
            ->all();
    }

    /**
     * Locales cuya `palabra_clave` tiene una variante VACIA (un `|` de mas).
     *
     * POR QUE ES GRAVE: el SP arma el patron `\b(<palabra_clave>)\b`. Si la clave
     * termina en `|` (o tiene `||`), el patron queda con una alternancia vacia:
     * `\b(SALEM PIURA|ALMACEN PIURA|)\b`, y eso **matchea cualquier texto**. Verificado
     * contra MySQL: `'CUALQUIER COSA' REGEXP '\b(A|B|)\b'` devuelve 1. Ese local se
     * vuelve un comodin que se lleva todas las refs que no matchearon antes (gana el
     * primero del alfabeto) y contamina rutas en silencio.
     *
     * @return array<int, array{id: string, palabra_clave: string, vacias: int}>
     */
    public static function palabrasClavePeligrosas(): array
    {
        $salida = [];

        foreach (DB::table('locales')->orderBy('id')->get(['id', 'palabra_clave']) as $local) {
            $clave = (string) ($local->palabra_clave ?? '');
            $vacias = count(array_filter(
                explode('|', $clave),
                fn (string $variante) => trim($variante) === ''
            ));

            if ($vacias > 0) {
                $salida[] = [
                    'id'            => (string) $local->id,
                    'palabra_clave' => $clave,
                    'vacias'        => $vacias,
                ];
            }
        }

        return $salida;
    }

    /**
     * Que tiene vinculado un local: si algo de esto es > 0, no se puede borrar.
     *
     * Las referencias no cuentan aparte porque cuelgan de una ruta: si no hay rutas,
     * tampoco hay referencias del local.
     *
     * @return array{rutas: int, rutas_lista: array<int, string>, promedios: int}
     */
    public static function vinculos(string $id): array
    {
        $rutas = DB::table('rutas')
            ->where('origen_id', $id)
            ->orWhere('destino_id', $id)
            ->pluck('id')
            ->all();

        $promedios = DB::table('tiempos_ruta_promedio')
            ->where('origen_id', $id)
            ->orWhere('destino_id', $id)
            ->count();

        return [
            'rutas'        => count($rutas),
            'rutas_lista'  => array_map('strval', $rutas),
            'promedios'    => $promedios,
        ];
    }

    /**
     * Borra un local SOLO si no tiene nada vinculado.
     *
     * Se revalida adentro de la transaccion: entre que la pantalla dibujo el boton y
     * el POST, alguien pudo generar una ruta con ese local.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function eliminar(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $local = DB::table('locales')->where('id', $id)->first(['id', 'nombre']);

            if ($local === null) {
                return ['ok' => false, 'error' => 'El local no existe.'];
            }

            $vinculos = self::vinculos($id);

            if ($vinculos['rutas'] > 0) {
                return ['ok' => false, 'error' => 'No se borró: el local aparece en '
                    . $vinculos['rutas'] . ' ruta(s) (' . implode(', ', array_slice($vinculos['rutas_lista'], 0, 5))
                    . (count($vinculos['rutas_lista']) > 5 ? '…' : '')
                    . '). Desactivalo si no lo querés usar, o fusionalo con el local correcto.'];
            }

            if ($vinculos['promedios'] > 0) {
                return ['ok' => false, 'error' => 'No se borró: hay ' . $vinculos['promedios']
                    . ' promedio(s) compilados que lo referencian.'];
            }

            DB::table('locales')->where('id', $id)->delete();

            return ['ok' => true, 'error' => null];
        });
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
