<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tools\ExcelTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ActualizarRefsController
 *
 * Puente con tceflexia.com (generalMilestonesRoute) para refrescar la tabla
 * `referencias` con data fresca del despachador, sin pasar por tmp_lotes_ref.
 *
 *  - devolver_refs_por_lotes(): GET /api/refs?lotes=csv  -> refs unicas de esos
 *    lotes que aun NO tienen fin_descargue (viajes completos = no se envian).
 *  - actualizar_refs():          POST /api/refs/actualizar -> escritura directa
 *    en referencias segun el flag referencias_de_lotes.
 *  - seleccion():        pantalla interna para elegir lotes por rango y armar
 *    el link de tceflexia.
 *  - documentacion():  contrato interna del API (que recibe / que devuelve).
 *
 * Reglas de negocio (confirmadas):
 *  referencias_de_lotes = true  -> SOLO data de los lotes enviados en el GET:
 *      titulo_viaje : solo si no tenia
 *      ruta_id      : solo si se obtuvo un titulo nuevo se reprocesa
 *      placa        : se remplaza siempre
 *      eventos      : se remplazan siempre con los nuevos
 *      refs nuevas  : NO se insertan (estado no_encontrada)
 *      refs con fin_descargue : se ignoran (estado completa)
 *  referencias_de_lotes = false -> upsert libre para incrementar data:
 *      existe  : reemplaza todo lo recibido
 *      no existe: INSERT nuevo (trt_id/conductor_id/fecha_despachador en NULL)
 *
 * fecha_despachador esta obsoleta: se ignora si llega.
 * Fechas aceptadas: d/m/Y H:i[:s] | d/m/Y | Y-m-d H:i[:s] | Y-m-d.
 * Vacio / null / '' = "no enviado" -> conserva el valor actual, nunca pisa a NULL.
 */
class ActualizarRefsController extends Controller
{
    // Eventos (milestones) que se escriben en referencias, en el orden del ciclo
    private const EVENTOS = [
        'compromiso_carga',
        'presenta_para_carga',
        'inicio_de_carga',
        'fin_de_carga',
        'inicio_ruta',
        'qr_llegada_destino',
        'inicio_descargue',
        'fin_descargue',
    ];

    public const MAX_REFS_POR_ENVIO = 1000;
    private const MAX_LOTES = 200;

    //=====================================================================
    // API PUBLICA (grupo /api, sin token, solo JSON) - estilo LlamadasApiController
    //=====================================================================

    /**
     * GET /api/refs?lotes=ID1,ID2,...
     * Devuelve las refs UNICAS de esos lotes que aun no tienen fin_descargue.
     */
    public function devolver_refs_por_lotes(Request $request)
    {
        $lotes = self::parse_lotes((string)$request->query('lotes', ''));

        if (empty($lotes)) {
            return response()->json([
                'success' => false,
                'error' => 'Parametro "lotes" vacio o invalido. Usar: ?lotes=ID1,ID2'
            ], 422);
        }

        $in = implode(',', array_fill(0, count($lotes), '?'));

        // refs de las llamadas de esos lotes, sin duplicados y excluding viajes
        // completos (fin_descargue ya registrado en referencias)
        $refs = DB::select("
            SELECT DISTINCT a.ref
            FROM llamadas a
            LEFT JOIN referencias r ON r.ref = a.ref
            WHERE a.lote_id IN ($in)
              AND a.ref IS NOT NULL AND a.ref <> ''
              AND r.fin_descargue IS NULL
            ORDER BY a.ref
        ", $lotes);

        $lista = array_map(fn($x) => $x->ref, $refs);

        return response()->json([
            'refs' => $lista,
            'total' => count($lista),
        ]);
    }

    /**
     * POST /api/refs/actualizar  (solo JSON)
     * Body: { "referencias_de_lotes": true|false, "refs": { "REF": {campos}, ... } }
     * Tambien acepta "refs" como lista de objetos con campo "ref".
     */
    public function actualizar_refs(Request $request)
    {
        if (!$request->isJson()) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se acepta JSON (Content-Type: application/json)'
            ], 415);
        }

        $payload = $request->json()->all();
        $de_lotes = !empty($payload['referencias_de_lotes']);

        if (!isset($payload['refs']) || !is_array($payload['refs'])) {
            return response()->json([
                'success' => false,
                'error' => 'Falta el campo "refs" (objeto {ref:{campos}} o lista [{ref:"X",...}])'
            ], 422);
        }

        $mapa = self::normalizar_refs($payload['refs']);

        if (empty($mapa)) {
            return response()->json([
                'success' => false,
                'error' => '"refs" llego vacio o sin claves de referencia validas'
            ], 422);
        }

        if (count($mapa) > self::MAX_REFS_POR_ENVIO) {
            return response()->json([
                'success' => false,
                'error' => 'Maximo ' . self::MAX_REFS_POR_ENVIO . ' referencias por envio'
            ], 422);
        }

        //------------------precargar el estado actual de todas las refs------------------
        $in = implode(',', array_fill(0, count($mapa), '?'));
        $existentes = DB::select("
            SELECT ref, titulo_viaje, placa, fin_descargue
            FROM referencias
            WHERE ref IN ($in)
        ", array_keys($mapa));

        $mapa_existentes = [];
        foreach ($existentes as $row)
            $mapa_existentes[$row->ref] = $row;

        //------------------validar TODO antes de escribir------------------
        $validos = [];   // ref => datos ya parseados
        $resultados = [];
        $resumen = [
            'recibidas' => count($mapa),
            'actualizadas' => 0,
            'nuevas' => 0,
            'completas' => 0,
            'no_encontradas' => 0,
            'sin_cambios' => 0,
            'invalidas' => 0,
        ];

        foreach ($mapa as $ref => $item) {
            // PHP re-vuelve clave int las refs numericas: normalizar a string
            $ref = (string)$ref;
            $errores = [];
            $datos = self::validar_ref($ref, $item, $errores);

            if ($errores) {
                $resumen['invalidas']++;
                $resultados[] = ['ref' => $ref, 'estado' => 'invalida', 'errores' => $errores];
                continue;
            }

            $validos[$ref] = $datos;
        }

        //------------------escritura directa a referencias------------------
        DB::transaction(function () use ($validos, $mapa_existentes, $de_lotes, &$resultados, &$resumen) {
            foreach ($validos as $ref => $datos) {
                $ref = (string)$ref;
                $existente = $mapa_existentes[$ref] ?? null;

                // ---- reglas de lotes: no insertar y no tocar viajes completos ----
                if ($de_lotes) {
                    if (!$existente) {
                        $resumen['no_encontradas']++;
                        $resultados[] = ['ref' => $ref, 'estado' => 'no_encontrada'];
                        continue;
                    }
                    if ($existente->fin_descargue !== null) {
                        $resumen['completas']++;
                        $resultados[] = ['ref' => $ref, 'estado' => 'completa'];
                        continue;
                    }
                }

                $set = [];      // columna => valor a escribir
                $campos = [];

                // titulo_viaje: en modo lotes SOLO si no tenia; en modo libre reemplaza
                if ($datos['titulo_viaje'] !== '') {
                    $titulo_viejo = trim((string)($existente->titulo_viaje ?? ''));
                    if (!$existente || !$de_lotes || $titulo_viejo === '') {
                        if (!$existente || $titulo_viejo !== $datos['titulo_viaje']) {
                            $set['titulo_viaje'] = $datos['titulo_viaje'];
                            $campos[] = 'titulo_viaje';
                        }
                    }
                }

                // placa: se remplaza siempre que venga data nueva
                if ($datos['placa'] !== '') {
                    if (!$existente || (string)$existente->placa !== $datos['placa']) {
                        $set['placa'] = $datos['placa'];
                        $campos[] = 'placa';
                    }
                }

                // eventos: se reemplazan siempre con los nuevos (vacio = no enviado)
                foreach (self::EVENTOS as $evento) {
                    if ($datos[$evento] !== '') {
                        $set[$evento] = $datos[$evento];
                        $campos[] = $evento;
                    }
                }

                try {
                    if (!$existente) {
                        // INSERT libre (solo modo sin lotes): se crea la referencia nueva
                        self::insertar_referencia($ref, $set);
                        $resumen['nuevas']++;
                        $estado = 'nueva';
                    } else {
                        if (empty($set)) {
                            $resumen['sin_cambios']++;
                            $resultados[] = ['ref' => $ref, 'estado' => 'sin_cambios'];
                            continue;
                        }
                        self::update_referencia($ref, $set);
                        $resumen['actualizadas']++;
                        $estado = 'actualizada';
                    }

                    // ruta_id: se reprocesa solo si se escribio un titulo nuevo
                    $ruta_reprocesada = false;
                    if (isset($set['titulo_viaje']))
                        $ruta_reprocesada = self::reprocesar_ruta($ref);

                    $resultados[] = [
                        'ref' => $ref,
                        'estado' => $estado,
                        'campos' => $campos,
                        'ruta_reprocesada' => $ruta_reprocesada,
                    ];
                } catch (\Throwable $e) {
                    $resumen['invalidas']++;
                    $resultados[] = [
                        'ref' => $ref,
                        'estado' => 'invalida',
                        'errores' => ['escritura: ' . $e->getMessage()],
                    ];
                }
            }
        });

        return response()->json([
            'success' => true,
            'referencias_de_lotes' => $de_lotes,
            'resumen' => $resumen,
            'resultados' => $resultados,
        ]);
    }

    //=====================================================================
    // PANTALLAS INTERNAS (grupo auth /lupita)
    //=====================================================================

    /**
     * GET /lupita/refs/actualizar : seleccion de lotes por rango + link a tceflexia
     */
    public function seleccion(Request $request)
    {
        $fecha_inicio = $request->query('fecha_inicio', date('Y-m-d', strtotime('-30 days')));
        $fecha_fin = $request->query('fecha_fin', date('Y-m-d'));

        // lote_id es BIGINT; se filtra por fecha de importacion del lote
        $lotes = DB::select("
            SELECT l.lote_id, l.nombre, l.comentario, l.created_at, u.nombres AS user_nombres,
                   COALESCE(rc.refs, 0) AS refs,
                   COALESCE(rc.pendientes, 0) AS pendientes
            FROM tmp_lotes l
            INNER JOIN users u ON u.id = l.usuario_id
            LEFT JOIN (
                SELECT a.lote_id,
                       COUNT(DISTINCT IF(a.ref <> '', a.ref, NULL)) AS refs,
                       COUNT(DISTINCT IF(a.ref <> '' AND r.fin_descargue IS NULL, a.ref, NULL)) AS pendientes
                FROM llamadas a
                LEFT JOIN referencias r ON r.ref = a.ref
                WHERE a.lote_id IN (
                    SELECT lote_id FROM tmp_lotes
                    WHERE procesado = 1 AND DATE(created_at) BETWEEN ? AND ?
                )
                GROUP BY a.lote_id
            ) rc ON rc.lote_id = l.lote_id
            WHERE l.procesado = 1 AND DATE(l.created_at) BETWEEN ? AND ?
            ORDER BY l.created_at DESC
            LIMIT 300
        ", [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);

        return view('refs.seleccion', compact('lotes', 'fecha_inicio', 'fecha_fin'));
    }

    /**
     * GET /lupita/refs/docs : documentacion del contrato del API
     */
    public function documentacion()
    {
        return view('refs.docs');
    }

    //=====================================================================
    // LOGICA DE APOYO
    //=====================================================================

    /** deja solo lote_id numericos, unicos, max MAX_LOTES */
    private static function parse_lotes(string $csv): array
    {
        $lotes = [];
        foreach (explode(',', $csv) as $lote) {
            $lote = trim($lote);
            if ($lote !== '' && ctype_digit($lote) && strlen($lote) <= 25)
                $lotes[$lote] = $lote;
            if (count($lotes) >= self::MAX_LOTES) break;
        }
        return array_values($lotes);
    }

    /** acepta dict {ref:{campos}} o lista [{ref:"X",...}]; devuelve ref=>item */
    private static function normalizar_refs(array $refs): array
    {
        $mapa = [];
        foreach ($refs as $key => $item) {
            if (!is_array($item)) continue;
            $ref = '';
            if (isset($item['ref']) && is_scalar($item['ref']))
                $ref = strtoupper(trim((string)$item['ref']));
            elseif (is_string($key) || is_int($key))
                // OJO: json_decode vuelve las refs numericas claves int, no string
                $ref = strtoupper(trim((string)$key));

            if ($ref !== '')
                $mapa[$ref] = $item;
        }
        return $mapa;
    }

    /**
     * Valida y parsea una ref recibida.
     * Retorna datos limpios: titulo_viaje, placa, cada evento como 'Y-m-d H:i:s'
     * o '' (no enviado / vacio). Los errores se acumulan por referencia.
     */
    private static function validar_ref(string $ref, array $item, array &$errores): array
    {
        // ref: alfanumerica corta (referencias.ref es VARCHAR(10))
        if (!preg_match('/^[A-Z0-9\-]{3,10}$/', $ref))
            $errores[] = 'ref: formato invalido (3-10 caracteres alfanumericos)';

        $titulo = ExcelTool::normalizarTexto($item['titulo_viaje'] ?? '');
        if (mb_strlen($titulo) > 200)
            $errores[] = 'titulo_viaje: excede 200 caracteres';

        // placa: normalizar y aplicar la regla de recorte por '/' (como DBReferencias)
        $placa = ExcelTool::normalizarTexto($item['placa'] ?? '');
        if (strlen($placa) > 10) {
            $placa = explode('/', $placa)[0];
        }
        if ($placa !== '' && strlen($placa) > 10)
            $errores[] = 'placa: excede 10 caracteres tras normalizar';

        $datos = ['titulo_viaje' => $titulo, 'placa' => $placa];

        // fecha_despachador: OBSOLETA, se ignora deliberadamente
        foreach (self::EVENTOS as $evento) {
            $valor = $item[$evento] ?? null;
            if ($valor === null || !is_string($valor)) {
                $valor = is_numeric($valor ?? null) ? (string)$valor : '';
            }
            $fecha = self::parse_fecha($valor);
            if ($fecha === null) {
                $errores[] = "$evento: fecha invalida \"$valor\" (esperado dd/mm/aaaa hh:mm)";
                $fecha = '';
            }
            $datos[$evento] = $fecha;
        }

        return $datos;
    }

    /**
     * Convierte la fecha recibida a 'Y-m-d H:i:s'.
     *  ''  = vacio/no enviado (conservar la actual)
     *  null = formato invalido
     */
    private static function parse_fecha($valor): ?string
    {
        $valor = trim((string)$valor);
        if ($valor === '' || $valor === '-') return '';

        // d/m/Y | d/m/Y H:i | d/m/Y H:i:s (tmb acepta "d/m/aaaa, hh:mm")
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ ,]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $valor, $m)) {
            $dia = (int)$m[1]; $mes = (int)$m[2]; $anio = (int)$m[3];
            $hora = (int)($m[4] ?? 0); $min = (int)($m[5] ?? 0); $seg = (int)($m[6] ?? 0);
            if (!checkdate($mes, $dia, $anio) || $hora > 23 || $min > 59 || $seg > 59) return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $anio, $mes, $dia, $hora, $min, $seg);
        }

        // Y-m-d | Y-m-d H:i | Y-m-d H:i:s (por si ya llega normalizada)
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $valor, $m)) {
            $anio = (int)$m[1]; $mes = (int)$m[2]; $dia = (int)$m[3];
            $hora = (int)($m[4] ?? 0); $min = (int)($m[5] ?? 0); $seg = (int)($m[6] ?? 0);
            if (!checkdate($mes, $dia, $anio) || $hora > 23 || $min > 59 || $seg > 59) return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $anio, $mes, $dia, $hora, $min, $seg);
        }

        return null;
    }

    /** UPDATE parcial: solo las columnas recibidas (blancas lista fija) */
    private static function update_referencia(string $ref, array $set): void
    {
        $cols = [];
        $binds = [];
        foreach ($set as $columna => $valor) {
            $cols[] = "$columna=?";
            $binds[] = $valor;
        }
        $binds[] = $ref;
        DB::update("UPDATE referencias SET " . implode(', ', $cols) . " WHERE ref=?", $binds);
    }

    /** INSERT de referencia nueva (modo libre): sin trt/conductor, sin fecha_despachador */
    private static function insertar_referencia(string $ref, array $set): void
    {
        $cols = ['ref', 'titulo_viaje', 'placa'];
        $binds = [$ref, $set['titulo_viaje'] ?? '', $set['placa'] ?? ''];
        foreach (self::EVENTOS as $evento) {
            if (($set[$evento] ?? '') !== '') {
                $cols[] = $evento;
                $binds[] = $set[$evento];
            }
        }
        $places = implode(',', array_fill(0, count($cols), '?'));
        DB::insert("INSERT INTO referencias (" . implode(', ', $cols) . ") VALUES ($places)", $binds);
    }

    /**
     * Reprocesa ruta_id de la ref con el SP existente.
     * La ref ya paso el filtro ^[A-Z0-9-]{3,10}$, asi que el inline es seguro
     * (mismo patron de DBReferencias::actualizar_rutas).
     */
    private static function reprocesar_ruta(string $ref): bool
    {
        try {
            DB::statement("CALL sp_actualizar_ruta_id('$ref')");
            return true;
        } catch (\Throwable $e) {
            // origen/destino no reconocidos en locales: el SP lanza SIGNAL; no frena el resto
            return false;
        }
    }
}
