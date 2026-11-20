<?php

namespace App\ElevenLabs;

use App\Database\DBColumns;
use Illuminate\Support\Facades\DB;

/**
 * Repositorio de las equivalencias de traduccion ElevenLabs -> `tmp_lotes_det`.
 *
 * Dos piezas (ver `z-eleven-labs/especificacion_mapeo_traduccion.md`):
 *   elevenlabs_mapeos_campo  columna destino <- ruta del JSON, constante o regla
 *   elevenlabs_mapeos_regla  "si <ruta> cumple <condicion> -> <columna> = <valor>"
 */
class MapeoTraduccion
{
    public const TRANSFORMACIONES = [
        'ninguna' => 'Sin transformar',
        'texto' => 'Texto (recorta espacios)',
        'numero' => 'Número',
        'telefono' => 'Teléfono (solo dígitos)',
        'segundos_a_ms' => 'Segundos → milisegundos',
        'mayusculas' => 'MAYÚSCULAS',
        'minusculas' => 'minúsculas',
        'truncar_255' => 'Recortar a 255',
        'json' => 'JSON compacto',
        'fecha_texto' => 'Fecha en texto natural → dd/mm/aaaa HH:mm',
        'veredicto_ia' => 'Veredicto de la IA (success → TRUE, resto → FALSE)',
        'booleano_0_1' => 'Booleano → 1 / 0',
        'texto_booleano' => 'Booleano → "true" / "false"',
        'alfanumerico' => 'Placa: solo letras y números, en MAYÚSCULAS',
    ];

    public const COMPARACIONES = [
        'igual' => 'es igual a',
        'diferente' => 'es diferente de',
        'contiene' => 'contiene',
        'empieza_por' => 'empieza por',
        'vacio' => 'está vacío',
        'no_vacio' => 'no está vacío',
        'else' => 'en otro caso (si nada más cumplió)',
    ];

    /** @return array<int, object> */
    public function campos(): array
    {
        return DB::table('elevenlabs_mapeos_campo')->orderBy('orden')->get()->all();
    }

    /** @return array<int, object> */
    public function reglas(): array
    {
        return DB::table('elevenlabs_mapeos_regla')
            ->orderBy('prioridad')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Nombres exactos de `razones_finalizacion` para el select del valor destino.
     *
     * El pipeline (`DBTmpLlamadas::obtener_id_razon`) compara EXACTO y sensible a
     * mayúsculas contra esta columna, así que el valor no puede ser texto libre.
     *
     * @return array<int, object>
     */
    public function razones(): array
    {
        return DB::table('razones_finalizacion')
            ->select('id', 'name', 'nombre')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Columnas del contrato que admiten valores por regla (banderas y catálogos).
     *
     * @return array<int, string>
     */
    public function columnasDeRegla(): array
    {
        return DB::table('elevenlabs_mapeos_campo')
            ->where('tipo_origen', 'regla')
            ->orderBy('orden')
            ->pluck('campo_destino')
            ->all();
    }

    /**
     * Rutas que se ofrecen como disparador en el editor de reglas.
     *
     * @return array<int, string>
     */
    public function rutasDisparador(): array
    {
        return [
            'metadata.termination_reason',
            'metadata.phone_call.call_result',
            'metadata.phone_call.direction',
            'metadata.error.error_type',
            'analysis.call_successful',
            'has_audio',
            'status',
        ];
    }

    /**
     * Que valores acepta cada columna de regla.
     *
     * No todas admiten texto libre: el pipeline castea distinto segun la columna.
     * Ofrecer el dominio real evita que una regla escriba algo que despues se lea
     * al reves o reviente:
     *   - `razon_finalizacion` se compara EXACTO contra razones_finalizacion.name
     *   - `exitosa_segun_ia` se compara contra 'TRUE'
     *   - `error_origen` solo admite -1..3
     *   - las banderas son columnas `tinyint(1)` en `llamadas`: solo 1, 0 o vacío
     *
     * Los dominios de tipo `lista` se validan al guardar: un valor fuera de la
     * lista se descarta en vez de terminar en una columna tinyint.
     *
     * @return array<string, array{tipo: string, opciones?: array<int, string>, nota?: string}>
     */
    public function opcionesValorDestino(): array
    {
        return [
            'type' => ['tipo' => 'texto', 'nota' => 'generalmente OUTBOUNDPHONECALL / INBOUNDPHONECALL'],
            'razon_finalizacion' => ['tipo' => 'razones'],
            'entro_llamada' => [
                'tipo' => 'lista',
                'opciones' => ['1', '0', ''],
                'nota' => 'se inserta crudo en un tinyint: solo 1, 0 o vacío',
            ],
            'error_origen' => [
                'tipo' => 'lista',
                'opciones' => ['-1', '0', '1', '2', '3'],
                'nota' => 'DBTmpLlamadas casi siempre lo sobrescribe con la razón',
            ],
            'error_tecnico_llamada' => [
                'tipo' => 'lista',
                'opciones' => ['1', '0', ''],
                'nota' => 'llamadas.error_tecnico_llamada es tinyint(1): solo 1, 0 o vacío',
            ],
            'conductor_cuelga' => [
                'tipo' => 'lista',
                'opciones' => ['1', '0', ''],
                'nota' => 'llamadas.conductor_cuelga es tinyint(1): solo 1, 0 o vacío',
            ],
            'conductor_no_contesta' => [
                'tipo' => 'lista',
                'opciones' => ['1', '0', ''],
                'nota' => 'llamadas.conductor_no_contesta es tinyint(1): solo 1, 0 o vacío',
            ],
        ];
    }

    /**
     * Guarda las filas de la pestaña "Campos".
     *
     * Nunca toca las de `tipo_origen = calculado`: son documentacion del contrato,
     * no configuracion. Un `campo_destino` que no exista en DBColumns se ignora,
     * para que un POST manipulado no meta columnas inventadas.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return int  filas actualizadas
     */
    public function guardarCampos(array $filas): int
    {
        $validas = DBColumns::tmpLotesDet();
        $transformaciones = array_keys(self::TRANSFORMACIONES);
        $actualizadas = 0;

        DB::transaction(function () use ($filas, $validas, $transformaciones, &$actualizadas) {
            foreach ($filas as $fila) {
                $destino = (string) ($fila['campo_destino'] ?? '');
                if (! in_array($destino, $validas, true)) {
                    continue;
                }

                $actual = DB::table('elevenlabs_mapeos_campo')->where('campo_destino', $destino)->first();
                if (! $actual || $actual->tipo_origen === 'calculado') {
                    continue;
                }

                $transformacion = (string) ($fila['transformacion'] ?? 'ninguna');
                if (! in_array($transformacion, $transformaciones, true)) {
                    $transformacion = 'ninguna';
                }

                $actualizadas += DB::table('elevenlabs_mapeos_campo')
                    ->where('campo_destino', $destino)
                    ->update([
                        // Varias rutas candidatas, una por linea (ResolvedorMapeo::candidatas)
                        'ruta_origen' => $this->limpiar($fila['ruta_origen'] ?? null, 2000),
                        'valor_constante' => $this->limpiar($fila['valor_constante'] ?? null, 255),
                        'transformacion' => $transformacion,
                        'valor_por_defecto' => $this->limpiar($fila['valor_por_defecto'] ?? null, 255),
                        'activo' => ! empty($fila['activo']),
                        'updated_at' => now(),
                    ]);
            }
        });

        return $actualizadas;
    }

    /**
     * Guarda las reglas: actualiza las que traen `id`, inserta las nuevas y borra
     * las que vengan en `$eliminar`.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<int, int|string>  $eliminar
     * @return array{actualizadas:int, creadas:int, eliminadas:int}
     */
    public function guardarReglas(array $filas, array $eliminar = []): array
    {
        $columnas = DBColumns::tmpLotesDet();
        $comparaciones = array_keys(self::COMPARACIONES);
        $nombresRazon = DB::table('razones_finalizacion')->pluck('name')->all();

        // Una regla solo aplica si su columna es de tipo `regla`; si se guardara
        // sobre una columna de campo o constante quedaria inerte y confundiria.
        $columnasDeRegla = $this->columnasDeRegla();

        // Dominios cerrados (banderas 1/0/vacio, error_origen -1..3): un valor
        // fuera de la lista revienta contra una columna tinyint al procesar el
        // lote, asi que se descarta al guardar.
        $dominios = $this->opcionesValorDestino();

        $resumen = ['actualizadas' => 0, 'creadas' => 0, 'eliminadas' => 0];

        DB::transaction(function () use ($filas, $eliminar, $columnas, $comparaciones, $nombresRazon, $columnasDeRegla, $dominios, &$resumen) {
            foreach ($eliminar as $id) {
                $resumen['eliminadas'] += DB::table('elevenlabs_mapeos_regla')
                    ->where('id', (int) $id)
                    ->delete();
            }

            foreach ($filas as $fila) {
                $destino = (string) ($fila['campo_destino'] ?? '');
                $ruta = trim((string) ($fila['ruta_origen'] ?? ''));
                if ($ruta === '' || ! in_array($destino, $columnas, true)) {
                    continue;
                }

                if (! in_array($destino, $columnasDeRegla, true)) {
                    continue;
                }

                $comparacion = (string) ($fila['comparacion'] ?? 'igual');
                if (! in_array($comparacion, $comparaciones, true)) {
                    $comparacion = 'igual';
                }

                $valorDestino = (string) ($fila['valor_destino'] ?? '');

                // Una razon que no existe deja la llamada en DESCONOCIDO en silencio.
                if ($destino === 'razon_finalizacion' && ! in_array($valorDestino, $nombresRazon, true)) {
                    continue;
                }

                // Fuera del dominio de la columna (ejm "true" en una bandera).
                if (($dominios[$destino]['tipo'] ?? '') === 'lista'
                    && ! in_array($valorDestino, $dominios[$destino]['opciones'] ?? [], true)) {
                    continue;
                }

                $valores = [
                    'ruta_origen' => mb_substr($ruta, 0, 200),
                    'comparacion' => $comparacion,
                    'valor_origen' => $this->limpiar($fila['valor_origen'] ?? null, 255),
                    'campo_destino' => $destino,
                    'valor_destino' => mb_substr($valorDestino, 0, 255),
                    'prioridad' => (int) ($fila['prioridad'] ?? 100),
                    'activo' => ! empty($fila['activo']),
                    'updated_at' => now(),
                ];

                $id = $fila['id'] ?? null;

                if ($id) {
                    $resumen['actualizadas'] += DB::table('elevenlabs_mapeos_regla')
                        ->where('id', (int) $id)
                        ->update($valores);

                    continue;
                }

                $valores['created_at'] = now();
                DB::table('elevenlabs_mapeos_regla')->insert($valores);
                $resumen['creadas']++;
            }
        });

        return $resumen;
    }

    private function limpiar(mixed $valor, int $max): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }
}
