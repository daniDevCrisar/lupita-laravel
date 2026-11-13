<?php

namespace App\Database;

use App\Tools\ExcelTool;
use Illuminate\Support\Facades\DB;

/**
 * Escritura de `referencias` desde el payload del despachador
 * (`POST /api/refs/actualizar`) y desde el endpoint de predicción, que puede
 * persistir la historia que recibe.
 *
 * Este código vivía dentro de `ActualizarRefsController`; se movió tal cual para
 * que la predicción pueda reusarlo sin duplicar las reglas de negocio. El
 * controlador sigue validando la entrada y devolviendo el mismo JSON de siempre:
 * aquí no cambió ninguna regla.
 *
 * Reglas (confirmadas, no tocar):
 *  referencias_de_lotes = true  -> SOLO data de los lotes enviados en el GET:
 *      titulo_viaje : solo si no tenia
 *      ruta_id      : solo si se obtuvo un titulo nuevo se reprocesa
 *      placa        : se remplaza siempre
 *      eventos      : se reemplazan siempre con los nuevos
 *      refs nuevas  : NO se insertan (estado no_encontrada)
 *      refs con fin_descargue : se ignoran (estado completa)
 *  referencias_de_lotes = false -> upsert libre para incrementar data:
 *      existe  : reemplaza todo lo recibido
 *      no existe: INSERT nuevo (fecha_despachador en NULL; trt_id/conductor_id
 *                solo si vinieron en el payload)
 *
 * fecha_despachador esta obsoleta: se ignora si llega.
 * Fechas aceptadas: d/m/Y H:i[:s] | d/m/Y | Y-m-d H:i[:s] | Y-m-d.
 * Vacio / null / '' = "no enviado" -> conserva el valor actual, nunca pisa a NULL.
 * conductor: se acepta el NOMBRE ("conductor" o "nombres") y se resuelve su id
 *   por similitud con DBConductoresResolver; tambien "conductor_id" numerico.
 *   Se escribe en referencias.conductor_id SOLO si la ref no tiene uno.
 * trt: SOLO el NOMBRE del transportista ("trt" o "transportista"), nunca RUC;
 *   tambien "trt_id" numerico directo. Si el nombre no existe se CREA un trts
 *   nuevo. Se escribe en referencias.trt_id SOLO si la ref no tiene uno.
 */
class DBRefsActualizar
{
    /** Eventos (milestones) que se escriben en referencias, en el orden del ciclo. */
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

    public const MAX_LOTES = 200;

    /** Cache nombre->resolucion para no repetir la busqueda por similitud por ref. */
    private static array $conductor_cache = [];

    /** Cache trt->id para no repetir la busqueda en trts por cada ref. */
    private static array $trt_cache = [];

    /**
     * Aplica el payload completo. Devuelve el mismo arreglo que serializa
     * `ActualizarRefsController::actualizar_refs()`.
     *
     * Un payload sin `refs` usables devuelve `success => false`: el controlador
     * ya lo rechazó antes con 422, así que ese camino sólo existe para llamadores
     * nuevos (la API de predicción valida igual antes de entrar).
     */
    public static function aplicar(array $payload): array
    {
        $de_lotes = !empty($payload['referencias_de_lotes']);

        if (!isset($payload['refs']) || !is_array($payload['refs'])) {
            return ['success' => false, 'error' => 'Falta el campo "refs"'];
        }

        $mapa = self::normalizar_refs($payload['refs']);
        if ($mapa === []) {
            return ['success' => false, 'error' => '"refs" llego vacio o sin claves de referencia validas'];
        }
        if (count($mapa) > self::MAX_REFS_POR_ENVIO) {
            return ['success' => false, 'error' => 'Maximo ' . self::MAX_REFS_POR_ENVIO . ' referencias por envio'];
        }

        //------------------precargar el estado actual de todas las refs------------------
        $in = implode(',', array_fill(0, count($mapa), '?'));
        $existentes = DB::select("
            SELECT ref, titulo_viaje, placa, conductor_id, trt_id, fin_descargue
            FROM referencias
            WHERE ref IN ($in)
        ", array_keys($mapa));

        $mapa_existentes = [];
        foreach ($existentes as $row) {
            $mapa_existentes[$row->ref] = $row;
        }

        //------------------validar TODO antes de escribir------------------
        $validos = [];
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
            $ref = (string) $ref;
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
                $ref = (string) $ref;
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
                    $titulo_viejo = trim((string) ($existente->titulo_viaje ?? ''));
                    if (!$existente || !$de_lotes || $titulo_viejo === '') {
                        if (!$existente || $titulo_viejo !== $datos['titulo_viaje']) {
                            $set['titulo_viaje'] = $datos['titulo_viaje'];
                            $campos[] = 'titulo_viaje';
                        }
                    }
                }

                // placa: se remplaza siempre que venga data nueva
                if ($datos['placa'] !== '') {
                    if (!$existente || (string) $existente->placa !== $datos['placa']) {
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

                // conductor: se RELLENA solo cuando la ref todavia NO tiene
                $conductor_info = null;
                $conductor_enviado = ($datos['conductor'] !== '' || $datos['conductor_id'] !== null);
                if ($conductor_enviado) {
                    $actual_cid = $existente ? (int) ($existente->conductor_id ?? 0) : 0;

                    if ($actual_cid === 0) {
                        $conductor_id = $datos['conductor_id'];
                        if ($conductor_id === null && $datos['conductor'] !== '') {
                            $res = self::resolver_conductor($datos['conductor']);
                            if (!empty($res['id'])) {
                                $conductor_id = (int) $res['id'];
                                $conductor_info = [
                                    'id' => $conductor_id,
                                    'nombre' => (string) ($res['row']->conductor ?? ''),
                                    'sim' => $res['comparar'],
                                    'accion' => $res['accion'],
                                    'motivo' => $res['motivo'],
                                    'escrito' => true,
                                ];
                            } else {
                                $conductor_info = [
                                    'id' => null,
                                    'estado' => 'no_encontrado',
                                    'accion' => $res['accion'],
                                    'sim' => $res['comparar'],
                                    'cercano' => $res['candidatos'][0] ?? null,
                                ];
                            }
                        }
                        if ($conductor_id !== null) {
                            $set['conductor_id'] = $conductor_id;
                            $campos[] = 'conductor_id';
                            $conductor_info = $conductor_info ?? ['id' => $conductor_id, 'via' => 'conductor_id', 'escrito' => true];
                        }
                    } else {
                        $conductor_info = ['id' => $actual_cid, 'estado' => 'ya_definido'];
                    }
                }

                // trt: se RELLENA solo cuando la ref todavia NO tiene trt_id
                $trt_info = null;
                $trt_enviado = ($datos['trt'] !== '' || $datos['trt_id'] !== null);
                if ($trt_enviado) {
                    $actual_tid = $existente ? (int) ($existente->trt_id ?? 0) : 0;

                    if ($actual_tid === 0) {
                        $trt_id = $datos['trt_id'];
                        if ($trt_id === null && $datos['trt'] !== '') {
                            $res = self::resolver_trt($datos['trt']);
                            if (!empty($res['id'])) {
                                $trt_id = (int) $res['id'];
                                $trt_info = [
                                    'id' => $trt_id,
                                    'nombre' => (string) ($res['nombre'] ?? ''),
                                    'via' => $res['via'],
                                    'escrito' => true,
                                ];
                            } else {
                                $trt_info = [
                                    'id' => null,
                                    'estado' => 'no_encontrado',
                                    'cercano' => $res['candidatos'][0] ?? null,
                                ];
                            }
                        }
                        if ($trt_id !== null) {
                            $set['trt_id'] = $trt_id;
                            $campos[] = 'trt_id';
                            $trt_info = $trt_info ?? ['id' => $trt_id, 'via' => 'trt_id', 'escrito' => true];
                        }
                    } else {
                        $trt_info = ['id' => $actual_tid, 'estado' => 'ya_definido'];
                    }
                }

                try {
                    if (!$existente) {
                        self::insertar_referencia($ref, $set);
                        $resumen['nuevas']++;
                        $estado = 'nueva';
                    } else {
                        if (empty($set)) {
                            $resumen['sin_cambios']++;
                            $resultados[] = array_filter([
                                'ref' => $ref,
                                'estado' => 'sin_cambios',
                                'conductor' => $conductor_info,
                                'trt' => $trt_info,
                            ], fn ($v) => $v !== null);
                            continue;
                        }
                        self::update_referencia($ref, $set);
                        $resumen['actualizadas']++;
                        $estado = 'actualizada';
                    }

                    // ruta_id: se reprocesa solo si se escribio un titulo nuevo
                    $ruta_reprocesada = false;
                    if (isset($set['titulo_viaje'])) {
                        $ruta_reprocesada = self::reprocesar_ruta($ref);
                    }

                    $resultados[] = array_filter([
                        'ref' => $ref,
                        'estado' => $estado,
                        'campos' => $campos,
                        'ruta_reprocesada' => $ruta_reprocesada,
                        'conductor' => $conductor_info,
                        'trt' => $trt_info,
                    ], fn ($v) => $v !== null);
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

        return [
            'success' => true,
            'referencias_de_lotes' => $de_lotes,
            'resumen' => $resumen,
            'resultados' => $resultados,
        ];
    }

    /** deja solo lote_id numericos, unicos, max MAX_LOTES */
    public static function parse_lotes(string $csv): array
    {
        $lotes = [];
        foreach (explode(',', $csv) as $lote) {
            $lote = trim($lote);
            if ($lote !== '' && ctype_digit($lote) && strlen($lote) <= 25) {
                $lotes[$lote] = $lote;
            }
            if (count($lotes) >= self::MAX_LOTES) {
                break;
            }
        }

        return array_values($lotes);
    }

    /**
     * Resuelve el nombre de un conductor a su id con DBConductoresResolver
     * (misma logica de similitud que la importacion). Se memoiza por request.
     */
    private static function resolver_conductor(string $nombre): array
    {
        $clave = mb_strtoupper(trim($nombre));
        if (array_key_exists($clave, self::$conductor_cache)) {
            return self::$conductor_cache[$clave];
        }

        return self::$conductor_cache[$clave] =
            DBConductoresResolver::resolver((object) ['conductor' => $nombre]);
    }

    /** normaliza un trt para comparar: mayusculas, sin espacios sobrantes */
    private static function normalizar_trt(string $trt): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper($trt)));
    }

    /**
     * Busca el trt (nombre del transportista, NUNCA RUC) en la tabla trts.
     *
     * @return array{id: ?int, nombre: string, via: string, candidatos: array}
     */
    private static function resolver_trt(string $trt): array
    {
        $clave = self::normalizar_trt($trt);
        if (array_key_exists($clave, self::$trt_cache)) {
            return self::$trt_cache[$clave];
        }

        $res = ['id' => null, 'nombre' => $clave, 'via' => '', 'candidatos' => []];
        if ($clave === '') {
            return self::$trt_cache[$clave] = $res;
        }

        // 1) nombre exacto normalizado: primero activos, luego tambien inactivos
        foreach ([true, false] as $solo_activos) {
            $row = DB::selectOne("
                SELECT id, nombres FROM trts
                WHERE REGEXP_REPLACE(UPPER(TRIM(nombres)), '[[:space:]]+', ' ') = ?
                  " . ($solo_activos ? 'AND activo = 1' : '') . "
                ORDER BY id LIMIT 1
            ", [$clave]);
            if ($row) {
                return self::$trt_cache[$clave] = ['id' => (int) $row->id, 'nombre' => (string) $row->nombres, 'via' => 'nombre_exacto', 'candidatos' => []];
            }
        }

        // 2) LIKE solo entre activos y con UNICO candidato
        $filas = DB::select("
            SELECT id, nombres FROM trts
            WHERE activo = 1 AND nombres LIKE ?
            ORDER BY id LIMIT 3
        ", ['%' . str_replace(['%', '_'], ['\%', '\_'], $clave) . '%']);
        if (count($filas) === 1) {
            return self::$trt_cache[$clave] = ['id' => (int) $filas[0]->id, 'nombre' => (string) $filas[0]->nombres, 'via' => 'unico_parecido', 'candidatos' => []];
        }
        if (count($filas) > 1) {
            $res['candidatos'] = array_map(fn ($f) => ['id' => (int) $f->id, 'nombre' => (string) $f->nombres], $filas);

            return self::$trt_cache[$clave] = $res;
        }

        // 3) no existe en trts: se ALTA uno nuevo con ese nombre
        try {
            $id = DB::table('trts')->insertGetId(['nombres' => $clave, 'activo' => 1]);
            $res = ['id' => (int) $id, 'nombre' => $clave, 'via' => 'nombre_nuevo', 'candidatos' => []];
        } catch (\Throwable $e) {
            // sin alta (p.ej. fallo de conexion): se reporta como no encontrado
        }

        return self::$trt_cache[$clave] = $res;
    }

    /** acepta dict {ref:{campos}} o lista [{ref:"X",...}]; devuelve ref=>item */
    public static function normalizar_refs(array $refs): array
    {
        $mapa = [];
        foreach ($refs as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $ref = '';
            if (isset($item['ref']) && is_scalar($item['ref'])) {
                $ref = strtoupper(trim((string) $item['ref']));
            } elseif (is_string($key) || is_int($key)) {
                // OJO: json_decode vuelve las refs numericas claves int, no string
                $ref = strtoupper(trim((string) $key));
            }

            if ($ref !== '') {
                $mapa[$ref] = $item;
            }
        }

        return $mapa;
    }

    /**
     * Valida y parsea una ref recibida. Los errores se acumulan por referencia.
     */
    public static function validar_ref(string $ref, array $item, array &$errores): array
    {
        // ref: alfanumerica corta (referencias.ref es VARCHAR(10))
        if (!preg_match('/^[A-Z0-9\-]{3,10}$/', $ref)) {
            $errores[] = 'ref: formato invalido (3-10 caracteres alfanumericos)';
        }

        $titulo = ExcelTool::normalizarTexto($item['titulo_viaje'] ?? '');
        if (mb_strlen($titulo) > 200) {
            $errores[] = 'titulo_viaje: excede 200 caracteres';
        }

        // placa: normalizar y aplicar la regla de recorte por '/' (como DBReferencias)
        $placa = ExcelTool::normalizarTexto($item['placa'] ?? '');
        if (strlen($placa) > 10) {
            $placa = explode('/', $placa)[0];
        }
        if ($placa !== '' && strlen($placa) > 10) {
            $errores[] = 'placa: excede 10 caracteres tras normalizar';
        }

        $datos = ['titulo_viaje' => $titulo, 'placa' => $placa];

        // conductor: nombre a resolver por similitud; conductor_id directo opcional
        $conductor_raw = $item['conductor'] ?? $item['nombres'] ?? '';
        $conductor = is_string($conductor_raw) ? ExcelTool::normalizarTexto($conductor_raw) : '';
        if (mb_strlen($conductor) > 200) {
            $errores[] = 'conductor: excede 200 caracteres';
        }

        $conductor_id = null;
        $cid_raw = $item['conductor_id'] ?? null;
        if ($cid_raw !== null && $cid_raw !== '' && is_numeric($cid_raw)) {
            $conductor_id = (int) $cid_raw;
            if ($conductor_id <= 0) {
                $errores[] = 'conductor_id: debe ser un entero positivo';
                $conductor_id = null;
            }
        }
        $datos['conductor'] = $conductor;
        $datos['conductor_id'] = $conductor_id;

        // trt: SOLO el nombre del transportista (nunca RUC); trt_id directo opcional
        $trt_raw = $item['trt'] ?? $item['transportista'] ?? '';
        if (!is_string($trt_raw)) {
            $trt_raw = is_numeric($trt_raw ?? null) ? (string) $trt_raw : '';
        }
        $trt = ExcelTool::normalizarTexto($trt_raw);
        if (mb_strlen($trt) > 150) {
            $errores[] = 'trt: excede 150 caracteres';
        }

        $trt_id = null;
        $tid_raw = $item['trt_id'] ?? null;
        if ($tid_raw !== null && $tid_raw !== '' && is_numeric($tid_raw)) {
            $trt_id = (int) $tid_raw;
            if ($trt_id <= 0) {
                $errores[] = 'trt_id: debe ser un entero positivo';
                $trt_id = null;
            }
        }
        $datos['trt'] = $trt;
        $datos['trt_id'] = $trt_id;

        // fecha_despachador: OBSOLETA, se ignora deliberadamente
        foreach (self::EVENTOS as $evento) {
            $valor = $item[$evento] ?? null;
            if ($valor === null || !is_string($valor)) {
                $valor = is_numeric($valor ?? null) ? (string) $valor : '';
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
    public static function parse_fecha($valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '' || $valor === '-') {
            return '';
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ ,]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $valor, $m)) {
            $dia = (int) $m[1]; $mes = (int) $m[2]; $anio = (int) $m[3];
            $hora = (int) ($m[4] ?? 0); $min = (int) ($m[5] ?? 0); $seg = (int) ($m[6] ?? 0);
            if (!checkdate($mes, $dia, $anio) || $hora > 23 || $min > 59 || $seg > 59) {
                return null;
            }

            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $anio, $mes, $dia, $hora, $min, $seg);
        }

        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $valor, $m)) {
            $anio = (int) $m[1]; $mes = (int) $m[2]; $dia = (int) $m[3];
            $hora = (int) ($m[4] ?? 0); $min = (int) ($m[5] ?? 0); $seg = (int) ($m[6] ?? 0);
            if (!checkdate($mes, $dia, $anio) || $hora > 23 || $min > 59 || $seg > 59) {
                return null;
            }

            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $anio, $mes, $dia, $hora, $min, $seg);
        }

        return null;
    }

    /** UPDATE parcial: solo las columnas recibidas */
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

    /** INSERT de referencia nueva (modo libre) */
    private static function insertar_referencia(string $ref, array $set): void
    {
        $cols = ['ref', 'titulo_viaje', 'placa'];
        $binds = [$ref, $set['titulo_viaje'] ?? '', $set['placa'] ?? ''];
        if (isset($set['conductor_id'])) {
            $cols[] = 'conductor_id';
            $binds[] = $set['conductor_id'];
        }
        if (isset($set['trt_id'])) {
            $cols[] = 'trt_id';
            $binds[] = $set['trt_id'];
        }
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
     * La ref ya paso el filtro ^[A-Z0-9-]{3,10}$, asi que el inline es seguro.
     */
    private static function reprocesar_ruta(string $ref): bool
    {
        try {
            DB::statement("CALL sp_actualizar_ruta_id('$ref')");

            return true;
        } catch (\Throwable $e) {
            // origen/destino no reconocidos en locales: el SP lanza SIGNAL
            return false;
        }
    }
}
