<?php

namespace App\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Casos de conductores: bandeja de candidatos, evidencia y seguimiento.
 *
 * Reglas de negocio:
 *  - Solo se consideran llamadas con error_origen = 0 (se descarta el error tecnico).
 *  - Se exige un minimo de llamadas para acusar a un conductor.
 *  - "No colabora" se mide sobre llamadas realmente contestadas, nunca sobre el total.
 *  - Un conductor solo puede tener un caso abierto a la vez (columna activo + unique).
 */
class DBConductoresCasos
{
    public const MIN_LLAMADAS = 10;
    public const MIN_CONTESTADAS = 5;   // sin contacto suficiente no se puede acusar rechazo
    public const UMBRAL_RECHAZO = 50;   // % de contestadas con cuelga o no habla
    public const UMBRAL_BUZON = 80;     // % de llamadas que caen en buzon
    public const UMBRAL_DUDOSO = 50;    // % de contestadas por otra persona (el numero equivocado entra con una sola)
    public const MIN_DUDOSAS = 2;       // una sola llamada con "otra persona" no basta para culpar al numero
    public const SCORE_MINIMO = 40;

    public const TIPOS = [
        'NUMERO_INVALIDO' => ['nombre' => 'Numero invalido', 'color' => 'warning', 'icono' => 'bi-telephone-x'],
        'NUMERO_EQUIVOCADO' => ['nombre' => 'Numero equivocado', 'color' => 'info', 'icono' => 'bi-telephone-inbound'],
        'NO_COLABORA' => ['nombre' => 'No colabora', 'color' => 'danger', 'icono' => 'bi-person-x'],
        'CONDUCTA' => ['nombre' => 'Conducta', 'color' => 'dark', 'icono' => 'bi-exclamation-octagon'],
    ];

    public const ESTADOS = [
        'NUEVO' => ['nombre' => 'Nuevo', 'color' => 'secondary', 'abierto' => true],
        'EN CURSO' => ['nombre' => 'En curso', 'color' => 'warning', 'abierto' => true],
        'REPORTADO' => ['nombre' => 'Reportado', 'color' => 'info', 'abierto' => true],
        'CERRADO' => ['nombre' => 'Cerrado', 'color' => 'success', 'abierto' => false],
        'CANCELADO' => ['nombre' => 'Cancelado', 'color' => 'danger', 'abierto' => false],
    ];

    public const RESULTADOS = [
        'MEJORO' => 'Mejoro',
        'IGUAL' => 'Igual',
        'EMPEORO' => 'Empeoro',
        'SIN_DATO' => 'Sin dato',
    ];

    public const MOTIVOS = [
        'BUZON' => 'Buzon persistente',
        'NO_CONTESTA' => 'Nunca contesta',
        'CUELGA' => 'Cuelga las llamadas',
        'NO_HABLA' => 'Contesta pero no habla',
        'NUMERO_EQUIVOCADO' => 'Numero equivocado',
        'OTRA_PERSONA' => 'Contesta otra persona',
        'CONDUCTA' => 'Conducta inapropiada',
    ];

    public static $filtro;

    public static function set_filtro($request): void
    {
        self::$filtro = new stdClass();
        self::$filtro->fecha_inicio = $request->fecha_inicio ?? '';
        self::$filtro->fecha_fin = $request->fecha_fin ?? '';
        self::$filtro->llamada_tipo_id = $request->llamada_tipo_id ?? '';
        self::$filtro->conductor = $request->conductor ?? '';
        self::$filtro->tipo_caso = $request->tipo_caso ?? '';
        self::$filtro->estado = $request->estado ?? '';
    }

    private static function filtro(): stdClass
    {
        if (!self::$filtro) {
            self::$filtro = (object) [
                'fecha_inicio' => '', 'fecha_fin' => '', 'llamada_tipo_id' => '',
                'conductor' => '', 'tipo_caso' => '', 'estado' => '',
            ];
        }

        return self::$filtro;
    }

    // ------------------------------------------------------------------
    // Bandeja de candidatos
    // ------------------------------------------------------------------

    private static function columnas(): string
    {
        return '
            COUNT(*) AS validas,
            SUM(a.buzon_de_voz = 1) AS buzon,
            SUM(a.entro_llamada = 1 AND a.buzon_de_voz = 0) AS contestadas,
            SUM(a.llamada_exitosa = 1) AS exitosas,
            SUM(a.conductor_no_contesta) AS no_contesta,
            SUM(a.conductor_cuelga) AS cuelga,
            SUM(a.conductor_contesta_pero_no_habla) AS no_habla,
            SUM(a.numero_equivocado) AS num_equivocado,
            SUM(a.contesta_otra_persona) AS otra_persona,
            SUM(a.conductor_conducta_inapropiada) AS conducta,
            SUM(a.conductor_confirma) AS confirma,
            SUM((a.conductor_cuelga = 1 OR a.conductor_contesta_pero_no_habla = 1)
                AND a.entro_llamada = 1 AND a.buzon_de_voz = 0) AS rechazos';
    }

    private static function base_sql(): array
    {
        $f = self::filtro();
        $sql = 'SELECT a.conductor_id, ' . self::columnas() . '
            FROM llamadas a
            WHERE a.error_origen = 0 AND a.conductor_id IS NOT NULL';
        $params = [];

        if ($f->fecha_inicio) {
            $sql .= ' AND a.created_at >= ? ';
            $params[] = Carbon::parse($f->fecha_inicio)->startOfDay();
        }
        if ($f->fecha_fin) {
            $sql .= ' AND a.created_at < ? ';
            $params[] = Carbon::parse($f->fecha_fin)->startOfDay()->addDay();
        }
        if ((string) $f->llamada_tipo_id !== '') {
            $sql .= ' AND a.llamada_tipo_id = ? ';
            $params[] = (int) $f->llamada_tipo_id;
        }
        if ($f->conductor !== '') {
            if (is_numeric($f->conductor)) {
                $sql .= ' AND a.conductor_id = ? ';
                $params[] = (int) $f->conductor;
            } else {
                $sql .= ' AND a.conductor_id IN (SELECT id FROM conductores WHERE nombres LIKE ?) ';
                $params[] = '%' . strtoupper(trim($f->conductor)) . '%';
            }
        }

        $sql .= ' GROUP BY a.conductor_id HAVING COUNT(*) >= ? ';
        $params[] = self::MIN_LLAMADAS;

        return [$sql, $params];
    }

    /**
     * Candidatos ordenados por prioridad. Los que no tienen caso abierto van primero.
     */
    public static function candidatos(): array
    {
        [$sql, $params] = self::base_sql();
        $filas = DB::select($sql, $params);

        $ids = array_map(fn ($f) => (int) $f->conductor_id, $filas);
        $nombres = $ids ? DB::table('conductores')->whereIn('id', $ids)->pluck('nombres', 'id') : collect();
        $abiertos = self::abiertos_por_conductor();

        $lista = [];
        foreach ($filas as $fila) {
            $m = self::normalizar($fila);
            if (!$m->es_candidato) {
                continue;
            }
            $m->conductor = $nombres[$fila->conductor_id] ?? ('#' . $fila->conductor_id);
            $m->caso = $abiertos[(int) $fila->conductor_id] ?? null;
            if (self::filtro()->tipo_caso !== '' && self::filtro()->tipo_caso !== $m->tipo) {
                continue;
            }
            // El filtro de estado se aplica sobre el caso abierto del conductor
            // (SIN_CASO deja solo los que todavia no se han reportado).
            $estado = self::filtro()->estado;
            if ($estado === 'SIN_CASO') {
                if ($m->caso) {
                    continue;
                }
            } elseif ($estado !== '' && (!$m->caso || $m->caso->status !== $estado)) {
                continue;
            }
            $lista[] = $m;
        }

        usort($lista, function ($a, $b) {
            $a_abierto = $a->caso ? 1 : 0;
            $b_abierto = $b->caso ? 1 : 0;
            if ($a_abierto !== $b_abierto) {
                return $a_abierto <=> $b_abierto; // sin caso primero
            }

            return $b->score <=> $a->score;
        });

        return $lista;
    }

    public static function resumen(array $candidatos, array $casos): stdClass
    {
        $r = new stdClass();
        $r->candidatos = count($candidatos);
        $r->cubiertos = 0;
        $r->por_tipo = array_fill_keys(array_keys(self::TIPOS), 0);
        foreach ($candidatos as $c) {
            $r->por_tipo[$c->tipo] = ($r->por_tipo[$c->tipo] ?? 0) + 1;
            if ($c->caso) {
                $r->cubiertos++;
            }
        }
        $r->sin_caso = $r->candidatos - $r->cubiertos;
        $r->cobertura = $r->candidatos > 0 ? round($r->cubiertos * 100 / $r->candidatos, 1) : 0.0;

        $r->casos = count($casos);
        $r->abiertos = 0;
        $r->reportados = 0;
        $r->cerrados = 0;
        $r->mejorados = 0;
        foreach ($casos as $c) {
            if (in_array($c->status, ['NUEVO', 'EN CURSO'], true)) {
                $r->abiertos++;
            }
            if ($c->status === 'REPORTADO') {
                $r->reportados++;
                $r->abiertos++;
            }
            if ($c->status === 'CERRADO') {
                $r->cerrados++;
                if ($c->resultado === 'MEJORO') {
                    $r->mejorados++;
                }
            }
        }

        return $r;
    }

    /**
     * Normaliza las metricas crudas de un conductor y calcula tipo, motivo y score.
     */
    public static function normalizar($fila): stdClass
    {
        $m = new stdClass();
        $m->conductor_id = (int) $fila->conductor_id;
        $m->validas = (int) $fila->validas;
        $m->buzon = (int) $fila->buzon;
        $m->contestadas = (int) $fila->contestadas;
        $m->exitosas = (int) $fila->exitosas;
        $m->no_contesta = (int) $fila->no_contesta;
        $m->cuelga = (int) $fila->cuelga;
        $m->no_habla = (int) $fila->no_habla;
        $m->num_equivocado = (int) $fila->num_equivocado;
        $m->otra_persona = (int) $fila->otra_persona;
        $m->conducta = (int) $fila->conducta;
        $m->confirma = (int) $fila->confirma;
        $m->rechazos = (int) $fila->rechazos;

        $m->pct_buzon = $m->validas ? round($m->buzon * 100 / $m->validas, 1) : 0.0;
        $m->pct_contacto = $m->validas ? round($m->contestadas * 100 / $m->validas, 1) : 0.0;
        $m->pct_exito = $m->contestadas ? round($m->exitosas * 100 / $m->contestadas, 1) : 0.0;
        $m->pct_rechazo = $m->contestadas ? min(100.0, round($m->rechazos * 100 / $m->contestadas, 1)) : 0.0;
        $m->pct_equivocado = $m->contestadas ? min(100.0, round($m->num_equivocado * 100 / $m->contestadas, 1)) : 0.0;
        $m->pct_otra_persona = $m->contestadas ? min(100.0, round($m->otra_persona * 100 / $m->contestadas, 1)) : 0.0;
        $m->rechazo_confiable = $m->contestadas >= self::MIN_CONTESTADAS;
        $m->dudoso_confiable = $m->contestadas >= self::MIN_CONTESTADAS;

        // Clasificacion: conducta manda; despues buzon/nunca contesta; despues el numero
        // (equivocado u otra persona, un solo tipo con dos motivos) y por ultimo el rechazo.
        $m->tipo = null;
        $m->motivo = null;
        if ($m->conducta > 0) {
            $m->tipo = 'CONDUCTA';
            $m->motivo = 'CONDUCTA';
        } elseif ($m->pct_buzon >= self::UMBRAL_BUZON) {
            $m->tipo = 'NUMERO_INVALIDO';
            $m->motivo = 'BUZON';
        } elseif ($m->contestadas === 0) {
            $m->tipo = 'NUMERO_INVALIDO';
            $m->motivo = $m->num_equivocado > 0 ? 'NUMERO_EQUIVOCADO'
                : ($m->otra_persona > 0 ? 'OTRA_PERSONA' : 'NO_CONTESTA');
        } elseif ($m->num_equivocado > 0) {
            // Una sola llamada con el numero equivocado ya invalida el contacto.
            $m->tipo = 'NUMERO_EQUIVOCADO';
            $m->motivo = 'NUMERO_EQUIVOCADO';
        } elseif ($m->pct_otra_persona >= self::UMBRAL_DUDOSO && $m->otra_persona >= self::MIN_DUDOSAS) {
            $m->tipo = 'NUMERO_EQUIVOCADO';
            $m->motivo = 'OTRA_PERSONA';
        } elseif ($m->pct_rechazo >= self::UMBRAL_RECHAZO && $m->contestadas >= self::MIN_CONTESTADAS) {
            $m->tipo = 'NO_COLABORA';
            $m->motivo = $m->cuelga >= $m->no_habla ? 'CUELGA' : 'NO_HABLA';
        }

        $score = 0.0;
        if ($m->contestadas === 0) {
            $score += 55; // nunca hubo contacto real
        } else {
            $score += 45 * ($m->pct_rechazo / 100);
            $score += 15 * (1 - $m->pct_exito / 100);
            // El numero equivocado pesa mas que "contesta otra persona": este ultimo
            // puede ser un familiar que avisa, mientras que el equivocado es un error real.
            $score += 60 * ($m->pct_equivocado / 100);
            $score += 45 * ($m->pct_otra_persona / 100);
        }
        $score += 30 * ($m->pct_buzon / 100);
        if ($m->conducta > 0) {
            $score += 10;
        }
        // Un numero equivocado confirmado entra a la bandeja aunque el porcentaje sea bajo.
        if ($m->tipo === 'NUMERO_EQUIVOCADO') {
            $score = max($score, self::SCORE_MINIMO + 5);
        }
        $m->score = (int) round(min(100, $score));
        $m->es_candidato = $m->tipo !== null && $m->score >= self::SCORE_MINIMO;

        return $m;
    }

    /**
     * Metricas del mismo periodo, recalculadas hoy. Sirve para medir si mejoro.
     */
    public static function metricas_de(int $conductor_id, ?string $desde = null, ?string $hasta = null): stdClass
    {
        $sql = 'SELECT ' . self::columnas() . '
            FROM llamadas a
            WHERE a.error_origen = 0 AND a.conductor_id = ?';
        $params = [$conductor_id];
        if ($desde) {
            $sql .= ' AND a.created_at >= ? ';
            $params[] = Carbon::parse($desde)->startOfDay();
        }
        if ($hasta) {
            $sql .= ' AND a.created_at < ? ';
            $params[] = Carbon::parse($hasta)->startOfDay()->addDay();
        }

        $fila = DB::selectOne($sql, $params);
        if (!$fila) {
            $fila = (object) array_merge(['conductor_id' => $conductor_id], array_fill_keys([
                'validas', 'buzon', 'contestadas', 'exitosas', 'no_contesta', 'cuelga',
                'no_habla', 'num_equivocado', 'otra_persona', 'conducta', 'confirma', 'rechazos',
            ], 0));
        }
        $fila->conductor_id = $conductor_id;

        return self::normalizar($fila);
    }

    // ------------------------------------------------------------------
    // Formulario del caso
    // ------------------------------------------------------------------

    public static function formulario(int $conductor_id): array
    {
        $existente = self::caso_activo($conductor_id);
        if ($existente) {
            return self::formulario_caso($existente->id_log_conductor, true);
        }

        $f = self::filtro();
        $desde = $f->fecha_inicio ?: null;
        $hasta = $f->fecha_fin ?: null;
        if (!$desde && !$hasta) {
            $rango = DB::selectOne(
                'SELECT MIN(created_at) a, MAX(created_at) b FROM llamadas WHERE conductor_id = ? AND error_origen = 0',
                [$conductor_id]
            );
            $desde = $rango && $rango->a ? Carbon::parse($rango->a)->toDateString() : null;
            $hasta = $rango && $rango->b ? Carbon::parse($rango->b)->toDateString() : null;
        }

        $m = self::metricas_de($conductor_id, $desde, $hasta);
        $conductor = DB::table('conductores')->where('id', $conductor_id)->first();
        if (!$conductor) {
            return [];
        }
        $trt = self::ultimo_trt($conductor_id);

        return [
            'id_log_conductor' => null,
            'id_conductor' => $conductor_id,
            'conductor' => $conductor->nombres ?? ('#' . $conductor_id),
            'trt_id' => $trt->id ?? 0,
            'trt' => $trt->nombres ?? 'SIN TRT',
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'llamada_tipo_id' => (string) $f->llamada_tipo_id,
            'tipo_caso' => $m->tipo ?: 'NO_COLABORA',
            'motivo' => $m->motivo ?: 'CUELGA',
            'score' => $m->score,
            'status' => 'NUEVO',
            'ubicacion' => 'LIMA',
            'analisis' => self::analisis_sugerido($m),
            'accion' => '',
            'respuesta' => '',
            'acuerdo' => '',
            'fecha_reporte' => null,
            'fecha_compromiso' => null,
            'resultado' => '',
            'evidencia' => self::evidencia($m, $desde, $hasta, $conductor_id),
            'metricas' => self::metricas_legacy($m),
            'actual' => $m,
            'caso_existente' => false,
            'fechas_bloqueadas' => false,
        ];
    }

    public static function formulario_caso(int $id, bool $existente = false): array
    {
        $caso = self::caso($id);
        if (!$caso) {
            return [];
        }

        $evidencia = $caso->evidencia ? (json_decode($caso->evidencia, true) ?: []) : [];
        $desde = $evidencia['periodo']['desde'] ?? ($caso->fecha_inicio ? Carbon::parse($caso->fecha_inicio)->toDateString() : null);
        $hasta = $evidencia['periodo']['hasta'] ?? ($caso->fecha_fin ? Carbon::parse($caso->fecha_fin)->toDateString() : null);
        $actual = self::metricas_de((int) $caso->id_conductor, $desde, $hasta);

        // Los casos anteriores a la evidencia automatica no tienen metricas congeladas:
        // se reconstruyen con las llamadas del periodo para no mostrar ceros.
        $reconstruida = empty($evidencia['metricas']);
        if ($reconstruida) {
            $evidencia = self::evidencia(
                $actual,
                $desde,
                $hasta,
                (int) $caso->id_conductor,
                $caso->llamada_tipo_id !== null ? (int) $caso->llamada_tipo_id : null
            );
            $evidencia['reconstruida'] = true;
        }

        $score = (int) $caso->score;
        if ($reconstruida && $score === 0) {
            $score = $actual->score;
        }

        return [
            'id_log_conductor' => (int) $caso->id_log_conductor,
            'id_conductor' => (int) $caso->id_conductor,
            'conductor' => $caso->conductor,
            'trt_id' => (int) $caso->last_id_trt,
            'trt' => $caso->trt_nombres ?? 'SIN TRT',
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'llamada_tipo_id' => (string) ($caso->llamada_tipo_id ?? ''),
            'tipo_caso' => $caso->tipo_caso ?: ($reconstruida ? ($actual->tipo ?: 'NO_COLABORA') : 'NO_COLABORA'),
            'motivo' => $caso->motivo ?: ($reconstruida ? ($actual->motivo ?: 'CUELGA') : 'CUELGA'),
            'score' => $score,
            'status' => $caso->status ?: 'NUEVO',
            'ubicacion' => $caso->ubicacion ?: 'LIMA',
            'analisis' => (string) $caso->analisis,
            'accion' => (string) $caso->accion,
            'respuesta' => (string) $caso->respuesta,
            'acuerdo' => (string) $caso->acuerdo,
            'fecha_reporte' => $caso->fecha_reporte,
            'fecha_compromiso' => $caso->fecha_compromiso,
            'resultado' => (string) $caso->resultado,
            'evidencia' => $evidencia,
            'metricas' => $caso->metricas ? (json_decode($caso->metricas, true) ?: []) : [],
            'actual' => $actual,
            'caso_existente' => $existente,
            'fechas_bloqueadas' => true,
        ];
    }

    private static function analisis_sugerido(stdClass $m): string
    {
        $partes = [];
        $partes[] = 'Periodo analizado: ' . $m->validas . ' llamadas validas.';
        $partes[] = 'Buzon: ' . $m->buzon . ' (' . $m->pct_buzon . '%).';
        $partes[] = 'Contestadas: ' . $m->contestadas . ' (' . $m->pct_contacto . '%).';
        $partes[] = 'Exito sobre contestadas: ' . $m->pct_exito . '%.';

        if ($m->contestadas > 0) {
            $partes[] = 'Rechazo (cuelga o no habla): ' . $m->rechazos . ' de ' . $m->contestadas
                . ' (' . $m->pct_rechazo . '%).';
        }
        if ($m->no_contesta > 0) {
            $partes[] = 'No contesta: ' . $m->no_contesta . '.';
        }
        if ($m->num_equivocado > 0 || $m->otra_persona > 0) {
            $partes[] = 'Numero dudoso: equivocado ' . $m->num_equivocado . ' (' . $m->pct_equivocado
                . '%), contesta otra persona ' . $m->otra_persona . ' (' . $m->pct_otra_persona . '%) de las contestadas.';
        }
        if ($m->conducta > 0) {
            $partes[] = 'Conducta inapropiada: ' . $m->conducta . ' llamadas.';
        }

        return implode(' ', $partes);
    }

    private static function evidencia(stdClass $m, ?string $desde, ?string $hasta, int $conductor_id, ?int $llamada_tipo_id = null): array
    {
        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'llamada_tipo_id' => $llamada_tipo_id ?? (self::filtro()->llamada_tipo_id !== '' ? (int) self::filtro()->llamada_tipo_id : null),
            'metricas' => [
                'validas' => $m->validas, 'buzon' => $m->buzon, 'contestadas' => $m->contestadas,
                'exitosas' => $m->exitosas, 'rechazos' => $m->rechazos, 'cuelga' => $m->cuelga,
                'no_habla' => $m->no_habla, 'no_contesta' => $m->no_contesta,
                'num_equivocado' => $m->num_equivocado, 'otra_persona' => $m->otra_persona,
                'conducta' => $m->conducta, 'confirma' => $m->confirma,
            ],
            'porcentajes' => [
                'buzon' => $m->pct_buzon, 'contacto' => $m->pct_contacto,
                'exito' => $m->pct_exito, 'rechazo' => $m->pct_rechazo,
                'equivocado' => $m->pct_equivocado, 'otra_persona' => $m->pct_otra_persona,
            ],
            'regla' => [
                'min_llamadas' => self::MIN_LLAMADAS,
                'min_contestadas' => self::MIN_CONTESTADAS,
                'umbral_rechazo' => self::UMBRAL_RECHAZO,
                'umbral_buzon' => self::UMBRAL_BUZON,
                'umbral_dudoso' => self::UMBRAL_DUDOSO,
                'score_minimo' => self::SCORE_MINIMO,
            ],
            'telefonos' => self::telefonos($conductor_id),
            'trt' => self::ultimo_trt($conductor_id),
            'generado' => now()->toDateTimeString(),
        ];
    }

    private static function metricas_legacy(stdClass $m): array
    {
        return [
            'total' => $m->validas,
            'exitosas' => $m->exitosas,
            'fallidas' => max(0, $m->validas - $m->exitosas),
            'errores' => 0, // las llamadas con error tecnico quedan fuera del universo
        ];
    }

    /**
     * Reconstruye las etiquetas que consume la bitacora anterior a partir de la evidencia.
     */
    private static function etiquetas_desde_evidencia(array $evidencia): array
    {
        $m = $evidencia['metricas'] ?? [];
        $negativas = [];
        foreach ([
            'Buzon de voz' => $m['buzon'] ?? 0,
            'Cuelga' => $m['cuelga'] ?? 0,
            'Contesta pero no habla' => $m['no_habla'] ?? 0,
            'No contesta' => $m['no_contesta'] ?? 0,
            'Numero equivocado' => $m['num_equivocado'] ?? 0,
            'Contesta otra persona' => $m['otra_persona'] ?? 0,
            'Conducta inapropiada' => $m['conducta'] ?? 0,
        ] as $nombre => $cantidad) {
            if ((int) $cantidad > 0) {
                $negativas[] = ['nombre' => $nombre, 'cantidad' => (int) $cantidad];
            }
        }

        $confirma = (int) ($m['confirma'] ?? 0);

        return [
            'positivas' => $confirma > 0 ? [['nombre' => 'Confirma', 'cantidad' => $confirma]] : [],
            'negativas' => $negativas,
        ];
    }

    private static function telefonos(int $conductor_id): array
    {
        return DB::table('tlf_conductores')
            ->where('conductor_id', $conductor_id)
            ->orderByDesc('activo')
            ->get(['telefono', 'activo'])
            ->map(fn ($t) => ['telefono' => $t->telefono, 'activo' => (int) $t->activo])
            ->all();
    }

    private static function ultimo_trt(int $conductor_id): ?stdClass
    {
        return DB::selectOne(
            'SELECT t.id, t.nombres
             FROM llamadas l
             INNER JOIN trts t ON t.id = l.trt_id
             WHERE l.conductor_id = ? AND l.trt_id IS NOT NULL
             ORDER BY l.created_at DESC LIMIT 1',
            [$conductor_id]
        );
    }

    // ------------------------------------------------------------------
    // Persistencia
    // ------------------------------------------------------------------

    public static function guardar(array $data): int
    {
        $id = (int) ($data['id_log_conductor'] ?? 0);
        $nuevo = $id === 0;
        $estado = $data['status'] ?: 'NUEVO';
        $abierto = self::ESTADOS[$estado]['abierto'] ?? true;

        // Si se reabre un caso de un conductor que ya tiene otro abierto, se reutiliza ese.
        if ($abierto && $nuevo) {
            $activo = self::caso_activo((int) $data['id_conductor']);
            if ($activo) {
                $id = (int) $activo->id_log_conductor;
                $nuevo = false;
            }
        }

        $evidencia = is_array($data['evidencia'] ?? null)
            ? $data['evidencia']
            : (json_decode($data['evidencia'] ?? '[]', true) ?: []);
        $metricas = $data['metricas'] ?: [];
        $etiquetas = $data['etiquetas'] ?? [];
        if (empty($etiquetas)) {
            $etiquetas = self::etiquetas_desde_evidencia($evidencia);
        }

        $campos = [
            'id_conductor' => (int) $data['id_conductor'],
            'last_id_trt' => (int) ($data['trt_id'] ?? 0),
            'tipo_caso' => $data['tipo_caso'] ?: null,
            'motivo' => $data['motivo'] ?: null,
            'score' => (int) ($data['score'] ?? 0),
            'llamada_tipo_id' => $data['llamada_tipo_id'] !== '' ? (int) $data['llamada_tipo_id'] : null,
            'fecha_inicio' => $data['fecha_inicio'] ?: null,
            'fecha_fin' => $data['fecha_fin'] ?: null,
            'metricas' => json_encode($metricas),
            'etiquetas_1' => json_encode($etiquetas['positivas'] ?? []),
            'etiquetas_0' => json_encode($etiquetas['negativas'] ?? []),
            'evidencia' => json_encode($evidencia),
            'analisis' => $data['analisis'] ?: null,
            'accion' => $data['accion'] ?: null,
            'respuesta' => $data['respuesta'] ?: null,
            'status' => $estado,
            'ubicacion' => $data['ubicacion'] ?: 'LIMA',
            'usuario_id' => Auth::id(),
            'fecha_reporte' => $data['fecha_reporte'] ?: null,
            'acuerdo' => $data['acuerdo'] ?: null,
            'fecha_compromiso' => $data['fecha_compromiso'] ?: null,
            'resultado' => $data['resultado'] ?: null,
            'fecha_resultado' => $abierto ? null : now()->toDateString(),
            'activo' => $abierto ? 1 : null,
            'telefonos' => json_encode($evidencia['telefonos'] ?? []),
            'rutas' => json_encode(['lista' => [], 'total' => 0]),
        ];

        if ($nuevo) {
            $campos['created_at'] = now();
            $campos['updated_at'] = now();
            $id = (int) DB::table('log_conductores')->insertGetId($campos);
            self::evento($id, 'APERTURA', $estado, 'Caso abierto con score ' . $campos['score']);
        } else {
            // Un conductor solo puede tener un caso abierto: se liberan los demas.
            if ($abierto) {
                DB::table('log_conductores')
                    ->where('id_conductor', $campos['id_conductor'])
                    ->where('id_log_conductor', '<>', $id)
                    ->where('activo', 1)
                    ->update(['activo' => null]);
            }
            $campos['updated_at'] = now();
            DB::table('log_conductores')->where('id_log_conductor', $id)->update($campos);
            self::evento($id, 'CAMBIO_ESTADO', $estado, 'Caso actualizado desde la bandeja');
        }

        // La ficha del conductor apunta a su ultimo caso (asi lo usa la vista anterior).
        if ($campos['last_id_trt'] > 0) {
            DB::table('conductores')->where('id', $campos['id_conductor'])->update(['last_id_log' => $id]);
        }

        return $id;
    }

    public static function cambiar_estado(int $id, string $estado, ?string $detalle = null, ?string $resultado = null): void
    {
        if (!isset(self::ESTADOS[$estado])) {
            return;
        }
        $abierto = self::ESTADOS[$estado]['abierto'];

        DB::table('log_conductores')->where('id_log_conductor', $id)->update([
            'status' => $estado,
            'activo' => $abierto ? 1 : null,
            'resultado' => $resultado ?: null,
            'fecha_resultado' => $abierto ? null : now()->toDateString(),
            'updated_at' => now(),
        ]);

        $tipo = $estado === 'REPORTADO' ? 'REPORTE' : ($abierto ? 'CAMBIO_ESTADO' : 'CIERRE');
        self::evento($id, $tipo, $estado, $detalle);
    }

    public static function evento(int $id_log_conductor, string $tipo, ?string $estado, ?string $detalle): void
    {
        DB::table('log_conductores_eventos')->insert([
            'id_log_conductor' => $id_log_conductor,
            'tipo' => $tipo,
            'estado' => $estado,
            'detalle' => $detalle,
            'usuario_id' => Auth::id(),
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Consultas de casos
    // ------------------------------------------------------------------

    public static function caso(int $id): ?stdClass
    {
        return DB::selectOne(
            'SELECT l.*, c.nombres AS conductor, c.last_id_log,
                    COALESCE(t.nombres, \'SIN TRT\') AS trt_nombres
             FROM log_conductores l
             INNER JOIN conductores c ON c.id = l.id_conductor
             LEFT JOIN trts t ON t.id = l.last_id_trt
             WHERE l.id_log_conductor = ?',
            [$id]
        );
    }

    public static function caso_activo(int $conductor_id): ?stdClass
    {
        return DB::selectOne(
            'SELECT id_log_conductor FROM log_conductores WHERE id_conductor = ? AND activo = 1 LIMIT 1',
            [$conductor_id]
        );
    }

    public static function abiertos_por_conductor(): array
    {
        $filas = DB::select(
            'SELECT id_conductor, id_log_conductor, status, tipo_caso, score
             FROM log_conductores WHERE activo = 1'
        );

        $mapa = [];
        foreach ($filas as $f) {
            $mapa[(int) $f->id_conductor] = $f;
        }

        return $mapa;
    }

    public static function casos(): array
    {
        $f = self::filtro();
        $sql = 'SELECT l.*, c.nombres AS conductor, c.last_id_log,
                       COALESCE(t.nombres, \'SIN TRT\') AS trt_nombres,
                       (SELECT COUNT(*) FROM log_conductores_eventos e WHERE e.id_log_conductor = l.id_log_conductor) AS eventos
                FROM log_conductores l
                INNER JOIN conductores c ON c.id = l.id_conductor
                LEFT JOIN trts t ON t.id = l.last_id_trt
                WHERE 1 = 1';
        $params = [];

        // SIN_CASO solo aplica a la bandeja de candidatos; en la lista de casos se ignora.
        if ($f->estado !== '' && $f->estado !== 'SIN_CASO') {
            $sql .= ' AND l.status = ? ';
            $params[] = $f->estado;
        }
        if ($f->tipo_caso !== '') {
            $sql .= ' AND l.tipo_caso = ? ';
            $params[] = $f->tipo_caso;
        }
        if ($f->conductor !== '') {
            if (is_numeric($f->conductor)) {
                $sql .= ' AND l.id_conductor = ? ';
                $params[] = (int) $f->conductor;
            } else {
                $sql .= ' AND c.nombres LIKE ? ';
                $params[] = '%' . strtoupper(trim($f->conductor)) . '%';
            }
        }
        if ($f->llamada_tipo_id !== '') {
            $sql .= ' AND l.llamada_tipo_id = ? ';
            $params[] = (int) $f->llamada_tipo_id;
        }
        if ($f->fecha_inicio) {
            $sql .= ' AND l.updated_at >= ? ';
            $params[] = Carbon::parse($f->fecha_inicio)->startOfDay();
        }
        if ($f->fecha_fin) {
            $sql .= ' AND l.updated_at < ? ';
            $params[] = Carbon::parse($f->fecha_fin)->startOfDay()->addDay();
        }

        $sql .= ' ORDER BY (l.activo IS NULL), FIELD(l.status, \'NUEVO\', \'EN CURSO\', \'REPORTADO\', \'CERRADO\', \'CANCELADO\'), l.updated_at DESC';

        return DB::select($sql, $params);
    }

    public static function eventos(int $id_log_conductor): array
    {
        return DB::table('log_conductores_eventos as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.usuario_id')
            ->where('e.id_log_conductor', $id_log_conductor)
            ->orderByDesc('e.id_evento')
            ->get(['e.*', 'u.name as usuario'])
            ->all();
    }

    public static function total_historico(int $conductor_id): int
    {
        return (int) DB::table('log_conductores')->where('id_conductor', $conductor_id)->count();
    }

    // ------------------------------------------------------------------
    // Presentacion
    // ------------------------------------------------------------------

    public static function etiqueta_estado(?string $estado): array
    {
        return self::ESTADOS[$estado] ?? ['nombre' => (string) $estado, 'color' => 'secondary', 'abierto' => false];
    }

    public static function etiqueta_tipo(?string $tipo): array
    {
        return self::TIPOS[$tipo] ?? ['nombre' => 'Sin clasificar', 'color' => 'secondary', 'icono' => 'bi-question'];
    }

    public static function nombre_motivo(?string $motivo): string
    {
        return self::MOTIVOS[$motivo] ?? 'Sin motivo';
    }

    public static function color_score(int $score): string
    {
        if ($score >= 80) {
            return 'danger';
        }
        if ($score >= 60) {
            return 'warning';
        }

        return 'secondary';
    }
}
