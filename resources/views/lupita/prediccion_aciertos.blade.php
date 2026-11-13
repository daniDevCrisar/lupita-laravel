@extends('layouts.app')

@section('title', 'Aciertos de la predicción de carga')

@section('content')
    @php
        $b = $aciertos;
        $motor = $b->resumen_motor;
        $fmt = fn ($min) => $min === null ? '—' : (abs($min) >= 60
            ? intdiv(abs($min), 60) . ' h ' . str_pad((string) (abs($min) % 60), 2, '0', STR_PAD_LEFT)
            : $min . ' min');
        $signo = fn ($min) => $min === null ? '—' : ($min > 0 ? '+' : ($min < 0 ? '−' : '')) . $fmt(abs($min));
        $colorError = fn ($min) => $min === null ? 'secondary'
            : (abs($min) <= 60 ? 'success' : (abs($min) <= 180 ? 'warning text-dark' : (abs($min) <= 720 ? 'info text-dark' : 'danger')));
        $coloresEvidencia = ['medido' => 'success', 'parcial' => 'warning text-dark', 'cadena' => 'danger', 'sin_datos' => 'secondary'];
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-bullseye"></i> Aciertos de la predicción de carga</h1>
            <p class="text-muted small mb-1">
                Se corre el motor en <strong>modo automático</strong> sobre las referencias del rango que ya tienen
                <em>presenta para carga</em>: el corte se pone en el <em>fin de descargue</em> del viaje anterior de la
                misma placa, y se compara contra la presentación real.
            </p>
            <p class="text-muted small mb-0">
                El error es <code>predicho − real</code>: positivo = el motor se quedó corto. La calibración y los
                promedios se aprenden sólo con datos <strong>anteriores al rango</strong>
                ({{ $b->aprendizaje_desde->format('d/m/Y') }} – {{ $b->desde->format('d/m/Y') }}), así ninguna fila ve
                su propia presentación. Las filas con un hueco real de más de 7 días o negativo se descartan
                ({{ $b->descartados }} en esta corrida): están fuera del dominio del modelo.
                Si el rango tiene más filas que el límite, se evalúan <strong>las más recientes</strong>: subí el
                límite para ver el rango completo.
                La columna <strong>Calibra con</strong> dice de quién sale el hueco: la <em>placa</em>, o el
                <em>conductor</em> cuando ese chofer concentra menos de la mitad de los viajes previos de la placa
                (misma regla que usa la calculadora y el API).
                <a href="{{ route('lupita.prediccion.presenta') }}">&larr; Volver a la calculadora</a>
            </p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.prediccion.aciertos') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ $filtros['desde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $filtros['hasta'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Placa</label>
                    <input type="text" name="placa" class="form-control" value="{{ $filtros['placa'] }}" maxlength="20">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Filas</label>
                    <select name="limite" class="form-select">
                        @foreach([10, 40, 80, 100] as $opcion)
                            <option value="{{ $opcion }}" @selected((int) $filtros['limite'] === $opcion)>{{ $opcion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Correr la tabla</button>
                    <div class="text-muted" style="font-size:.7rem">
                        @if(($calculo_ms ?? 0) > 250)
                            calculada ahora en {{ number_format(($calculo_ms ?? 0) / 1000, 1, ',', '.') }} s ·
                        @else
                            servida de caché (menos de 10 min) ·
                        @endif
                        <a href="{{ route('lupita.prediccion.aciertos', array_merge(request()->query(), ['refrescar' => 1])) }}">
                            recalcular
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white"><strong>Métricas del motor</strong></div>
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold">{{ $fmt($motor->mae_min) }} <span class="fs-6 fw-normal text-muted">MAE</span></div>
                    <div class="small text-muted">
                        n={{ $motor->n }} · mediana {{ $signo($motor->mediana_error_min) }}
                        · sesgo {{ $signo($motor->sesgo_min) }}
                        @if(($b->sin_ruta ?? 0) > 0)
                            · <span class="badge bg-dark" title="Referencias sin ruta asignada: no se predicen ni se evalúan">
                                {{ $b->sin_ruta }} sin ruta, fuera
                            </span>
                        @endif
                        @if(($b->descartados ?? 0) > 0)
                            · <span class="badge bg-secondary" title="Hueco real negativo o de más de 7 días: fuera del dominio del modelo">
                                {{ $b->descartados }} descartadas
                            </span>
                        @endif
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <span class="badge bg-success">±30m {{ $motor->pct_30 ?? '—' }}%</span>
                        <span class="badge bg-success">±1h {{ $motor->pct_60 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">±2h {{ $motor->pct_120 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">±3h {{ $motor->pct_180 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">±6h {{ $motor->pct_360 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">±12h {{ $motor->pct_720 ?? '—' }}%</span>
                        <span class="badge bg-secondary">éxito rápido–tardado {{ $motor->pct_ventana ?? '—' }}%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= PREDICCIONES GUARDADAS (historial real) ================= --}}
    @php $g = $guardadas ?? []; @endphp
    <div class="card mb-3 border-success">
        <div class="card-header bg-success text-white d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-database-check"></i> Predicciones guardadas</strong>
            <span class="small">
                tabla <code class="text-white">prediccion_resultados</code> ·
                {{ $g['guardadas'] ?? 0 }} registros pedidos en el rango
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-2">
                <div class="col-md-3">
                    <div class="text-muted small">Éxito dentro de rápido–tardado</div>
                    <div class="display-6 fw-bold text-success">{{ $g['pct_ventana'] ?? '—' }}%</div>
                    <div class="small text-muted">
                        {{ $g['dentro_ventana'] ?? 0 }} de {{ $g['n'] ?? 0 }} ya presentadas
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Éxito a ±3 h</div>
                    <div class="display-6 fw-bold">{{ $g['pct_tolerancia'] ?? '—' }}%</div>
                    <div class="small text-muted">
                        {{ $g['dentro_tolerancia'] ?? 0 }} de {{ $g['n'] ?? 0 }}
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Error mediano</div>
                    <div class="fs-3 fw-bold">
                        {{ $g['error_mediano'] === null ? '—' : (($g['error_mediano'] > 0 ? '+' : '') . $g['error_mediano']) }}
                    </div>
                    <div class="small text-muted">minutos</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">
                        Dónde se guardan los resultados
                    </div>
                    <div class="small">
                        Cada vez que la pantalla o el API predicen, queda una fila en
                        <code>prediccion_resultados</code> con las <strong>tres fechas</strong>
                        (rápido / normal / tardado), el corte, la evidencia y la confianza.
                        Cuando la referencia consigue su <em>presenta para carga</em> real, se compara
                        contra lo guardado (se toma la última predicción hecha antes de presentar) y
                        sale el porcentaje de arriba.
                    </div>
                </div>
            </div>

            @if(!empty($g['filas']))
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Ref</th>
                            <th>Placa</th>
                            <th>Corte</th>
                            <th class="text-success">Rápido</th>
                            <th class="text-primary">Predicho</th>
                            <th class="text-warning">Tardado</th>
                            <th class="text-success">Real<br><span class="fw-normal small">presenta_para_carga</span></th>
                            <th class="text-end">Error</th>
                            <th>Acierto</th>
                            <th>Origen</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($g['filas'] as $fila)
                            <tr>
                                <td><code>{{ $fila->ref }}</code></td>
                                <td class="small">{{ $fila->placa ?: '—' }}</td>
                                <td class="small">{{ $fila->corte->format('d/m H:i') }}</td>
                                <td class="small text-success">{{ $fila->rapido ? $fila->rapido->format('d/m H:i') : '—' }}</td>
                                <td class="small text-primary fw-bold">{{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}</td>
                                <td class="small text-warning">{{ $fila->tardado ? $fila->tardado->format('d/m H:i') : '—' }}</td>
                                <td class="small text-success fw-bold">
                                    {{ $fila->real ? $fila->real->format('d/m/Y H:i') : 'sin presentar' }}
                                </td>
                                <td class="small text-end">
                                    @if($fila->error_min === null)
                                        —
                                    @else
                                        <span class="badge bg-{{ abs($fila->error_min) <= 180 ? 'success' : 'danger' }}">
                                            {{ ($fila->error_min > 0 ? '+' : '') . $fila->error_min }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if($fila->en_ventana === null)
                                        <span class="badge bg-secondary">pendiente</span>
                                    @elseif($fila->en_ventana)
                                        <span class="badge bg-success">en ventana</span>
                                    @else
                                        <span class="badge bg-danger">fuera</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $fila->origen }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-muted small">
                    Todavía no hay predicciones guardadas en el rango. Se llenan solas al usar
                    <a href="{{ route('lupita.prediccion.presenta') }}">la calculadora</a> o el API.
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2">
            <div>
                <strong>Real vs predicho</strong>
                <div class="small text-muted">
                    <span class="text-success fw-bold">Real</span> = la hora que quedó registrada en
                    <code>referencias.presenta_para_carga</code>.
                    <span class="text-primary fw-bold">Predicho</span> = lo que dijo el motor para esa ref
                    (la fecha normal; al lado están la rápida y la tardada).
                </div>
            </div>
            <span class="small text-muted">
                {{ count($b->filas) }} filas · rango {{ $b->desde->format('d/m/Y') }} – {{ $b->hasta->format('d/m/Y') }}
            </span>
        </div>

        {{-- % de acierto de ESTA tabla --}}
        <div class="card-body py-2 border-bottom bg-light">
            <div class="d-flex flex-wrap align-items-center gap-4">
                <div>
                    <div class="text-muted small">% de acierto — la hora real cayó entre la rápida y la tardada</div>
                    <div class="fs-3 fw-bold text-success">
                        {{ $b->resumen_motor->pct_ventana ?? '—' }}%
                        <span class="fs-6 fw-normal text-muted">
                            {{ $b->resumen_motor->dentro_ventana ?? 0 }} de {{ $b->resumen_motor->n ?? 0 }} refs
                        </span>
                    </div>
                </div>
                <div>
                    <div class="text-muted small mb-1">Acierto por tolerancia</div>
                    <div class="d-flex flex-wrap gap-1">
                        <span class="badge bg-success">≤30 min {{ $b->resumen_motor->pct_30 ?? '—' }}%</span>
                        <span class="badge bg-success">≤1 h {{ $b->resumen_motor->pct_60 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">≤2 h {{ $b->resumen_motor->pct_120 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">≤3 h {{ $b->resumen_motor->pct_180 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">≤6 h {{ $b->resumen_motor->pct_360 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">≤12 h {{ $b->resumen_motor->pct_720 ?? '—' }}%</span>
                    </div>
                </div>
                <div class="small text-muted">
                    MAE {{ $fmt($b->resumen_motor->mae_min) }}
                    · mediana {{ $signo($b->resumen_motor->mediana_error_min) }}
                    · sesgo {{ $signo($b->resumen_motor->sesgo_min) }}
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Ref</th>
                    <th>Placa</th>
                    <th>Ancla<br><span class="fw-normal small">fin de descargue</span></th>
                    <th class="text-success">Rápido</th>
                    <th class="text-primary">Predicho</th>
                    <th class="text-warning">Tardado</th>
                    <th class="text-success">Real<br><span class="fw-normal small">presenta_para_carga</span></th>
                    <th class="text-end">Error</th>
                    <th>Calibra<br><span class="fw-normal small">con</span></th>
                    <th>Evidencia</th>
                    <th>API</th>
                </tr>
                </thead>
                <tbody>
                @forelse($b->filas as $fila)
                    @php
                        $paramsApi = array_filter([
                            'ref' => $fila->ref,
                            'ahora' => $fila->ancla->format('Y-m-d H:i'),
                            // el ancla explícita: sin esto el motor elige el viaje
                            // más reciente antes del corte, que no siempre es el
                            // que se usó en esta fila
                            'ancla' => $fila->ancla_ref,
                        ]);
                        $urlApiFila = url('/api/prediccion/presenta') . '?' . http_build_query($paramsApi);
                    @endphp
                    <tr>
                        <td><code>{{ $fila->ref }}</code>
                            <div class="text-muted" style="font-size:.7rem">← {{ $fila->ancla_ref }}</div>
                        </td>
                        <td class="small">{{ $fila->placa }}</td>
                        <td class="small">{{ $fila->ancla->format('d/m H:i') }}</td>
                        <td class="small text-success text-nowrap">
                            {{ ($fila->rapido ?? null) ? $fila->rapido->format('d/m H:i') : '—' }}
                        </td>
                        <td class="small text-primary fw-bold text-nowrap">
                            {{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}
                            @if($fila->dentro)
                                <div class="text-muted fw-normal" style="font-size:.65rem">dentro de rápido–tardado</div>
                            @endif
                        </td>
                        <td class="small text-warning text-nowrap">
                            {{ ($fila->tardado ?? null) ? $fila->tardado->format('d/m H:i') : '—' }}
                        </td>
                        <td class="small text-success fw-bold text-nowrap">
                            {{ $fila->real->format('d/m/Y H:i') }}
                        </td>
                        <td class="small text-end text-nowrap">
                            <span class="badge bg-{{ $colorError($fila->error_min) }}">{{ $signo($fila->error_min) }}</span>
                            @if($fila->predicho)
                                <div class="text-muted" style="font-size:.65rem">
                                    {{ $fila->error_min === null ? '' : (abs($fila->error_min) <= 60 ? 'acertado' : ($fila->error_min > 0 ? 'predicho antes' : 'predicho después')) }}
                                </div>
                            @endif
                        </td>
                        <td class="small text-nowrap">
                            @if(($fila->grupo ?? 'placa') === 'conductor')
                                <span class="badge bg-info text-dark" title="El conductor concentra menos de la mitad de los viajes previos de la placa: el hueco sale de su historial">
                                    conductor
                                </span>
                            @else
                                <span class="badge bg-light text-dark" title="El hueco sale del historial de la placa">
                                    placa
                                </span>
                            @endif
                            <div class="text-muted" style="font-size:.7rem">{{ $fila->pares_calibracion ?? 0 }} pares</div>
                        </td>
                        <td>
                            <span class="badge bg-{{ $coloresEvidencia[$fila->evidencia] ?? 'secondary' }}">
                                {{ $fila->evidencia }}
                            </span>
                            <div class="text-muted" style="font-size:.7rem">{{ $fila->confianza }}%</div>
                        </td>
                        <td class="small text-nowrap">
                            <a class="btn btn-sm btn-success py-0 px-1"
                               href="{{ route('lupita.prediccion.api_ref', $paramsApi) }}"
                               title="Predecir {{ $fila->ref }} con el corte {{ $fila->ancla->format('d/m H:i') }} y el ancla {{ $fila->ancla_ref }} (queda guardada)">
                                <i class="bi bi-lightning-charge"></i> Predecir
                            </a>
                            <a class="btn btn-sm btn-outline-dark py-0 px-1"
                               href="{{ $urlApiFila }}" target="_blank" rel="noopener" title="Ver el JSON del API">
                                <i class="bi bi-braces"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-3">
                            Sin referencias evaluables en el rango (prueba con otro rango, más filas o sin filtro de placa).
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('lupita.partials.prediccion_parametros')
@endsection
