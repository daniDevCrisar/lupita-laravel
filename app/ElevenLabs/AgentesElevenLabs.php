<?php

namespace App\ElevenLabs;

use Illuminate\Support\Facades\DB;

/**
 * Catalogo local de asistentes de Eleven Labs y sus equivalencias con el tipo de
 * llamada de Lupita (`elevenlabs_agentes`, ver la migracion de la tabla).
 *
 * El sync es "por nombre": se pide /v1/convai/agents y se hace firstOrCreate por
 * `agent_id`, refrescando el nombre. Nunca borra filas: si un agente desaparece
 * de la API se marca `activo = 0`, para no perder la equivalencia que el usuario
 * ya habia configurado.
 */
class AgentesElevenLabs
{
    /** Cache por request de `tipos_llamada` (id => nombre). */
    private static ?array $tiposCache = null;

    public function __construct(private readonly ElevenLabsClient $cliente)
    {
    }

    /**
     * Tipos de llamada disponibles, leidos de la tabla `tipos_llamada`.
     *
     * NO se hardcodean: es la misma tabla que usan ReportesController,
     * DBLlamadas::$tipos_llamada y los selects de lista_trts / palabras / scoring.
     * En esta base ya existe el id 4 ("En ruta") y la IA empezo a hacer esas
     * llamadas, asi que una lista fija en el codigo se quedaba corta.
     *
     * Se excluye el id 0 ("Indeterminada"): no es un tipo que se asigne, es el
     * valor de fallback cuando la IA no pudo clasificar.
     *
     * @return array<int, string>  id => nombre
     */
    public static function tiposLlamada(): array
    {
        if (self::$tiposCache === null) {
            $filas = DB::table('tipos_llamada')
                ->where('activo', 1)
                ->where('id', '>', 0)
                ->orderBy('id')
                ->pluck('nombre', 'id');

            $tipos = [];
            foreach ($filas as $id => $nombre) {
                $tipos[(int) $id] = (string) $nombre;
            }

            self::$tiposCache = $tipos;
        }

        return self::$tiposCache;
    }

    /**
     * Trae el catalogo del workspace y lo refleja en la tabla.
     *
     * @return array{nuevos:int, actualizados:int, vistos:int}
     */
    public function sincronizar(): array
    {
        $remotos = $this->cliente->agentes();

        $nuevos = 0;
        $actualizados = 0;
        $vistos = [];

        foreach ($remotos as $remoto) {
            $agentId = $remoto['agent_id'];
            if ($agentId === '') {
                continue;
            }
            $vistos[] = $agentId;

            $actual = DB::table('elevenlabs_agentes')->where('agent_id', $agentId)->first();

            if (! $actual) {
                DB::table('elevenlabs_agentes')->insert([
                    'agent_id' => $agentId,
                    'nombre' => $remoto['nombre'],
                    'tipo_llamada_id' => null,
                    'incluir_en_lote' => false,
                    'activo' => ! $remoto['archivado'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $nuevos++;

                continue;
            }

            $activo = ! $remoto['archivado'];
            if ($actual->nombre !== $remoto['nombre'] || (bool) $actual->activo !== $activo) {
                DB::table('elevenlabs_agentes')->where('agent_id', $agentId)->update([
                    'nombre' => $remoto['nombre'],
                    'activo' => $activo,
                    'updated_at' => now(),
                ]);
                $actualizados++;
            }
        }

        // Los que ya no vienen de la API quedan inactivos, no se borran.
        if ($vistos) {
            DB::table('elevenlabs_agentes')
                ->whereNotIn('agent_id', $vistos)
                ->where('activo', true)
                ->update(['activo' => false, 'updated_at' => now()]);
        }

        return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'vistos' => count($vistos)];
    }

    /**
     * @return array<int, object>
     */
    public function todos(): array
    {
        return DB::table('elevenlabs_agentes')
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->get()
            ->all();
    }

    /**
     * @return array<string, object>  agent_id => fila
     */
    public function porAgentId(): array
    {
        $mapa = [];
        foreach ($this->todos() as $fila) {
            $mapa[$fila->agent_id] = $fila;
        }

        return $mapa;
    }

    public function vacia(): bool
    {
        return DB::table('elevenlabs_agentes')->count() === 0;
    }

    /**
     * Guarda la configuracion que manda el modal de equivalencias.
     *
     * Solo actualiza agentes que ya existen: si llega un `agent_id` desconocido se
     * ignora en vez de crearlo, para que un POST manipulado no pueda meter filas
     * en el catalogo. Un `tipo_llamada_id` fuera del dominio (1,2,3,5,6) se
     * normaliza a NULL = "fuera del lote", nunca revienta el guardado entero.
     *
     * @param  array<int, array{agent_id?:string, tipo_llamada_id?:mixed, incluir_en_lote?:mixed, activo?:mixed}>  $filas
     * @return int  filas realmente actualizadas
     */
    public function actualizar(array $filas): int
    {
        $tiposValidos = array_keys(self::tiposLlamada());
        $actualizadas = 0;

        DB::transaction(function () use ($filas, $tiposValidos, &$actualizadas) {
            foreach ($filas as $fila) {
                $agentId = trim((string) ($fila['agent_id'] ?? ''));
                if ($agentId === '') {
                    continue;
                }

                $tipo = $fila['tipo_llamada_id'] ?? null;
                $tipo = ($tipo === null || $tipo === '') ? null : (int) $tipo;
                if ($tipo !== null && ! in_array($tipo, $tiposValidos, true)) {
                    $tipo = null;
                }

                $actualizadas += DB::table('elevenlabs_agentes')
                    ->where('agent_id', $agentId)
                    ->update([
                        'tipo_llamada_id' => $tipo,
                        'incluir_en_lote' => ! empty($fila['incluir_en_lote']),
                        'activo' => ! empty($fila['activo']),
                        'updated_at' => now(),
                    ]);
            }
        });

        return $actualizadas;
    }
}
