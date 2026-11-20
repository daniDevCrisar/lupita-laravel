<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * DBFusionLocales
 *
 * Fusiona dos locales que son el mismo lugar. Es la unica parte del modulo que
 * escribe en `rutas`, `referencias` y las tablas compiladas, asi que va en
 * transaccion y exige una simulacion previa (`plan()`).
 *
 * POR QUE SE DETECTAN POR PALABRA CLAVE Y NO POR NOMBRE: el nombre parecido miente
 * (ICA ~ NAZCA dan 85 % de similitud y son ciudades distintas) y en esta base no hay
 * ningun nombre exactamente repetido. Lo que si genera duplicados reales es que dos
 * locales compartan una variante de `palabra_clave`: ahi el SP se queda con el
 * primero del alfabeto y el otro queda como peso muerto.
 *
 * QUE HACE UNA FUSION:
 *  - uniona las `palabra_clave` de los dos (recortando a 100, que es la columna);
 *  - hereda las coordenadas y el ubigeo del que se va, si el que sobrevive no tiene;
 *  - repunta `rutas.origen_id/destino_id` al que sobrevive y renombra el id de la
 *    ruta (`RUT_A-B` -> `RUT_A-C`);
 *  - si la ruta nueva ya existe, suma `veces_usada`, se queda con la ultima fecha y
 *    borra la sobrante, repuntando lo que colgaba de ella;
 *  - repunta `referencias`, `prediccion_resultados` y `tiempos_ruta_horario`;
 *  - se queda con la fila de `tiempos_ruta_promedio` que sobrevive;
 *  - **desactiva** el local sobrante (`activo=0`): no lo borra, igual que el
 *    fusionador de conductores. Queda en el historial de viajes.
 *
 * LO QUE NO TOCA: las rutas que quedarian con origen == destino. Ahi el viaje es
 * "del mismo lugar al mismo lugar": se informan en la simulacion y se dejan como
 * estan para que lo decida una persona.
 */
class DBFusionLocales
{
    /**
     * Pares de locales que comparten alguna variante de palabra clave.
     *
     * @return array<int, array{variante: string, a: array, b: array}>
     */
    public static function pares(): array
    {
        $locales = DB::table('locales')
            ->orderBy('id')
            ->get(['id', 'cliente_id', 'nombre', 'palabra_clave', 'distrito_ubigeo', 'lat', 'lng', 'activo']);

        $porVariante = [];
        foreach ($locales as $local) {
            foreach (self::variantes($local->palabra_clave) as $variante) {
                $porVariante[$variante][] = $local;
            }
        }

        $pares = [];

        foreach ($porVariante as $variante => $lista) {
            $total = count($lista);

            for ($i = 0; $i < $total; $i++) {
                for ($j = $i + 1; $j < $total; $j++) {
                    $a = $lista[$i];
                    $b = $lista[$j];

                    if ((int) $a->activo === 0 && (int) $b->activo === 0) {
                        continue;   // dos inactivos no son un problema
                    }

                    $llave = $a->id < $b->id ? $a->id . '|' . $b->id : $b->id . '|' . $a->id;

                    if (isset($pares[$llave])) {
                        continue;
                    }

                    $pares[$llave] = [
                        'variante' => $variante,
                        'a'        => self::resumen($a),
                        'b'        => self::resumen($b),
                    ];
                }
            }
        }

        usort($pares, fn (array $x, array $y) => strcmp($x['variante'], $y['variante']));

        return array_values($pares);
    }

    /**
     * Simulacion: que cambiaria si se fusionan, sin escribir nada.
     *
     * @return array<string, mixed>
     */
    public static function plan(string $sobrevive, string $se_va): array
    {
        $sobrevive = trim($sobrevive);
        $se_va = trim($se_va);

        $vacio = [
            'ok'                       => false,
            'error'                    => null,
            'sobrevive'                => null,
            'se_va'                    => null,
            'palabra_clave_resultante' => '',
            'palabra_clave_recortada'  => false,
            'coordenadas'              => 'ninguna',
            'rutas_repuntadas'         => 0,
            'rutas_colapsadas'         => [],
            'rutas_self'               => [],
            'rutas_sin_convencion'     => [],
            'referencias'              => 0,
            'tiempos_ruta_promedio'    => 0,
            'tiempos_ruta_horario'     => 0,
            'prediccion_resultados'    => 0,
            'avisos'                   => [],
        ];

        if ($sobrevive === '' || $se_va === '') {
            return ['error' => 'Elegí los dos locales.'] + $vacio;
        }

        if ($sobrevive === $se_va) {
            return ['error' => 'No se puede fusionar un local consigo mismo.'] + $vacio;
        }

        $a = DB::table('locales')->where('id', $sobrevive)->first();
        $b = DB::table('locales')->where('id', $se_va)->first();

        if ($a === null || $b === null) {
            return ['error' => 'Alguno de los dos locales no existe.'] + $vacio;
        }

        [$palabra_clave, $recortada] = self::unir_palabras_clave((string) $a->palabra_clave, (string) $b->palabra_clave);

        $coordenadas = 'ninguna';
        if ($a->lat !== null && $a->lng !== null) {
            $coordenadas = 'conserva';
        } elseif ($b->lat !== null && $b->lng !== null) {
            $coordenadas = 'hereda';
        }

        $afectadas = self::rutas_afectadas($sobrevive, $se_va);

        $ids_viejos = array_column($afectadas, 'id_viejo');
        $ids_nuevos = array_column($afectadas, 'id_nuevo');

        $plan = [
            'ok'                       => true,
            'error'                    => null,
            'sobrevive'                => self::resumen($a),
            'se_va'                    => self::resumen($b),
            'palabra_clave_resultante' => $palabra_clave,
            'palabra_clave_recortada'  => $recortada,
            'coordenadas'              => $coordenadas,
            'rutas_repuntadas'         => count(array_filter($afectadas, fn ($x) => !$x['self'])),
            'rutas_colapsadas'         => array_values(array_filter($afectadas, fn ($x) => !$x['self'] && $x['existe_destino'])),
            'rutas_self'               => array_values(array_filter(array_column($afectadas, 'id_viejo'), fn () => true)),
            'rutas_sin_convencion'     => array_values(array_filter($afectadas, fn ($x) => !$x['respeta_convencion'])),
            'referencias'              => self::contar('referencias', 'ruta_id', $ids_viejos),
            'tiempos_ruta_promedio'    => self::contar('tiempos_ruta_promedio', 'ruta_id', $ids_viejos),
            'tiempos_ruta_horario'     => self::contar('tiempos_ruta_horario', 'ruta_id', $ids_viejos),
            'prediccion_resultados'    => self::contar('prediccion_resultados', 'ruta_id', $ids_viejos),
            'avisos'                   => [],
        ];

        // rutas_self solo las que de verdad quedarian origen == destino
        $plan['rutas_self'] = array_values(array_map(
            fn ($x) => $x['id_viejo'],
            array_filter($afectadas, fn ($x) => $x['self'])
        ));

        if ($recortada) {
            $plan['avisos'][] = 'Las palabras clave juntas pasan de 100 caracteres: se recortan.';
        }
        if ($plan['rutas_self'] !== []) {
            $plan['avisos'][] = count($plan['rutas_self']) . ' ruta(s) quedarían con origen y destino en el '
                . 'mismo local; se dejan como están para que las revises.';
        }
        if ($plan['rutas_sin_convencion'] !== []) {
            $plan['avisos'][] = count($plan['rutas_sin_convencion']) . ' ruta(s) tienen un id que no sigue la '
                . 'convención RUT_origen-destino: se repunta el lado pero no se renombran.';
        }
        if ($plan['tiempos_ruta_promedio'] > 0) {
            $plan['avisos'][] = 'Hay promedios compilados de esas rutas: conviene recompilar tiempos después.';
        }

        unset($ids_nuevos);

        return $plan;
    }

    /**
     * Aplica la fusion. Todo o nada: si algo falla, no queda nada a medias.
     *
     * @return array{ok: bool, error: ?string, plan: array<string, mixed>}
     */
    public static function aplicar(string $sobrevive, string $se_va): array
    {
        $plan = self::plan($sobrevive, $se_va);

        if (!$plan['ok']) {
            return ['ok' => false, 'error' => $plan['error'], 'plan' => $plan];
        }

        DB::transaction(function () use ($sobrevive, $se_va, $plan) {
            $a = DB::table('locales')->where('id', $sobrevive)->first();
            $b = DB::table('locales')->where('id', $se_va)->first();

            // 1) el sobreviviente se queda con palabras clave, coordenadas y ubigeo
            $cambios = ['palabra_clave' => $plan['palabra_clave_resultante']];

            if ($a->lat === null || $a->lng === null) {
                if ($b->lat !== null && $b->lng !== null) {
                    $cambios['lat'] = $b->lat;
                    $cambios['lng'] = $b->lng;
                    $cambios['geo_texto'] = $b->geo_texto;
                    $cambios['geo_fuente'] = $b->geo_fuente;
                    $cambios['geo_actualizado_en'] = $b->geo_actualizado_en;
                }
            }

            if (($a->distrito_ubigeo ?? '') === '' && ($b->distrito_ubigeo ?? '') !== '') {
                $cambios['distrito_ubigeo'] = $b->distrito_ubigeo;
            }

            DB::table('locales')->where('id', $sobrevive)->update($cambios);

            // 2) las rutas
            foreach (self::rutas_afectadas($sobrevive, $se_va) as $ruta) {
                if ($ruta['self']) {
                    continue;   // "del mismo lugar al mismo lugar": lo decide una persona
                }

                if (!$ruta['respeta_convencion']) {
                    DB::table('rutas')->where('id', $ruta['id_viejo'])->update([
                        'origen_id'  => $ruta['origen_nuevo'],
                        'destino_id' => $ruta['destino_nuevo'],
                    ]);
                    continue;
                }

                if ($ruta['existe_destino']) {
                    self::absorber_ruta($ruta['id_viejo'], $ruta['id_nuevo']);
                    continue;
                }

                DB::table('rutas')->where('id', $ruta['id_viejo'])->update([
                    'id'         => $ruta['id_nuevo'],
                    'origen_id'  => $ruta['origen_nuevo'],
                    'destino_id' => $ruta['destino_nuevo'],
                ]);

                self::repuntar_dependientes($ruta['id_viejo'], $ruta['id_nuevo']);
            }

            // 3) los promedios compilados que hayan quedado apuntando al local que se va
            DB::table('tiempos_ruta_promedio')->where('origen_id', $se_va)->update(['origen_id' => $sobrevive]);
            DB::table('tiempos_ruta_promedio')->where('destino_id', $se_va)->update(['destino_id' => $sobrevive]);

            // 4) el sobrante se desactiva, nunca se borra
            DB::table('locales')->where('id', $se_va)->update(['activo' => 0]);
        });

        return ['ok' => true, 'error' => null, 'plan' => $plan];
    }

    /**
     * Absorbe la ruta $id_viejo en la $id_nuevo (que ya existe): suma veces, se queda
     * con la ultima fecha, repunta lo que colgaba y borra la sobrante.
     */
    private static function absorber_ruta(string $id_viejo, string $id_nuevo): void
    {
        $vieja = DB::table('rutas')->where('id', $id_viejo)->first();
        $nueva = DB::table('rutas')->where('id', $id_nuevo)->first();

        if ($vieja === null) {
            return;
        }

        if ($nueva !== null) {
            $ultima = $vieja->ultima_vez;
            if ($nueva->ultima_vez !== null && ($ultima === null || $nueva->ultima_vez > $ultima)) {
                $ultima = $nueva->ultima_vez;
            }

            DB::table('rutas')->where('id', $id_nuevo)->update([
                'veces_usada' => (int) $nueva->veces_usada + (int) $vieja->veces_usada,
                'ultima_vez'  => $ultima,
            ]);
        }

        self::repuntar_dependientes($id_viejo, $id_nuevo);

        DB::table('rutas')->where('id', $id_viejo)->delete();
    }

    /**
     * Mueve todo lo que cuelga de una ruta a otra.
     */
    private static function repuntar_dependientes(string $id_viejo, string $id_nuevo): void
    {
        foreach (['referencias', 'tiempos_ruta_horario', 'prediccion_resultados'] as $tabla) {
            DB::table($tabla)->where('ruta_id', $id_viejo)->update(['ruta_id' => $id_nuevo]);
        }

        // tiempos_ruta_promedio tiene ruta_id como PK: si la fila nueva ya existe, se
        // descarta la vieja (los promedios se recompilan)
        $existe = DB::table('tiempos_ruta_promedio')->where('ruta_id', $id_nuevo)->exists();

        if ($existe) {
            DB::table('tiempos_ruta_promedio')->where('ruta_id', $id_viejo)->delete();
        } else {
            DB::table('tiempos_ruta_promedio')->where('ruta_id', $id_viejo)->update(['ruta_id' => $id_nuevo]);
        }
    }

    /**
     * Rutas donde aparece el local que se va, con el id y los lados ya mapeados.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rutas_afectadas(string $sobrevive, string $se_va): array
    {
        $filas = DB::table('rutas')
            ->where(function ($q) use ($se_va) {
                $q->where('origen_id', $se_va)->orWhere('destino_id', $se_va);
            })
            ->orderBy('id')
            ->get(['id', 'origen_id', 'destino_id']);

        $salida = [];

        foreach ($filas as $fila) {
            $origen = (string) $fila->origen_id === $se_va ? $sobrevive : (string) $fila->origen_id;
            $destino = (string) $fila->destino_id === $se_va ? $sobrevive : (string) $fila->destino_id;

            $id_nuevo = 'RUT_' . str_replace('LOC_', '', $origen) . '-' . str_replace('LOC_', '', $destino);
            $id_esperado_viejo = 'RUT_' . str_replace('LOC_', '', (string) $fila->origen_id)
                . '-' . str_replace('LOC_', '', (string) $fila->destino_id);

            $salida[] = [
                'id_viejo'           => (string) $fila->id,
                'id_nuevo'           => $id_nuevo,
                'origen_nuevo'       => $origen,
                'destino_nuevo'      => $destino,
                'self'               => $origen === $destino,
                'respeta_convencion' => (string) $fila->id === $id_esperado_viejo,
                'existe_destino'     => DB::table('rutas')->where('id', $id_nuevo)->exists(),
            ];
        }

        return $salida;
    }

    /**
     * Uniona las palabras clave sin repetir variantes, recortando a 100 caracteres.
     *
     * @return array{0: string, 1: bool}
     */
    private static function unir_palabras_clave(string $a, string $b): array
    {
        $variantes = [];

        foreach (self::separar($a) as $v) {
            $variantes[self::normalizar($v)] = $v;
        }
        foreach (self::separar($b) as $v) {
            $llave = self::normalizar($v);
            if (!isset($variantes[$llave])) {
                $variantes[$llave] = $v;
            }
        }

        $resultante = implode('|', array_values($variantes));
        $recortada = mb_strlen($resultante) > 100;

        if ($recortada) {
            $resultante = rtrim(mb_substr($resultante, 0, 100), '| ');
        }

        return [$resultante, $recortada];
    }

    /** @return array<int, string> */
    private static function separar(string $palabra_clave): array
    {
        $salida = [];
        foreach (explode('|', $palabra_clave) as $v) {
            $v = trim($v);
            if ($v !== '') {
                $salida[] = $v;
            }
        }

        return $salida;
    }

    /** @return array<int, string> variantes normalizadas (mayusculas, sin parentesis ni espacios de sobra) */
    private static function variantes(?string $palabra_clave): array
    {
        $salida = [];
        foreach (self::separar((string) $palabra_clave) as $v) {
            $normal = self::normalizar($v);
            if ($normal !== '') {
                $salida[$normal] = $normal;
            }
        }

        return array_values($salida);
    }

    private static function normalizar(string $texto): string
    {
        $texto = str_replace(['(', ')'], '', $texto);

        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $texto)));
    }

    /**
     * Cuantas filas de una tabla apuntan a alguna de esas rutas.
     *
     * @param array<int, string> $ids
     */
    private static function contar(string $tabla, string $columna, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($ids, 200) as $lote) {
            $total += DB::table($tabla)->whereIn($columna, $lote)->count();
        }

        return $total;
    }

    /**
     * Datos cortos de un local para la pantalla.
     *
     * @return array<string, mixed>
     */
    private static function resumen(object $local): array
    {
        $rutas = DB::table('rutas')
            ->where(function ($q) use ($local) {
                $q->where('origen_id', $local->id)->orWhere('destino_id', $local->id);
            })->count();

        $refs = DB::table('referencias as r')
            ->join('rutas as ru', 'ru.id', '=', 'r.ruta_id')
            ->where(function ($q) use ($local) {
                $q->where('ru.origen_id', $local->id)->orWhere('ru.destino_id', $local->id);
            })->count();

        return [
            'id'            => (string) $local->id,
            'nombre'        => (string) $local->nombre,
            'cliente_id'    => (string) $local->cliente_id,
            'palabra_clave' => (string) $local->palabra_clave,
            'distrito_ubigeo' => $local->distrito_ubigeo !== null ? (string) $local->distrito_ubigeo : null,
            'lat'           => $local->lat !== null ? (float) $local->lat : null,
            'lng'           => $local->lng !== null ? (float) $local->lng : null,
            'activo'        => (int) $local->activo,
            'rutas'         => $rutas,
            'refs'          => $refs,
        ];
    }
}
