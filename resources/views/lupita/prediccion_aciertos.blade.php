@extends('layouts.app')

@section('title', 'Aciertos de la predicción de carga')

@section('content')
    @php
        $b = $aciertos;
        $motor = $b->resumen_motor;
        $base = $b->resumen_base;
        $comp = $b->comparacion;
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
                misma placa, y se compara contra la presentación real. En la misma tabla se corre el método que hay hoy
                en la ficha (<em>la hora más frecuente de sus presentas pasadas</em>) para tener la línea base.
            </p>
            <p class="text-muted small mb-0">
                El error es <code>predicho − real</code>: positivo = el motor se quedó corto. La calibración y los
                promedios se aprenden sólo con datos <strong>anteriores al rango</strong>
                ({{ $b->aprendizaje_desde->format('d/m/Y') }} – {{ $b->desde->format('d/m/Y') }}), así ninguna fila ve
                su propia presentación. Las filas con un hueco real de más de 7 días o negativo se descartan
                ({{ $b->descartados }} en esta corrida): están fuera del dominio del modelo.
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
                    <input type="number" name="limite" class="form-control" value="{{ $filtros['limite'] }}" min="1" max="1000">
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Correr la tabla</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <div class="card h-100 border-primary">
                <div class="card-header bg-primary text-white"><strong>Motor de la cadena</strong></div>
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold">{{ $fmt($motor->mae_min) }} <span class="fs-6 fw-normal text-muted">MAE</span></div>
                    <div class="small text-muted">
                        n={{ $motor->n }} · mediana {{ $signo($motor->mediana_error_min) }}
                        · sesgo {{ $signo($motor->sesgo_min) }}
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <span class="badge bg-success">±30m {{ $motor->pct_30 ?? '—' }}%</span>
                        <span class="badge bg-success">±1h {{ $motor->pct_60 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">±2h {{ $motor->pct_120 ?? '—' }}%</span>
                        <span class="badge bg-info text-dark">±3h {{ $motor->pct_180 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">±6h {{ $motor->pct_360 ?? '—' }}%</span>
                        <span class="badge bg-warning text-dark">±12h {{ $motor->pct_720 ?? '—' }}%</span>
                        <span class="badge bg-secondary">ventana {{ $motor->pct_ventana ?? '—' }}%</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card h-100 border-secondary">
                <div class="card-header bg-secondary text-white"><strong>Método actual (histograma)</strong></div>
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold">{{ $fmt($base->mae_min) }} <span class="fs-6 fw-normal text-muted">MAE</span></div>
                    <div class="small text-muted">
                        n={{ $base->n }} · mediana {{ $signo($base->mediana_error_min) }}
                        · sesgo {{ $signo($base->sesgo_min) }}
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <span class="badge bg-light text-dark">±1h {{ $base->pct_60 ?? '—' }}%</span>
                        <span class="badge bg-light text-dark">±3h {{ $base->pct_180 ?? '—' }}%</span>
                        <span class="badge bg-light text-dark">±6h {{ $base->pct_360 ?? '—' }}%</span>
                        <span class="badge bg-light text-dark">±12h {{ $base->pct_720 ?? '—' }}%</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card h-100 border-{{ $comp->gana === 'motor' ? 'success' : ($comp->gana === 'base' ? 'danger' : 'secondary') }}">
                <div class="card-body py-2 text-center">
                    <div class="text-muted small">Quién gana</div>
                    <div class="fs-5 fw-bold text-uppercase">{{ $comp->gana }}</div>
                    <div class="small text-muted">por 30 min o más de MAE</div>
                    <div class="small mt-2">Δ MAE {{ $signo($comp->delta_mae_min) }}</div>
                    <div class="small">Δ ±1h {{ $comp->delta_pct_60 === null ? '—' : ($comp->delta_pct_60 > 0 ? '+' : '') . $comp->delta_pct_60 }} pts</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <strong>Referencias evaluadas</strong>
            <span class="small text-muted">
                {{ count($b->filas) }} filas · rango {{ $b->desde->format('d/m/Y') }} – {{ $b->hasta->format('d/m/Y') }}
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Ref</th>
                    <th>Placa</th>
                    <th>Ancla (fin de descargue)</th>
                    <th class="text-end">Hueco real</th>
                    <th>Motor</th>
                    <th class="text-end">Error</th>
                    <th>Real</th>
                    <th>Método actual</th>
                    <th class="text-end">Error</th>
                    <th>Evidencia</th>
                </tr>
                </thead>
                <tbody>
                @forelse($b->filas as $fila)
                    <tr>
                        <td><code>{{ $fila->ref }}</code>
                            <div class="text-muted" style="font-size:.7rem">← {{ $fila->ancla_ref }}</div>
                        </td>
                        <td class="small">{{ $fila->placa }}</td>
                        <td class="small">{{ $fila->ancla->format('d/m H:i') }}</td>
                        <td class="small text-end">{{ $fmt($fila->hueco_min) }}</td>
                        <td class="small">
                            {{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}
                            @if($fila->dentro)
                                <span class="badge bg-secondary">ventana</span>
                            @endif
                        </td>
                        <td class="small text-end">
                            <span class="badge bg-{{ $colorError($fila->error_min) }}">{{ $signo($fila->error_min) }}</span>
                        </td>
                        <td class="small">{{ $fila->real->format('d/m H:i') }}</td>
                        <td class="small text-muted">{{ $fila->base ? $fila->base->format('d/m H:i') : '—' }}</td>
                        <td class="small text-end text-muted">{{ $signo($fila->base_error_min) }}</td>
                        <td>
                            <span class="badge bg-{{ $coloresEvidencia[$fila->evidencia] ?? 'secondary' }}">
                                {{ $fila->evidencia }}
                            </span>
                            <div class="text-muted" style="font-size:.7rem">{{ $fila->confianza }}%</div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-3">
                            Sin referencias evaluables en el rango (prueba con un rango con datos o sin filtro de placa).
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('lupita.partials.prediccion_parametros')
@endsection
