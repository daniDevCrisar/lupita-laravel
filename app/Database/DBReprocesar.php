<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * DBReprocesar
 *
 * Llama a `sp_actualizar_ruta_id` ref por ref y arma el reporte. **No reimplementa
 * la logica**: el SP es el unico que decide y el unico que escribe en `rutas` y
 * `referencias`. El analizador en PHP (App\Ubicaciones\AnalizadorTitulos) solo
 * sirve para predecir y diagnosticar.
 *
 * Tres cosas del SP que estan manejadas aca a proposito:
 *  1. Devuelve un result set (los SELECT de diagnostico y el final). Por eso se
 *     llama con DB::select() y no con DB::statement(): encadenar CALLs con
 *     resultados sin consumir rompe la conexion ("Cannot execute queries while
 *     other unbuffered queries are active").
 *  2. Cada llamada hace `veces_usada = veces_usada + 1` aunque el ruta_id salga
 *     igual, asi que las refs que ya tienen ruta se omiten.
 *  3. Un SIGNAL (origen/destino no encontrado, multiparada) es un error por ref,
 *     no del lote: cada llamada va en su try/catch y el lote sigue.
 */
class DBReprocesar
{
    /**
     * ¿Esta instalado el procedimiento?
     *
     * `config('ubicaciones.sp_forzado')` permite forzarlo en tests y en
     * operaciones (por ejemplo, deshabilitar el boton sin tocar la base).
     */
    public static function spDisponible(): bool
    {
        $forzado = config('ubicaciones.sp_forzado');

        if ($forzado !== null) {
            return (bool) $forzado;
        }

        return DBUbicaciones::spDisponible();
    }

    /**
     * Llamador real del SP.
     *
     * @return callable(string): array
     */
    public static function llamador(): callable
    {
        return function (string $ref): array {
            $filas = DB::select('CALL sp_actualizar_ruta_id(?)', [$ref]);

            return (array) ($filas[0] ?? []);
        };
    }

    /**
     * Reprocesa una lista de refs.
     *
     * `$llamar` se inyecta en los tests para no depender de que el SP exista.
     *
     * `$omitir_con_ruta`: por defecto se saltan las refs que ya tienen `ruta_id`, para no
     * inflar `veces_usada` si se aprieta dos veces. Hay que pasarlo en FALSE cuando el
     * objetivo ES reprocesar refs ya resueltas: es el caso de las rutas que quedaron con
     * un ubigeo en el id y ahora tienen local (ver RutasUbigeoController).
     *
     * @param array<int, string>      $refs
     * @param callable|null           $llamar
     * @param bool                    $omitir_con_ruta
     *
     * @return array{total: int, ok: int, error: int, omitidas: array<int, string>, filas: array<int, array>}
     */
    public static function procesar(array $refs, ?callable $llamar = null, bool $omitir_con_ruta = true): array
    {
        $llamar = $llamar ?? self::llamador();

        $refs = array_values(array_unique(array_map('strval', $refs)));
        $con_ruta = $omitir_con_ruta ? DBUbicaciones::refsConRuta($refs) : [];

        $omitidas = [];
        $a_procesar = [];
        foreach ($refs as $ref) {
            if (in_array($ref, $con_ruta, true)) {
                $omitidas[] = $ref;
            } else {
                $a_procesar[] = $ref;
            }
        }

        $filas = [];
        $ok = 0;
        $error = 0;

        foreach ($a_procesar as $ref) {
            // se revalida justo antes de llamar: entre la comprobacion del lote y este
            // momento, otro proceso (u otro admin) pudo resolverla, y el SP suma en
            // `veces_usada` en cada llamada aunque el ruta_id salga igual
            if ($omitir_con_ruta && DBUbicaciones::refsConRuta([$ref]) !== []) {
                $omitidas[] = $ref;
                continue;
            }

            try {
                $resultado = $llamar($ref);
                $filas[] = [
                    'ref'     => $ref,
                    'ok'      => true,
                    'ruta_id' => isset($resultado['v_ruta_id']) ? (string) $resultado['v_ruta_id'] : null,
                    'mensaje' => null,
                ];
                $ok++;
            } catch (\Throwable $e) {
                $filas[] = [
                    'ref'     => $ref,
                    'ok'      => false,
                    'ruta_id' => null,
                    'mensaje' => $e->getMessage(),
                ];
                $error++;
            }
        }

        return [
            'total'    => count($refs),
            'ok'       => $ok,
            'error'    => $error,
            'omitidas' => $omitidas,
            'filas'    => $filas,
        ];
    }
}
