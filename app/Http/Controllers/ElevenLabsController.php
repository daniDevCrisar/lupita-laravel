<?php

namespace App\Http\Controllers;

use App\Database\DBColumns;
use App\ElevenLabs\AgentesElevenLabs;
use App\ElevenLabs\ElevenLabsClient;
use App\ElevenLabs\ElevenLabsException;
use App\ElevenLabs\MapeoTraduccion;
use App\ElevenLabs\OpcionesElevenLabs;
use App\ElevenLabs\ResolvedorMapeo;
use App\ElevenLabs\TraductorElevenLabs;
use App\Database\Tmp\DBTmpLotes;
use App\Tools\ExcelTool;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Modulo Eleven Labs — SOLO LECTURA de la API de Conversational AI.
 *
 * Etapas 0-3 del plan (`z-eleven-labs/plan_modulo_eleven_labs.md`): esta pantalla
 * sirve para MIRAR y para configurar las equivalencias. No escribe en `llamadas`
 * ni en `tmp_lotes_det`; la importacion al lote es la Etapa 5.
 *
 * Lo que hace hoy:
 *   main()                   pantalla con rango fecha+hora, asistentes y detalle
 *   conversaciones()         listado crudo del rango (JSON, paginado por la API)
 *   detalleJson()            JSON completo de UNA conversacion
 *   audio()                  MP3 en streaming, sin tocar disco
 *   sincronizarAsistentes()  trae el catalogo de agentes a `elevenlabs_agentes`
 *   equivalencias()          catalogo de asistentes (pestana Asistentes)
 *   mapeo()                  equivalencias de traduccion (pestanas Campos y Reglas)
 *   guardarEquivalencias()   guarda las tres pestanas
 *   probarMapeo()            resuelve una llamada real contra el mapeo guardado
 */
class ElevenLabsController extends Controller
{
    /**
     * Tope de asistentes en el filtro: cada uno es una consulta a la API, y con
     * el workspace actual (11 agentes) 20 deja aire de sobra.
     */
    private const MAX_AGENTES_FILTRO = 20;

    public function __construct(
        private readonly ElevenLabsClient $cliente,
        private readonly AgentesElevenLabs $agentes,
        private readonly MapeoTraduccion $mapeo,
        private readonly ResolvedorMapeo $resolvedor,
        private readonly TraductorElevenLabs $traductor,
        private readonly OpcionesElevenLabs $opciones,
    ) {
    }

    // --------------------------------------------------------------------- main

    public function main(Request $request)
    {
        // Rango por defecto: hoy completo, en la zona horaria de la app
        // (config('app.timezone') = America/Lima). La API trabaja en epoch UTC;
        // la conversion la hace Carbon al pasar a segundos.
        [$desde, $hasta] = $this->rango($request);

        return view('elevenlabs.main', [
            'desde' => $desde,
            'hasta' => $hasta,
            'agentesDetalle' => $this->agentesParaJson(),
            'agentesVacios' => $this->agentes->vacia(),
            'configurado' => $this->cliente->configurado(),
            'tipos' => AgentesElevenLabs::tiposLlamada(),
            'puedeEditar' => (bool) $request->user()?->can('importar eleven labs'),
        ]);
    }

    // ------------------------------------------------------------ conversaciones

    /**
     * Listado del rango.
     *
     * FILTRO POR VARIOS ASISTENTES
     * `/v1/convai/conversations` solo acepta UN `agent_id` por llamada, asi que
     * cuando se piden varios asistentes se hace **una consulta por asistente** y se
     * unen los resultados. Antes se pedia el rango completo y se filtraba en el
     * navegador, lo que dejaba llamadas de otros asistentes en la tabla si algo
     * fallaba en el camino. Asi el servidor solo devuelve lo pedido.
     */
    public function conversaciones(Request $request)
    {
        [$desde, $hasta] = $this->rango($request);

        $agentes = $this->agentesPedidos($request);

        if (count($agentes) > self::MAX_AGENTES_FILTRO) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Demasiados asistentes en el filtro (máximo ' . self::MAX_AGENTES_FILTRO . ').',
            ], 422);
        }

        try {
            $conversaciones = [];
            $paginas = 0;
            $hasMore = false;
            $tope = false;
            $consultas = 0;
            $fallidos = [];

            // Sin filtro: una sola consulta al rango completo.
            $objetivos = $agentes === [] ? [null] : $agentes;

            foreach ($objetivos as $agentId) {
                try {
                    $resultado = $this->cliente->conversaciones($desde->timestamp, $hasta->timestamp, $agentId);
                } catch (ElevenLabsException $e) {
                    // Sin filtro el error es del rango y se propaga. Con filtro se
                    // salta ese asistente: si el catalogo tiene un id que ya no
                    // existe en ElevenLabs, no debe romper el listado de los demas.
                    if ($agentId === null) {
                        throw $e;
                    }

                    $fallidos[$agentId] = $this->mensajeError($e);

                    continue;
                }

                $consultas++;

                $conversaciones = array_merge($conversaciones, $resultado['conversations']);
                $paginas += $resultado['paginas'];
                $hasMore = $hasMore || $resultado['has_more'];
                $tope = $tope || $resultado['tope'];
            }

            // Si fallaron todos, no hay nada que mostrar.
            if ($consultas === 0 && $fallidos !== []) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => 'No se pudo consultar ninguno de los asistentes: '
                        . implode(' | ', array_values($fallidos)),
                ], 502);
            }
        } catch (ElevenLabsException $e) {
            return $this->errorJson($e);
        }

        // Una conversacion no puede venir dos veces (mismo id en dos consultas).
        $porId = [];
        foreach ($conversaciones as $c) {
            $id = (string) ($c['conversation_id'] ?? '');
            if ($id !== '') {
                $porId[$id] = $c;
            }
        }

        // Asistentes INACTIVOS fuera del listado, tambien cuando no hay filtro.
        // Antes, sin seleccion, se devolvia el rango completo y se colaban las
        // llamadas de los asistentes desactivados. Un agent_id que no este en el
        // catalogo NO se excluye: puede ser uno nuevo sin sincronizar, y conviene
        // que se vea para poder incorporarlo.
        $inactivos = DB::table('elevenlabs_agentes')
            ->where('activo', false)
            ->pluck('agent_id')
            ->all();

        $excluidasInactivas = 0;

        if ($inactivos !== []) {
            $inactivos = array_flip($inactivos);

            foreach ($porId as $id => $c) {
                if (isset($inactivos[(string) ($c['agent_id'] ?? '')])) {
                    unset($porId[$id]);
                    $excluidasInactivas++;
                }
            }
        }

        // Cada conversacion se acompaña de su equivalencia local para que la
        // tabla pueda decir a que tipo de llamada corresponde sin traducir nada.
        $mapa = $this->agentes->porAgentId();

        $filas = [];
        foreach ($porId as $c) {
            $agentIdFila = (string) ($c['agent_id'] ?? '');
            $agente = $mapa[$agentIdFila] ?? null;

            $filas[] = [
                'conversation_id' => (string) ($c['conversation_id'] ?? ''),
                'agent_id' => $agentIdFila,
                'agent_name' => (string) ($c['agent_name'] ?? ''),
                'start_time_unix_secs' => (int) ($c['start_time_unix_secs'] ?? 0),
                'inicio_local' => isset($c['start_time_unix_secs'])
                    ? Carbon::createFromTimestamp((int) $c['start_time_unix_secs'])->format('Y-m-d H:i:s')
                    : '',
                'call_duration_secs' => (int) ($c['call_duration_secs'] ?? 0),
                'status' => (string) ($c['status'] ?? ''),
                'direction' => (string) ($c['direction'] ?? ''),
                'call_successful' => (string) ($c['call_successful'] ?? ''),
                'termination_reason' => (string) ($c['termination_reason'] ?? ''),
                'message_count' => (int) ($c['message_count'] ?? 0),
                'cost' => (int) ($c['cost'] ?? 0),
                'cost_fiat' => (float) ($c['cost_fiat'] ?? 0),
                'call_summary_title' => (string) ($c['call_summary_title'] ?? ''),
                'main_language' => (string) ($c['main_language'] ?? ''),
                // equivalencia local (NULL = fuera del lote)
                'tipo_llamada_id' => $agente?->tipo_llamada_id !== null
                    ? (int) $agente->tipo_llamada_id
                    : null,
                'incluir_en_lote' => $agente ? (bool) $agente->incluir_en_lote : false,
            ];
        }

        // Orden descendente por fecha de inicio: lo mas reciente arriba, igual
        // que como lo devuelve la API pero explicito por si hubiera mezcla.
        usort($filas, fn ($a, $b) => $b['start_time_unix_secs'] <=> $a['start_time_unix_secs']);

        return response()->json([
            'ok' => true,
            'filas' => $filas,
            'meta' => [
                'total' => count($filas),
                'paginas' => $paginas,
                'has_more' => $hasMore,
                'tope' => $tope,
                'desde_local' => $desde->format('Y-m-d H:i:s'),
                'hasta_local' => $hasta->format('Y-m-d H:i:s'),
                'desde_unix' => $desde->timestamp,
                'hasta_unix' => $hasta->timestamp,
                'agentes' => $agentes,
                // cuantas consultas se hicieron a la API (1 sin filtro, N con filtro)
                'consultas' => $consultas,
                // asistentes que la API no devolvio (id inexistente, permisos...)
                'agentes_fallidos' => $fallidos,
                // llamadas descartadas por ser de un asistente desactivado
                'excluidas_inactivas' => $excluidasInactivas,
            ],
        ]);
    }

    /**
     * Asistentes pedidos en el filtro: `agentes[]=...` (nuevo) o `agent_id=` (viejo).
     *
     * @return array<int, string>
     */
    private function agentesPedidos(Request $request): array
    {
        $crudo = $request->query('agentes');

        if (is_string($crudo)) {
            $crudo = [$crudo];
        }

        if (! is_array($crudo) || $crudo === []) {
            $uno = trim((string) $request->query('agent_id', ''));

            return $uno === '' ? [] : [$uno];
        }

        $limpios = [];
        foreach ($crudo as $id) {
            $id = trim((string) $id);
            // Los ids de ElevenLabs son agent_ + alfanumericos; se filtra para que
            // un valor manipulado no viaje a la API.
            if ($id !== '' && preg_match('/^[A-Za-z0-9_]{6,64}$/', $id)) {
                $limpios[$id] = $id;
            }
        }

        return array_values($limpios);
    }

    // ------------------------------------------------------------- detalleJson

    /**
     * Detalle completo de UNA conversacion para el modal de la vista principal.
     *
     * Se devuelve el arreglo ya decodificado (no un string JSON armado aqui) para
     * que el navegador pueda pintar la conversacion, las variables de entrada y el
     * analisis por secciones. La pestaña "JSON" lo vuelve a serializar con
     * JSON.stringify, asi que no viaja dos veces el mismo contenido.
     */
    public function detalleJson(string $conversation_id)
    {
        try {
            $detalle = $this->cliente->detalle($conversation_id);
        } catch (ElevenLabsException $e) {
            return $this->errorJson($e);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation_id,
            'has_audio' => (bool) ($detalle['has_audio'] ?? false),
            'datos' => $detalle,
        ]);
    }

    // -------------------------------------------------------------------- audio

    /**
     * Sirve el MP3 sin almacenarlo (el disco del hosting es escaso).
     *
     * El navegador puede cachearlo en memoria mientras dura la sesion gracias al
     * `Cache-Control: private`, sin que el servidor guarde nada.
     *
     * OJO: la API de ElevenLabs NO soporta `Range` (responde 200 con el archivo
     * completo, nunca 206), asi que el reproductor no permite adelantar.
     */
    public function audio(string $conversation_id)
    {
        try {
            $respuesta = $this->cliente->audio($conversation_id);
        } catch (ElevenLabsException $e) {
            return $this->errorJson($e);
        }

        if (! $respuesta->successful()) {
            return $this->errorJson(
                ElevenLabsException::desdeRespuesta($respuesta->status(), $respuesta->json())
            );
        }

        $cuerpo = $respuesta->toPsrResponse()->getBody();

        $cabeceras = [
            'Content-Type' => $respuesta->header('Content-Type') ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="' . $conversation_id . '.mp3"',
            // cache del navegador, no del servidor
            'Cache-Control' => 'private, max-age=3600',
            // nginx: que no acumule la respuesta antes de enviarla
            'X-Accel-Buffering' => 'no',
        ];

        $largo = $respuesta->header('Content-Length');
        if ($largo) {
            $cabeceras['Content-Length'] = $largo;
        }

        return response()->stream(function () use ($cuerpo) {
            while (! $cuerpo->eof()) {
                echo $cuerpo->read(8192);

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, $cabeceras);
    }

    // -------------------------------------------------- sincronizarAsistentes

    public function sincronizarAsistentes(Request $request)
    {
        try {
            $resumen = $this->agentes->sincronizar();
        } catch (ElevenLabsException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'mensaje' => $this->mensajeError($e)], 502);
            }

            return redirect()
                ->route('elevenlabs.main')
                ->with('elevenlabs_error', $this->mensajeError($e));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'resumen' => $resumen,
                'tipos' => AgentesElevenLabs::tiposLlamada(),
                'agentes' => $this->agentesParaJson(),
            ]);
        }

        return redirect()
            ->route('elevenlabs.main')
            ->with(
                'elevenlabs_ok',
                'Asistentes sincronizados: ' . $resumen['vistos'] . ' vistos, '
                . $resumen['nuevos'] . ' nuevos, ' . $resumen['actualizados'] . ' actualizados.'
            );
    }

    // ------------------------------------------------------------ equivalencias

    /**
     * Catalogo de equivalencias agente -> tipo de llamada, para el modal.
     */
    public function equivalencias()
    {
        return response()->json([
            'ok' => true,
            'tipos' => AgentesElevenLabs::tiposLlamada(),
            'agentes' => $this->agentesParaJson(),
        ]);
    }

    // ------------------------------------------------------- mapeo de traduccion

    /**
     * Todo lo que necesita el modal de Equivalencias: asistentes, campos, reglas
     * y los catalogos para los selects.
     */
    public function mapeo()
    {
        return response()->json([
            'ok' => true,
            'tipos' => AgentesElevenLabs::tiposLlamada(),
            'agentes' => $this->agentesParaJson(),
            'campos' => $this->camposParaJson(),
            'reglas' => $this->reglasParaJson(),
            'transformaciones' => MapeoTraduccion::TRANSFORMACIONES,
            'comparaciones' => MapeoTraduccion::COMPARACIONES,
            'razones' => $this->mapeo->razones(),
            'columnas_regla' => $this->mapeo->columnasDeRegla(),
            'rutas_disparador' => $this->mapeo->rutasDisparador(),
            'opciones_valor' => $this->mapeo->opcionesValorDestino(),
            'columnas_destino' => DBColumns::tmpLotesDet(),
            // tope de cada columna de tmp_lotes_det, para que se vea en la pantalla
            'columnas_max' => $this->maximosDeColumna(),
            // politica del modulo (pestaña Politica): que estados entran al lote
            'opciones' => $this->opciones->paraPantalla(),
        ]);
    }

    /**
     * Largo maximo de cada columna de `tmp_lotes_det` (varchar). null en las TEXT.
     *
     * Sirve para que la pestaña Campos muestre "255 car." y no haya que adivinar
     * por que un valor largo aparece recortado.
     *
     * @return array<string, int|null>
     */
    private function maximosDeColumna(): array
    {
        $maximos = [];

        $filas = DB::select(
            "SELECT COLUMN_NAME AS columna, CHARACTER_MAXIMUM_LENGTH AS maximo
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'tmp_lotes_det'"
        );

        foreach ($filas as $fila) {
            $maximos[$fila->columna] = $fila->maximo !== null ? (int) $fila->maximo : null;
        }

        return $maximos;
    }

    /**
     * Guarda el modal de equivalencias (las tres pestanas juntas).
     *
     * Es lo unico de esta pantalla que escribe en base, y por eso exige el permiso
     * `importar eleven labs`: define que llamadas entraran al lote y con que datos.
     */
    public function guardarEquivalencias(Request $request)
    {
        $datos = $request->validate([
            'agentes' => ['sometimes', 'array'],
            'agentes.*.agent_id' => ['required', 'string', 'max:64'],
            'agentes.*.tipo_llamada_id' => ['nullable', 'integer', 'in:' . implode(',', array_keys(AgentesElevenLabs::tiposLlamada()))],
            'agentes.*.incluir_en_lote' => ['boolean'],
            'agentes.*.activo' => ['boolean'],

            'campos' => ['sometimes', 'array'],
            'campos.*.campo_destino' => ['required', 'string', 'max:64'],
            // Varias rutas candidatas, una por linea: la columna es `text` y una
            // cadena de 4-5 rutas largas pasa de 200 caracteres con facilidad.
            'campos.*.ruta_origen' => ['nullable', 'string', 'max:2000'],
            'campos.*.valor_constante' => ['nullable', 'string', 'max:255'],
            'campos.*.transformacion' => ['nullable', 'string', 'max:30'],
            'campos.*.valor_por_defecto' => ['nullable', 'string', 'max:255'],
            'campos.*.activo' => ['boolean'],

            'reglas' => ['sometimes', 'array'],
            'reglas.*.id' => ['nullable', 'integer'],
            'reglas.*.ruta_origen' => ['required', 'string', 'max:200'],
            'reglas.*.comparacion' => ['required', 'string', 'in:' . implode(',', array_keys(MapeoTraduccion::COMPARACIONES))],
            'reglas.*.valor_origen' => ['nullable', 'string', 'max:255'],
            'reglas.*.campo_destino' => ['required', 'string', 'max:64'],
            'reglas.*.valor_destino' => ['required', 'string', 'max:255'],
            'reglas.*.prioridad' => ['nullable', 'integer'],
            'reglas.*.activo' => ['boolean'],

            'reglas_eliminar' => ['sometimes', 'array'],
            'reglas_eliminar.*' => ['integer'],

            // Pestana Politica: el valor llega como lista de estados marcados.
            'opciones' => ['sometimes', 'array'],
            'opciones.*' => ['array'],
            'opciones.*.*' => ['string', 'max:64'],
        ]);

        $resumen = [
            'asistentes' => isset($datos['agentes']) ? $this->agentes->actualizar($datos['agentes']) : 0,
            'campos' => isset($datos['campos']) ? $this->mapeo->guardarCampos($datos['campos']) : 0,
            'reglas' => $this->mapeo->guardarReglas(
                $datos['reglas'] ?? [],
                $datos['reglas_eliminar'] ?? []
            ),
            'opciones' => isset($datos['opciones']) ? count($this->opciones->guardar($datos['opciones'])) : 0,
        ];

        return response()->json([
            'ok' => true,
            'resumen' => $resumen,
            'tipos' => AgentesElevenLabs::tiposLlamada(),
            'agentes' => $this->agentesParaJson(),
            'campos' => $this->camposParaJson(),
            'reglas' => $this->reglasParaJson(),
            'opciones' => $this->opciones->paraPantalla(),
        ]);
    }

    /**
     * Rutas hoja del JSON de una llamada, con su valor actual.
     *
     * Alimenta el buscador de rutas que hay al lado de cada campo: en vez de
     * escribir a mano `conversation_initiation_client_data.dynamic_variables.carrier_name`,
     * se busca por nombre o por valor y se elige.
     */
    public function rutasJson(Request $request)
    {
        $datos = $request->validate([
            'conversation_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $detalle = $this->cliente->detalle($datos['conversation_id']);
        } catch (ElevenLabsException $e) {
            return $this->errorJson($e);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => (string) ($detalle['conversation_id'] ?? $datos['conversation_id']),
            'agente' => (string) ($detalle['agent_name'] ?? ''),
            'rutas' => $this->resolvedor->aplanar($detalle),
        ]);
    }

    /**
     * "Probar": resuelve una llamada real contra el mapeo guardado, sin escribir
     * nada. Es la forma de validar la configuracion antes de la importacion.
     */
    public function probarMapeo(Request $request)
    {
        $datos = $request->validate([
            'conversation_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $detalle = $this->cliente->detalle($datos['conversation_id']);
        } catch (ElevenLabsException $e) {
            return $this->errorJson($e);
        }

        $resuelto = $this->resolvedor->resolver($detalle);

        $filas = [];
        foreach (DBColumns::tmpLotesDet() as $columna) {
            $fila = $resuelto[$columna] ?? ['valor' => '', 'estado' => 'defecto', 'nota' => 'sin configuración'];

            $filas[] = [
                'campo' => $columna,
                'valor' => (string) $fila['valor'],
                'estado' => $fila['estado'],
                'nota' => (string) $fila['nota'],
            ];
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $detalle['conversation_id'] ?? $datos['conversation_id'],
            'agente' => (string) ($detalle['agent_name'] ?? ''),
            'inicio_local' => $this->resolvedor->inicioLocal($detalle),
            'has_audio' => (bool) ($detalle['has_audio'] ?? false),
            'filas' => $filas,
            // Mapa real del JSON: el selector de "ruta disparador" y el de "ruta"
            // de la pestaña Campos se llenan con esto en vez de una lista fija.
            'rutas' => $this->resolvedor->aplanar($detalle),
        ]);
    }

    // ------------------------------------------------------------- importacion

    /**
     * Etapa 5: importa un grupo de llamadas al lote (`tmp_lotes_det`).
     *
     * Va por BLOQUES de 25 (el mismo tope que la previsualizacion) porque cada
     * llamada cuesta una consulta de detalle a la API: un rango de 200 llamadas en
     * un solo request tumbaria cualquier timeout.
     *
     * NO crea `llamadas` directamente: arma el lote y deja que el pipeline de
     * siempre ("Procesar lote") resuelva conductores, TRT, refs, mensajes y scoring.
     * `lote_id` viene en el primer bloque y se reenvia en los siguientes.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importar(Request $request)
    {
        // El middleware de la ruta ya exige este permiso; se comprueba igual porque
        // esta accion escribe en `tmp_lotes` / `tmp_lotes_det`. Se mira tambien
        // `Auth::user()`: `$request->user()` es null cuando la peticion se construye
        // a mano (pruebas), pero nunca en una peticion HTTP real.
        $usuario = $request->user() ?? \Illuminate\Support\Facades\Auth::user();

        if (! $usuario?->can('importar eleven labs')) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Falta el permiso «importar eleven labs».',
            ], 403);
        }

        $datos = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:25'],
            'conversation_ids.*' => ['required', 'string', 'max:64'],
            'lote_id' => ['nullable', 'string', 'max:100'],
            'rango_texto' => ['nullable', 'string', 'max:120'],
        ]);

        $ids = array_values(array_unique($datos['conversation_ids']));

        // El lote se crea en el primer bloque; los siguientes lo reciben.
        $loteId = $datos['lote_id'] ?? null;
        $creado = false;

        if ($loteId === null || $loteId === '') {
            $loteId = (string) ExcelTool::generarLoteId();

            // `tmp_lotes.nombre` es varchar(50) y `comentario` varchar(200): el
            // rango completo (ejm "2026-10-01 00:00 hasta 2026-10-15 11:00") no
            // entra en el nombre, asi que se recorta y el texto completo va al
            // comentario, que tiene mas espacio.
            $rango = trim((string) ($datos['rango_texto'] ?? ''));

            try {
                DBTmpLotes::crear(
                    $loteId,
                    mb_substr('ElevenLabs API' . ($rango !== '' ? ' ' . $rango : ''), 0, 50),
                    mb_substr(
                        'Importado desde la API de ElevenLabs'
                            . ($rango !== '' ? '. Rango: ' . $rango : ''),
                        0,
                        200
                    ),
                    // tipo 2 = API de ElevenLabs (1 = Excel/VAPI)
                    2
                );
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => 'No se pudo crear el lote: ' . $e->getMessage(),
                ], 500);
            }

            $creado = true;
        }

        $yaImportadas = $this->traductor->yaImportadas($ids);
        $yaEnTemporal = $this->traductor->yaEnTemporal($ids);

        $filas = [];
        $errores = [];
        $motivos = [];
        $omitidas = 0;
        $duplicadas = 0;

        foreach ($ids as $id) {
            if (isset($yaImportadas[$id])) {
                $duplicadas++;
                $motivos['ya_importada'] = ($motivos['ya_importada'] ?? 0) + 1;

                continue;
            }

            if (isset($yaEnTemporal[$id])) {
                $duplicadas++;
                $motivos['ya_en_el_lote'] = ($motivos['ya_en_el_lote'] ?? 0) + 1;

                continue;
            }

            try {
                $detalle = $this->cliente->detalle($id);
            } catch (ElevenLabsException $e) {
                $errores[] = ['conversation_id' => $id, 'mensaje' => $this->mensajeError($e)];

                continue;
            }

            $fila = $this->traductor->fila($detalle);
            $evaluacion = $this->traductor->evaluar($detalle, $fila);

            if (! $evaluacion['incluye']) {
                $omitidas++;

                foreach ($evaluacion['motivos'] as $motivo) {
                    $motivos[$motivo] = ($motivos[$motivo] ?? 0) + 1;
                }

                continue;
            }

            $fila['lote_id'] = (string) $loteId;
            $filas[] = $fila;
        }

        try {
            $insertadas = $this->traductor->insertar($filas);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'lote_id' => (string) $loteId,
                'lote_creado' => $creado,
                'mensaje' => 'No se pudo guardar el detalle del lote: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'lote_id' => (string) $loteId,
            'lote_creado' => $creado,
            'pedidas' => count($datos['conversation_ids']),
            'insertadas' => $insertadas,
            'omitidas' => $omitidas,
            'duplicadas' => $duplicadas,
            'motivos' => $motivos,
            'errores' => $errores,
            'url_lote' => route('importar.excel.lote', ['lote_id' => $loteId]),
            'motivos_texto' => TraductorElevenLabs::MOTIVOS,
        ]);
    }

    // -------------------------------------------------------- previsualizacion

    /**
     * Etapa 4b: traduce una lista de llamadas y dice cuales entrarian al lote.
     *
     * NO ESCRIBE NADA: es la revision previa a la importacion. Se limita a 25
     * llamadas por pedido porque cada una cuesta una consulta de detalle a la API
     * (que no gasta creditos, pero si tiempo).
     */
    public function previsualizar(Request $request)
    {
        $datos = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:25'],
            'conversation_ids.*' => ['required', 'string', 'max:64'],
        ]);

        $ids = array_values(array_unique($datos['conversation_ids']));
        $yaImportadas = $this->traductor->yaImportadas($ids);

        $llamadas = [];
        $errores = [];

        foreach ($ids as $id) {
            try {
                $detalle = $this->cliente->detalle($id);
            } catch (ElevenLabsException $e) {
                $errores[] = ['conversation_id' => $id, 'mensaje' => $this->mensajeError($e)];

                continue;
            }

            $fila = $this->traductor->fila($detalle);
            $evaluacion = $this->traductor->evaluar($detalle, $fila, isset($yaImportadas[$id]));

            $clave = [];
            foreach (TraductorElevenLabs::COLUMNAS_CLAVE as $columna) {
                $clave[$columna] = $fila[$columna] ?? '';
            }

            $llamadas[] = [
                'conversation_id' => (string) ($detalle['conversation_id'] ?? $id),
                'agente' => (string) ($detalle['agent_name'] ?? ''),
                'inicio_local' => $this->resolvedor->inicioLocal($detalle),
                'status' => (string) ($detalle['status'] ?? ''),
                'incluye' => $evaluacion['incluye'],
                'motivos' => $evaluacion['motivos'],
                'avisos' => $evaluacion['avisos'],
                'clave' => $clave,
            ];
        }

        return response()->json([
            'ok' => true,
            'resumen' => $this->traductor->resumir($llamadas),
            'llamadas' => $llamadas,
            'errores' => $errores,
            'columnas_clave' => TraductorElevenLabs::COLUMNAS_CLAVE,
            'motivos_texto' => TraductorElevenLabs::MOTIVOS,
            'solicitadas' => count($datos['conversation_ids']),
            'traducidas' => count($llamadas),
        ]);
    }

    // ------------------------------------------------------------------ internos

    /** @return array<int, array<string, mixed>> */
    private function camposParaJson(): array
    {
        $filas = [];

        foreach ($this->mapeo->campos() as $campo) {
            $filas[] = [
                'campo_destino' => $campo->campo_destino,
                'tipo_origen' => $campo->tipo_origen,
                'ruta_origen' => $campo->ruta_origen,
                'valor_constante' => $campo->valor_constante,
                'transformacion' => $campo->transformacion,
                'valor_por_defecto' => $campo->valor_por_defecto,
                'descripcion' => $campo->descripcion,
                'activo' => (bool) $campo->activo,
            ];
        }

        return $filas;
    }

    /** @return array<int, array<string, mixed>> */
    private function reglasParaJson(): array
    {
        $filas = [];

        foreach ($this->mapeo->reglas() as $regla) {
            $filas[] = [
                'id' => (int) $regla->id,
                'ruta_origen' => $regla->ruta_origen,
                'comparacion' => $regla->comparacion,
                'valor_origen' => $regla->valor_origen,
                'campo_destino' => $regla->campo_destino,
                'valor_destino' => $regla->valor_destino,
                'prioridad' => (int) $regla->prioridad,
                'descripcion' => $regla->descripcion,
                'activo' => (bool) $regla->activo,
            ];
        }

        return $filas;
    }

    /**
     * El catalogo de agentes en el shape que consume el navegador.
     *
     * @return array<int, array{agent_id:string, nombre:string, tipo_llamada_id:int|null, incluir_en_lote:bool, activo:bool}>
     */
    private function agentesParaJson(): array
    {
        $filas = [];

        foreach ($this->agentes->todos() as $fila) {
            $filas[] = [
                'agent_id' => $fila->agent_id,
                'nombre' => (string) $fila->nombre,
                'tipo_llamada_id' => $fila->tipo_llamada_id !== null ? (int) $fila->tipo_llamada_id : null,
                'incluir_en_lote' => (bool) $fila->incluir_en_lote,
                'activo' => (bool) $fila->activo,
            ];
        }

        return $filas;
    }

    /**
     * Rango pedido en hora local de la app.
     *
     * Se aceptan los campos por separado (fecha + hora) porque la pantalla los
     * pide asi; si no vienen, el rango por defecto es "hoy" completo.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(Request $request): array
    {
        $tz = config('app.timezone');

        $desde = $this->fechaHora(
            (string) $request->query('fecha_desde', ''),
            (string) $request->query('hora_desde', ''),
            $tz
        ) ?? Carbon::now($tz)->startOfDay();

        $hasta = $this->fechaHora(
            (string) $request->query('fecha_hasta', ''),
            (string) $request->query('hora_hasta', ''),
            $tz
        ) ?? Carbon::now($tz)->endOfDay();

        // Un rango invertido se corrige en vez de devolver vacio: es el error de
        // tipeo mas comun y no hay razon para castigarlo.
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
        }

        return [$desde, $hasta];
    }

    private function fechaHora(string $fecha, string $hora, string $tz): ?Carbon
    {
        $fecha = trim($fecha);
        if ($fecha === '') {
            return null;
        }

        $hora = trim($hora);
        if ($hora === '') {
            $hora = '00:00';
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i', $fecha . ' ' . $hora, $tz) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function errorJson(ElevenLabsException $e)
    {
        $status = match (true) {
            $e->codigoApi === 'id_invalido' => 400,
            $e->esNoEncontrado() => 404,
            $e->esSinKey() => 400,
            default => 502,
        };

        return response()->json([
            'ok' => false,
            'mensaje' => $this->mensajeError($e),
            'status_api' => $e->status,
            'codigo' => $e->codigoApi,
        ], $status);
    }

    private function mensajeError(ElevenLabsException $e): string
    {
        if ($e->esSinKey() && $e->codigoApi === 'sin_api_key') {
            return 'Falta ELEVENLABS_API_KEY en el .env del servidor.';
        }

        if ($e->esSinPermiso()) {
            return 'La key de Eleven Labs no tiene el permiso necesario: ' . $e->getMessage();
        }

        if ($e->esSinKey()) {
            return 'La key de Eleven Labs fue rechazada (401): ' . $e->getMessage();
        }

        return $e->getMessage();
    }
}
