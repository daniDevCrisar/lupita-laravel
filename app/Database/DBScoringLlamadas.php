<?php

namespace App\Database;

use App\Database\DBPalabrasLlamadas as Palabras;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Puntua el porcentaje de exito de una llamada a partir de las palabras y frases
 * que dijo el conductor, con los pesos clasificados por tipo de llamada.
 *
 * La formula es logit, no suma de puntos porcentuales:
 *
 *   logit(p) = logit(base del tipo) + suma de los pesos que dispararon
 *
 * Asi el puntaje jamas se sale de 0-100 (dos palabras optimistas no se saturan
 * contra el techo) y cada peso se lee como multiplicador de la posibilidad:
 * 0,65 ≈ duplicar. Es la misma unidad que usa una regresion logica, de modo que
 * si algun dia los pesos se ajustan solos contra los datos, la tabla no cambia.
 *
 * La base NO se fija a mano: se mide en el rango filtrado (`base_por_tipo()`),
 * porque el exito real por tipo va del 16,8 % en Confirmacion al 33 % en Fuera de
 * planta para carga, y un punto de partida unico mentiria en los extremos.
 *
 * Reglas heredadas del modulo de palabras, para que las dos paginas puedan
 * compararse sin discutir:
 * - solo texto HUMANO (mensajes `USER`); el guion de la IA se autocita;
 * - `buzon_de_voz = 1` no se puntua: es un contestador y su efecto ya esta
 *   dentro de la base medida del tipo;
 * - el mismo normalizador (minusculas sin acentos) y las mismas stopwords.
 *
 * El puntaje se PERSISTE en `scoring_llamadas` y se calcula una sola vez por
 * version de pesos (`pesos_version`): editar un peso deja la vista de evaluacion
 * avisando que los puntajes estan obsoletos en vez de recalcular a cada request.
 *
 * Esta clase NUNCA escribe en `llamadas`. Guarda una foto de `llamada_exitosa`
 * del humano para poder comparar, y el desacuerdo solo se senala: decide quien
 * escucha el audio en la pantalla de Etiquetar.
 *
 * Junto al puntaje guarda una SUGERENCIA de analisis de audio (`nota_sugerida`),
 * sacada de las notas que los humanos ya escribieron en llamadas parecidas. Es
 * solo lectura en pantalla: no se copia a `llamadas.analisis_audio` y no reemplaza
 * a nadie. Ver `sugerir_nota()`.
 */
class DBScoringLlamadas
{
    // tamano del lote de llamadas leido y guardado de una vez
    const LOTE_LLAMADAS = 400;
    const LOTE_GUARDADO = 500;

    // filas de la tabla de llamadas y del panel de auditoria
    const MAX_FILAS = 200;

    // filas por pagina en el listado de llamadas del rango (vista dedicada)
    const LISTA_POR_PAGINA = 50;

    // amortiguacion de los pesos sugeridos: con pocas llamadas el log(OR) de una
    // palabra rara vale 3 y hundiria el puntaje; n/(n+SHRINK) lo encoge
    const SHRINK = 30;

    // apoyo minimo para que una palabra sea siquiera candidata a peso
    const MIN_APOYO = 5;

    // techo del peso editable, para que un desliz no convierta la base en ruido
    const PESO_MAX = 4;

    // apoyo minimo de una nota del humano para que sea siquiera sugerible: con una
    // sola llamada la sugerencia es anecdótica (el criterio de
    // `analisis_audio_mas_usado()` pide > 3 por lo mismo). Es el DEFECTO: desde la
    // pantalla de Pesos se ajusta en `scoring_config.nota_min`. Con el voto
    // ponderado (B) la unidad es la fuerza, no la cuenta: suma de |peso|.
    const NOTA_MIN = 3;

    // techo de notas distintas que entran a la co-ocurrencia: las mas usadas. El
    // vocabulario del revisor es chico (11.671 notas dan ~1.750 unicas), asi que
    // con 200 se cubre casi todo el corpus y el mapa no crece solo. Es el DEFECTO:
    // se ajusta en `scoring_config.nota_top`.
    const NOTA_TOP = 200;

    // cuantas sugerencias se recuerdan por request por conjunto de disparos: miles
    // de llamadas comparten el mismo vocabulario, y repetir el merge en cada una
    // seria el costo real de la pasada completa
    const SUGERENCIAS_CACHE = 20000;

    public static $motivos = [
        'ok' => 'Con texto del conductor',
        'buzon' => 'Buzón de voz: no se puntúa',
        'sin_texto' => 'Sin mensajes del conductor',
    ];

    public static $vistas = [
        'todas' => 'Todas las puntuadas',
        'desacuerdos' => 'Solo desacuerdos con el humano',
        'peores' => 'Peores errores',
        'sin_texto' => 'Sin texto (el modelo no opina)',
    ];

    public static $umbrales = [50, 60, 70];

    private static $filtro;

    // cache por request: los pesos y su version se leen una sola vez
    private static $pesos = null;
    private static $version = null;
    private static $compilados = [];

    // base medida cacheada por request: puntuar_llamada() corre una vez por
    // llamada al importar y no puede repetir el agregado completo en cada una
    private static $bases_cache = null;

    // si la base no se pudo medir, no se reintenta en cada llamada de la importacion
    private static $bases_error = false;

    // mapa palabra => nota, armado una vez por request
    private static $notas = null;

    // mismo criterio que $notas_error: si el mapa fallo, no se reintenta por fila
    private static $notas_error = false;

    // sugerencias ya resueltas, indexadas por conjunto de palabras que dispararon
    private static $sugerencias_cache = [];

    // parametros de la sugerencia (`scoring_config`), leidos una vez por request;
    // si la tabla no existe aun, quedan los defectos de las constantes
    private static $params = null;

    private static $params_error = false;

    //------------------- filtro -------------------

    public static function set_filtro($request): void
    {
        // `?? null` permite probar la clase con un objeto plano
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio ?? null;
        self::$filtro->fecha_fin = $request->fecha_fin ?? null;
        self::$filtro->llamada_tipo_id = $request->llamada_tipo_id ?? null;
        self::$filtro->conductor = $request->conductor ?? null;
        self::$filtro->trt = $request->trt ?? null;
        self::$filtro->ruta_id = $request->ruta_id ?? null;
        self::$filtro->universo = $request->universo ?? null;
        self::$filtro->umbral = $request->umbral ?? null;
        self::$filtro->vista = $request->vista ?? null;
        self::$filtro->offset = $request->offset ?? null;
        // la tabla de pesos puede pedir sugerencias hasta de 5 palabras: el campo
        // lo consume la clase hermana a traves del mismo objeto de filtro
        self::$filtro->max_gram = $request->max_gram ?? null;
        // las llamadas sin texto del conductor quedan fuera de AUC/precision por
        // defecto: el modelo no opinó, su puntaje es la base del tipo
        self::$filtro->incluir_sin_texto = $request->incluir_sin_texto ?? null;

        // los pesos cambiaron o se cambió el filtro: cae la cache
        self::$pesos = null;
        self::$version = null;
        self::$compilados = [];
        self::$bases_cache = null;
        self::$bases_error = false;
        self::$notas = null;
        self::$notas_error = false;
        self::$sugerencias_cache = [];
        self::$params = null;
        self::$params_error = false;
    }

    public static function filtro(): stdClass
    {
        if (self::$filtro === null) {
            self::set_filtro(new stdClass());
        }

        return self::$filtro;
    }

    public static function universo(): string
    {
        $valor = (string) (self::filtro()->universo ?? '');

        return isset(Palabras::$universos[$valor]) ? $valor : 'contestadas';
    }

    public static function vista(): string
    {
        $valor = (string) (self::filtro()->vista ?? '');

        return isset(self::$vistas[$valor]) ? $valor : 'todas';
    }

    /** Corte binario para la matriz de confusion, en porcentaje. */
    public static function umbral(): int
    {
        $valor = (int) (self::filtro()->umbral ?? 50);

        return max(1, min(99, $valor));
    }

    public static function offset(): int
    {
        return max(0, (int) (self::filtro()->offset ?? 0));
    }

    /** Si el toggle de la vista mete también a las llamadas sin texto. */
    public static function incluyeSinTexto(): bool
    {
        return (string) (self::filtro()->incluir_sin_texto ?? '') === '1';
    }

    /**
     * Si esta fila entra en las métricas: tiene puntaje y o bien expresó el
     * vocabulario, o el usuario pidió contar también las que no tienen texto.
     * Es la unica definicion, para que la matriz, la concordancia y el veredicto
     * de la tabla no discrepen sobre qué filas cuentan.
     */
    public static function en_metricas(stdClass $f): bool
    {
        if ($f->puntaje === null) return false;

        return $f->motivo === 'ok' || ($f->motivo === 'sin_texto' && self::incluyeSinTexto());
    }

    /**
     * WHERE sobre `llamadas`. Es el mismo criterio que la pagina de palabras,
     * copiado y no heredado: la clase hermana mantiene su WHERE privado y las
     * dos vistas tienen que poder divergir sin romperse (aqui no se tokeniza por
     * `fuente` ni se filtra por `palabra`).
     */
    private static function filtro_llamadas(): array
    {
        $sql = ' AND l.llamada_exitosa IN (0, 1) ';

        // el tipo 0 (INDETERMINADA: la IA no pudo clasificar) y el sin tipo no
        // puntuan: quedan fuera de la base medida, del universo, del calculo y de
        // las metricas. Asi la base de cada tipo real no se contamina con llamadas
        // que no tienen desenlace clasificable.
        $sql .= ' AND l.llamada_tipo_id IS NOT NULL AND l.llamada_tipo_id <> 0 ';

        $universo = self::universo();
        if ($universo !== 'todas') {
            $sql .= ' AND l.entro_llamada = 1 ';
        }
        if ($universo === 'sin_error') {
            $sql .= ' AND l.error_origen = 0 ';
        }

        $params = [];
        $filtro = self::filtro();

        if ($filtro->fecha_inicio ?? '') {
            $sql .= ' AND l.created_at >= ? ';
            $params[] = Carbon::parse($filtro->fecha_inicio)->startOfDay();
        }

        if ($filtro->fecha_fin ?? '') {
            $sql .= ' AND l.created_at < ? ';
            $params[] = Carbon::parse($filtro->fecha_fin)->addDay()->startOfDay();
        }

        $tipo = trim((string) ($filtro->llamada_tipo_id ?? ''));
        if ($tipo !== '') {
            $sql .= ' AND l.llamada_tipo_id = ? ';
            $params[] = (int) $tipo;
        }

        $conductor = trim((string) ($filtro->conductor ?? ''));
        if ($conductor !== '') {
            if (is_numeric($conductor)) {
                $sql .= ' AND l.conductor_id = ? ';
                $params[] = (int) $conductor;
            } else {
                $sql .= ' AND co.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($conductor) . '%';
            }
        }

        $trt = trim((string) ($filtro->trt ?? ''));
        if ($trt !== '') {
            if (is_numeric($trt)) {
                $sql .= ' AND l.trt_id = ? ';
                $params[] = (int) $trt;
            } else {
                $sql .= ' AND t.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($trt) . '%';
            }
        }

        $ruta = trim((string) ($filtro->ruta_id ?? ''));
        if ($ruta !== '') {
            $sql .= ' AND r.ruta_id = ? ';
            $params[] = (int) $ruta;
        }

        return [$sql, $params];
    }

    private static function joins(): string
    {
        return '
        FROM llamadas l
        LEFT JOIN conductores co ON co.id = l.conductor_id
        LEFT JOIN trts t ON t.id = l.trt_id
        LEFT JOIN referencias r ON r.ref = l.ref';
    }

    //------------------- pesos -------------------

    /**
     * Pesos activos de la tabla, separados en globales y por tipo.
     */
    public static function pesos_activos(): array
    {
        if (self::$pesos !== null) {
            return self::$pesos;
        }

        $filas = DB::select('
        SELECT id, palabra, gram, llamada_tipo_id, peso, origen, nota
        FROM palabras_pesos
        WHERE activa = 1
        ORDER BY llamada_tipo_id IS NOT NULL DESC, gram DESC, peso DESC');

        $out = ['global' => [], 'tipos' => []];

        foreach ($filas as $f) {
            $entrada = (object) [
                'id' => (int) $f->id,
                'palabra' => (string) $f->palabra,
                'gram' => max(1, min(5, (int) $f->gram)),
                'tipo' => $f->llamada_tipo_id === null ? null : (int) $f->llamada_tipo_id,
                'peso' => (float) $f->peso,
                'origen' => (string) $f->origen,
                'nota' => (string) ($f->nota ?? ''),
            ];

            if ($entrada->tipo === null) $out['global'][] = $entrada;
            else $out['tipos'][$entrada->tipo][] = $entrada;
        }

        self::$pesos = $out;

        return $out;
    }

    /**
     * Pesos que aplican a un tipo: los globales menos los que el tipo redefine.
     *
     * La fila del tipo gana sobre la global para la misma palabra y grama: es el
     * ajuste local (en Confirmacion "ahorita" no significa lo mismo que en una
     * descarga dentro de planta).
     */
    public static function pesos_para_tipo(?int $tipo): array
    {
        $pesos = self::pesos_activos();
        $locales = $pesos['tipos'][$tipo] ?? [];

        $claves = [];
        foreach ($locales as $p) {
            $claves[$p->palabra . '|' . $p->gram] = true;
        }

        $mezcla = $locales;

        foreach ($pesos['global'] as $p) {
            if (!isset($claves[$p->palabra . '|' . $p->gram])) $mezcla[] = $p;
        }

        return $mezcla;
    }

    /**
     * Huella de los pesos activos: cambia al editar cualquiera, y con eso la vista
     * de evaluacion sabe que sus puntajes guardados estan obsoletos.
     */
    public static function pesos_version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $trozos = [];

        foreach (self::pesos_activos()['global'] as $p) {
            $trozos[] = 'G|' . $p->palabra . '|' . $p->gram . '|' . $p->peso;
        }
        foreach (self::pesos_activos()['tipos'] as $tipo => $lista) {
            foreach ($lista as $p) {
                $trozos[] = $tipo . '|' . $p->palabra . '|' . $p->gram . '|' . $p->peso;
            }
        }

        self::$version = md5(implode(';', $trozos));

        return self::$version;
    }

    /**
     * Grama maxima entre los pesos activos: manda hasta donde hay que buscar
     * frases. Sin pesos, 1.
     */
    public static function max_gram(): int
    {
        $max = 1;

        foreach (self::pesos_activos()['global'] as $p) {
            $max = max($max, $p->gram);
        }
        foreach (self::pesos_activos()['tipos'] as $lista) {
            foreach ($lista as $p) {
                $max = max($max, $p->gram);
            }
        }

        return $max;
    }

    /**
     * Pesos del tipo convertidos a dos canastas: palabras sueltas (mapa token =>
     * peso) y frases (regex con limite de palabra ya armada).
     *
     * Las frases se buscan sobre el texto completo y no sobre n-gramas: hay
     * decenas de entradas y miles de mensajes, y generar hasta 5-gramas de todo
     * el corpus costaria mas que la busqueda directa.
     */
    private static function pesos_compilados(?int $tipo): array
    {
        // -1 es la clave de los pesos globales: null como indice de arreglo se
        // convierte en cadena vacia y se presta a empates raros
        $clave = $tipo ?? -1;

        if (isset(self::$compilados[$clave])) {
            return self::$compilados[$clave];
        }

        $sueltas = [];
        $frases = [];

        foreach (self::pesos_para_tipo($tipo) as $p) {
            if ($p->peso == 0.0) continue;

            if ($p->gram === 1) {
                $sueltas[$p->palabra] = $p;
            } else {
                $frases[] = (object) [
                    'entrada' => $p,
                    'regex' => '/(?<![a-z0-9])' . preg_quote($p->palabra, '/') . '(?![a-z0-9])/u',
                ];
            }
        }

        $out = ['sueltas' => $sueltas, 'frases' => $frases];
        self::$compilados[$clave] = $out;

        return $out;
    }

    //------------------- logit -------------------

    /** Logit de un porcentaje (0-100), acotado para no dividir por cero. */
    public static function logit(float $pct): float
    {
        $p = max(0.5, min(99.5, $pct)) / 100;

        return log($p / (1 - $p));
    }

    /** Porcentaje (0-100) a partir de un logit. */
    public static function invertido(float $x): float
    {
        return 100 / (1 + exp(-$x));
    }

    //------------------- base medida -------------------

    /**
     * Tasa de exito real por tipo de llamada dentro del filtro, y la global como
     * respaldo para un tipo sin llamadas en el rango.
     */
    public static function base_por_tipo(): array
    {
        [$where, $params] = self::filtro_llamadas();

        $filas = DB::select("
        SELECT COALESCE(l.llamada_tipo_id, 0) AS tipo,
               COUNT(*) AS n,
               SUM(l.llamada_exitosa = 1) AS e
        " . self::joins() . '
        WHERE 1 = 1 ' . $where . '
        GROUP BY COALESCE(l.llamada_tipo_id, 0)', $params);

        $out = [];
        $tot_n = 0;
        $tot_e = 0;

        foreach ($filas as $f) {
            $n = (int) $f->n;
            $e = (int) $f->e;

            $out[(int) $f->tipo] = (object) [
                'n' => $n,
                'e' => $e,
                'pct' => $n ? $e * 100 / $n : 0,
            ];

            $tot_n += $n;
            $tot_e += $e;
        }

        $out[0] = (object) [
            'n' => $tot_n,
            'e' => $tot_e,
            'pct' => $tot_n ? $tot_e * 100 / $tot_n : 0,
            'es_global' => true,
        ];

        return $out;
    }

    private static function base_de(?int $tipo, array $bases): array
    {
        $clave = (int) ($tipo ?? 0);

        if (isset($bases[$clave]) && $bases[$clave]->n > 0) {
            return [$bases[$clave]->pct, $bases[$clave]->n];
        }

        // tipo sin llamadas en el rango: se parte de la global para que el
        // puntaje no dependa de una base inventada
        return [$bases[0]->pct, 0];
    }

    //------------------- calcular -------------------

    /**
     * Pasada completa: lee los mensajes del rango, aplica los pesos y guarda el
     * puntaje por llamada (upsert por vapi_id).
     *
     * Es la unica parte que tokeniza, y se corre a pedido desde la vista de
     * evaluacion. Los lotes se guardan en cuanto se cierran para no sostener las
     * 24 mil llamadas en memoria.
     */
    public static function calcular(): stdClass
    {
        $ini = microtime(true);
        @ini_set('memory_limit', '512M');
        DB::statement('SET SESSION wait_timeout = 600');

        $bases = self::base_por_tipo();
        $version = self::pesos_version();
        $rango = self::rango_etiqueta();
        $ahora = Carbon::now();

        $out = new stdClass();
        $out->guardadas = 0;
        $out->sugerencias = 0;
        $out->por_motivo = ['ok' => 0, 'buzon' => 0, 'sin_texto' => 0];
        $out->bases = $bases;
        $out->version = $version;
        $out->max_gram = self::max_gram();

        [$where, $params] = self::filtro_llamadas();

        $ids = DB::select('SELECT l.vapi_id' . self::joins() . ' WHERE 1 = 1 ' . $where, $params);
        $ids = array_column($ids, 'vapi_id');
        $out->llamadas = count($ids);

        foreach (array_chunk($ids, self::LOTE_LLAMADAS) as $lote) {
            $llamadas = DB::select("
            SELECT l.vapi_id, l.llamada_tipo_id, l.llamada_exitosa, l.buzon_de_voz
            FROM llamadas l
            WHERE l.vapi_id IN (" . implode(',', array_fill(0, count($lote), '?')) . ')', $lote);

            $mensajes = DB::select("
            SELECT m.vapi_id, m.mensaje
            FROM mensajes m
            WHERE m.tipo = 'USER' AND m.vapi_id IN ("
                . implode(',', array_fill(0, count($lote), '?')) . ')
            ORDER BY m.vapi_id, m.orden', $lote);

            $textos = [];
            foreach ($mensajes as $m) {
                $textos[$m->vapi_id][] = Palabras::normalizar((string) $m->mensaje);
            }
            unset($mensajes);

            $filas = [];

            foreach ($llamadas as $l) {
                $tipo = $l->llamada_tipo_id === null ? null : (int) $l->llamada_tipo_id;
                [$base_pct, $base_n] = self::base_de($tipo, $bases);

                // el buzon no se puntua: su efecto ya esta dentro de la base
                if ((int) $l->buzon_de_voz === 1) {
                    $filas[] = self::fila($l, $base_pct, null, null, [], 'buzon', $version, $rango, $ahora);
                    $out->por_motivo['buzon']++;
                    continue;
                }

                $texto = trim(implode(' ', $textos[$l->vapi_id] ?? []));

                if ($texto === '') {
                    $filas[] = self::fila($l, $base_pct, $base_pct, self::logit($base_pct), [],
                        'sin_texto', $version, $rango, $ahora);
                    $out->por_motivo['sin_texto']++;
                    continue;
                }

                $disparos = self::disparos($texto, $tipo);

                $suma = 0;
                foreach ($disparos as $d) $suma += $d->peso;

                $logit = self::logit($base_pct) + $suma;
                $puntaje = max(0.5, min(99.5, self::invertido($logit)));

                $filas[] = self::fila($l, $base_pct, $puntaje, $logit, $disparos,
                    'ok', $version, $rango, $ahora);
                $out->por_motivo['ok']++;
            }

            self::guardar($filas);
            $out->guardadas += count($filas);

            // cuantas filas salen con una sugerencia de analisis al lado del
            // puntaje: en la primera pasada del rango suelen ser las menos, porque
            // la senal por palabra todavia no existe
            $out->sugerencias += count(array_filter(array_column($filas, 'nota_sugerida')));

            unset($llamadas, $textos, $filas);
        }

        $out->segundos = round(microtime(true) - $ini, 2);
        $out->obsoletas = self::llamadas_obsoletas();

        return $out;
    }

    /**
     * Puntua UNA sola llamada y persiste su fila en `scoring_llamadas`.
     *
     * Es el criterio de `calcular()` reducido a un vapi_id: lo usan los flujos
     * que incorporan llamadas de a una (importacion de VAPI) para que la llamada
     * quede puntuada sin esperar la pasada completa por rango.
     *
     * Devuelve null si la llamada no existe; si existe, {vapi_id, puntaje, motivo}.
     */
    public static function puntuar_llamada(string $vapi_id): ?stdClass
    {
        $vapi_id = trim($vapi_id);
        if ($vapi_id === '') return null;

        $l = DB::selectOne(
            'SELECT vapi_id, llamada_tipo_id, llamada_exitosa, buzon_de_voz
             FROM llamadas
             WHERE vapi_id = ? LIMIT 1',
            [$vapi_id]
        );

        if (!$l) return null;

        $tipo = $l->llamada_tipo_id === null ? null : (int) $l->llamada_tipo_id;

        // tipo 0 / NULL no se puntua: mismo criterio que el batch, que los deja
        // fuera de la base medida
        if ($tipo === null || $tipo === 0) {
            return (object) ['vapi_id' => $vapi_id, 'puntaje' => null, 'motivo' => 'sin_tipo'];
        }

        // la base se mide una sola vez por request. Si falla, se saltea el puntaje
        // de esa llamada y no se reintenta en las siguientes de la importacion.
        if (self::$bases_error) return null;

        if (self::$bases_cache === null) {
            try {
                self::$bases_cache = self::base_por_tipo();
            } catch (\Throwable $e) {
                self::$bases_error = true;
                return null;
            }
        }

        $version = self::pesos_version();
        $rango = self::rango_etiqueta();
        $ahora = Carbon::now();

        [$base_pct, $base_n] = self::base_de($tipo, self::$bases_cache);

        // el buzon no se puntua: su efecto ya esta dentro de la base
        if ((int) $l->buzon_de_voz === 1) {
            self::guardar([self::fila($l, $base_pct, null, null, [], 'buzon', $version, $rango, $ahora)]);
            return (object) ['vapi_id' => $vapi_id, 'puntaje' => null, 'motivo' => 'buzon'];
        }

        $mensajes = DB::select(
            "SELECT mensaje FROM mensajes WHERE tipo = 'USER' AND vapi_id = ? ORDER BY orden",
            [$vapi_id]
        );

        $texto = trim(implode(' ', array_map(
            fn($m) => Palabras::normalizar((string) $m->mensaje),
            $mensajes
        )));

        if ($texto === '') {
            self::guardar([self::fila($l, $base_pct, $base_pct, self::logit($base_pct), [],
                'sin_texto', $version, $rango, $ahora)]);
            return (object) ['vapi_id' => $vapi_id, 'puntaje' => round($base_pct, 2), 'motivo' => 'sin_texto'];
        }

        $disparos = self::disparos($texto, $tipo);

        $suma = 0;
        foreach ($disparos as $d) $suma += $d->peso;

        $logit = self::logit($base_pct) + $suma;
        $puntaje = max(0.5, min(99.5, self::invertido($logit)));

        self::guardar([self::fila($l, $base_pct, $puntaje, $logit, $disparos,
            'ok', $version, $rango, $ahora)]);

        return (object) ['vapi_id' => $vapi_id, 'puntaje' => round($puntaje, 2), 'motivo' => 'ok'];
    }

    /**
     * Que pesos del tipo disparan en este texto, y con cuanto.
     *
     * Si una frase y una palabra suelta caen dentro de la misma frase, solo
     * cuenta la frase: es el criterio de `sin_redundancia()` de la pagina de
     * palabras, aplicado a la llamada en vez del diccionario. Sin eso, "va a
     * cargar" y "cargar" sumarian dos veces la misma evidencia.
     */
    public static function disparos(string $texto_normalizado, ?int $tipo): array
    {
        $canastas = self::pesos_compilados($tipo);

        $frases = [];
        foreach ($canastas['frases'] as $f) {
            if (preg_match($f->regex, $texto_normalizado)) {
                $frases[] = $f->entrada;
            }
        }

        $tokens = array_unique(
            preg_split('/[^a-z0-9ñ]+/u', $texto_normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        $sueltas = [];
        foreach ($tokens as $t) {
            if (isset($canastas['sueltas'][$t])) $sueltas[] = $canastas['sueltas'][$t];
        }

        $dentro = [];
        foreach ($sueltas as $s) {
            foreach ($frases as $f) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($s->palabra, '/') . '(?![a-z0-9])/u', $f->palabra)) {
                    $dentro[$s->palabra] = true;
                    break;
                }
            }
        }

        $disparos = [];

        foreach ($frases as $f) {
            $disparos[] = (object) [
                'palabra' => $f->palabra,
                'gram' => $f->gram,
                'tipo' => $f->tipo,
                'peso' => $f->peso,
            ];
        }
        foreach ($sueltas as $s) {
            if (isset($dentro[$s->palabra])) continue;

            $disparos[] = (object) [
                'palabra' => $s->palabra,
                'gram' => $s->gram,
                'tipo' => $s->tipo,
                'peso' => $s->peso,
            ];
        }

        return $disparos;
    }

    //------------------- sugerencia de analisis -------------------

    /**
     * Parametros de la sugerencia, leidos de `scoring_config` una vez por request.
     *
     * Si la tabla no existe todavia (en el servidor final el CREATE TABLE se
     * aplica a mano) o un valor llega sucio, se usan las constantes de la clase:
     * la pasada de calculo nunca puede reventar por un parametro.
     */
    public static function parametros_sugerencia(): array
    {
        if (self::$params !== null) {
            return self::$params;
        }

        self::$params = ['nota_min' => self::NOTA_MIN, 'nota_top' => self::NOTA_TOP];

        if (!self::$params_error) {
            try {
                $valores = DB::table('scoring_config')->pluck('valor', 'clave');

                if (isset($valores['nota_min'])) {
                    self::$params['nota_min'] = max(1, min(50, (int) $valores['nota_min']));
                }
                if (isset($valores['nota_top'])) {
                    self::$params['nota_top'] = max(20, min(1000, (int) $valores['nota_top']));
                }
            } catch (\Throwable $e) {
                self::$params_error = true;
            }
        }

        return self::$params;
    }

    /** Fuerza minima de apoyo (suma de |peso| de los disparos) para sugerir. */
    public static function nota_min(): int
    {
        return self::parametros_sugerencia()['nota_min'];
    }

    /** Techo de notas distintas del humano que entran a la co-ocurrencia. */
    public static function nota_top(): int
    {
        return self::parametros_sugerencia()['nota_top'];
    }

    /**
     * Escribe los dos parametros desde la pantalla de Pesos y corta la cache para
     * que la misma request los vea aplicados. No toca el puntaje: solo la
     * sugerencia, y surte efecto en la siguiente pasada de `calcular()`.
     */
    public static function guardar_parametros_sugerencia(int $nota_min, int $nota_top): array
    {
        $nota_min = max(1, min(50, $nota_min));
        $nota_top = max(20, min(1000, $nota_top));

        $ahora = now();

        DB::table('scoring_config')->upsert([
            ['clave' => 'nota_min', 'valor' => (string) $nota_min, 'updated_at' => $ahora],
            ['clave' => 'nota_top', 'valor' => (string) $nota_top, 'updated_at' => $ahora],
        ], 'clave', ['valor', 'updated_at']);

        self::$params = null;
        self::$params_error = false;

        return ['nota_min' => $nota_min, 'nota_top' => $nota_top];
    }

    /**
     * Mapa de las notas que el humano ya escribio en `llamadas.analisis_audio`.
     *
     * Unica fuente de la sugerencia: la clase no redacta nada, solo busca la nota
     * que la gente usa en llamadas que se parecen a esta. Se arma una vez por
     * request, igual que los pesos.
     *
     * La co-ocurrencia palabra => nota sale de `scoring_llamadas.disparos`, o sea
     * de llamadas YA puntuadas: la primera pasada de un rango todavia no tiene
     * con que comparar y no sugiere, y desde la segunda entra la senal por
     * palabra. No hay que hacer nada extra, se aprovecha lo que la pasada
     * anterior dejo guardado.
     *
     * Cada voto se pesa con el |peso| con que la palabra movio el puntaje de esa
     * llamada (las fuertes ordenan la sugerencia, las debiles apenas suman), y la
     * fuerza minima para sugerir se puede ajustar desde Pesos: `nota_min()`.
     *
     * Tres canastas, indexadas por la nota en mayusculas y sin acentos (el
     * mismo normalizador del ranking: 'NO ATIENDE' y 'No atiende' son una sola
     * nota y no hay que partir su conteo): `palabra` (nota => ['n' => llamadas
     * que la tienen y dispararon esa palabra, 'f' => su fuerza ponderada]),
     * `total` (nota => llamadas) y `mostrar` (nota => la grafia mas usada para
     * mostrarla).
     */
    public static function mapa_notas(): array
    {
        if (self::$notas !== null) {
            return self::$notas;
        }

        if (self::$notas_error) {
            return self::mapa_notas_vacio();
        }

        try {
            self::$notas = self::construir_mapa_notas();
        } catch (\Throwable $e) {
            // secundario: una sugerencia jamas puede frenar un calculo ni una
            // importacion, y no se reintenta fila por fila
            self::$notas_error = true;
            self::$notas = self::mapa_notas_vacio();
        }

        return self::$notas;
    }

    private static function mapa_notas_vacio(): array
    {
        return ['palabra' => [], 'total' => [], 'mostrar' => []];
    }

    private static function construir_mapa_notas(): array
    {
        $total = [];
        $mostrar = [];

        // 1) cuantas veces el humano escribio cada nota: un GROUP BY de ~2.000
        // filas sobre todo el corpus (23 ms medidos), no un escaneo por
        // llamada, asi que sale gratis en cualquier request
        $filas = DB::select("
        SELECT TRIM(l.analisis_audio) AS nota, COUNT(*) AS n
        FROM llamadas l
        WHERE l.analisis_audio IS NOT NULL AND TRIM(l.analisis_audio) <> ''
        GROUP BY TRIM(l.analisis_audio)");

        foreach ($filas as $f) {
            $cruda = (string) $f->nota;
            $n = (int) $f->n;

            $clave = mb_strtoupper(Palabras::normalizar($cruda));
            if ($clave === '' || mb_strlen($clave) > 120) continue;

            $total[$clave] = ($total[$clave] ?? 0) + $n;

            // se devuelve la grafia mas usada, no la primera que aparecio
            if (!isset($mostrar[$clave]) || $n > (int) ($mostrar[$clave]->n ?? 0)) {
                $mostrar[$clave] = (object) ['nota' => $cruda, 'n' => $n];
            }
        }

        // 2) co-ocurrencia con los disparos, solo sobre las notas con apoyo y las
        //    top del corpus: el IN acota las filas leidas y el mapa se queda en
        //    unos pocos miles de claves aunque el historial crezca
        $candidatas = [];
        $piso = self::nota_min();
        foreach ($total as $clave => $n) {
            if ($n >= $piso) $candidatas[$clave] = (int) $n;
        }
        arsort($candidatas);
        $candidatas = array_slice(array_keys($candidatas), 0, self::nota_top());

        $palabra = [];

        if ($candidatas) {
            $cooc = DB::select("SELECT s.disparos, TRIM(l.analisis_audio) AS nota
            FROM scoring_llamadas s
            JOIN llamadas l ON l.vapi_id = s.vapi_id
            WHERE TRIM(l.analisis_audio) IN ("
                . implode(',', array_fill(0, count($candidatas), '?')) . ')',
                array_map(fn($c) => $mostrar[$c]->nota, $candidatas));

            // el IN de MySQL compara sin distinguir mayusculas, asi que la fila
            // puede volver con otra grafia: se la reconvierte a la clave del mapa
            $aceptadas = array_flip($candidatas);

            foreach ($cooc as $f) {
                $clave = mb_strtoupper(Palabras::normalizar((string) $f->nota));
                if (!isset($aceptadas[$clave])) continue;

                foreach (json_decode((string) $f->disparos) ?: [] as $d) {
                    $p = (string) ($d->palabra ?? '');
                    if ($p === '') continue;

                    // el voto se pesa con el |peso| con que la palabra movio el puntaje:
                    // las fuertes mandan en la sugerencia y las debiles apenas suman;
                    // el piso 0.25 evita que un peso casi nulo vote como si no existiera
                    $fuerza = max(0.25, abs((float) ($d->peso ?? 1)));

                    $palabra[$p][$clave]['n'] = ($palabra[$p][$clave]['n'] ?? 0) + 1;
                    $palabra[$p][$clave]['f'] = ($palabra[$p][$clave]['f'] ?? 0.0) + $fuerza;
                }
            }
        }

        return [
            'palabra' => $palabra,
            'total' => $total,
            'mostrar' => array_map(fn($m) => $m->nota, $mostrar),
        ];
    }

    /**
     * Nota que el humano suele escribir en una llamada con estas mismas palabras.
     *
     * Unica evidencia: los disparos, o sea las palabras con peso que movieron el
     * puntaje. Si la llamada no tiene texto del conductor, o el texto no disparo
     * ninguna palabra con peso, NO hay sugerencia: es mejor callar que apoyarce
     * en el tipo a ciegas.
     *
     * Tambien devuelve null cuando la co-ocurrencia no alcanza el apoyo minimo
     * (rango aun sin puntuar, notas anecdoticas). No escribe en ninguna parte de
     * `llamadas`: es un borrador en pantalla y decide el revisor.
     */
    public static function sugerir_nota(array $disparos): ?stdClass
    {
        $palabras = [];

        foreach ($disparos as $d) {
            $p = (string) ($d->palabra ?? '');
            if ($p !== '') $palabras[$p] = true;
        }

        if (!$palabras) return null;

        // la clave es el CONJUNTO de palabras, ordenado: dos llamadas que
        // dispararon las mismas palabras reciben la misma sugerencia
        ksort($palabras);
        $clave = implode('|', array_keys($palabras));

        if (array_key_exists($clave, self::$sugerencias_cache)) {
            return self::$sugerencias_cache[$clave];
        }

        $sugerida = self::calcular_sugerencia(array_keys($palabras));

        if (count(self::$sugerencias_cache) < self::SUGERENCIAS_CACHE) {
            self::$sugerencias_cache[$clave] = $sugerida;
        }

        return $sugerida;
    }

    private static function calcular_sugerencia(array $palabras): ?stdClass
    {
        $mapa = self::mapa_notas();

        $apoyos = [];

        foreach ($palabras as $p) {
            foreach ($mapa['palabra'][$p] ?? [] as $clave => $v) {
                $apoyos[$clave]['n'] = ($apoyos[$clave]['n'] ?? 0) + $v['n'];
                $apoyos[$clave]['f'] = ($apoyos[$clave]['f'] ?? 0.0) + $v['f'];
            }
        }

        $elegida = self::mejor_nota($apoyos, $mapa['total']);

        if ($elegida === null) return null;

        $out = new stdClass();
        $out->nota = mb_substr((string) ($mapa['mostrar'][$elegida->clave] ?? $elegida->clave), 0, 120);
        $out->n = $elegida->n;
        $out->total = $elegida->total;

        return $out;
    }

    /**
     * La mejor nota de una canasta de apoyos, o null si ninguna llega a la fuerza
     * minima (`nota_min`, configurable desde Pesos).
     *
     * Ordena por observado contra esperado (`f * f / total`, un chi-cuadrado
     * simplificado sobre la fuerza PONDERADA con el |peso| de las palabras):
     * gana la nota que se repite mucho ENTRE las llamadas parecidas y por palabras
     * fuertes, no la que simplemente es frecuente en todo el corpus. El conteo `n`
     * queda para la vista ("5 de 42"), pero quien decide es la fuerza.
     */
    private static function mejor_nota(array $apoyos, array $totales): ?stdClass
    {
        $mejor = null;
        $score = 0.0;
        $piso = self::nota_min();

        foreach ($apoyos as $clave => $v) {
            $f = (float) ($v['f'] ?? 0);
            if ($f < $piso) continue;

            $n = (int) ($v['n'] ?? 0);
            $total = max(1, (int) ($totales[$clave] ?? $n));
            $s = $f * $f / $total;

            if ($s > $score) {
                $score = $s;
                $mejor = (object) ['clave' => $clave, 'n' => $n, 'f' => $f, 'total' => $total];
            }
        }

        return $mejor;
    }

    //------------------- fila guardada -------------------

    private static function fila(
        stdClass $l, float $base_pct, ?float $puntaje, ?float $logit,
        array $disparos, string $motivo, string $version, string $rango, $ahora
    ): array {
        $tipo = $l->llamada_tipo_id === null ? null : (int) $l->llamada_tipo_id;

        // la sugerencia usa la MISMA evidencia que el puntaje: solo las palabras
        // con peso que dispararon. Buzon y sin texto del conductor no sugieren
        $sugerida = ($motivo !== 'ok' || !self::tiene_nota_sugerida())
            ? null
            : self::sugerir_nota($disparos);

        return [
            'vapi_id' => $l->vapi_id,
            'llamada_tipo_id' => $tipo,
            'humano_exitosa' => (int) $l->llamada_exitosa === 1,
            'base_pct' => round($base_pct, 2),
            'puntaje' => $puntaje === null ? null : round($puntaje, 2),
            'logit' => $logit === null ? null : round($logit, 4),
            'disparos' => json_encode($disparos, JSON_UNESCAPED_UNICODE),
            'motivo' => $motivo,
            // sugerencia de analisis: se guarda al lado del puntaje y SOLO se lee
            // en pantalla; `llamadas.analisis_audio` sigue siendo del humano
            'nota_sugerida' => $sugerida->nota ?? null,
            'nota_sugerida_n' => $sugerida->n ?? null,
            'nota_sugerida_total' => $sugerida->total ?? null,
            'pesos_version' => $version,
            'rango' => $rango,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    /**
     * Columnas propias de la sugerencia, para escribir y para comparar contra el
     * esquema real.
     */
    private const NOTAS_COLUMNAS = [
        'nota_sugerida', 'nota_sugerida_n', 'nota_sugerida_total',
    ];

    /**
     * Si el esquema ya tiene las cuatro columnas de la sugerencia.
     *
     * En el servidor final no hay consola y las migraciones se aplican a mano: si
     * alguien sube este codigo antes del ALTER, `calcular()` no puede reventar. Se
     * comprueba una sola vez por request y, si faltan, el puntaje se guarda igual
     * y la sugerencia simplemente no existe.
     */
    private static function tiene_nota_sugerida(): bool
    {
        static $ok = null;

        if ($ok !== null) return $ok;

        try {
            $cols = DB::select("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scoring_llamadas'
              AND COLUMN_NAME LIKE 'nota\\_sugerida%'");

            $ok = count($cols) >= count(self::NOTAS_COLUMNAS);
        } catch (\Throwable $e) {
            $ok = false;
        }

        return $ok;
    }

    private static function guardar(array $filas): void
    {
        if (!$filas) return;

        $columnas = ['llamada_tipo_id', 'humano_exitosa', 'base_pct', 'puntaje',
            'logit', 'disparos', 'motivo',
            'nota_sugerida', 'nota_sugerida_n', 'nota_sugerida_total',
            'pesos_version', 'rango', 'updated_at'];

        if (!self::tiene_nota_sugerida()) {
            // el alter pendiente: se quitan las claves del arreglo y del upsert
            foreach ($filas as $i => $fila) {
                foreach (self::NOTAS_COLUMNAS as $c) unset($filas[$i][$c]);
            }

            $columnas = array_values(array_diff($columnas, self::NOTAS_COLUMNAS));
        }

        foreach (array_chunk($filas, self::LOTE_GUARDADO) as $lote) {
            DB::table('scoring_llamadas')->upsert($lote, 'vapi_id', $columnas);
        }
    }

    /** Etiqueta del rango que se guardo en cada fila, para trazabilidad. */
    public static function rango_etiqueta(): string
    {
        $filtro = self::filtro();
        $ini = $filtro->fecha_inicio ?? '';
        $fin = $filtro->fecha_fin ?? '';

        $texto = ($ini ? mb_substr((string) $ini, 0, 10) : 'desde')
            . '_' . ($fin ? mb_substr((string) $fin, 0, 10) : 'hasta');

        return mb_substr($texto, 0, 24);
    }

    //------------------- comparar -------------------

    /**
     * Cruce del puntaje guardado con lo que clasifico el humano.
     *
     * Las metricas que salen:
     * - matriz 2x2 en el umbral, con precision, recall y F1;
     * - AUC por Mann-Whitney: que tan bien ordena el puntaje, sin umbral;
     * - deciles de calibracion: si el modelo dice 70 %, deberia haber pasado 70 %;
     * - KPIs por tipo, para ver si un tipo funciona peor que el promedio;
     * - cuantas filas quedaron obsoletas contra la version actual de los pesos.
     */
    public static function comparar(): stdClass
    {
        [$where, $params] = self::filtro_llamadas();

        $filas = DB::select("
        SELECT s.*, l.analisis_audio, l.audio_duracion, l.audio_link, l.origen, l.destino,
               l.created_at, l.buzon_de_voz, l.entro_llamada,
               COALESCE(co.nombres, 'SIN CONDUCTOR') AS conductor,
               COALESCE(t.nombres, 'SIN TRT') AS trt,
               tl.nombre AS tipo_nombre
        FROM scoring_llamadas s
        JOIN llamadas l ON l.vapi_id = s.vapi_id
        " . str_replace(['FROM llamadas l', 'WHERE 1 = 1'], '', self::joins()) . "
        LEFT JOIN tipos_llamada tl ON tl.id = l.llamada_tipo_id
        WHERE 1 = 1 " . $where, $params);

        foreach ($filas as $f) {
            $f->disparos = json_decode((string) $f->disparos) ?: [];
            $f->disparos_texto = self::disparos_texto($f->disparos);
        }

        $out = new stdClass();
        $out->filas = $filas;
        $out->totales = count($filas);
        $out->umbral = self::umbral();
        $out->version_actual = self::pesos_version();
        $out->bases = self::base_por_tipo();

        // solo las puntuadas opinan: buzon y sin texto quedan aparte (el toggle
        // de la vista decide si las sin texto tambien entran)
        $ok = array_values(array_filter($filas, fn($f) => self::en_metricas($f)));

        $out->por_motivo = ['ok' => 0, 'buzon' => 0, 'sin_texto' => 0];
        foreach ($filas as $f) {
            $out->por_motivo[$f->motivo] = ($out->por_motivo[$f->motivo] ?? 0) + 1;
        }
        $out->en_metricas = count($ok);

        $out->matriz = self::matriz($ok, $out->umbral);
        $out->auc = self::auc($ok);
        $out->deciles = self::deciles($ok);
        $out->por_tipo = self::kpis_por_tipo($ok);
        $out->sugerencia = self::metricas_sugerencia($filas);
        $out->obsoletas = self::obsoletas($filas, $out->version_actual);
        $out->obsoletas_totales = self::llamadas_obsoletas();
        $out->ultima = self::ultima_ejecucion($filas);
        $out->lista = self::ordenar($filas, self::vista(), self::offset());

        return $out;
    }

    /**
     * Metrica de la sugerencia de analisis de audio en el rango de la pagina:
     * cobertura (cuantas de las ok tienen sugerencia) y coincidencia exacta con
     * la nota que escribio el humano, normalizada con la misma funcion del mapa
     * ('NO ATIENDE' y 'No atiende' cuentan como una sola nota).
     *
     * `human_sin_sugerencia` cuenta las ok que si tienen nota del humano pero la
     * sugerencia callo (no alcanzaron el apoyo minimo): es el techo real de la
     * cobertura, no un error. Si el ALTER aun no esta aplicado, todo sale en cero
     * y la vista lo muestra como pendiente, no como fallo.
     */
    private static function metricas_sugerencia(array $filas): stdClass
    {
        $sugeridas = 0;
        $comparables = 0;
        $coinciden = 0;
        $human_sin_sugerencia = 0;

        foreach ($filas as $f) {
            $sug = trim((string) ($f->nota_sugerida ?? ''));
            $hum = trim((string) ($f->analisis_audio ?? ''));

            if ($sug !== '') {
                $sugeridas++;

                if ($hum !== '') {
                    $comparables++;

                    if (mb_strtoupper(Palabras::normalizar($sug))
                        === mb_strtoupper(Palabras::normalizar($hum))) $coinciden++;
                }
            } elseif ($hum !== '' && $f->motivo === 'ok') {
                $human_sin_sugerencia++;
            }
        }

        $out = new stdClass();
        $out->sugeridas = $sugeridas;
        $out->comparables = $comparables;
        $out->coinciden = $coinciden;
        $out->pct = $comparables > 0 ? $coinciden * 100 / $comparables : null;
        $out->human_sin_sugerencia = $human_sin_sugerencia;

        return $out;
    }

    private static function matriz(array $ok, int $umbral): stdClass
    {
        $tp = $fp = $fn = $tn = 0;

        foreach ($ok as $f) {
            $predicho_pos = (float) $f->puntaje >= $umbral;
            $real = (int) $f->humano_exitosa === 1;

            if ($predicho_pos && $real) $tp++;
            elseif ($predicho_pos) $fp++;
            elseif ($real) $fn++;
            else $tn++;
        }

        $n = max(1, $tp + $fp + $fn + $tn);
        $precision = ($tp + $fp) ? $tp / ($tp + $fp) : null;
        $recall = ($tp + $fn) ? $tp / ($tp + $fn) : null;

        $out = new stdClass();
        $out->tp = $tp;
        $out->fp = $fp;
        $out->fn = $fn;
        $out->tn = $tn;
        $out->n = $tp + $fp + $fn + $tn;
        $out->accuracy = ($tp + $tn) * 100 / $n;
        $out->precision = $precision === null ? null : $precision * 100;
        $out->recall = $recall === null ? null : $recall * 100;
        $out->f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0)
            ? 2 * $precision * $recall / ($precision + $recall) : 0;

        // con que tasa mayoritaria hay que compararse: un modelo que siempre
        // dice "fallida" ya acierta el 75 % sin ver el audio
        $real_pos = ($tp + $fn) / $n;
        $out->mayoritaria = max($real_pos, 1 - $real_pos) * 100;

        return $out;
    }

    /**
     * AUC por Mann-Whitney con rangos promedio para los empates.
     *
     * Mide orden, no calibracion: si el puntaje de las exitosas suele quedar por
     * encima del de las fallidas, el area se acerca a 1. 0,5 es azar.
     */
    public static function auc(array $ok): ?float
    {
        $positivos = array_values(array_filter($ok, fn($f) => (int) $f->humano_exitosa === 1));
        $negativas = array_values(array_filter($ok, fn($f) => (int) $f->humano_exitosa === 0));

        if (!$positivos || !$negativas) return null;

        $ordenadas = $ok;
        usort($ordenadas, fn($a, $b) => (float) $a->puntaje <=> (float) $b->puntaje);

        $rangos = [];
        $i = 0;
        $n = count($ordenadas);

        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && (float) $ordenadas[$j + 1]->puntaje === (float) $ordenadas[$i]->puntaje) $j++;

            // rango promedio del bloque empatado (1-based)
            $promedio = ($i + $j) / 2 + 1;
            for ($k = $i; $k <= $j; $k++) $rangos[$k] = $promedio;

            $i = $j + 1;
        }

        $suma_pos = 0;
        foreach ($ordenadas as $idx => $f) {
            if ((int) $f->humano_exitosa === 1) $suma_pos += $rangos[$idx];
        }

        $np = count($positivos);
        $nn = count($negativas);

        return ($suma_pos - $np * ($np + 1) / 2) / ($np * $nn);
    }

    /** Calibracion en 10 cajones iguales: puntaje medio vs exito real. */
    public static function deciles(array $ok): array
    {
        if (!$ok) return [];

        $ordenadas = $ok;
        usort($ordenadas, fn($a, $b) => (float) $a->puntaje <=> (float) $b->puntaje);

        $n = count($ordenadas);
        $out = [];

        for ($d = 0; $d < 10; $d++) {
            $desde = (int) floor($d * $n / 10);
            $hasta = (int) floor(($d + 1) * $n / 10);
            $trozo = array_slice($ordenadas, $desde, $hasta - $desde);

            if (!$trozo) continue;

            $e = 0;
            $suma = 0;
            foreach ($trozo as $f) {
                $e += (int) $f->humano_exitosa;
                $suma += (float) $f->puntaje;
            }

            $out[] = (object) [
                'decil' => $d + 1,
                'n' => count($trozo),
                'puntaje_medio' => $suma / count($trozo),
                'real_pct' => $e * 100 / count($trozo),
                'exitosas' => $e,
            ];
        }

        return $out;
    }

    /** Concordancia y brecha de puntajes dentro de cada tipo de llamada. */
    public static function kpis_por_tipo(array $ok): array
    {
        $grupos = [];

        foreach ($ok as $f) {
            $clave = (int) ($f->llamada_tipo_id ?? 0);
            if (!isset($grupos[$clave])) $grupos[$clave] = [];
            $grupos[$clave][] = $f;
        }

        $out = [];

        foreach ($grupos as $tipo => $lista) {
            $acierta = 0;
            $e_media = 0;
            $n_e = 0;
            $f_media = 0;
            $n_f = 0;

            foreach ($lista as $f) {
                $predicho_pos = (float) $f->puntaje >= self::umbral();
                $real = (int) $f->humano_exitosa === 1;

                if ($predicho_pos === $real) $acierta++;

                if ($real) {
                    $e_media += (float) $f->puntaje;
                    $n_e++;
                } else {
                    $f_media += (float) $f->puntaje;
                    $n_f++;
                }
            }

            $out[$tipo] = (object) [
                'tipo' => $tipo,
                'nombre' => $lista[0]->tipo_nombre ?? ('tipo ' . $tipo),
                'n' => count($lista),
                'accuracy' => $acierta * 100 / count($lista),
                'media_e' => $n_e ? $e_media / $n_e : null,
                'media_f' => $n_f ? $f_media / $n_f : null,
                'brecha' => ($n_e && $n_f) ? ($e_media / $n_e) - ($f_media / $n_f) : null,
            ];
        }

        uasort($out, fn($a, $b) => $b->n <=> $a->n);

        return $out;
    }

    private static function obsoletas(array $filas, string $version): int
    {
        $n = 0;
        foreach ($filas as $f) {
            if ((string) $f->pesos_version !== $version) $n++;
        }

        return $n;
    }

    private static function ultima_ejecucion(array $filas): ?string
    {
        $max = null;
        foreach ($filas as $f) {
            if ($f->updated_at !== null && ($max === null || $f->updated_at > $max)) $max = $f->updated_at;
        }

        return $max === null ? null : (string) $max;
    }

    /** Orden de la tabla segun la pestana elegida, con su tope de filas. */
    public static function ordenar(array $filas, string $vista, int $offset = 0): array
    {
        $candidatas = $filas;

        // `desacuerdos` contestan al "que me toca revisar": lo mas reciente que
        // queda mal. `peores` contesta al "donde mas me equivoco": magnitud del
        // error. Son listas distintas, no la misma ordenada dos veces.
        if ($vista === 'desacuerdos') {
            $candidatas = array_values(array_filter($filas, fn($f) => self::falla($f)));
            usort($candidatas, fn($a, $b) => strcmp((string) $b->created_at, (string) $a->created_at));
        } elseif ($vista === 'peores') {
            $candidatas = array_values(array_filter($filas, fn($f) => self::falla($f)));
            usort($candidatas, fn($a, $b) => abs(self::error($b)) <=> abs(self::error($a)));
        } elseif ($vista === 'sin_texto') {
            $candidatas = array_values(array_filter($filas,
                fn($f) => $f->motivo !== 'ok' || $f->puntaje === null));
        } else {
            usort($candidatas, fn($a, $b) => strcmp((string) $b->created_at, (string) $a->created_at));
        }

        return array_slice($candidatas, $offset, self::MAX_FILAS);
    }

    /**
     * Desacuerdos entre el modelo y el humano, ordenados por el tamano del error.
     *
     * Solo senala: ninguna de estas filas se reetiqueta ni se escribe en
     * `llamadas`. Quien decide escucha el audio en Etiquetar.
     */
    public static function desacuerdos(?stdClass $comparado = null, int $limite = self::MAX_FILAS): array
    {
        $datos = $comparado ?? self::comparar();

        $fallas = array_values(array_filter($datos->filas, fn($f) => self::falla($f)));
        usort($fallas, fn($a, $b) => abs(self::error($b)) <=> abs(self::error($a)));

        return array_slice($fallas, 0, $limite);
    }

    /** El modelo y el humano no coinciden (las que no tienen puntaje no cuentan). */
    public static function falla(stdClass $f): bool
    {
        if (!self::en_metricas($f)) return false;

        $predicho_pos = (float) $f->puntaje >= self::umbral();

        return $predicho_pos !== ((int) $f->humano_exitosa === 1);
    }

    /** Distancia al veredicto del humano: exito real = 100, fracaso = 0. */
    public static function error(stdClass $f): float
    {
        if ($f->puntaje === null) return 0;

        $real = (int) $f->humano_exitosa === 1 ? 100 : 0;

        return (float) $f->puntaje - $real;
    }

    public static function disparos_texto(array $disparos): string
    {
        $trozos = [];

        foreach ($disparos as $d) {
            $peso = (float) $d->peso;
            $trozos[] = $d->palabra . ' ' . ($peso > 0 ? '+' : '') . number_format($peso, 2);
        }

        return implode(' · ', $trozos);
    }

    //------------------- auditoria de los pesos -------------------

    /**
     * Que paso realmente en las llamadas donde un peso disparo.
     *
     * Compara el peso asignado contra el efecto que tuvo: se toma el logit de la
     * tasa de exito observada en esas llamadas, se le resta el logit de la base
     * del tipo (que es de donde partio el modelo) y se amortigua con el apoyo.
     * Si `delta` es grande y el apoyo alto, el peso esta mintiendo.
     *
     * La clave de cada contador incluye el tipo de la ENTRADA (`palabra|gram|G|1`):
     * la misma palabra puede tener un peso distinto en Global y en un tipo, y sin
     * esa dimension las dos filas se fusionarian y el delta mentiria. `disparos`
     * guarda el tipo que efectivamente aplico en esa llamada, y ahi se lee.
     *
     * `$filas` es opcional: la pagina de evaluacion ya las trae en memoria y las
     * pasa; la de configuracion las lee sola con `filas_rango()`.
     */
    public static function auditar_pesos(?array $filas = null): array
    {
        $filas = $filas ?? self::filas_rango();

        $bases = self::base_por_tipo();
        $pesos = self::pesos_activos();

        $contadores = [];
        $anadir = function ($p) use (&$contadores) {
            $contadores[self::clave_entrada($p->palabra, $p->gram, $p->tipo)] = (object) [
                'entrada' => $p,
                'n' => 0,
                'e' => 0,
                'suma_puntaje' => 0,
            ];
        };

        foreach ($pesos['global'] as $p) $anadir($p);
        foreach ($pesos['tipos'] as $lista) {
            foreach ($lista as $p) $anadir($p);
        }

        foreach ($filas as $f) {
            if ($f->motivo !== 'ok') continue;

            foreach ($f->disparos as $d) {
                $clave = self::clave_entrada($d->palabra, (int) $d->gram, $d->tipo ?? null);
                if (!isset($contadores[$clave])) continue;

                $contadores[$clave]->n++;
                $contadores[$clave]->e += (int) $f->humano_exitosa;
                $contadores[$clave]->suma_puntaje += (float) $f->puntaje;
            }
        }

        $out = [];

        foreach ($contadores as $clave => $c) {
            if (!$c->n) {
                $out[$clave] = (object) [
                    'clave' => $clave,
                    'palabra' => $c->entrada->palabra,
                    'gram' => $c->entrada->gram,
                    'tipo' => $c->entrada->tipo,
                    'peso' => $c->entrada->peso,
                    'n' => 0,
                    'e' => 0,
                    'real_pct' => null,
                    'puntaje_medio' => null,
                    'sugerido' => null,
                    'delta' => null,
                    'apoyable' => false,
                ];
                continue;
            }

            $real = $c->e * 100 / $c->n;

            $tipo = $c->entrada->tipo;
            // la base que resta es la de la entrada: una entrada global se audita
            // contra el promedio de los tipos que la usaron, que es de donde
            // partio realmente el puntaje de esas llamadas
            [$base_pct] = self::base_de($tipo, $bases);

            $sugerido = (self::logit($real) - self::logit($base_pct))
                * $c->n / ($c->n + self::SHRINK);
            $sugerido = round(max(-self::PESO_MAX, min(self::PESO_MAX, $sugerido)), 3);

            $out[$clave] = (object) [
                'clave' => $clave,
                'palabra' => $c->entrada->palabra,
                'gram' => $c->entrada->gram,
                'tipo' => $tipo,
                'peso' => $c->entrada->peso,
                'n' => $c->n,
                'e' => $c->e,
                'real_pct' => $real,
                'puntaje_medio' => $c->suma_puntaje / $c->n,
                'sugerido' => $sugerido,
                'delta' => round($sugerido - $c->entrada->peso, 3),
                'apoyable' => $c->n >= Palabras::soporte_por_tipo(),
            ];
        }

        uasort($out, fn($a, $b) => abs((float) $b->delta) <=> abs((float) $a->delta));

        return $out;
    }

    /**
     * Concordancia del rango sin armar las demas metricas.
     *
     * La pantalla de configuracion la necesita para responder "guarde un peso y
     * mejoro?" sin pagarse el `comparar()` completo (matriz, deciles, KPIs por
     * tipo y tabla de llamadas).
     */
    public static function concordancia(array $filas): stdClass
    {
        $ok = array_values(array_filter($filas, fn($f) => self::en_metricas($f)));

        $aciertos = 0;
        foreach ($ok as $f) {
            if (!self::falla($f)) $aciertos++;
        }

        $out = new stdClass();
        $out->n = count($ok);
        $out->aciertos = $aciertos;
        $out->pct = $out->n ? $aciertos * 100 / $out->n : null;
        $out->umbral = self::umbral();
        $out->auc = self::auc($ok);

        return $out;
    }

    private static function clave_entrada(string $palabra, int $gram, ?int $tipo): string
    {
        return $palabra . '|' . $gram . '|' . ($tipo === null ? 'G' : $tipo);
    }

    /**
     * Filas guardadas del rango, sin armar las metricas: es lo que necesitan la
     * pantalla de configuracion (columnas "dispara / % real / delta") y el panel
     * de pesos que mienten cuando nadie pidio todavia la comparacion.
     */
    public static function filas_rango(): array
    {
        [$where, $params] = self::filtro_llamadas();

        $filas = DB::select("
        SELECT s.vapi_id, s.llamada_tipo_id, s.humano_exitosa, s.base_pct, s.puntaje,
               s.logit, s.disparos, s.motivo, s.pesos_version, s.rango, s.updated_at
        FROM scoring_llamadas s
        JOIN llamadas l ON l.vapi_id = s.vapi_id
        " . str_replace(['FROM llamadas l', 'WHERE 1 = 1'], '', self::joins()) . '
        WHERE 1 = 1 ' . $where, $params);

        foreach ($filas as $f) {
            $f->disparos = json_decode((string) $f->disparos) ?: [];
        }

        return $filas;
    }

    /**
     * Listado paginado de las llamadas del rango, filtrable por puntaje, cruce
     * modelo/humano, motivo, codigo del revisor y palabra que disparo.
     *
     * A diferencia de `comparar()` (que carga todo el rango en memoria para armar
     * matriz, AUC y deciles), esta vista de listado filtra y pagina en SQL: no
     * necesita las metricas, solo las filas que se van a mostrar, asi que abre
     * barata aunque el rango tenga 24 mil llamadas.
     *
     * El rango base (fecha, tipo, conductor, universo) ya viene de
     * `filtro_llamadas()`; `$extra` son los filtros propios de esta pantalla. El
     * umbral para el cruce modelo/humano se lee del filtro compartido.
     */
    public static function listar_llamadas_rango(array $extra, int $offset, int $limite): stdClass
    {
        [$where, $params] = self::filtro_llamadas();
        $sql = $where;

        // omitir buzon: saca las llamadas de contestador del listado
        if (!empty($extra['omitir_buzon'])) {
            $sql .= ' AND l.buzon_de_voz = 0 ';
        }

        $motivo = (string) ($extra['motivo'] ?? '');
        if (isset(self::$motivos[$motivo])) {
            $sql .= ' AND s.motivo = ? ';
            $params[] = $motivo;
        }

        // rango de puntaje del modelo; las filas sin puntaje (buzon/sin texto)
        // quedan fuera por el propio comparador de SQL (NULL no pasa un >=)
        if (($extra['puntaje_min'] ?? '') !== '') {
            $sql .= ' AND s.puntaje >= ? ';
            $params[] = (float) $extra['puntaje_min'];
        }
        if (($extra['puntaje_max'] ?? '') !== '') {
            $sql .= ' AND s.puntaje <= ? ';
            $params[] = (float) $extra['puntaje_max'];
        }

        $umbral = self::umbral();
        switch ((string) ($extra['cruce'] ?? '')) {
            case 'coincide':
                $sql .= ' AND ((s.puntaje >= ? AND s.humano_exitosa = 1) OR (s.puntaje < ? AND s.humano_exitosa = 0)) ';
                $params[] = $umbral; $params[] = $umbral;
                break;
            case 'desacuerda':
                $sql .= ' AND ((s.puntaje < ? AND s.humano_exitosa = 1) OR (s.puntaje >= ? AND s.humano_exitosa = 0)) ';
                $params[] = $umbral; $params[] = $umbral;
                break;
            case 'fp':
                $sql .= ' AND s.puntaje >= ? AND s.humano_exitosa = 0 ';
                $params[] = $umbral;
                break;
            case 'fn':
                $sql .= ' AND s.puntaje < ? AND s.humano_exitosa = 1 ';
                $params[] = $umbral;
                break;
            case 'humano_e': $sql .= ' AND s.humano_exitosa = 1 '; break;
            case 'humano_f': $sql .= ' AND s.humano_exitosa = 0 '; break;
            case 'modelo_e': $sql .= ' AND s.puntaje >= ? '; $params[] = $umbral; break;
            case 'modelo_f': $sql .= ' AND s.puntaje < ? '; $params[] = $umbral; break;
        }

        // codigo que el revisor escribe en llamadas.analisis_audio (búsqueda libre)
        $codigo = trim((string) ($extra['audio_codigo'] ?? ''));
        if ($codigo !== '') {
            $sql .= ' AND l.analisis_audio LIKE ? ';
            $params[] = '%' . $codigo . '%';
        }

        // codigo que el revisor escribe en llamadas.analisis_transcripcion
        $trascripcion = trim((string) ($extra['transcripcion_codigo'] ?? ''));
        if ($trascripcion !== '') {
            $sql .= ' AND l.analisis_transcripcion LIKE ? ';
            $params[] = '%' . $trascripcion . '%';
        }

        // palabra o frase que encendio un peso en esta llamada: se busca dentro del
        // JSON de disparos por su campo `palabra`, no por un LIKE a ciegas
        $palabra = Palabras::normalizar(trim((string) ($extra['palabra'] ?? '')));
        if ($palabra !== '') {
            $sql .= ' AND JSON_SEARCH(s.disparos, "one", ?, NULL, "$[*].palabra") IS NOT NULL ';
            $params[] = $palabra;
        }

        $from = '
        FROM scoring_llamadas s
        JOIN llamadas l ON l.vapi_id = s.vapi_id'
            . str_replace(['FROM llamadas l', 'WHERE 1 = 1'], '', self::joins()) . '
        LEFT JOIN tipos_llamada tl ON tl.id = l.llamada_tipo_id
        WHERE 1 = 1 ' . $sql;

        $total = (int) (DB::select('SELECT COUNT(*) AS n' . $from, $params)[0]->n ?? 0);

        $orden = match ((string) ($extra['orden'] ?? '')) {
            'puntaje_desc' => ' ORDER BY s.puntaje IS NULL, s.puntaje DESC, l.created_at DESC ',
            'puntaje_asc' => ' ORDER BY s.puntaje IS NULL, s.puntaje ASC, l.created_at DESC ',
            default => ' ORDER BY l.created_at DESC ',
        };

        $filas = DB::select("
        SELECT s.*, l.analisis_audio, l.analisis_transcripcion, l.audio_link, l.audio_duracion,
               l.buzon_de_voz, l.created_at,
               COALESCE(co.nombres, 'SIN CONDUCTOR') AS conductor,
               COALESCE(t.nombres, 'SIN TRT') AS trt,
               tl.nombre AS tipo_nombre" . $from . $orden . ' LIMIT ? OFFSET ?',
            array_merge($params, [$limite, max(0, $offset)]));

        foreach ($filas as $f) {
            $f->disparos = json_decode((string) $f->disparos) ?: [];
            $f->disparos_texto = self::disparos_texto($f->disparos);
        }

        $out = new stdClass();
        $out->filas = $filas;
        $out->total = $total;
        $out->offset = max(0, $offset);
        $out->limite = $limite;
        $out->umbral = $umbral;

        return $out;
    }

    /**
     * Llamadas cuyo puntaje guardado ya no corresponde a los pesos actuales, en
     * toda la tabla: son las que el banner invita a recalcular.
     */
    public static function llamadas_obsoletas(): int
    {
        return (int) DB::table('scoring_llamadas')
            ->where('pesos_version', '!=', self::pesos_version())
            ->count();
    }


    //------------------- sugerir desde palabras -------------------

    /**
     * Candidatas a peso salidas del analisis de `lupita/palabras`, en una sola
     * pasada por la transcripcion y abiertas por tipo de llamada.
     *
     * El peso inicial es el logaritmo del razon de momios (odds ratio) de la
     * palabra en la tabla 2x2 de exitosas/fallidas, amortiguado segun su apoyo:
     *
     *   OR   = [e/(tot_e-e)] / [f/(tot_f-f)]     (con correccion de continuidad)
     *   peso = log(OR) * n/(n + 30)
     *
     * Se descarta el ECO de la IA (la palabra existe porque la IA la pregunta),
     * lo que no es significativo y las stopwords; una palabra rara no puede
     * arrastrar el puntaje porque el factor n/(n+30) la encoge.
     *
     * No escribe nada: devuelve el preview y decide el usuario.
     */
    public static function sugerir_desde_palabras(int $limite = 25): array
    {
        // la clase hermana guarda el filtro en un estatico: se le pasa el mismo
        // filtro y se le devuelve el acumulador a su estado neutro al terminar
        Palabras::set_filtro(self::filtro());
        Palabras::set_excluir_indeterminada(true);
        Palabras::set_acumular_por_tipo(true);

        $ini = microtime(true);
        $datos = Palabras::analizar();
        Palabras::set_acumular_por_tipo(false);

        $existentes = self::claves_existentes();

        $out = ['grupos' => [], 'segundos' => round(microtime(true) - $ini, 2), 'limite' => $limite];

        foreach ($datos->por_tipo as $tipo => $bloque) {
            $out['grupos'][(int) $tipo] = self::candidatas(
                $bloque->palabras, (int) $tipo, $bloque->tot_e, $bloque->tot_f, $existentes, $limite
            );
        }

        // el corte global va bajo la clave 'G', no bajo 0: el tipo 0
        // (INDETERMINADA) usa esa clave numérica y las sugerencias de Global
        // desaparecerian al armar la pestana
        $out['grupos']['G'] = self::candidatas(
            $datos->palabras, null, $datos->con_texto_e, $datos->con_texto_f, $existentes, $limite
        );

        ksort($out['grupos']);

        return $out;
    }

    /**
     * Filtra y convierte las filas del ranking en candidatas con peso.
     */
    private static function candidatas(array $palabras, ?int $tipo, int $tot_e, int $tot_f,
                                       array $existentes, int $limite): array
    {
        $firmes = array_values(array_filter($palabras,
            fn($p) => $p->sig !== '' && !$p->eco
                && $p->total >= self::MIN_APOYO
                && !Palabras::es_stopword($p->palabra)));

        usort($firmes, fn($a, $b) => abs((float) $b->z) <=> abs((float) $a->z));

        $out = [];

        foreach (array_slice($firmes, 0, $limite) as $p) {
            $e = (int) $p->e;
            $f = (int) $p->f;

            // correccion de continuidad: una palabra que solo aparece en
            // exitosas daria un OR infinito; con +0.5 el peso queda grande
            // pero finito
            $or = (($e + 0.5) / max(0.5, $tot_e - $e + 0.5))
                / (($f + 0.5) / max(0.5, $tot_f - $f + 0.5));

            $peso = log($or) * ($e + $f) / ($e + $f + self::SHRINK);
            $peso = round(max(-self::PESO_MAX, min(self::PESO_MAX, $peso)), 3);

            $out[] = (object) [
                'palabra' => $p->palabra,
                'gram' => (int) $p->gram,
                'tipo' => $tipo,
                'peso' => $peso,
                'e' => $e,
                'f' => $f,
                'total' => (int) $p->total,
                // `lift` sale INF cuando la palabra solo aparece en exitosas
                // (share_f = 0), y `z` puede venir null: `response()->json()`
                // revienta con "Inf and NaN cannot be JSON encoded" si se cuela
                // un no-finito, tumbando toda la peticion de sugerencias. Se
                // envian a null para que el modal muestre el guion (—).
                'z' => self::finito($p->z),
                'sig' => $p->sig,
                'lift' => self::finito($p->lift),
                'origen' => $p->origen,
                'existe' => isset($existentes[$p->palabra . '|' . $p->gram . '|' . ($tipo ?? 'G')])
                    || isset($existentes[$p->palabra . '|' . $p->gram . '|G']),
            ];
        }

        return $out;
    }

    private static function claves_existentes(): array
    {
        $out = [];

        foreach (DB::select('SELECT palabra, gram, llamada_tipo_id FROM palabras_pesos') as $f) {
            $clave = $f->palabra . '|' . (int) $f->gram . '|'
                . ($f->llamada_tipo_id === null ? 'G' : (int) $f->llamada_tipo_id);
            $out[$clave] = true;
        }

        return $out;
    }

    /** Un float no-finito (INF/-INF/NaN) no se puede ir a JSON: se manda a null. */
    private static function finito($valor)
    {
        if ($valor === null) return null;

        return (is_numeric($valor) && is_finite((float) $valor)) ? (float) $valor : null;
    }

    //------------------- guardar pesos -------------------

    /**
     * Alta, edicion y baja de pesos.
     *
     * La clave es (palabra, gram, tipo). Como el indice unico de MySQL no puede
     * impedir dos filas globales iguales (NULL no rompe el empate), la clave se
     * comprueba a mano antes de insertar.
     */
    public static function guardar_pesos(array $cambios, array $borrar, int $usuario_id): stdClass
    {
        $out = new stdClass();
        $out->creados = 0;
        $out->actualizados = 0;
        $out->borrados = 0;
        $out->errores = [];
        $out->version = '';

        $ahora = Carbon::now();

        DB::transaction(function () use ($cambios, $borrar, $usuario_id, $ahora, $out) {
            foreach ($cambios as $c) {
                $palabra = Palabras::normalizar(trim((string) ($c['palabra'] ?? '')));
                $palabra = preg_replace('/\s+/', ' ', $palabra);

                if ($palabra === '' || mb_strlen($palabra) > 80) {
                    $out->errores[] = 'palabra vacia o demasiado larga';
                    continue;
                }

                $gram = max(1, min(5, str_word_count($palabra)));
                $tipo = isset($c['llamada_tipo_id']) && $c['llamada_tipo_id'] !== ''
                    ? (int) $c['llamada_tipo_id'] : null;
                $peso = round(max(-self::PESO_MAX, min(self::PESO_MAX, (float) ($c['peso'] ?? 0))), 3);
                $activa = !empty($c['activa']);
                $nota = mb_substr(trim((string) ($c['nota'] ?? '')), 0, 190) ?: null;

                $datos = [
                    'palabra' => $palabra,
                    'gram' => $gram,
                    'llamada_tipo_id' => $tipo,
                    'peso' => $peso,
                    'activa' => $activa,
                    'nota' => $nota,
                    'actualizada_por' => $usuario_id,
                    'updated_at' => $ahora,
                ];

                $id = isset($c['id']) && $c['id'] ? (int) $c['id'] : null;

                if ($id) {
                    DB::table('palabras_pesos')->where('id', $id)->update($datos);
                    $out->actualizados++;
                    continue;
                }

                // busqueda de la misma clave: para Global hay que usar whereNull,
                // combinarlo con where('llamada_tipo_id', $tipo) nunca daria match
                $igual = DB::table('palabras_pesos')
                    ->where('palabra', $palabra)
                    ->where('gram', $gram)
                    ->when($tipo === null, fn($q) => $q->whereNull('llamada_tipo_id'),
                        fn($q) => $q->where('llamada_tipo_id', $tipo))
                    ->value('id');

                if ($igual) {
                    DB::table('palabras_pesos')->where('id', $igual)->update($datos);
                    $out->actualizados++;
                } else {
                    DB::table('palabras_pesos')->insert($datos + [
                        // la alta manual nace manual; una fila sugerida que se
                        // reescribe por esta via pasa a ser del usuario
                        'origen' => 'manual',
                        'creada_por' => $usuario_id,
                        'created_at' => $ahora,
                    ]);
                    $out->creados++;
                }
            }

            if ($borrar) {
                $out->borrados = DB::table('palabras_pesos')
                    ->whereIn('id', array_map('intval', $borrar))
                    ->delete();
            }
        });

        // se cayo la cache de la request: la vista tiene que mostrar ya el nuevo
        // estado y marcar obsoletos los puntajes guardados
        self::$pesos = null;
        self::$version = null;
        self::$compilados = [];
        $out->version = self::pesos_version();

        return $out;
    }

    /**
     * Aplica las candidatas elegidas del preview de `sugerir_desde_palabras()`.
     *
     * Inserta con `origen = sugerida` y respeta lo que el usuario ya escribio a
     * mano: una fila `manual` nunca se pisa, ni siquiera si la sugerencia pide
     * otro peso. Una fila `sugerida` previa si se actualiza, porque vuelve a ser
     * el mismo calculo sobre datos mas recientes.
     */
    public static function aplicar_sugeridos(array $lista, int $usuario_id): stdClass
    {
        $out = new stdClass();
        $out->creados = 0;
        $out->actualizados = 0;
        $out->omitidos = 0;
        $out->errores = [];

        $ahora = Carbon::now();

        DB::transaction(function () use ($lista, $usuario_id, $ahora, $out) {
            foreach ($lista as $c) {
                $palabra = preg_replace('/\s+/', ' ',
                    Palabras::normalizar(trim((string) ($c['palabra'] ?? ''))));

                if ($palabra === '' || mb_strlen($palabra) > 80) {
                    $out->errores[] = 'palabra vacia o demasiado larga';
                    continue;
                }

                $gram = max(1, min(5, (int) ($c['gram'] ?? 1) ?: str_word_count($palabra)));
                $tipo = isset($c['llamada_tipo_id']) && $c['llamada_tipo_id'] !== ''
                    ? (int) $c['llamada_tipo_id'] : null;
                $peso = round(max(-self::PESO_MAX, min(self::PESO_MAX,
                    (float) ($c['peso'] ?? 0))), 3);

                $busqueda = DB::table('palabras_pesos')
                    ->where('palabra', $palabra)
                    ->where('gram', $gram)
                    ->when($tipo === null, fn($q) => $q->whereNull('llamada_tipo_id'),
                        fn($q) => $q->where('llamada_tipo_id', $tipo))
                    ->first(['id', 'origen']);

                if ($busqueda && $busqueda->origen === 'manual') {
                    $out->omitidos++;
                    continue;
                }

                if ($busqueda) {
                    DB::table('palabras_pesos')->where('id', $busqueda->id)->update([
                        'peso' => $peso,
                        'activa' => true,
                        'actualizada_por' => $usuario_id,
                        'updated_at' => $ahora,
                    ]);
                    $out->actualizados++;
                    continue;
                }

                DB::table('palabras_pesos')->insert([
                    'palabra' => $palabra,
                    'gram' => $gram,
                    'llamada_tipo_id' => $tipo,
                    'peso' => $peso,
                    'activa' => true,
                    'origen' => 'sugerida',
                    'nota' => mb_substr(trim((string) ($c['nota'] ?? '')), 0, 190) ?: null,
                    'creada_por' => $usuario_id,
                    'actualizada_por' => $usuario_id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                $out->creados++;
            }
        });

        self::$pesos = null;
        self::$version = null;
        self::$compilados = [];
        $out->version = self::pesos_version();

        return $out;
    }

    /**
     * Copia los pesos de un tipo a otro (o de Global a un tipo) sin pisar los que
     * ya existen en el destino.
     */
    public static function copiar_pesos(?int $desde, ?int $hacia, int $usuario_id): int
    {
        $filas = DB::select('
        SELECT palabra, gram, peso, nota FROM palabras_pesos
        WHERE activa = 1 AND ' . ($desde === null ? 'llamada_tipo_id IS NULL' : 'llamada_tipo_id = ?')
            , $desde === null ? [] : [$desde]);

        $copiadas = 0;
        $ahora = Carbon::now();

        foreach ($filas as $f) {
            $ya = DB::table('palabras_pesos')
                ->where('palabra', $f->palabra)
                ->where('gram', $f->gram)
                ->when($hacia === null, fn($q) => $q->whereNull('llamada_tipo_id'),
                    fn($q) => $q->where('llamada_tipo_id', $hacia))
                ->exists();

            if ($ya) continue;

            DB::table('palabras_pesos')->insert([
                'palabra' => $f->palabra,
                'gram' => (int) $f->gram,
                'llamada_tipo_id' => $hacia,
                'peso' => (float) $f->peso,
                'activa' => true,
                'origen' => 'manual',
                'nota' => $f->nota,
                'creada_por' => $usuario_id,
                'actualizada_por' => $usuario_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $copiadas++;
        }

        return $copiadas;
    }

    //------------------- detalle -------------------

    /** Texto del conductor y pesos aplicados de una llamada, para el modal. */
    public static function detalle(string $vapi_id): stdClass
    {
        $out = new stdClass();

        $fila = DB::select('
        SELECT s.*, l.analisis_audio, l.audio_link, l.created_at
        FROM scoring_llamadas s
        JOIN llamadas l ON l.vapi_id = s.vapi_id
        WHERE s.vapi_id = ?
        LIMIT 1', [$vapi_id]);

        $out->fila = $fila ? $fila[0] : null;

        if ($out->fila) {
            $out->fila->disparos = json_decode((string) $out->fila->disparos) ?: [];
            $out->fila->disparos_texto = self::disparos_texto($out->fila->disparos);
        }

        $mensajes = DB::select("
        SELECT tipo, mensaje, orden
        FROM mensajes
        WHERE vapi_id = ? AND tipo = 'USER'
        ORDER BY orden", [$vapi_id]);

        $out->mensajes = array_map(fn($m) => (string) $m->mensaje, $mensajes);

        return $out;
    }

    //------------------- listas de apoyo -------------------

    /**
     * Tipos con llamadas dentro del filtro, para las pestanas de configuracion.
     *
     * El volumen y la tasa salen de `base_por_tipo()` y el conteo de pesos de
     * `pesos_activos()`, no de un SQL aparte: asi las pestanas usan exactamente
     * el mismo universo (fechas, conductor, TRT, ruta) que el resto de la pagina
     * en vez de mantener un segundo WHERE que se desincroniza.
     */
    public static function tipos_con_volumen(): array
    {
        $catalogo = DB::select('SELECT id, nombre, emoji, color FROM tipos_llamada ORDER BY id');
        $bases = self::base_por_tipo();
        $pesos = self::pesos_activos();

        $por_tipo = [];
        foreach ($pesos['tipos'] as $tipo => $lista) {
            $por_tipo[(int) $tipo] = count($lista);
        }

        $out = [(object) [
            'id' => null,
            'nombre' => 'Global',
            // sin emoji literal: la vista pone el icono de la pestana global
            'emoji' => '',
            'color' => 'secondary',
            'n' => $bases[0]->n ?? 0,
            'e' => $bases[0]->e ?? 0,
            'pct' => $bases[0]->pct ?? null,
            'pesos' => count($pesos['global']),
        ]];

        foreach ($catalogo as $t) {
            $id = (int) $t->id;

            // el tipo 0 (INDETERMINADA) no se configura: sus llamadas quedan
            // fuera del scoring, asi que no tiene pestana de pesos
            if ($id === 0) continue;

            $base = $bases[$id] ?? null;

            $out[] = (object) [
                'id' => $id,
                'nombre' => (string) $t->nombre,
                'emoji' => (string) ($t->emoji ?? ''),
                'color' => (string) ($t->color ?? ''),
                'n' => $base->n ?? 0,
                'e' => $base->e ?? 0,
                'pct' => $base->pct ?? null,
                'pesos' => $por_tipo[$id] ?? 0,
            ];
        }

        return $out;
    }

    /** Cuantos pesos globales hay activos. */
    public static function pesos_globales(): int
    {
        return (int) DB::table('palabras_pesos')
            ->where('activa', 1)
            ->whereNull('llamada_tipo_id')
            ->count();
    }

    /** Tabla completa de la configuracion, con el efecto medido en el rango. */
    public static function listar_pesos(): array
    {
        $filas = DB::select('
        SELECT p.*, tl.nombre AS tipo_nombre,
               u.name AS editada_por
        FROM palabras_pesos p
        LEFT JOIN tipos_llamada tl ON tl.id = p.llamada_tipo_id
        LEFT JOIN users u ON u.id = p.actualizada_por
        ORDER BY p.llamada_tipo_id IS NOT NULL DESC, p.llamada_tipo_id, p.gram DESC, ABS(p.peso) DESC');

        foreach ($filas as $f) {
            $f->peso = (float) $f->peso;
            $f->gram = (int) $f->gram;
            $f->activa = (int) $f->activa;
            $f->llamada_tipo_id = $f->llamada_tipo_id === null ? null : (int) $f->llamada_tipo_id;
        }

        return $filas;
    }

    //------------------- presentacion -------------------

    public static function peso_texto(float $peso): string
    {
        if ($peso === 0.0) return '0,00';

        return ($peso > 0 ? '+' : '') . number_format($peso, 2, ',', '.');
    }

    /** Multiplicador de posibilidad que significa el peso. */
    public static function factor(float $peso): string
    {
        return number_format(exp($peso), 2, ',', '.') . '×';
    }

    public static function color_puntaje(?float $puntaje): string
    {
        if ($puntaje === null) return 'secondary';
        if ($puntaje >= 70) return 'success';
        if ($puntaje >= 45) return 'warning';

        return 'danger';
    }

    public static function veredicto(stdClass $f): string
    {
        if ($f->motivo === 'buzon') return 'buzón';
        if ($f->motivo === 'sin_texto') return 'sin texto';
        if ($f->puntaje === null) return '—';

        return self::falla($f) ? '✗' : '✓';
    }
}
