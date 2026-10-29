<?php

namespace App\Database;

use App\Tools\TextAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Palabras clave de las llamadas exitosas vs las fallidas.
 *
 * La clasificacion es la del humano: `llamada_exitosa` (1 = exitosa revisada,
 * 0 = fallida revisada). Las -1 (indeterminadas) quedan fuera siempre.
 *
 * LAS PALABRAS SOLO SE TOMAN DEL TEXTO HUMANO: de los mensajes `USER` del
 * conductor y/o de `analisis_audio`, la nota que escribe el revisor. El guion
 * del `BOT` nunca aporta palabras al ranking: se lee unicamente para comparar,
 * y cada palabra informa en que porcentaje de sus llamadas la IA tambien la
 * dijo (`pct_ia`). Un porcentaje alto es un ECO: la palabra existe porque la IA
 * la pregunta, no porque el conductor la use, y no sirve como senal.
 *
 * El conteo es por LLAMADA que contiene la palabra (document frequency), nunca
 * por numero de apariciones: asi un conductor parlanchin no domina el ranking.
 *
 * Tres metricas se calculan en la misma pasada y se muestran en pestanas:
 *   - frecuencia: cuantas llamadas de cada grupo la contienen.
 *   - lift: proporcion en exitosas vs fallidas, su diferencia y la z de dos
 *     proporciones (lo que de verdad distingue un grupo del otro).
 *   - tf-idf: tf = peso de la palabra dentro del grupo, idf = ln(1 + 2G/df),
 *     que castiga las palabras presentes en ambos grupos.
 *
 * `max_gram` llega hasta 5 palabras: las frases del revisor (`confirma y da
 * motivos`, `ya descargo`) solo salen con ese margen.
 *
 * La nota del revisor tiene ademas su propia tabla (`codigos_revisor`), porque
 * es un vocabulario corto y controlado: mezclarla con las palabras habladas
 * barateria el ranking, ya que esas notas se escribieron sabiendo el desenlace
 * (`CONFIRMA` 882 de 885 exitosas; `NO HABLA` 0 de 1062).
 *
 * `sugerir_palabras()` cierra el analisis: se queda con lo significativo y sin
 * redundancias, y lo devuelve como diccionario listo para copiar.
 *
 * El aviso de buzon de voz llega a `mensajes` como `USER`. El criterio para
 * descartarlo es el campo `llamadas.buzon_de_voz`: lo fija quien escucha el
 * audio, marca 7 321 llamadas y NI-UNA es exitosa, mientras que el detector de
 * frases solo alcanza a reconocer 7 168 y ademas parte el aviso en pedazos que
 * parecen habla humana (`grabe su mensaje.`, `despues el tono`). Con
 * `buzon_de_voz = 1` se descarta todo el texto del conductor de ESA llamada; el
 * patron de frases queda como respaldo para las 9 llamadas no marcadas cuyo
 * mensaje si es de contestador. Por eso `excluir_buzon` viene activado por
 * defecto: sin filtrar, "buzon/mensaje/tono" salen como palabras tipicas de las
 * fallidas sin decir nada del conductor.
 */
class DBPalabrasLlamadas
{
    public static $filtro;

    // Palabras de funcion del espanol: se descartan para no tapar la señal.
    // "si" y "no" NO entran: son justamente el indicador de confirmacion.
    public static $stopwords = [
        'de', 'del', 'la', 'el', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas',
        'y', 'o', 'u', 'a', 'al', 'en', 'con', 'sin', 'por', 'para', 'que', 'se',
        'me', 'mi', 'mis', 'te', 'tu', 'tus', 'le', 'les', 'su', 'sus',
        'es', 'son', 'ser', 'fue', 'fueron', 'era', 'han', 'ha', 'hay', 'haya',
        'este', 'esta', 'esto', 'estos', 'estas', 'ese', 'esa', 'esos', 'esas',
        'muy', 'mas', 'menos', 'tan', 'tanto', 'como', 'pero', 'sino', 'donde',
        'cuando', 'cual', 'quien', 'nos', 'usted', 'ustedes', 'sobre', 'entre',
        'desde', 'hasta', 'según', 'cada', 'otro', 'otra', 'otros', 'otras',
        'mismo', 'misma', 'tiene', 'tienen', 'tengo', 'tenemos', 'puedo',
        'puede', 'pueden', 'voy', 'vas', 'va', 'vamos', 'van', 'ir', 'ido',
        'dar', 'dando', 'hacer', 'hecho', 'pues', 'ah', 'eh', 'jm', 'ajm',
    ];

    // Variantes de aviso de buzon que el detector general no alcanza: la
    // transcripcion automatica escribe "no sentimos, ha llegado al tiempo
    // limite de mensaje de voz..." sin decir nunca "buzon de voz". Van en
    // minusculas y sin acentos, como sale de normalizar().
    public static $patrones_buzon = [
        'tiempo limite de mensaje',
        'mensaje fue grabado',
        'grabado exitosamente',
        'mensaje de voz',
        'numero al que llama',
        'numero al que esta llamando',
        'te has comunicado con el buzon',
        'deja tu mensaje',
        'deje su mensaje',
        'despues del tono',
        'despues del no',
        'intente de nuevo',
        'intente mas tarde',
        'no esta disponible',
        'no se encuentra disponible',
        'casilla de voz',
        'voicemail',
    ];

    // Tamano del lote de llamadas cuyos mensajes se leen de una vez
    const LOTE_LLAMADAS = 400;

    const TOP_FILAS = 25;
    const MAX_EJEMPLOS = 40;

    //Tabla de codigos de analisis_audio
    const MIN_CODIGO = 5;
    const MAX_CODIGOS = 60;

    // Palabras que la IA tambien dice en casi todas sus llamadas: eco del guion
    const UMBRAL_ECO = 70;

    const MAX_SUGERENCIAS = 12;

    // Tope de filas del modal de auditoria de buzones sin marcar
    const MAX_SIN_MARCA = 300;

    // Etiquetas que el modal muestra para entender que puso el revisor en la
    // llamada. Los nombres salen de DBLlamadas, no se duplican aqui.
    private static $audita_etiquetas = [
        'conductor_confirma',
        'conductor_contesta_pero_no_habla',
        'conductor_no_escucha',
        'conductor_cuelga',
        'conductor_no_contesta',
        'contesta_otra_persona',
        'numero_equivocado',
        'confusion_en_llamada',
        'ia_no_escucha',
        'error_tecnico_llamada',
    ];

    public static $metricas = [
        'frecuencia' => 'Frecuencia',
        'lift' => 'Diferencia y lift',
        'tfidf' => 'TF-IDF',
    ];

    // Fuentes posibles: todas humanas. La IA quedo fuera como fuente de
    // palabras por decision del negocio: su guion se autocita.
    public static $fuentes = [
        'usuario' => 'Solo lo que dijo el conductor',
        'usuario_notas' => 'Conductor + notas del revisor',
        'notas' => 'Solo notas del revisor (analisis_audio)',
    ];

    public static $max_grams = [
        1 => 'Palabras sueltas',
        2 => 'Sueltas + parejas',
        3 => 'Sueltas, parejas y ternas',
        4 => 'Frases de hasta 4 palabras',
        5 => 'Frases de hasta 5 palabras',
    ];

    public static $universos = [
        'contestadas' => 'Solo donde el conductor entró a la llamada',
        'sin_error' => 'Solo contestadas y sin error de plataforma',
        'todas' => 'Todas las llamadas revisadas',
    ];

    public static $soportes = [5, 10, 20, 50, 100];

    public static function set_filtro($request): void
    {
        // `?? null` permite probar la clase con un objeto plano, no solo con
        // una Request
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio ?? null;
        self::$filtro->fecha_fin = $request->fecha_fin ?? null;
        self::$filtro->llamada_tipo_id = $request->llamada_tipo_id ?? null;
        self::$filtro->conductor = $request->conductor ?? null;
        self::$filtro->trt = $request->trt ?? null;
        self::$filtro->ruta_id = $request->ruta_id ?? null;
        self::$filtro->universo = $request->universo ?? null;
        self::$filtro->fuente = $request->fuente ?? null;
        self::$filtro->excluir_buzon = $request->excluir_buzon ?? null;
        self::$filtro->max_gram = $request->max_gram ?? null;
        self::$filtro->min_llamadas = $request->min_llamadas ?? null;
        self::$filtro->palabra = $request->palabra ?? null;
    }

    //------------------- filtros -------------------

    public static function universo(): string
    {
        $valor = self::$filtro->universo ?? '';

        return isset(self::$universos[$valor]) ? $valor : 'contestadas';
    }

    public static function fuente(): string
    {
        $valor = self::$filtro->fuente ?? '';

        return isset(self::$fuentes[$valor]) ? $valor : 'usuario';
    }

    /** Si la fuente del ranking lee los mensajes del conductor. */
    public static function leer_usuario(): bool
    {
        return self::fuente() !== 'notas';
    }

    /** Si la fuente del ranking lee la nota del revisor. */
    public static function leer_notas(): bool
    {
        return self::fuente() !== 'usuario';
    }

    public static function excluirBuzon(): bool
    {
        return (string) (self::$filtro->excluir_buzon ?? 1) !== '0';
    }

    /**
     * Hasta cuantas palabras por combinacion: 1 = solo sueltas, 2 = sueltas y
     * parejas, 3 = ademas ternas.
     */
    public static function max_gram(): int
    {
        return max(1, min(5, (int) (self::$filtro->max_gram ?? 2)));
    }

    /**
     * Llamadas minimas que deben contener una palabra para entrar al ranking.
     */
    public static function soporte_minimo(): int
    {
        return max(1, (int) (self::$filtro->min_llamadas ?? 20));
    }

    /**
     * Palabra a investigar (drill-down). Va normalizada como el texto.
     */
    public static function palabra_buscar(): string
    {
        return self::normalizar((string) (self::$filtro->palabra ?? ''));
    }

    /**
     * WHERE sobre `llamadas`. Las fechas van sobre l.created_at, igual que en
     * el resto de listados del sistema.
     */
    private static function filtro_llamadas(): array
    {
        $sql = ' AND l.llamada_exitosa IN (0, 1) ';

        $universo = self::universo();
        if ($universo !== 'todas') {
            $sql .= ' AND l.entro_llamada = 1 ';
        }
        if ($universo === 'sin_error') {
            $sql .= ' AND l.error_origen = 0 ';
        }

        $params = [];

        if (self::$filtro->fecha_inicio ?? '') {
            $sql .= ' AND l.created_at >= ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_inicio)->startOfDay();
        }

        if (self::$filtro->fecha_fin ?? '') {
            $sql .= ' AND l.created_at < ? ';
            $params[] = Carbon::parse(self::$filtro->fecha_fin)->addDay()->startOfDay();
        }

        $tipo = trim((string) (self::$filtro->llamada_tipo_id ?? ''));
        if ($tipo !== '') {
            $sql .= ' AND l.llamada_tipo_id = ? ';
            $params[] = (int) $tipo;
        }

        $conductor = trim((string) (self::$filtro->conductor ?? ''));
        if ($conductor !== '') {
            if (is_numeric($conductor)) {
                $sql .= ' AND l.conductor_id = ? ';
                $params[] = (int) $conductor;
            } else {
                $sql .= ' AND co.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($conductor) . '%';
            }
        }

        $trt = trim((string) (self::$filtro->trt ?? ''));
        if ($trt !== '') {
            if (is_numeric($trt)) {
                $sql .= ' AND l.trt_id = ? ';
                $params[] = (int) $trt;
            } else {
                $sql .= ' AND t.nombres LIKE ? ';
                $params[] = '%' . mb_strtoupper($trt) . '%';
            }
        }

        $ruta = trim((string) (self::$filtro->ruta_id ?? ''));
        if ($ruta !== '') {
            $sql .= ' AND r.ruta_id = ? ';
            $params[] = (int) $ruta;
        }

        return [$sql, $params];
    }

    //------------------- texto -------------------

    /**
     * Minusculas y sin acentos, para que "SEÑOR" y "senor" cuenten igual.
     */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    /**
     * Set [grama => true] de un texto ya normalizado, sin stopwords ni numeros.
     * Es un set para que cada palabra cuente una sola vez por llamada.
     */
    private static function gramas(string $texto_normalizado, int $max_gram): array
    {
        $partes = preg_split('/[^a-z0-9ñ]+/u', $texto_normalizado, -1, PREG_SPLIT_NO_EMPTY);

        $palabras = [];
        foreach ($partes ?: [] as $p) {
            if (strlen($p) < 3 or ctype_digit($p) or self::es_stopword($p)) {
                continue;
            }
            $palabras[] = $p;
        }

        $gramas = $palabras;

        for ($n = 2; $n <= $max_gram; $n++) {
            $ultima = count($palabras) - $n;
            for ($i = 0; $i <= $ultima; $i++) {
                $gramas[] = implode(' ', array_slice($palabras, $i, $n));
            }
        }

        return array_fill_keys(array_unique($gramas), true);
    }

    /**
     * Listas de palabras sin repetirse. Se arma una sola vez por request.
     */
    private static function es_stopword(string $palabra): bool
    {
        static $set = null;

        if ($set === null) {
            $set = array_flip(self::$stopwords);
        }

        return isset($set[$palabra]);
    }

    /**
     * Llamadas del filtro, sin los textos: se leen despues por lotes para no
     * sostener toda la transcripcion en memoria.
     */
    private static function llamadas_filtro(): array
    {
        [$where, $params] = self::filtro_llamadas();

        return DB::select("
        SELECT
            l.vapi_id,
            l.llamada_exitosa,
            l.audio_duracion,
            l.analisis_audio,
            l.buzon_de_voz,
            l.created_at,
            l.conductor_id,
            COALESCE(co.nombres, 'SIN CONDUCTOR') AS conductor,
            COALESCE(t.nombres, 'SIN TRT') AS trt,
            l.origen,
            l.destino,
            l.audio_link
        FROM llamadas l
        LEFT JOIN conductores co ON co.id = l.conductor_id
        LEFT JOIN trts t ON t.id = l.trt_id
        LEFT JOIN referencias r ON r.ref = l.ref
        WHERE 1 = 1 " . $where . "
        ORDER BY l.created_at DESC", $params);
    }

    /**
     * Mensajes de un lote de llamadas, en el orden de la conversacion.
     */
    private static function mensajes_de(array $vapi_ids): array
    {
        if (!$vapi_ids) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($vapi_ids), '?'));

        return DB::select("
        SELECT m.vapi_id, m.tipo, m.mensaje
        FROM mensajes m
        WHERE m.vapi_id IN ($marcas)
        ORDER BY m.vapi_id, m.orden", $vapi_ids);
    }

    /**
     * Que frase de contestador hay en el texto ya normalizado, o cadena vacia.
     *
     * Es el RESPALDO del campo `llamadas.buzon_de_voz`, que es el criterio
     * principal: sirve para los mensajes de contestador que aparecen en llamadas
     * que el revisor no marco como buzon.
     *
     * Devuelve la frase encontrada, no un booleano, para que la auditoria pueda
     * decir por que sobro esa llamada.
     *
     * Se apoya en TextAnalyzer y ademas mira frases concretas: el detector
     * general puntua las palabras sueltas y varias variantes reales de la
     * transcripcion automatica no llegan al umbral.
     */
    private static function patron_buzon(string $texto): string
    {
        if (strlen($texto) < 25) {
            return '';
        }

        foreach (self::$patrones_buzon as $patron) {
            if (mb_strpos($texto, $patron) !== false) {
                return $patron;
            }
        }

        // TextAnalyzer espera el texto en mayusculas
        return TextAnalyzer::esBuzonDeVoz(mb_strtoupper($texto, 'UTF-8')) ? 'TextAnalyzer' : '';
    }

    //------------------- analisis -------------------

    /**
     * Pasada completa sobre las llamadas del filtro. Devuelve los totales, la
     * lista de palabras con sus metricas y, si se pidio una palabra, la
     * evidencia de llamadas que la contienen.
     */
    public static function analizar(): stdClass
    {
        $ini = microtime(true);

        $llamadas = self::llamadas_filtro();
        $llamadas_e = 0;
        $llamadas_f = 0;
        foreach ($llamadas as $l) {
            if ($l->llamada_exitosa) $llamadas_e++;
            else $llamadas_f++;
        }

        $acumulado = self::contar($llamadas);

        // llamadas del grupo que aportaron al menos una palabra usable
        $tot_e = max(1, $acumulado['con_texto']['e']);
        $tot_f = max(1, $acumulado['con_texto']['f']);

        $medidas = self::finalizar($acumulado['palabras'], $tot_e, $tot_f);

        $resultado = new stdClass();
        $resultado->llamadas = count($llamadas);
        $resultado->llamadas_e = $llamadas_e;
        $resultado->llamadas_f = $llamadas_f;
        $resultado->con_texto_e = $acumulado['con_texto']['e'];
        $resultado->con_texto_f = $acumulado['con_texto']['f'];
        $resultado->sin_texto = $acumulado['sin_texto'];
        $resultado->sin_usable = $acumulado['sin_usable'];
        $resultado->con_nota = $acumulado['con_nota'];
        $resultado->mensajes_buzon = $acumulado['buzones_saltados'];
        $resultado->mensajes_buzon_ia = $acumulado['buzones_ia'];
        $resultado->mensajes_buzon_campo = $acumulado['buzon_campo'];
        $resultado->llamadas_buzon_campo = $acumulado['buzon_campo_llamadas'];
        $resultado->palabras = $medidas;
        $resultado->palabras_totales = count($acumulado['palabras']);
        $resultado->ejemplos = $acumulado['ejemplos'];
        $resultado->max_ejemplos = self::MAX_EJEMPLOS;
        $resultado->top_filas = self::TOP_FILAS;
        $resultado->totales_e = $tot_e;
        $resultado->totales_f = $tot_f;
        $resultado->segundos = round(microtime(true) - $ini, 2);
        $resultado->fuente = self::fuente();
        $resultado->fuente_texto = self::$fuentes[self::fuente()];
        $resultado->universo = self::universo();
        $resultado->max_gram = self::max_gram();
        $resultado->soporte = self::soporte_minimo();
        $resultado->excluir_buzon = self::excluirBuzon();
        $resultado->palabra_filtrada = self::palabra_buscar();
        $resultado->umbral_eco = self::UMBRAL_ECO;

        // las sugerencias y los codigos del revisor salen del mismo filtro
        $resultado->sugerencias = self::sugerir_palabras($medidas);
        $resultado->codigos = self::codigos_revisor(count($llamadas));

        // las llamadas que parecen buzon sin estar marcadas se colectaron durante
        // el recorrido de los mensajes: no cuesta otra pasada por `mensajes`
        $resultado->buzones = self::buzones_sin_marca(
            $acumulado['sin_marca'], $acumulado['sin_marca_llamadas']);

        return $resultado;
    }

    /**
     * Lee los mensajes por lotes, arma el set de palabras humanas de cada
     * llamada y lo acumula una sola vez por palabra.
     *
     * Los mensajes del `BOT` se tokenizan igual, pero solo aterrizan en
     * `$set_b` para poder comparar; nunca meten palabras en `$base`.
     *
     * El aviso de buzon se descarta por el campo `buzon_de_voz` de la llamada (y
     * el patron de frases como respaldo), nunca al reves: el campo lo fija quien
     * oyo el audio.
     */
    private static function contar(array $llamadas): array
    {
        $leer_usuario = self::leer_usuario();
        $leer_notas = self::leer_notas();
        $max_gram = self::max_gram();
        $buscar = self::palabra_buscar();

        $palabras = [];
        $ejemplos = [];
        $con_texto = ['e' => 0, 'f' => 0];
        $con_algo = 0;
        $con_nota = 0;
        $buzones_saltados = 0;
        $buzones_ia = 0;
        $buzon_campo = 0;
        $buzon_campo_llamadas = 0;
        $sin_marca = [];
        $sin_marca_vistas = [];

        foreach (array_chunk($llamadas, self::LOTE_LLAMADAS) as $chunk) {
            $mensajes = self::mensajes_de(array_column($chunk, 'vapi_id'));

            $agrupados = [];
            foreach ($mensajes as $m) {
                $agrupados[$m->vapi_id][] = $m;
            }
            unset($mensajes);

            foreach ($chunk as $l) {
                $fila = $agrupados[$l->vapi_id] ?? [];

                // el campo manda: si el revisor marco la llamada como buzon, todo
                // lo que dice el lado "humano" de esa grabacion es la contestadora.
                // && y no `and`: con `and` la asignacion queda solo con el booleano
                $llamada_buzon = self::excluirBuzon()
                    && (int) ($l->buzon_de_voz ?? 0) === 1;

                if ($llamada_buzon) $buzon_campo_llamadas++;

                $base = [];
                $set_u = [];
                $set_b = [];
                $fragmentos = [];
                $nota = [];

                if ($fila) $con_algo++;

                foreach ($fila as $m) {
                    $es_usuario = $m->tipo === 'USER';
                    $texto = self::normalizar((string) $m->mensaje);

                    if ($texto === '') continue;

                    $patron = self::patron_buzon($texto);

                    // auditoria: el aviso lo dijo el lado "humano" y la casilla de
                    // buzon de esa llamada sigue en 0. Se registra con el toggle
                    // apagado tambien, porque entonces ese texto esta entrando al
                    // ranking y el aviso es mas fuerte
                    if ($es_usuario and $patron !== ''
                        and (int) ($l->buzon_de_voz ?? 0) !== 1) {
                        $sin_marca_vistas[$l->vapi_id] = true;

                        if (count($sin_marca) < self::MAX_SIN_MARCA
                            and !isset($sin_marca[$l->vapi_id])) {
                            $sin_marca[$l->vapi_id] = (object) [
                                'vapi_id' => $l->vapi_id,
                                'patron' => $patron,
                                'aviso' => mb_substr($texto, 0, 200),
                            ];
                        }
                    }

                    // el campo excluye solo el texto del conductor: el guion de la
                    // IA de esa llamada sigue entrando para la comparacion
                    $motivo_buzon = ($es_usuario && $llamada_buzon) ? 'campo'
                        : (($patron !== '' && self::excluirBuzon()) ? 'patron' : '');

                    // la evidencia se recoge antes de descartar el mensaje: si se
                    // investiga "buzon", hay que poder senalar donde aparecio
                    if ($buscar !== '' and count($fragmentos) < 3
                        and mb_strpos($texto, $buscar) !== false) {
                        $fragmentos[] = ($es_usuario ? 'conductor: ' : 'IA: ')
                            . ($motivo_buzon === 'campo' ? '[buzon de voz, no contado] '
                                : ($motivo_buzon === 'patron' ? '[aviso de buzon, no contado] ' : ''))
                            . mb_substr($texto, 0, 160);
                    }

                    if ($motivo_buzon !== '') {
                        if ($motivo_buzon === 'campo') $buzon_campo++;
                        else {
                            $buzones_saltados++;

                            // lo que cae del canal IA no mueve el ranking: la IA no
                            // es fuente, solo alimenta la columna de comparacion
                            if (!$es_usuario) $buzones_ia++;
                        }

                        continue;
                    }

                    foreach (self::gramas($texto, $max_gram) as $grama => $true) {
                        if ($es_usuario) {
                            $set_u[$grama] = true;
                            if ($leer_usuario) $base[$grama] = true;
                        } else {
                            // de la IA solo se compara, no se toman palabras
                            $set_b[$grama] = true;
                        }
                    }
                }

                // la nota la escribe el revisor: es texto humano y entra como
                // fuente, pero queda contada aparte para saber de donde salio. En
                // la evidencia no se repite: la vista muestra el campo entero
                if ($leer_notas) {
                    $texto_nota = self::normalizar(trim((string) ($l->analisis_audio ?? '')));

                    if ($texto_nota !== '') {
                        if (!$fila) $con_algo++;
                        $con_nota++;

                        $nota = self::gramas($texto_nota, $max_gram);
                        $base = $base + $nota;
                    }
                }

                // la evidencia va antes del filtro por `$base` vacia: una llamada de
                // buzon no aporta palabras, y es justamente la que hay que poder ver
                // cuando se investiga como aparece el aviso
                if ($buscar !== '' and $fragmentos and count($ejemplos) < self::MAX_EJEMPLOS) {
                    $ejemplos[] = self::ejemplo($l, $fragmentos);
                }

                // cada palabra cuenta una sola vez por llamada y por grupo
                if (!$base) continue;

                $lado = $l->llamada_exitosa ? 'e' : 'f';
                $con_texto[$lado]++;

                foreach ($base as $grama => $true) {
                    if (!isset($palabras[$grama])) {
                        $palabras[$grama] = ['e' => 0, 'f' => 0, 'u' => 0, 'n' => 0, 'b' => 0];
                    }

                    $palabras[$grama][$lado]++;

                    if (isset($set_u[$grama])) $palabras[$grama]['u']++;
                    if (isset($nota[$grama])) $palabras[$grama]['n']++;
                    if (isset($set_b[$grama])) $palabras[$grama]['b']++;
                }
            }

            unset($agrupados);
        }

        // llamadas que no tienen ni un mensaje ni una nota
        $sin_texto = count($llamadas) - $con_algo;

        // las que si tienen texto pero se quedaron sin palabras usable
        // (todo el texto era aviso de buzon o solo tenia stopwords)
        $sin_usable = $con_algo - $con_texto['e'] - $con_texto['f'];

        return [
            'palabras' => $palabras,
            'ejemplos' => $ejemplos,
            'con_texto' => $con_texto,
            'sin_texto' => $sin_texto,
            'sin_usable' => $sin_usable,
            'con_nota' => $con_nota,
            'buzones_saltados' => $buzones_saltados,
            'buzones_ia' => $buzones_ia,
            'buzon_campo' => $buzon_campo,
            'buzon_campo_llamadas' => $buzon_campo_llamadas,
            'sin_marca' => array_values($sin_marca),
            'sin_marca_llamadas' => count($sin_marca_vistas),
        ];
    }

    private static function ejemplo(stdClass $llamada, array $fragmentos): stdClass
    {
        $item = clone $llamada;
        $item->fragmentos = $fragmentos;

        return $item;
    }

    /**
     * Convierte los conteos crudos en las metricas de las tres pestanas y deja
     * fuera lo que no alcanza el soporte minimo.
     *
     * `$tot_e`/`$tot_f` son las llamadas con texto de cada grupo (el divisor de
     * las proporciones) y `$suma_e`/`$suma_f` la suma de conteos del grupo, que
     * es el divisor del termino tf del tf-idf.
     *
     * El `idf` es compartido por ambos grupos, asi que la columna tfidf_e
     * supera a tfidf_f solo cuando la palabra pesa mas en ese grupo: las
     * palabras presentes en los dos lados caen por idf bajo.
     */
    private static function finalizar(array $palabras, int $tot_e, int $tot_f): array
    {
        $suma_e = 0;
        $suma_f = 0;
        foreach ($palabras as $p) {
            $suma_e += $p['e'];
            $suma_f += $p['f'];
        }

        // 2 grupos: el idf clasico castiga las palabras que estan en muchas
        // llamadas de ambos lados
        $soporte = self::soporte_minimo();
        $lista = [];
        $n_corpus = max(1, $suma_e + $suma_f);

        foreach ($palabras as $palabra => $p) {
            $total = $p['e'] + $p['f'];
            if ($total < $soporte) {
                continue;
            }

            $share_e = $p['e'] / $tot_e;
            $share_f = $p['f'] / $tot_f;
            $diff = ($share_e - $share_f) * 100;

            if ($share_f > 0) {
                $lift = $share_e > 0 ? $share_e / $share_f : null;
            } else {
                $lift = $share_e > 0 ? INF : null;
            }

            $z = self::z_dos_proporciones($p['e'], $tot_e, $p['f'], $tot_f);

            // idf compartido por los dos grupos: lo que separa una columna de
            // la otra es el peso de la palabra dentro del grupo (tf)
            $idf = log(1 + $n_corpus / max(1, $total));

            $lista[] = (object) [
                'palabra' => $palabra,
                'gram' => substr_count($palabra, ' ') + 1,
                'e' => $p['e'],
                'f' => $p['f'],
                'total' => $total,
                'share_e' => $share_e * 100,
                'share_f' => $share_f * 100,
                'diff' => $diff,
                'lift' => $lift,
                'z' => $z,
                'sig' => self::estrellas($z),
                'idf' => $idf,
                'tfidf_e' => $suma_e ? ($p['e'] / $suma_e) * $idf : 0,
                'tfidf_f' => $suma_f ? ($p['f'] / $suma_f) * $idf : 0,
                'origen' => self::origen_palabra($p),
                'pct_conductor' => $total ? (int) round($p['u'] * 100 / $total) : 0,
                'pct_nota' => $total ? (int) round($p['n'] * 100 / $total) : 0,
                'pct_ia' => $total ? (int) round($p['b'] * 100 / $total) : 0,
                'eco' => $total and $p['b'] * 100 / $total >= self::UMBRAL_ECO,
            ];
        }

        return $lista;
    }

    /**
     * De donde salio la palabra: solo puede ser texto humano (conductor, nota
     * del revisor, o ambos). La IA ya no es fuente, solo comparacion.
     */
    private static function origen_palabra(array $p): string
    {
        if ($p['u'] and $p['n']) return 'conductor y nota';
        if ($p['n']) return 'nota del revisor';
        if ($p['u']) return 'conductor';

        return '—';
    }

    /**
     * Etiqueta corta del eco: en cuantas de sus llamadas la dijo tambien la IA.
     */
    public static function eco_texto($pct_ia): string
    {
        if ($pct_ia >= self::UMBRAL_ECO) return 'eco de la IA';
        if ($pct_ia >= 40) return 'la IA tambien la dice';
        if ($pct_ia > 0) return 'casi solo del humano';

        return 'solo humano';
    }

    /**
     * z de dos proporciones: compara la proporcion de llamadas de cada grupo
     * que contienen la palabra.
     */
    private static function z_dos_proporciones(int $x1, int $n1, int $x2, int $n2): ?float
    {
        if ($n1 < 1 or $n2 < 1) {
            return null;
        }

        $p = ($x1 + $x2) / ($n1 + $n2);
        if ($p <= 0 or $p >= 1) {
            return null;
        }

        $error = sqrt($p * (1 - $p) * ((1 / $n1) + (1 / $n2)));
        if ($error <= 0) {
            return null;
        }

        return (($x1 / $n1) - ($x2 / $n2)) / $error;
    }

    private static function estrellas(?float $z): string
    {
        if (is_null($z)) return '';

        $abs = abs($z);
        if ($abs >= 3.291) return '***';
        if ($abs >= 2.576) return '**';
        if ($abs >= 1.960) return '*';

        return '';
    }

    //------------------- clasificacion escrita por el revisor -------------------

    /**
     * Tabla de los codigos que el revisor escribe en `llamadas.analisis_audio`.
     *
     * No se mezclan con las palabras habladas: son vocabulario controlado y se
     * asignan sabiendo el resultado, asi que su separacion es casi perfecta por
     * construccion. Su valor esta en ver CUALES etiquetas pesan mas y en que
     * tasa de exito real tienen, no en descubrir lenguaje nuevo.
     */
    public static function codigos_revisor(int $llamadas_filtro = 0): stdClass
    {
        [$where, $params] = self::filtro_llamadas();

        $joins = '
        FROM llamadas l
        LEFT JOIN conductores co ON co.id = l.conductor_id
        LEFT JOIN trts t ON t.id = l.trt_id
        LEFT JOIN referencias r ON r.ref = l.ref
        WHERE 1 = 1 ';

        $resumen = DB::select("SELECT COUNT(*) AS n, SUM(l.llamada_exitosa = 1) AS e"
            . $joins . $where . " AND TRIM(l.analisis_audio) <> ''", $params);

        $tot_n = (int) ($resumen[0]->n ?? 0);
        $tot_e = (int) ($resumen[0]->e ?? 0);

        $filas = $tot_n ? DB::select("
        SELECT
            TRIM(l.analisis_audio) AS codigo,
            COUNT(*) AS n,
            SUM(l.llamada_exitosa = 1) AS e
        " . $joins . $where . "
            AND TRIM(l.analisis_audio) <> ''
            AND CHAR_LENGTH(TRIM(l.analisis_audio)) <= 60
        GROUP BY TRIM(l.analisis_audio)
        HAVING COUNT(*) >= ?
        ORDER BY COUNT(*) DESC
        LIMIT " . self::MAX_CODIGOS, array_merge($params, [self::MIN_CODIGO])) : [];

        $base = $tot_n ? $tot_e * 100 / $tot_n : 0;

        $lista = [];
        $vistos = 0;
        foreach ($filas as $f) {
            $n = (int) $f->n;
            $e = (int) $f->e;
            $pct = $n ? $e * 100 / $n : 0;

            $z = self::z_dos_proporciones($e, $n, $tot_e - $e, max(0, $tot_n - $n));

            $lista[] = (object) [
                'codigo' => $f->codigo,
                'buscar' => self::normalizar(trim((string) $f->codigo)),
                'gram' => str_word_count(self::normalizar(trim((string) $f->codigo))),
                'n' => $n,
                'e' => $e,
                'f' => $n - $e,
                'pct_e' => $pct,
                'diff' => $pct - $base,
                'z' => $z,
                'sig' => self::estrellas($z),
            ];

            $vistos += $n;
        }

        $out = new stdClass();
        $out->lista = $lista;
        $out->llamadas = $tot_n;
        $out->exitosas = $tot_e;
        $out->base = $base;
        $out->min = self::MIN_CODIGO;
        $out->ocultos = max(0, $tot_n - $vistos);
        $out->cobertura = $llamadas_filtro ? $tot_n * 100 / $llamadas_filtro : 0;

        return $out;
    }

    //------------------- auditoria de buzones sin marcar -------------------

    /**
     * Llamadas que parecen un buzon de voz pero tienen `buzon_de_voz = 0`.
     *
     * Dos origenes posibles, y una llamada puede tener ambos: `texto`, cuando el
     * mensaje del conductor es una frase de contestador (sale del mismo recorrido
     * de `contar()`, asi que nunca puede discrepar del numero de la tarjeta), y
     * `nota`, cuando el revisor escribio BUZON en `analisis_audio` o en
     * `analisis_transcripcion` y dejo la casilla en 0.
     *
     * No re-etiquetan nada ni tocan el ranking: son candidatos para que alguien
     * escuche el audio. `buzon_de_voz = 1` si es buzon y manda.
     */
    public static function buzones_sin_marca(array $del_texto, int $llamadas_vistas): stdClass
    {
        $avisos = [];
        foreach ($del_texto as $d) {
            $avisos[$d->vapi_id] = $d;
        }

        $de_nota = self::notas_buzon_sin_marca();

        $motivos = [];
        foreach (array_keys($avisos) as $id) {
            $motivos[$id] = 'texto';
        }
        foreach ($de_nota as $id) {
            $motivos[$id] = isset($motivos[$id]) ? 'texto y nota' : 'nota';
        }

        $out = new stdClass();
        $out->por_texto = count($avisos);
        $out->llamadas_vistas = $llamadas_vistas;
        $out->por_nota = count($de_nota);
        $out->tope = self::MAX_SIN_MARCA;
        $out->lista = [];
        $out->total = count($motivos);

        if (!$motivos) {
            return $out;
        }

        // las filas se hidratan de una sola vez: la lista corta cabe en un IN
        $ids = array_keys($motivos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $filas = DB::select("
        SELECT l.*, COALESCE(co.nombres, 'SIN CONDUCTOR') AS conductor,
               COALESCE(t.nombres, 'SIN TRT') AS trt
        FROM llamadas l
        LEFT JOIN conductores co ON co.id = l.conductor_id
        LEFT JOIN trts t ON t.id = l.trt_id
        WHERE l.vapi_id IN ($marcas)
        ORDER BY l.created_at DESC", $ids);

        foreach ($filas as $f) {
            $f->motivo = $motivos[$f->vapi_id];
            $f->patron = $avisos[$f->vapi_id]->patron ?? '';
            $f->aviso = $avisos[$f->vapi_id]->aviso ?? '';
            $f->etiquetas = self::etiquetas_activas($f);

            $out->lista[] = $f;
        }

        return $out;
    }

    /**
     * Llamadas sin la casilla de buzon cuya nota del revisor si lo nombra.
     *
     * El sufijo `- NO` de `BUZON DE VOZ - NO` no niega el buzon: 954 llamadas con
     * ese texto si estan marcadas y solo 4 no, asi que aqui solo se senala la
     * contradiccion para que la vea un humano.
     */
    private static function notas_buzon_sin_marca(): array
    {
        [$where, $params] = self::filtro_llamadas();

        $filas = DB::select("
        SELECT l.vapi_id
        FROM llamadas l
        LEFT JOIN conductores co ON co.id = l.conductor_id
        LEFT JOIN trts t ON t.id = l.trt_id
        LEFT JOIN referencias r ON r.ref = l.ref
        WHERE 1 = 1 " . $where . "
            AND l.buzon_de_voz = 0
            AND (l.analisis_audio LIKE '%BUZON%' OR l.analisis_transcripcion LIKE '%BUZON%')
        ORDER BY l.created_at DESC
        LIMIT " . self::MAX_SIN_MARCA, $params);

        return array_column($filas, 'vapi_id');
    }

    /** Etiquetas de la llamada que valen 1, con el nombre del sistema. */
    private static function etiquetas_activas(stdClass $f): array
    {
        $activas = [];

        foreach (self::$audita_etiquetas as $campo) {
            if ((int) ($f->$campo ?? 0) === 1) {
                $activas[] = DBLlamadas::$etiquetas_icon_bi[$campo][1] ?? $campo;
            }
        }

        return $activas;
    }

    //------------------- sugerencias -------------------

    /**
     * Sugerencias de diccionario a partir del ranking ya filtrado.
     *
     * Solo entran palabras con diferencia significativa (asterisco) y al menos
     * 3 puntos porcentuales de separacion, y se descarta todo ECO: lo que la IA
     * repite en mas del 70% de esas llamadas es su propio guion, no señal del
     * conductor.
     *
     * `sin_redundancia()` recorta la familia: si `va a cargar` y `cargar` casi
     * estan en las mismas llamadas, solo se sugiere la primera vez que aparece
     * ordenada por fuerza.
     */
    public static function sugerir_palabras(array $lista): stdClass
    {
        $firmes = array_values(array_filter($lista,
            fn($p) => $p->sig !== '' && abs($p->diff) >= 3 && !$p->eco));

        $exito = array_values(array_filter($firmes, fn($p) => $p->diff > 0));
        usort($exito, fn($a, $b) => $b->z <=> $a->z);

        $falla = array_values(array_filter($firmes, fn($p) => $p->diff < 0));
        usort($falla, fn($a, $b) => $a->z <=> $b->z);

        $exito = self::sin_redundancia($exito, self::MAX_SUGERENCIAS);
        $falla = self::sin_redundancia($falla, self::MAX_SUGERENCIAS);

        $frases = array_values(array_filter($firmes, fn($p) => $p->gram >= 2));
        usort($frases, fn($a, $b) => abs($b->diff) <=> abs($a->diff));

        $ecos = array_values(array_filter($lista,
            fn($p) => $p->eco && $p->total >= self::soporte_minimo()));
        usort($ecos, fn($a, $b) => $b->total <=> $a->total);

        $out = new stdClass();
        $out->exito = $exito;
        $out->falla = $falla;
        $out->frases = array_slice($frases, 0, 10);
        $out->ecos = array_slice($ecos, 0, 10);
        $out->diccionario = implode(', ', array_unique(array_merge(
            array_column($exito, 'palabra'),
            array_column($falla, 'palabra')
        )));
        $out->umbral = self::UMBRAL_ECO;
        $out->totales = count($lista);

        return $out;
    }

    /**
     * Quita las palabras que no aportan nada frente a otra ya aceptada: las
     * que estan dentro de esa y en casi las mismas llamadas.
     */
    private static function sin_redundancia(array $lista, int $limite): array
    {
        $keeps = [];

        foreach (array_slice($lista, 0, 150) as $p) {
            $redundante = false;

            foreach ($keeps as $k) {
                if ($k->palabra === $p->palabra) continue;

                if (mb_strpos($k->palabra, $p->palabra) !== false
                    and abs($k->total - $p->total) <= 0.2 * max(1, $p->total)) {
                    $redundante = true;
                    break;
                }
            }

            if (!$redundante) $keeps[] = $p;

            if (count($keeps) >= $limite) break;
        }

        return $keeps;
    }

    //------------------- rankings de cada pestana -------------------

    /**
     * Top de frecuencia dentro de cada grupo: las palabras que mas aparecen
     * en exitosas y las que mas en fallidas.
     */
    public static function top_frecuencia(array $palabras, int $limite = self::TOP_FILAS): array
    {
        $por_e = $palabras;
        usort($por_e, fn($a, $b) => $b->e <=> $a->e);

        $por_f = $palabras;
        usort($por_f, fn($a, $b) => $b->f <=> $a->f);

        return [
            'exitosas' => array_slice($por_e, 0, $limite),
            'fallidas' => array_slice($por_f, 0, $limite),
        ];
    }

    /**
     * Top por diferencia de proporciones: lo que distingue un grupo del otro,
     * con su lift y su significancia.
     */
    public static function top_lift(array $palabras, int $limite = self::TOP_FILAS): array
    {
        $lista = $palabras;
        usort($lista, fn($a, $b) => abs($b->diff) <=> abs($a->diff));

        return array_slice($lista, 0, $limite);
    }

    /**
     * Top por tf-idf de cada grupo: palabras típicas del grupo que no son
     * típicas del otro.
     */
    public static function top_tfidf(array $palabras, int $limite = self::TOP_FILAS): array
    {
        $por_e = $palabras;
        usort($por_e, fn($a, $b) => $b->tfidf_e <=> $a->tfidf_e);

        $por_f = $palabras;
        usort($por_f, fn($a, $b) => $b->tfidf_f <=> $a->tfidf_f);

        return [
            'exitosas' => array_slice($por_e, 0, $limite),
            'fallidas' => array_slice($por_f, 0, $limite),
        ];
    }

    //------------------- listas de apoyo -------------------

    public static function tipos_lista(): array
    {
        return DB::select('SELECT id, nombre FROM tipos_llamada ORDER BY id');
    }

    //------------------- presentacion -------------------

    public static function porcentaje($valor, int $decimales = 1): string
    {
        if ($valor === null) return '—';

        return number_format((float) $valor, $decimales, '.', ',') . '%';
    }

    public static function lift_texto($lift): string
    {
        if ($lift === null) return '—';
        if ($lift === INF) return 'solo en exitosas';
        if ($lift === 0) return '0×';

        if ($lift > 1) return number_format($lift, 1, '.', ',') . '× más';

        return number_format($lift, 1, '.', ',') . '×';
    }

    /**
     * Color bootstrap segun el lado que domina la palabra.
     */
    public static function color_lado($diff): string
    {
        if ($diff > 0) return 'success';
        if ($diff < 0) return 'danger';

        return 'secondary';
    }

    /**
     * Porcentaje de ancho de la barra de una tabla respecto a su propio maximo.
     */
    public static function barra($valor, $maximo): int
    {
        if (!$maximo or $valor <= 0) return 0;

        return (int) max(1, round(($valor / $maximo) * 100));
    }
}
