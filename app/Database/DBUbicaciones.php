<?php

namespace App\Database;

use App\Ubicaciones\AnalizadorTitulos;
use Illuminate\Support\Facades\DB;

/**
 * DBUbicaciones
 *
 * Capa de datos del modulo /lupita/ubicaciones. Hace las consultas y devuelve
 * arreglos listos para el analisis; no tiene logica de parseo (esa vive en
 * App\Ubicaciones\AnalizadorTitulos).
 *
 * Lo unico que se escribe aca son los descartes: el resto del modulo es de
 * lectura sobre `referencias` y `locales`.
 */
class DBUbicaciones
{
    /**
     * Refs que quedaron sin `ruta_id`, con su titulo ('' si no tienen).
     *
     * Se ordena por `ref` para que la salida sea estable entre requests.
     *
     * @return array<int, array{ref: string, titulo: string}>
     */
    public static function refsSinRuta(): array
    {
        return DB::table('referencias')
            ->where(function ($q) {
                $q->whereNull('ruta_id')->orWhere('ruta_id', '');
            })
            ->orderBy('ref')
            ->get(['ref', 'titulo_viaje'])
            ->map(fn ($f) => [
                'ref'    => (string) $f->ref,
                'titulo' => (string) ($f->titulo_viaje ?? ''),
            ])
            ->all();
    }

    /**
     * Subconjunto de $refs que YA tiene `ruta_id`.
     *
     * Sirve para que el boton de reprocesar no vuelva a llamar al SP sobre una
     * ref ya resuelta: el SP incrementa `veces_usada` en cada llamada aunque el
     * `ruta_id` salga igual.
     *
     * @param array<int, string> $refs
     *
     * @return array<int, string>
     */
    public static function refsConRuta(array $refs): array
    {
        $refs = array_values(array_unique(array_map('strval', $refs)));
        if ($refs === []) {
            return [];
        }

        $salida = [];

        // en lotes: la lista puede traer cientos de refs
        foreach (array_chunk($refs, 500) as $lote) {
            foreach (DB::table('referencias')
                ->whereIn('ref', $lote)
                ->whereNotNull('ruta_id')
                ->where('ruta_id', '<>', '')
                ->pluck('ref') as $ref) {
                $salida[] = (string) $ref;
            }
        }

        return $salida;
    }

    /**
     * Catalogos que consume el analizador.
     *
     * `locales` trae `cliente_id` porque AnalisisTitulos lo necesita para decidir
     * si una palabra aparece en titulos de clientes distintos.
     *
     * @return array{locales: array<int, array>, distritos: array<int, array>}
     */
    public static function catalogos(): array
    {
        $locales = DB::table('locales')
            ->orderBy('id')
            ->get(['id', 'palabra_clave', 'nombre', 'cliente_id'])
            ->map(fn ($l) => [
                'id'            => (string) $l->id,
                'palabra_clave' => (string) ($l->palabra_clave ?? ''),
                'nombre'        => (string) ($l->nombre ?? ''),
                'cliente_id'    => (string) ($l->cliente_id ?? ''),
            ])
            ->all();

        $distritos = DB::table('ubigeo_distritos')
            ->orderBy('distrito')
            ->get(['ubigeo', 'distrito'])
            ->map(fn ($d) => [
                'ubigeo'   => (string) $d->ubigeo,
                'distrito' => (string) $d->distrito,
            ])
            ->all();

        return ['locales' => $locales, 'distritos' => $distritos];
    }

    /**
     * Analizador armado con el catalogo de la base (memoizado por request).
     */
    public static function analizador(): AnalizadorTitulos
    {
        static $analizador = null;

        if ($analizador === null) {
            $catalogos = self::catalogos();
            $analizador = new AnalizadorTitulos($catalogos['locales'], $catalogos['distritos']);
        }

        return $analizador;
    }

    /**
     * Descartados de un tipo, indexados por clave.
     *
     * @return array<string, array{texto: string, motivo: ?string}>
     */
    public static function descartes(string $tipo = 'palabra'): array
    {
        $salida = [];

        foreach (DB::table('ubicaciones_descartes')->where('tipo', $tipo)->get(['id', 'clave', 'motivo']) as $fila) {
            $salida[(string) $fila->id] = [
                'texto'  => (string) $fila->clave,
                'motivo' => $fila->motivo !== null ? (string) $fila->motivo : null,
            ];
        }

        return $salida;
    }

    /**
     * Descarta un candidato (idempotente: descartar dos veces no duplica).
     */
    public static function descartar(
        string $tipo,
        string $clave,
        string $texto,
        ?string $motivo = null,
        ?int $usuarioId = null
    ): void {
        DB::table('ubicaciones_descartes')->updateOrInsert(
            ['id' => $clave],
            [
                'tipo'       => $tipo,
                'clave'      => mb_substr($texto, 0, 500),
                'motivo'     => $motivo,
                'usuario_id' => $usuarioId,
                'created_at' => now(),
            ]
        );
    }

    /**
     * Vuelve a mostrar un candidato descartado.
     */
    public static function restaurar(string $tipo, string $clave): void
    {
        DB::table('ubicaciones_descartes')->where('tipo', $tipo)->where('id', $clave)->delete();
    }

    /**
     * ¿Esta instalado `sp_actualizar_ruta_id` en esta base?
     *
     * Se consulta `information_schema` (MySQL). En cualquier otro motor se
     * responde que no: el boton de reprocesar se deshabilita en vez de reventar.
     */
    public static function spDisponible(): bool
    {
        static $disponible = null;

        if ($disponible !== null) {
            return $disponible;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return $disponible = false;
        }

        try {
            $filas = DB::select(
                "SELECT ROUTINE_NAME FROM information_schema.ROUTINES
                 WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'
                   AND ROUTINE_NAME = 'sp_actualizar_ruta_id'"
            );
        } catch (\Throwable $e) {
            return $disponible = false;
        }

        return $disponible = count($filas) > 0;
    }

    /**
     * Cuantas refs siguen sin `ruta_id` (lo que muestra la cabecera).
     */
    public static function pendientes(): int
    {
        return DB::table('referencias')
            ->where(function ($q) {
                $q->whereNull('ruta_id')->orWhere('ruta_id', '');
            })
            ->count();
    }
}
