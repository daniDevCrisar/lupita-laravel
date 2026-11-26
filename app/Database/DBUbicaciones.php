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
     * Rutas cuyo `origen_id` o `destino_id` quedo con un UBIGEO en vez de un local.
     *
     * Pasa cuando el SP no encontro ningun local en ese momento y uso el distrito
     * (`rutas.origen_id = '150132'`). Al dar de alta locales nuevos, esas rutas pueden
     * pasar a apuntar al local, pero solo si se reprocesan las refs.
     *
     * @return array<int, array{id: string, origen_id: string, destino_id: string,
     *                          origen_ubigeo: bool, destino_ubigeo: bool,
     *                          veces_usada: int, refs: int}>
     */
    public static function rutasConUbigeo(): array
    {
        $rutas = DB::table('rutas')
            ->orderBy('id')
            ->get(['id', 'origen_id', 'destino_id', 'veces_usada']);

        $salida = [];
        $ids = [];

        foreach ($rutas as $ruta) {
            $origen_ubigeo = preg_match('/^\d{6}$/', (string) $ruta->origen_id) === 1;
            $destino_ubigeo = preg_match('/^\d{6}$/', (string) $ruta->destino_id) === 1;

            if (!$origen_ubigeo && !$destino_ubigeo) {
                continue;
            }

            $ids[] = (string) $ruta->id;
            $salida[(string) $ruta->id] = [
                'id'            => (string) $ruta->id,
                'origen_id'     => (string) $ruta->origen_id,
                'destino_id'    => (string) $ruta->destino_id,
                'origen_ubigeo' => $origen_ubigeo,
                'destino_ubigeo' => $destino_ubigeo,
                'veces_usada'   => (int) $ruta->veces_usada,
                'refs'          => 0,
            ];
        }

        // cuantas refs cuelgan de cada una (en lotes, puede haber cientos de rutas)
        foreach (array_chunk($ids, 500) as $lote) {
            foreach (DB::table('referencias')
                ->whereIn('ruta_id', $lote)
                ->selectRaw('ruta_id, COUNT(*) AS n')
                ->groupBy('ruta_id')
                ->get() as $fila) {
                $clave = (string) $fila->ruta_id;
                if (isset($salida[$clave])) {
                    $salida[$clave]['refs'] = (int) $fila->n;
                }
            }
        }

        return array_values($salida);
    }

    /**
     * Refs (con su titulo) que cuelgan de esas rutas.
     *
     * @param array<int, string> $rutas
     *
     * @return array<string, string> ref => titulo
     */
    public static function refsDeRutas(array $rutas): array
    {
        $salida = [];

        foreach (array_chunk(array_values($rutas), 500) as $lote) {
            foreach (DB::table('referencias')
                ->whereIn('ruta_id', $lote)
                ->whereNotNull('titulo_viaje')
                ->where('titulo_viaje', '<>', '')
                ->orderBy('ref')
                ->get(['ref', 'titulo_viaje']) as $fila) {
                $salida[(string) $fila->ref] = (string) $fila->titulo_viaje;
            }
        }

        return $salida;
    }

    /**
     * Cuantas refs de rutas con ubigeo mencionan alguna de esas palabras clave.
     *
     * Es el aviso barato que se muestra al guardar un local ("esto puede desbloquear N
     * refs"), sin analizar 3.700 titulos en cada guardado.
     *
     * @param array<int, string> $palabras
     */
    public static function refsUbigeoQueMencionan(array $palabras): int
    {
        $palabras = array_values(array_filter(array_map(
            fn ($p) => trim((string) $p),
            $palabras
        ), fn (string $p) => $p !== ''));

        if ($palabras === []) {
            return 0;
        }

        $rutas = array_map(fn (array $r) => $r['id'], self::rutasConUbigeo());
        if ($rutas === []) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($rutas, 500) as $lote_rutas) {
            $consulta = DB::table('referencias')->whereIn('ruta_id', $lote_rutas);

            $consulta->where(function ($q) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $q->orWhere('titulo_viaje', 'like', '%' . $palabra . '%');
                }
            });

            $total += $consulta->count();
        }

        return $total;
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
     * Analizador armado con el catalogo de la base.
     *
     * NO se memoiza a proposito: un static sobrevive a la request en procesos de larga
     * vida (workers, Octane, y la propia suite de tests, donde un catalogo de prueba
     * quedaba pegado al test siguiente). El catalogo son dos consultas chicas y cada
     * controller lo pide una sola vez.
     */
    public static function analizador(): AnalizadorTitulos
    {
        $catalogos = self::catalogos();

        return new AnalizadorTitulos($catalogos['locales'], $catalogos['distritos']);
    }

    /**
     * Descartados de un tipo, indexados por clave.
     *
     * Si la tabla no existe todavia (una base nueva, o alguien la borro), devuelve una
     * lista vacia en vez de reventar la pantalla: igual que hace `DBGeoapify` con su
     * contador. Descartar candidatos es una comodidad, no un requisito para diagnosticar.
     *
     * @return array<string, array{texto: string, motivo: ?string}>
     */
    public static function descartes(string $tipo = 'palabra'): array
    {
        $salida = [];

        try {
            $filas = DB::table('ubicaciones_descartes')->where('tipo', $tipo)->get(['id', 'clave', 'motivo']);
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($filas as $fila) {
            $salida[(string) $fila->id] = [
                'texto'  => (string) $fila->clave,
                'motivo' => $fila->motivo !== null ? (string) $fila->motivo : null,
            ];
        }

        return $salida;
    }

    /**
     * Descarta un candidato (idempotente: descartar dos veces no duplica).
     *
     * Devuelve false si no se pudo guardar (por ejemplo, si la tabla no existe), para
     * que el controlador avise en vez de tirar un 500.
     */
    public static function descartar(
        string $tipo,
        string $clave,
        string $texto,
        ?string $motivo = null,
        ?int $usuarioId = null
    ): bool {
        try {
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

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Vuelve a mostrar un candidato descartado.
     */
    public static function restaurar(string $tipo, string $clave): bool
    {
        try {
            DB::table('ubicaciones_descartes')->where('tipo', $tipo)->where('id', $clave)->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
