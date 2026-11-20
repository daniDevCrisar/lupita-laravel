<?php

namespace App\ElevenLabs;

use App\Database\DBColumns;
use App\Database\DBCore;
use Illuminate\Support\Facades\DB;

/**
 * Convierte el detalle de una llamada en una fila lista para `tmp_lotes_det`
 * y decide si esa llamada entra o no al lote.
 *
 * Es la pieza que faltaba de la Etapa 4 del plan: el `ResolvedorMapeo` dice QUE
 * valor va en cada columna; este servicio arma la fila en el orden del contrato,
 * resuelve los duplicados contra `llamadas` y explica por que una llamada no
 * entra.
 *
 * NO ESCRIBE NADA. La importacion (Etapa 5) es la que inserta.
 */
class TraductorElevenLabs
{
    /** Columnas que se muestran en la tabla de previsualizacion. */
    public const COLUMNAS_CLAVE = [
        'llamada_tipo',
        'ref',
        'origen',
        'destino',
        'telefono',
        'conductor',
        'fecha_prometida',
        'razon_finalizacion',
        'audio_duracion',
        'costo',
    ];

    /**
     * Motivos por los que una llamada NO se puede importar.
     * El texto es el que ve el usuario en la previsualizacion.
     */
    public const MOTIVOS = [
        'no_marcado_para_lote' => 'El asistente no está marcado para entrar al lote',
        'sin_tipo' => 'El asistente no tiene tipo de llamada asignado',
        'estado_no_final' => 'El estado de la llamada queda fuera de la política de importación',
        'sin_conductor' => 'Sin conductor: el pipeline descarta la llamada',
        'ya_importada' => 'Ya existe en llamadas (misma conversación)',
    ];

    public function __construct(
        private readonly ResolvedorMapeo $resolvedor,
        private readonly AgentesElevenLabs $agentes,
        private readonly OpcionesElevenLabs $opciones,
    ) {
    }

    /** Avisos que deja `fila()` para que `evaluar()` los reporte. */
    private array $notas = [];

    /**
     * Fila lista para `tmp_lotes_det`, en el orden exacto de DBColumns.
     *
     * @return array<string, string>  columna => valor
     */
    public function fila(array $detalle): array
    {
        $resuelto = $this->resolvedor->resolver($detalle);
        $fila = [];

        foreach (DBColumns::tmpLotesDet() as $columna) {
            $fila[$columna] = (string) ($resuelto[$columna]['valor'] ?? '');
        }

        $this->notas = [];

        // DECISION D2 (provisional): en inbound `carrier_name` viene vacio. Antes
        // de dejar la llamada sin conductor se busca en la base por telefono; si
        // aparece, se usa el nombre REAL del conductor (el pipeline lo empareja por
        // nombre). Si no aparece, queda vacio y el pipeline descarta la llamada,
        // cosa que la previsualizacion informa: no se inventa un nombre.
        if (trim($fila['conductor']) === '') {
            $porTelefono = $this->conductorPorTelefono($fila['telefono'] ?? '');

            if ($porTelefono !== null) {
                $fila['conductor'] = $porTelefono;
                $this->notas[] = 'Conductor resuelto por teléfono desde la base';
            }
        }

        return $fila;
    }

    /**
     * Nombre del conductor que ya tiene ese teléfono en `tlf_conductores`.
     */
    public function conductorPorTelefono(string $telefono): ?string
    {
        $telefono = preg_replace('/[^0-9]/', '', $telefono) ?? '';

        if ($telefono === '') {
            return null;
        }

        $fila = DB::table('tlf_conductores as t')
            ->join('conductores as c', 'c.id', '=', 't.conductor_id')
            ->where('t.telefono', $telefono)
            ->orderByDesc('c.activo')
            ->orderBy('c.id')
            ->select('c.nombres')
            ->first();

        return $fila && trim((string) $fila->nombres) !== '' ? (string) $fila->nombres : null;
    }

    /**
     * Decide si la llamada entra al lote.
     *
     * Las reglas son las mismas que aplica el pipeline, pero dichas en voz alta:
     * `DBTmpLlamadas::importar_llamadas_de_tmp_al_sistema()` descarta la llamada
     * si no tiene conductor, y el pipeline auto-clasifica cuando `llamada_tipo` es 0.
     * Mejor saberlo antes de crear el lote que descubrirlo en el log.
     *
     * @param  array<string, string>  $fila
     * @return array{incluye: bool, motivos: array<int, string>, avisos: array<int, string>}
     */
    public function evaluar(array $detalle, array $fila, bool $yaImportada = false): array
    {
        $motivos = [];
        $avisos = [];

        $agente = $this->agenteDe((string) ($detalle['agent_id'] ?? ''));

        if (! $agente) {
            $motivos[] = 'sin_tipo';
        } elseif (! $agente->incluir_en_lote) {
            $motivos[] = 'no_marcado_para_lote';
        }

        if (($fila['llamada_tipo'] ?? '') === '') {
            $motivos[] = 'sin_tipo';
        }

        // Que estados entran al lote se configura desde la pestaña «Política»
        // (tabla `elevenlabs_opciones`). Por defecto entran `done` y `failed`:
        // `initiated` es una conversacion que aun no termina, sin transcripcion
        // ni analisis, y volveria a aparecer despues como `done`.
        if (! $this->opciones->estadoEsImportable($detalle['status'] ?? null)) {
            $motivos[] = 'estado_no_final';
        }

        // Sin conductor el pipeline NO importa la llamada. Sigue pendiente la
        // decision D2 (en inbound `carrier_name` viene vacio): hasta entonces se
        // informa como motivo y no se inventa un conductor.
        if (trim((string) ($fila['conductor'] ?? '')) === '') {
            $motivos[] = 'sin_conductor';
        }

        if ($yaImportada) {
            $motivos[] = 'ya_importada';
        }

        if (trim((string) ($fila['mensajes_conten'] ?? '')) === '') {
            $avisos[] = 'La llamada no tiene transcripción (se importaría sin mensajes)';
        }

        if (trim((string) ($fila['ref'] ?? '')) === '') {
            $avisos[] = 'Sin referencia de viaje';
        }

        if (trim((string) ($fila['fecha_prometida'] ?? '')) === '') {
            $avisos[] = 'Sin fecha prometida interpretable';
        }

        // Avisos que dejó `fila()` (por ejemplo, conductor resuelto por teléfono)
        return [
            'incluye' => $motivos === [],
            'motivos' => array_values(array_unique($motivos)),
            'avisos' => array_values(array_unique(array_merge($this->notas, $avisos))),
        ];
    }

    /**
     * Inserta las filas en `tmp_lotes_det`.
     *
     * `vapi_id` es la PRIMARY KEY de esa tabla, así que `insertOrIgnore` deja fuera
     * las que ya estaban: reintentar una importación no duplica nada.
     *
     * @param  array<int, array<string, string>>  $filas
     * @return int  filas realmente insertadas
     */
    public function insertar(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        $columnas = DBColumns::tmpLotesDet();

        $ordenadas = [];
        foreach ($filas as $fila) {
            $ordenada = [];
            foreach ($columnas as $columna) {
                $ordenada[] = (string) ($fila[$columna] ?? '');
            }
            $ordenadas[] = $ordenada;
        }

        return DBCore::insertBatch('tmp_lotes_det', $columnas, $ordenadas);
    }

    /**
     * Ids que ya están en `tmp_lotes_det` (de cualquier lote).
     *
     * @param  array<int, string>  $ids
     * @return array<string, true>
     */
    public function yaEnTemporal(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('tmp_lotes_det')
            ->whereIn('vapi_id', $ids)
            ->pluck('vapi_id')
            ->mapWithKeys(fn ($id) => [$id => true])
            ->all();
    }


    /**
     * Ids de conversación que ya están en `llamadas`, por cualquiera de las dos
     * columnas (el id se escribe en `vapi_id` y en `el_id`).
     *
     * @param  array<int, string>  $ids
     * @return array<string, true>
     */
    public function yaImportadas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = DB::table('llamadas')
            ->select('vapi_id', 'el_id')
            ->whereIn('vapi_id', $ids)
            ->orWhereIn('el_id', $ids)
            ->get();

        $encontradas = [];
        foreach ($filas as $fila) {
            if ($fila->vapi_id !== null) {
                $encontradas[$fila->vapi_id] = true;
            }
            if ($fila->el_id !== null) {
                $encontradas[$fila->el_id] = true;
            }
        }

        return $encontradas;
    }

    /**
     * Resumen de una previsualizacion.
     *
     * @param  array<int, array<string, mixed>>  $llamadas
     * @return array{total:int, incluidas:int, fuera:int, motivos: array<string,int>, avisos:int}
     */
    public function resumir(array $llamadas): array
    {
        $resumen = [
            'total' => count($llamadas),
            'incluidas' => 0,
            'fuera' => 0,
            'motivos' => [],
            'avisos' => 0,
        ];

        foreach ($llamadas as $llamada) {
            if ($llamada['incluye']) {
                $resumen['incluidas']++;

                continue;
            }

            $resumen['fuera']++;

            foreach ($llamada['motivos'] as $motivo) {
                $resumen['motivos'][$motivo] = ($resumen['motivos'][$motivo] ?? 0) + 1;
            }
        }

        foreach ($llamadas as $llamada) {
            $resumen['avisos'] += count($llamada['avisos']);
        }

        return $resumen;
    }

    private function agenteDe(string $agentId): ?object
    {
        if ($agentId === '') {
            return null;
        }

        return DB::table('elevenlabs_agentes')->where('agent_id', $agentId)->first();
    }
}
