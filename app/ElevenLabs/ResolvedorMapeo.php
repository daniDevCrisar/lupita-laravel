<?php

namespace App\ElevenLabs;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve el JSON de detalle de una llamada contra las equivalencias guardadas.
 *
 * Es el motor que usara el traductor de la Etapa 4b, y hoy lo ejercita el boton
 * "Probar" de la pantalla: por cada columna de `tmp_lotes_det` dice que valor se
 * escribiria y de donde salio. Asi se valida el mapeo SIN importar nada.
 *
 * Orden de resolucion (ver `z-eleven-labs/especificacion_mapeo_traduccion.md`):
 *   1. `calculado`  -> no aplica (lo resuelve el codigo)
 *   2. `constante`  -> valor fijo
 *   3. `campo`      -> ruta + transformacion, o valor por defecto si no existe
 *   4. `regla`      -> primera regla que cumple (por prioridad), o el por defecto
 */
class ResolvedorMapeo
{
    public const ESTADO_CAMPO = 'campo';
    public const ESTADO_CONSTANTE = 'constante';
    public const ESTADO_DEFECTO = 'defecto';
    public const ESTADO_REGLA = 'regla';
    public const ESTADO_CALCULADO = 'calculado';
    public const ESTADO_SIN_REGLA = 'sin_regla';

    public function __construct(
        private readonly MapeoTraduccion $mapeo,
        private readonly AgentesElevenLabs $agentes,
        private readonly MensajesTranscripcion $mensajes,
        private readonly AnalizadorTranscripcion $analizador,
    ) {
    }

    /**
     * @return array<string, array{valor:string, estado:string, nota:string}>
     */
    public function resolver(array $detalle): array
    {
        $contexto = ['anio' => FechaTexto::anioDeLaLlamada($detalle)];
        $campos = $this->mapeo->campos();
        $calculados = $this->calculados($detalle);
        $salida = [];
        $porRegla = [];

        // ---- 1 y 2: calculadas, constantes y lecturas directas
        foreach ($campos as $campo) {
            $destino = $campo->campo_destino;

            if ($campo->tipo_origen === 'calculado') {
                $salida[$destino] = [
                    'valor' => (string) ($calculados[$destino]['valor'] ?? ''),
                    'estado' => self::ESTADO_CALCULADO,
                    'nota' => (string) ($calculados[$destino]['nota'] ?? $campo->descripcion),
                ];

                continue;
            }

            if ($campo->tipo_origen === 'regla') {
                // Se llenan en el paso 3; quedan marcadas para saber cuales faltaron.
                $porRegla[$destino] = true;
                $salida[$destino] = [
                    'valor' => (string) ($campo->valor_por_defecto ?? ''),
                    'estado' => self::ESTADO_SIN_REGLA,
                    'nota' => (string) $campo->descripcion,
                ];

                continue;
            }

            if (! $campo->activo) {
                $salida[$destino] = [
                    'valor' => '',
                    'estado' => self::ESTADO_DEFECTO,
                    'nota' => 'fila desactivada',
                ];

                continue;
            }

            if ($campo->tipo_origen === 'constante') {
                $salida[$destino] = [
                    'valor' => (string) ($campo->valor_constante ?? ''),
                    'estado' => self::ESTADO_CONSTANTE,
                    'nota' => 'valor fijo',
                ];

                continue;
            }

            // tipo_origen = 'campo': lista de rutas CANDIDATAS, en orden.
            // La primera que trae un valor (despues de transformar) gana; si una no
            // existe, viene vacia o no se puede interpretar, se pasa a la siguiente.
            $candidatas = $this->candidatas((string) ($campo->ruta_origen ?? ''));
            $defecto = (string) ($campo->valor_por_defecto ?? '');

            if ($candidatas === []) {
                $salida[$destino] = [
                    'valor' => $defecto,
                    'estado' => self::ESTADO_DEFECTO,
                    'nota' => 'sin ruta configurada',
                ];

                continue;
            }

            $resuelto = null;
            $intentadas = [];

            foreach ($candidatas as $indice => $ruta) {
                $lectura = $this->leerRuta($detalle, $ruta);

                if (! $lectura['existe']) {
                    $intentadas[] = 'no existe';

                    continue;
                }

                if ($lectura['valor'] === null || $lectura['valor'] === '') {
                    $intentadas[] = 'vacía';

                    continue;
                }

                $valor = $this->transformar($lectura['valor'], (string) $campo->transformacion, $contexto);

                // Si la transformacion no logra un valor (p. ej. una fecha en texto
                // que no se entiende), se sigue con la siguiente candidata.
                if ($valor === '') {
                    $intentadas[] = 'sin valor util';

                    continue;
                }

                $resuelto = [
                    'valor' => $valor,
                    'estado' => self::ESTADO_CAMPO,
                    'nota' => ($indice > 0 ? '#' . ($indice + 1) . ' ' : '') . $ruta
                        // Se deja rastro de las que se probaron antes y no sirvieron
                        . ($intentadas !== [] ? ' (antes: ' . implode(', ', $intentadas) . ')' : ''),
                ];

                break;
            }

            $salida[$destino] = $resuelto ?? [
                'valor' => $defecto,
                'estado' => self::ESTADO_DEFECTO,
                'nota' => 'ninguna de las ' . count($candidatas) . ' rutas sirvió ('
                    . implode(', ', $intentadas) . ')',
            ];
        }

        // ---- 3: reglas, por prioridad; para la misma columna gana la primera
        $yaResuelto = [];

        foreach ($this->mapeo->reglas() as $regla) {
            $destino = $regla->campo_destino;

            if (! $regla->activo || ! isset($porRegla[$destino]) || isset($yaResuelto[$destino])) {
                continue;
            }

            $lectura = $this->leerRuta($detalle, (string) $regla->ruta_origen);

            if (! $this->cumple($lectura['valor'], (string) $regla->comparacion, $regla->valor_origen)) {
                continue;
            }

            $salida[$destino] = [
                'valor' => (string) $regla->valor_destino,
                'estado' => self::ESTADO_REGLA,
                'nota' => $regla->ruta_origen . ' ' . $regla->comparacion
                    . ($regla->valor_origen !== null ? ' "' . $regla->valor_origen . '"' : ''),
            ];
            $yaResuelto[$destino] = true;
        }

        return $salida;
    }

    /**
     * Valor de las columnas que no salen de una ruta: las que el codigo calcula.
     *
     * Antes se devolvian vacias y la pestaña Probar las mostraba en blanco, que es
     * justo lo que no servia: `mensajes_conten` y `audio` son las dos que el
     * usuario necesita ver para confiar en la importacion.
     *
     * @return array<string, array{valor: string, nota: string}>
     */
    private function calculados(array $detalle): array
    {
        $id = (string) ($detalle['conversation_id'] ?? '');
        $transcript = is_array($detalle['transcript'] ?? null) ? $detalle['transcript'] : [];

        $mensajesConten = $this->mensajes->construir($transcript);
        $turnos = $this->mensajes->turnos($transcript);

        // El analisis necesita la razon ya resuelta y la duracion real.
        $razon = $this->razonResuelta($detalle);
        $duracion = (int) ($detalle['metadata']['call_duration_secs'] ?? 0);

        $this->analizador->procesar($mensajesConten, $duracion, $razon);

        $agente = $this->agenteDe((string) ($detalle['agent_id'] ?? ''));
        $tipo = $agente?->tipo_llamada_id !== null ? (string) (int) $agente->tipo_llamada_id : '';

        return [
            'vapi_id' => [
                'valor' => $id,
                'nota' => 'Es el conversation_id de ElevenLabs (llave de llamadas, mensajes y scoring)',
            ],
            'audio' => [
                'valor' => $id !== '' ? route('elevenlabs.audio', ['conversation_id' => $id]) : '',
                'nota' => 'Link propio: el MP3 lo sirve este backend, no se almacena',
            ],
            'mensajes_conten' => [
                'valor' => $mensajesConten,
                'nota' => count($turnos) . ' turnos como //BOT: … //USER: …',
            ],
            'llamada_tipo' => [
                'valor' => $tipo,
                'nota' => $agente
                    ? ($tipo !== '' ? 'Segun el catalogo de asistentes: ' . $agente->nombre : 'El asistente no tiene tipo asignado (fuera del lote)')
                    : 'Asistente no encontrado en el catalogo de equivalencias',
            ],
            'analisis_transcripcion' => [
                // La columna es varchar(1000) y el analizador ACUMULA un fragmento por
                // coincidencia (puede pasar de 1000 en una llamada larga). Se recorta
                // aqui para que el insert no falle en modo estricto.
                'valor' => mb_substr($this->analizador->analisis_transcripcion, 0, 1000),
                'nota' => 'Analizador de transcripcion (port de procesar_transcripcion.js)',
            ],
            'conductor_contesta_pero_no_habla' => [
                'valor' => $this->analizador->conductor_contesta_pero_no_habla ? '1' : '',
                'nota' => 'Analizador de transcripcion',
            ],
            'lote_id' => [
                'valor' => '',
                'nota' => 'Se genera al crear el lote; no depende de la llamada',
            ],
        ];
    }

    /**
     * Separa la lista de rutas candidatas: una por linea.
     *
     * Se admiten comentarios con `#` y lineas vacias, para poder dejar anotado por
     * que esta ahi cada ruta.
     *
     * @return array<int, string>
     */
    public function candidatas(string $texto): array
    {
        $rutas = [];

        foreach (preg_split('/\r\n|\r|\n/', $texto) ?: [] as $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            $rutas[] = $linea;
        }

        return array_values(array_unique($rutas));
    }

    /**
     * Aplana el JSON del detalle en rutas hoja con su valor actual.
     *
     * Es lo que alimenta el selector dinamico de "ruta disparador" y el de "ruta"
     * de la pestaña Campos: en vez de una lista fija de 7 rutas escritas a mano,
     * el usuario elige sobre las rutas que REALMENTE trae la llamada, viendo el
     * valor que traen (que es justo lo que necesita para escribir la condicion).
     *
     * Se podan los subarboles que solo generan ruido y cientos de rutas inutiles
     * (el desglose de cobro, las metricas por turno, el uso de features...).
     *
     * @return array<string, array{ruta: string, valor: string, tipo: string}>
     */
    public function aplanar(array $datos, string $prefijo = ''): array
    {
        $salida = [];

        foreach ($datos as $clave => $valor) {
            $ruta = $prefijo === '' ? (string) $clave : $prefijo . '.' . $clave;

            if ($this->esRutaPodada($ruta)) {
                continue;
            }

            if (is_array($valor)) {
                // Las listas (transcript, empresas, negociaciones...) no sirven como
                // disparador de una regla y multiplicarian las rutas por indice.
                if ($valor === [] || array_is_list($valor)) {
                    continue;
                }

                $salida += $this->aplanar($valor, $ruta);

                continue;
            }

            $salida[$ruta] = [
                'ruta' => $ruta,
                'valor' => $this->valorLegible($valor),
                'tipo' => gettype($valor),
            ];
        }

        return $salida;
    }

    /** Subarboles que aportan cientos de rutas y no sirven como condicion. */
    private function esRutaPodada(string $ruta): bool
    {
        foreach ([
            'metadata.charging',
            'metadata.deletion_settings',
            'metadata.voice_rewards',
            'metadata.feedback',
            'metadata.rag_usage',
            'metadata.avatar_sessions',
            // De features_usage solo se poda el arbol de configuracion: el resto de
            // banderas SI sirven, y metadata.features_usage.voicemail_detection.used
            // es justamente el campo que alimenta `buzon_de_voz`.
            'metadata.features_usage.workflow',
            'metadata.features_usage.agent_testing',
            'otlp_traces',
            'realtime_config_snapshots',
            'experiment_assignments',
            'orchestrator',
            'worked_examples',
        ] as $podada) {
            if ($ruta === $podada || str_starts_with($ruta, $podada . '.')) {
                return true;
            }
        }

        return false;
    }

    /** Valor corto y legible para mostrar al lado de la ruta. */
    private function valorLegible(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        $texto = is_array($valor)
            ? json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $valor;

        $texto = trim(preg_replace('/\s+/', ' ', (string) $texto) ?? '');

        return mb_strlen($texto) > 120 ? mb_substr($texto, 0, 120) . '…' : $texto;
    }

    /**
     * La razon tal como la resuelven las reglas (para el analizador, que decide
     * "CUELGA" / "NO HABLA" mirando el motivo de fin).
     */
    private function razonResuelta(array $detalle): string
    {
        $yaResuelto = [];

        foreach ($this->mapeo->reglas() as $regla) {
            if (! $regla->activo || $regla->campo_destino !== 'razon_finalizacion') {
                continue;
            }
            if (isset($yaResuelto[$regla->prioridad])) {
                continue;
            }

            $lectura = $this->leerRuta($detalle, (string) $regla->ruta_origen);

            if ($this->cumple($lectura['valor'], (string) $regla->comparacion, $regla->valor_origen)) {
                return (string) $regla->valor_destino;
            }
        }

        return '';
    }

    private function agenteDe(string $agentId): ?object
    {
        if ($agentId === '') {
            return null;
        }

        return DB::table('elevenlabs_agentes')->where('agent_id', $agentId)->first();
    }

    /**
     * Lee una ruta con puntos. Devuelve si existia y el valor (puede ser array).
     *
     * @return array{existe: bool, valor: mixed}
     */
    public function leerRuta(array $datos, string $ruta): array
    {
        if ($ruta === '') {
            return ['existe' => false, 'valor' => null];
        }

        $actual = $datos;

        foreach (explode('.', $ruta) as $parte) {
            if (! is_array($actual) || ! array_key_exists($parte, $actual)) {
                return ['existe' => false, 'valor' => null];
            }
            $actual = $actual[$parte];
        }

        return ['existe' => true, 'valor' => $actual];
    }

    /**
     * Transformaciones de lista cerrada. Devuelve siempre string, porque
     * `tmp_lotes_det` es todo varchar/text.
     */
    public function transformar(mixed $valor, string $transformacion, array $contexto = []): string
    {
        if (is_array($valor)) {
            $valor = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $texto = trim((string) $valor);

        return match ($transformacion) {
            'numero' => is_numeric($texto) ? (string) (0 + $texto) : $texto,
            'telefono' => preg_replace('/[^0-9]/', '', $texto) ?? '',
            'segundos_a_ms' => is_numeric($texto) ? (string) ((int) $texto * 1000) : $texto,
            'mayusculas' => mb_strtoupper($texto),
            'minusculas' => mb_strtolower($texto),
            'truncar_255' => mb_substr($texto, 0, 255),
            'json' => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fecha_texto' => FechaTexto::parsear($texto, (int) ($contexto['anio'] ?? (int) date('Y'))) ?? '',

            // Veredicto de ElevenLabs (analysis.call_successful). NO se reinterpreta:
            // solo se adapta el formato, porque DBTmpLlamadas compara exacto con
            // 'TRUE' y guardaria 0 si le llega "success".
            'veredicto_ia' => mb_strtolower($texto) === 'success' ? 'TRUE' : 'FALSE',

            // Booleanos del JSON (true/false, "true"/"false", 1/0) -> 1 / 0.
            'booleano_0_1' => in_array(mb_strtolower($texto), ['1', 'true', 'si', 'sí', 'yes'], true) ? '1' : '0',

            // Booleanos -> el texto "true" / "false". OJO: en PHP el string "false"
            // es verdadero, asi que DBTmpLlamadas::etiqueta_valor() interpreta el
            // texto explicitamente; antes "false" se guardaba como 1.
            'texto_booleano' => in_array(mb_strtolower($texto), ['1', 'true', 'si', 'sí', 'yes'], true) ? 'true' : 'false',

            // Placa: mayusculas y SOLO letras/numeros. "A,B,C,1,2,3" -> "ABC123",
            // "abc-123" -> "ABC123", "ÁB C.123" -> "ABC123". Es la normalizacion que
            // hace comparables las placas entre plataformas y contra DBReferencias.
            'alfanumerico' => $this->soloAlfanumerico($texto),

            default => $texto,
        };
    }

    /**
     * Mayusculas sin acentos ni simbolos: solo A-Z y 0-9.
     *
     * No se usa iconv('//TRANSLIT'): en este entorno convierte "ñ" en "~n"
     * ("mañana" -> "ma~nana"), asi que un mapa explicito es lo unico fiable.
     */
    public function soloAlfanumerico(string $texto): string
    {
        $texto = strtr($texto, [
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U',
            'à' => 'A', 'è' => 'E', 'ì' => 'I', 'ò' => 'O', 'ù' => 'U',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
            'ñ' => 'N', 'Ñ' => 'N', 'ç' => 'C', 'Ç' => 'C',
        ]);

        $texto = mb_strtoupper($texto);

        return preg_replace('/[^A-Z0-9]/', '', $texto) ?? '';
    }

    /** Evaluación de la condición de una regla. */
    public function cumple(mixed $valor, string $comparacion, ?string $esperado): bool
    {
        $texto = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : (string) ($valor ?? '');

        return match ($comparacion) {
            'vacio' => trim($texto) === '',
            'no_vacio' => trim($texto) !== '',
            'contiene' => $esperado !== null && $esperado !== ''
                && mb_stripos($texto, $esperado) !== false,
            'empieza_por' => $esperado !== null && $esperado !== ''
                && mb_stripos($texto, $esperado) === 0,
            // "es diferente de": mismo criterio exacto que `igual`, negado.
            'diferente' => trim($texto) !== trim((string) $esperado),
            // "en otro caso": cumple SIEMPRE. Como para cada columna gana la primera
            // regla que cumple (por prioridad), una regla `else` solo actúa de red
            // de seguridad si va con prioridad alta (al final).
            'else' => true,
            default => trim($texto) === trim((string) $esperado),
        };
    }

    /**
     * Fecha local del inicio de la llamada, para mostrar en la pantalla.
     */
    public function inicioLocal(array $detalle): string
    {
        $inicio = (int) ($detalle['metadata']['start_time_unix_secs'] ?? 0);
        if ($inicio <= 0) {
            return '';
        }

        return (new DateTimeImmutable('@' . $inicio))
            ->setTimezone(new \DateTimeZone(config('app.timezone')))
            ->format('Y-m-d H:i:s');
    }
}
