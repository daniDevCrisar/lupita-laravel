<?php

namespace App\Prediccion;

use App\Database\DBPrediccion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Orquestador del módulo: junta la capa de datos con el motor puro y arma lo que
 * consumen la pantalla, la API y la tabla de aciertos.
 *
 * Reglas que se respetan acá (y no en el motor, que no sabe de base de datos):
 *  - el ancla es el último viaje del camión ANTERIOR a la referencia a predecir;
 *  - la calibración del hueco se aprende sólo con datos anteriores al corte:
 *    en la tabla de aciertos eso es el inicio del rango evaluado, para que el
 *    motor nunca vea el `presenta_para_carga` que tiene que adivinar;
 *  - las franjas de no-actividad son del camión, y si su historia es muy corta o
 *    el perfil sale absurdo (más de 60 % del día inactivo) se usa la noche por
 *    defecto en vez de un perfil que empujaría todo al día siguiente.
 */
class Servicio
{
    /** Ventanas de respaldo cuando la placa no tiene historia creíble. */
    public const VENTANAS_DEFECTO = [
        ['desde' => 1320, 'hasta' => 1440],   // 22:00 - 24:00
        ['desde' => 0, 'hasta' => 360],       // 00:00 - 06:00
    ];

    /**
     * Predicción de una referencia concreta.
     *
     * Opciones: ahora (Carbon|string), ancla_ref (string), ubicacion_tipo
     * (auto|local|ubigeo|indeterminado), ubicacion_id (string).
     */
    public static function predecir(string $ref, array $opciones = []): stdClass
    {
        $p = Parametros::cargar();
        $ahora = self::carbon($opciones['ahora'] ?? null) ?? Carbon::now();
        $ref = trim($ref);
        $fila = $ref !== '' ? DBPrediccion::ref($ref) : null;

        if ($fila === null) {
            return (object) [
                'ref' => $ref,
                'error' => 'ref_no_encontrada',
                'presenta' => null,
                'desglose' => [],
                'supuestos' => [],
                'advertencias' => ['ref_no_encontrada: la referencia no existe en la base'],
                'candidatos' => [],
                'historia' => null,
            ];
        }

        $placa = (string) $fila->placa;
        $desde = $ahora->copy()->subDays(max(1, (int) $p->lookback_dias));

        $candidatos = self::candidatos_viaje($placa, $ahora, 40);
        $candidatos = array_values(array_filter($candidatos, fn (stdClass $viaje) => $viaje->ref !== $ref));

        $ancla = null;
        $pedida = isset($opciones['ancla_ref']) ? (string) $opciones['ancla_ref'] : '';
        foreach ($candidatos as $viaje) {
            if ($pedida !== '' && $viaje->ref === $pedida) {
                $ancla = $viaje;
                break;
            }
        }
        if ($ancla === null && $pedida === '' && $candidatos !== []) {
            $ancla = $candidatos[0];
        }

        $rutaRef = (string) ($fila->ruta_id ?? '');
        $plantaCarga = (string) ($fila->origen_id ?? '');
        $etapasPorRuta = DBPrediccion::etapas_por_ruta($desde, $ahora);

        // desde dónde vuelve el camión: su última posición conocida o lo que declare el usuario
        $desdePlanta = $ancla !== null ? (string) ($ancla->destino_id ?? '') : '';
        $desdeUbigeo = $ancla !== null ? (string) ($ancla->ubigeo_destino ?? '') : '';

        $ubicacionTipo = (string) ($opciones['ubicacion_tipo'] ?? 'auto');
        $ubicacionId = trim((string) ($opciones['ubicacion_id'] ?? ''));

        if ($ubicacionTipo === 'local' && $ubicacionId !== '') {
            $desdePlanta = $ubicacionId;
            $desdeUbigeo = self::ubigeo_local($ubicacionId);
        } elseif ($ubicacionTipo === 'ubigeo' && $ubicacionId !== '') {
            $desdePlanta = '';
            $desdeUbigeo = $ubicacionId;
        } elseif ($ubicacionTipo === 'indeterminado') {
            $desdePlanta = '';
            $desdeUbigeo = '';
        }

        $traslado = $plantaCarga !== ''
            ? DBPrediccion::traslado($plantaCarga, $desdePlanta !== '' ? $desdePlanta : null,
                $desdeUbigeo !== '' ? $desdeUbigeo : null, (string) ($fila->ubigeo_origen ?? ''),
                $etapasPorRuta, $rutaRef !== '' ? $rutaRef : null, (int) $p->traslado_defecto_min)
            : null;

        // calibración: pares de la placa (o del chofer si concentra sus viajes)
        [$grupoCalibracion, $claveCalibracion] = self::clave_calibracion(
            $placa,
            $fila->conductor_clave !== null ? (int) $fila->conductor_clave : null,
            self::pct_chofer_habitual($fila, $desde, $ahora)
        );

        $pares = DBPrediccion::pares($ref, $desde, $ahora, $placa, null, 5000);
        if ($grupoCalibracion === 'conductor') {
            foreach ($pares as $par) {
                $par->entidad = $par->conductor !== null ? (string) $par->conductor : 'sin_conductor';
            }
        }

        $calibracion = Calibrador::calibrar($pares, (int) $p->min_pares_ruta,
            (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max);

        $rutaAncla = $ancla !== null ? (string) ($ancla->ruta_id ?? '') : '';
        $horaAncla = self::hora_ancla(
            $ancla?->fecha,
            $ancla?->hito,
            (bool) ($ancla->cerrado ?? false),
            $etapasPorRuta[$rutaAncla] ?? [],
            $ahora
        );

        $calibrador = Calibrador::estimar($calibracion,
            $rutaAncla !== '' ? $rutaAncla : null,
            $rutaRef !== '' ? $rutaRef : null,
            $claveCalibracion,
            $horaAncla);

        $ventanas = self::ventanas($placa, $desde, $ahora, $p);

        $resultado = MotorPresenta::predecir((object) [
            'ahora' => $ahora,
            'ref' => $ref,
            'planta_carga' => $plantaCarga !== '' ? $plantaCarga : null,
            'ref_presenta' => $fila->presenta_para_carga,
            'ancla_ref' => $ancla->ref ?? '',
            'ancla' => (object) [
                'hito' => $ancla?->hito,
                'fecha' => $ancla?->fecha,
                'cerrada' => (bool) ($ancla->cerrado ?? false),
            ],
            'etapas' => $etapasPorRuta[$rutaAncla] ?? [],
            'calibrador' => $calibrador,
            'traslado' => $traslado,
            'ventanas' => $ventanas['ventanas'],
            'espera_carga_min' => DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
                $rutaRef !== '' ? $rutaRef : null),
            'parametros' => $p,
        ]);

        $resultado->placa = $placa;
        $resultado->ruta = $rutaRef;
        $resultado->ruta_nombre = self::nombre_ruta($fila);
        $resultado->planta_carga = $plantaCarga;
        $resultado->candidatos = $candidatos;
        $resultado->historia = (object) [
            'pares' => count($pares),
            'nivel' => $calibrador->nivel,
            'n' => $calibrador->n,
            'grupo' => $grupoCalibracion,
            'clave' => $claveCalibracion,
            'ventanas' => $ventanas['ventanas'],
            'ventanas_origen' => $ventanas['origen'],
        ];

        return $resultado;
    }

    /**
     * Tabla de aciertos: corre el motor y el método actual sobre las referencias
     * ya cerradas del rango y compara contra el `presenta_para_carga` real.
     *
     * La calibración, las etapas y las franjas se aprenden con datos ANTERIORES
     * al rango evaluado, así ninguna fila ve su propia presentación.
     */
    public static function backtest(array $filtros = []): stdClass
    {
        $p = Parametros::cargar();
        $desde = self::carbon($filtros['desde'] ?? null) ?? Carbon::now()->subDays(30);
        $hasta = self::carbon($filtros['hasta'] ?? null) ?? Carbon::now();
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta->copy(), $desde->copy()];
        }
        $placa = trim((string) ($filtros['placa'] ?? ''));
        $limite = max(1, min(1000, (int) ($filtros['limite'] ?? 200)));

        $aprendizajeDesde = $desde->copy()->subDays(max(1, (int) $p->lookback_dias));

        $etapas = DBPrediccion::etapas_por_ruta($aprendizajeDesde, $desde);
        $rutas = DBPrediccion::rutas();

        $paresAprendizaje = DBPrediccion::pares(null, $aprendizajeDesde, $desde,
            $placa !== '' ? $placa : null, null, 20000);
        $calibracionGlobal = Calibrador::calibrar($paresAprendizaje, (int) $p->min_pares_ruta,
            (int) $p->min_pares_entidad, (int) $p->bins_horarios, (int) $p->pares_recientes_max);

        $presentasPorPlaca = [];
        foreach ($paresAprendizaje as $par) {
            $presentasPorPlaca[$par->placa][] = $par->presenta;
        }

        $pares = DBPrediccion::pares(null, $desde, $hasta, $placa !== '' ? $placa : null, null, $limite);

        // historia completa por placa (aprendizaje + rango): cada fila calibra con
        // SUS propios pares anteriores, así el motor no ve el futuro ni arrastra
        // una calibración vieja
        $historialPorPlaca = [];
        foreach ($paresAprendizaje as $par) {
            $historialPorPlaca[$par->placa][] = $par;
        }
        foreach ($pares as $par) {
            $historialPorPlaca[$par->placa][] = $par;
        }

        $placas = array_values(array_unique(array_map(fn (stdClass $par) => $par->placa, $pares)));
        // los hitos de TODO el tramo se traen una vez; cada fila filtra los suyos
        // anteriores a su ancla (ver el cálculo de $ventanasCache en el bucle)
        $hitosPorPlaca = DBPrediccion::hitos_por_placa($aprendizajeDesde, $hasta, $placas);

        $filas = [];
        $erroresMotor = [];
        $erroresBase = [];
        $dentroMotor = [];
        $dentroBase = [];
        $ventanasCache = [];
        $descartados = 0;
        $topeHueco = max(1, (int) $p->tope_hueco_min);

        foreach ($pares as $par) {
            // fuera del dominio del modelo (hueco de más de 7 días o negativo):
            // no es predecible y métricamente sólo aporta ruido
            if ($par->hueco_min <= 0 || $par->hueco_min > $topeHueco) {
                $descartados++;
                continue;
            }

            $rutaSiguiente = (string) ($par->ruta_siguiente ?? '');
            $rutaAncla = (string) ($par->ruta_ancla ?? '');

            $plantaCarga = (string) ($rutas[$rutaSiguiente]->origen_id ?? '');
            $desdePlanta = (string) ($rutas[$rutaAncla]->destino_id ?? '');
            $desdeUbigeo = (string) ($rutas[$rutaAncla]->ubigeo_destino ?? '');
            $plantaUbigeo = (string) ($rutas[$rutaSiguiente]->ubigeo_origen ?? '');

            $traslado = $plantaCarga !== ''
                ? DBPrediccion::traslado($plantaCarga, $desdePlanta !== '' ? $desdePlanta : null,
                    $desdeUbigeo !== '' ? $desdeUbigeo : null, $plantaUbigeo,
                    $etapas, $rutaSiguiente !== '' ? $rutaSiguiente : null, (int) $p->traslado_defecto_min)
                : null;

            // calibración as-of: sólo los pares de esta placa anteriores al ancla,
            // y la corrección horaria global (que no conoce la fila)
            $previos = self::pares_previos($historialPorPlaca[$par->placa] ?? [], $par->ancla,
                max(1, (int) $p->pares_recientes_max));

            $calFila = Calibrador::calibrar($previos, (int) $p->min_pares_ruta,
                (int) $p->min_pares_entidad, (int) $p->bins_horarios, 0);
            $calFila->curva = $calibracionGlobal->curva;

            $calibrador = Calibrador::estimar($calFila,
                $rutaAncla !== '' ? $rutaAncla : null,
                $rutaSiguiente !== '' ? $rutaSiguiente : null,
                $par->placa,
                (int) $par->ancla->format('G'));

            // franjas inactivas as-of: sólo los hitos anteriores al ancla EXACTA de
            // esta fila (la clave lleva el timestamp, no el día: cachear por día
            // dejaba ver hasta 24 h de futuro dentro de la misma jornada)
            $llaveVentana = $par->placa . '|' . $par->ancla->format('Y-m-d H:i:s');
            if (!isset($ventanasCache[$llaveVentana])) {
                $hitosPrevios = array_values(array_filter(
                    $hitosPorPlaca[$par->placa] ?? [],
                    fn (Carbon $hito) => $hito->lessThan($par->ancla)
                ));
                $ventanasCache[$llaveVentana] = self::ventanas_con_hitos(
                    $hitosPrevios, $aprendizajeDesde, $par->ancla, $p);
            }

            $resultado = MotorPresenta::predecir((object) [
                'ahora' => $par->ancla,
                'ref' => $par->ref,
                'planta_carga' => $plantaCarga !== '' ? $plantaCarga : null,
                'ref_presenta' => null,
                'ancla_ref' => $par->ref_ancla,
                'ancla' => (object) ['hito' => 'fin_descargue', 'fecha' => $par->ancla, 'cerrada' => true],
                'etapas' => $etapas[$rutaAncla] ?? [],
                'calibrador' => $calibrador,
                'traslado' => $traslado,
                'ventanas' => $ventanasCache[$llaveVentana]['ventanas'],
                'espera_carga_min' => DBPrediccion::espera_carga($plantaCarga !== '' ? $plantaCarga : null,
                    $rutaSiguiente !== '' ? $rutaSiguiente : null),
                'parametros' => $p,
            ]);

            $real = $par->presenta;
            $predicho = $resultado->presenta;
            $error = $predicho !== null ? self::minutos($real, $predicho) : null;
            $dentroFila = self::enVentana($predicho, $resultado->ventana);

            $base = BaseHistograma::estimar($presentasPorPlaca[$par->placa] ?? [], $par->ancla);
            $errorBase = $base !== null ? self::minutos($real, $base) : null;

            if ($error !== null) {
                $erroresMotor[] = $error;
                $dentroMotor[] = $dentroFila;
            }
            if ($errorBase !== null) {
                $erroresBase[] = $errorBase;
            }

            $filas[] = (object) [
                'ref' => $par->ref,
                'placa' => $par->placa,
                'ancla_ref' => $par->ref_ancla,
                'ancla' => $par->ancla,
                'hueco_min' => $par->hueco_min,
                'predicho' => $predicho,
                'real' => $real,
                'error_min' => $error,
                'ventana_desde' => $resultado->ventana['desde'] ?? null,
                'ventana_hasta' => $resultado->ventana['hasta'] ?? null,
                'dentro' => $dentroFila,
                'evidencia' => $resultado->evidencia,
                'confianza' => $resultado->confianza,
                'estado' => $resultado->estado->clave ?? null,
                'base' => $base,
                'base_error_min' => $errorBase,
            ];
        }

        $resumenMotor = Aciertos::resumir($erroresMotor, $dentroMotor);
        // la línea base no tiene ventana p25-p75: su acierto se mide con las tolerancias
        $resumenBase = Aciertos::resumir($erroresBase);

        return (object) [
            'filas' => $filas,
            'resumen_motor' => $resumenMotor,
            'resumen_base' => $resumenBase,
            'comparacion' => Aciertos::comparar($resumenMotor, $resumenBase),
            'parametros' => $p,
            'desde' => $desde,
            'hasta' => $hasta,
            'aprendizaje_desde' => $aprendizajeDesde,
            'pares' => count($filas),
            'descartados' => $descartados,
        ];
    }

    /**
     * Viajes del camión que pueden servir de ancla, del más reciente al más viejo.
     *
     * @return array<int, stdClass>
     */
    public static function candidatos_viaje(string $placa, ?Carbon $antes = null, int $limite = 40): array
    {
        $placa = trim($placa);
        if ($placa === '') {
            return [];
        }

        $salida = [];
        foreach (DBPrediccion::refs_por_placa($placa, $antes, $limite) as $fila) {
            $hito = DBPrediccion::hito_mas_reciente($fila);
            $fecha = $hito !== null ? self::carbon($fila->{$hito} ?? null) : null;

            $salida[] = (object) [
                'ref' => (string) $fila->ref,
                'ruta_id' => (string) ($fila->ruta_id ?? ''),
                'ruta' => self::nombre_ruta($fila),
                'origen_id' => (string) ($fila->origen_id ?? ''),
                'destino_id' => (string) ($fila->destino_id ?? ''),
                'ubigeo_origen' => (string) ($fila->ubigeo_origen ?? ''),
                'ubigeo_destino' => (string) ($fila->ubigeo_destino ?? ''),
                'hito' => $hito,
                'fecha' => $fecha,
                'cerrado' => $hito === 'fin_descargue',
                'edad_min' => ($fecha !== null && $antes !== null) ? (int) round($fecha->diffInMinutes($antes)) : null,
                'presenta' => $fila->presenta_para_carga ?? null,
                'inicio_ruta' => $fila->inicio_ruta ?? null,
                'fin_descargue' => $fila->fin_descargue ?? null,
                'conductor' => $fila->conductor_nombre ?? null,
            ];
        }

        return $salida;
    }

    /** Catálogos y parámetros que comparten las dos pantallas. */
    public static function catalogos(): stdClass
    {
        $locales = [];
        try {
            $locales = DB::table('locales')->orderBy('nombre')->get(['id', 'nombre', 'palabra_clave', 'distrito_ubigeo'])->all();
        } catch (\Throwable $e) {
            // sin catálogo de locales el formulario sigue con ubigeo a mano
        }

        $departamentos = [];
        try {
            $departamentos = DB::table('ubigeo_departamentos')->orderBy('departamento')->get(['id', 'departamento'])->all();
        } catch (\Throwable $e) {
        }

        return (object) [
            'rutas' => DBPrediccion::rutas(),
            'plantas' => DBPrediccion::promedios_planta(),
            'locales' => $locales,
            'departamentos' => $departamentos,
            'parametros' => Parametros::cargar(),
        ];
    }

    /**
     * Pares que se pueden usar para calibrar una fila: sólo los anteriores a su
     * ancla (nada de futuro), del más reciente al más viejo, y como máximo `$max`.
     *
     * Es la guarda anti-fuga de la tabla de aciertos, sacada a un método puro
     * para poder probarla sin base de datos.
     *
     * @param  array<int, stdClass>  $historial  pares de la placa (cada uno con ->presenta Carbon)
     * @return array<int, stdClass>
     */
    public static function pares_previos(array $historial, Carbon $ancla, int $max = 20): array
    {
        $previos = [];
        foreach ($historial as $parcial) {
            if (isset($parcial->presenta) && $parcial->presenta instanceof Carbon
                && $parcial->presenta->lessThan($ancla)) {
                $previos[] = $parcial;
            }
        }

        usort($previos, fn (stdClass $a, stdClass $b) => $b->presenta->getTimestamp() <=> $a->presenta->getTimestamp());

        return array_slice($previos, 0, max(1, $max));
    }

    /**
     * Valida la ubicación declarada por el usuario: si el local no existe o el
     * ubigeo no es de 6 dígitos, el traslado caería a otro nivel y devolvería
     * una hora plausible sin decir por qué. Devuelve el mensaje de error o NULL.
     */
    public static function ubicacion_invalida(string $tipo, string $id): ?string
    {
        $tipo = trim($tipo) !== '' ? trim($tipo) : 'auto';
        $id = trim($id);

        if ($id === '') {
            return null;   // sin ubicación declarada: se deduce del último hito
        }

        if ($tipo === 'local') {
            try {
                return DB::table('locales')->where('id', $id)->exists()
                    ? null
                    : 'la ubicación "' . $id . '" no existe en el catálogo de locales';
            } catch (\Throwable $e) {
                return null;   // sin catálogo no se puede juzgar: no se bloquea
            }
        }

        if ($tipo === 'ubigeo') {
            return preg_match('/^\d{6}$/', $id) === 1
                ? null
                : 'el ubigeo tiene que ser de 6 dígitos (ej. 140101)';
        }

        return null;
    }

    /**
     * Con qué clave se calibra el hueco: la placa manda, salvo que un conductor
     * concentre menos de la mitad de sus viajes (misma regla que
     * `ComportamientoController::chofer_habitual`), en cuyo caso los promedios de
     * la placa no describen a nadie.
     *
     * @return array{0: string, 1: string} [grupo, clave]
     */
    public static function clave_calibracion(?string $placa, ?int $conductorId, ?float $pctHabitual): array
    {
        $placa = trim((string) $placa);

        if ($placa === '') {
            return ['placa', ''];
        }

        if ($conductorId !== null && $conductorId > 0 && $pctHabitual !== null && $pctHabitual < 50.0) {
            return ['conductor', (string) $conductorId];
        }

        return ['placa', $placa];
    }

    //------------------- ayudas internas -------------------

    /** Qué porcentaje de los viajes de la placa hizo el conductor de esta ref. */
    private static function pct_chofer_habitual(object $fila, Carbon $desde, Carbon $hasta): ?float
    {
        $conductor = (int) ($fila->conductor_clave ?? 0);
        $placa = (string) ($fila->placa ?? '');
        if ($conductor <= 0 || $placa === '') {
            return null;
        }

        try {
            $total = (int) DB::table('referencias')
                ->where('placa', $placa)
                ->whereBetween('presenta_para_carga', [$desde, $hasta])
                ->count();
            if ($total === 0) {
                return null;
            }

            $propios = (int) DB::table('referencias')
                ->where('placa', $placa)
                ->whereBetween('presenta_para_carga', [$desde, $hasta])
                ->where('conductor_id', $conductor)
                ->count();
        } catch (\Throwable $e) {
            return null;
        }

        return round(100 * $propios / $total, 1);
    }

    /** Hora del día en la que queda libre el camión: la que corrige el hueco. */
    private static function hora_ancla(?Carbon $fechaAncla, ?string $hito, bool $cerrada, array $etapas, Carbon $ahora): int
    {
        if ($fechaAncla === null) {
            return (int) $ahora->format('G');
        }

        if ($cerrada) {
            return (int) $fechaAncla->format('G');
        }

        $faltante = MotorPresenta::faltantes($hito, $etapas);
        $tFin = $fechaAncla->copy()->addMinutes($faltante ?? 0);
        if ($tFin->lessThan($ahora)) {
            $tFin = $ahora->copy();
        }

        return (int) $tFin->format('G');
    }

    /** @return array{ventanas: array<int, array{desde: int, hasta: int}>, origen: string} */
    private static function ventanas(string $placa, Carbon $desde, Carbon $hasta, stdClass $p): array
    {
        $hitos = DBPrediccion::hitos_entidad('placa', $placa, $desde, $hasta);

        return self::ventanas_con_hitos($hitos, $desde, $hasta, $p);
    }

    /**
     * Franjas inactivas a partir de las marcas ya cargadas, con las dos guardas:
     * historia mínima y perfil creíble (menos del 60 % del día quieto).
     *
     * Devuelve también el ORIGEN (perfil del camión o una de las dos caídas al
     * perfil por defecto): la pantalla tiene que poder decir de dónde salen.
     *
     * @param  array<int, \Carbon\Carbon>  $hitos
     * @return array{ventanas: array<int, array{desde: int, hasta: int}>, origen: string}
     */
    private static function ventanas_con_hitos(array $hitos, Carbon $desde, Carbon $hasta, stdClass $p): array
    {
        if ($hitos === []) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_sin_hitos'];
        }

        $primero = $hitos[0];
        $ultimo = $hitos[array_key_last($hitos)];
        $dias = max(1, (int) round($primero->copy()->startOfDay()->diffInDays($ultimo->copy()->startOfDay())) + 1);

        if ($dias < max(1, (int) $p->min_dias_actividad)) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_historia_corta'];
        }

        $ventanas = Ventanas::inactivas($hitos, $desde, $hasta,
            (int) $p->min_hitos_bin, (int) $p->granularidad_min);

        if (Ventanas::minutosInactivos($ventanas) > (int) (0.6 * Ventanas::MINUTOS_DIA)) {
            return ['ventanas' => self::VENTANAS_DEFECTO, 'origen' => 'defecto_perfil_absurdo'];
        }

        return ['ventanas' => $ventanas, 'origen' => 'perfil_del_camion'];
    }

    /** Minutos de `$hasta - $desde` (positivo si la predicción llegó tarde). */
    private static function minutos(Carbon $referencia, Carbon $otra): int
    {
        return (int) round(($otra->getTimestamp() - $referencia->getTimestamp()) / 60);
    }

    /** Si la fecha cae dentro de la ventana p25-p75 de la predicción. */
    private static function enVentana(?Carbon $fecha, array $ventana): bool
    {
        $desde = $ventana['desde'] ?? null;
        $hasta = $ventana['hasta'] ?? null;

        if ($fecha === null || !($desde instanceof Carbon) || !($hasta instanceof Carbon)) {
            return false;
        }

        return $fecha->greaterThanOrEqualTo($desde) && $fecha->lessThanOrEqualTo($hasta);
    }

    /** "PLANTA X → PLANTA Y" o el id de la ruta si no hay catálogo. */
    private static function nombre_ruta($fila): string
    {
        $origen = trim((string) ($fila->origen_nombre ?? ''));
        $destino = trim((string) ($fila->destino_nombre ?? ''));

        if ($origen !== '' && $destino !== '') {
            return $origen . ' → ' . $destino;
        }

        return (string) ($fila->ruta_id ?? '');
    }

    /** Ubigeo (distrito) de un local del catálogo. */
    private static function ubigeo_local(string $local): ?string
    {
        try {
            $ubigeo = DB::table('locales')->where('id', $local)->value('distrito_ubigeo');

            return $ubigeo !== null && $ubigeo !== '' ? (string) $ubigeo : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function carbon($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return $valor->copy();
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }
}
