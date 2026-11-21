<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * DBClientes
 *
 * CRUD del catalogo `clientes`, del que cuelgan los locales (`locales.cliente_id`).
 *
 * REGLA: el id de un cliente NO se edita, igual que el de un local: los locales
 * apuntan a el. Para "renombrar" hay que dar de alta otro cliente.
 *
 * Tampoco hay borrado: un cliente sin locales se puede dejar ahi (no molesta) y uno
 * con locales dejaria los locales huerfanos. Si algun dia hace falta, primero hay que
 * decidir que pasa con sus locales.
 */
class DBClientes
{
    /**
     * Clientes con cuantos locales cuelgan de cada uno.
     *
     * OJO: NO se hace leftJoin a `locales` para contar. El join multiplica la fila del
     * cliente por cada local (CLI_BABEL con 11 locales salia 11 veces) y `keyBy('id')`
     * en el test lo tapaba. Se cuenta con una subconsulta correlacionada.
     *
     * @return array<int, object>
     */
    public static function listar(): array
    {
        return DB::table('clientes as c')
            ->select([
                'c.id',
                'c.nombre',
                'c.tipo',
                'c.descripcion',
                'c.palabra_clave',
            ])
            ->selectSub(
                DB::table('locales')->selectRaw('COUNT(*)')->whereRaw('locales.cliente_id = c.id'),
                'locales_count'
            )
            ->orderBy('c.nombre')
            ->get()
            ->all();
    }

    public static function buscar(string $id): ?object
    {
        return DB::table('clientes')->where('id', $id)->first([
            'id', 'nombre', 'tipo', 'descripcion', 'palabra_clave',
        ]);
    }

    /**
     * Tipos que ya se usaron, para el datalist del formulario (no hay tabla de tipos).
     *
     * @return array<int, string>
     */
    public static function tipos(): array
    {
        return DB::table('clientes')
            ->whereNotNull('tipo')
            ->where('tipo', '<>', '')
            ->distinct()
            ->orderBy('tipo')
            ->pluck('tipo')
            ->map(fn ($t) => (string) $t)
            ->all();
    }

    /**
     * Locales que cuelgan de un cliente (para mostrar y para el link al listado).
     *
     * @return array<int, string>
     */
    public static function localesDe(string $id): array
    {
        return DB::table('locales')
            ->where('cliente_id', $id)
            ->orderBy('nombre')
            ->pluck('id')
            ->map(fn ($l) => (string) $l)
            ->all();
    }

    public static function crear(array $datos): void
    {
        DB::table('clientes')->insert(self::fila($datos) + ['id' => $datos['id']]);
    }

    public static function actualizar(string $id, array $datos): void
    {
        DB::table('clientes')->where('id', $id)->update(self::fila($datos));
    }

    /**
     * Columnas editables del cliente, normalizadas tal como se guardan.
     *
     * @return array<string, mixed>
     */
    private static function fila(array $datos): array
    {
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $palabra_clave = trim((string) ($datos['palabra_clave'] ?? ''));

        return [
            'nombre'        => trim((string) $datos['nombre']),
            'tipo'          => trim((string) $datos['tipo']),
            'descripcion'   => $descripcion !== '' ? $descripcion : null,
            'palabra_clave' => $palabra_clave !== '' ? mb_substr($palabra_clave, 0, 100) : null,
        ];
    }
}
