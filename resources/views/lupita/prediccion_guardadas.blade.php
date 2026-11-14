@extends('layouts.app')

@section('title', 'Predicciones guardadas')

@section('content')
    @php
        $g = $resumen ?? [];
        $fmt = fn ($min) => $min === null ? '—' : (abs($min) >= 60
            ? intdiv(abs($min), 60) . ' h ' . str_pad((string) (abs($min) % 60), 2, '0', STR_PAD_LEFT)
            : $min . ' min');
        $signo = fn ($min) => $min === null ? '—' : ($min > 0 ? '+' : ($min < 0 ? '−' : '')) . $fmt(abs($min));
        $colorError = fn ($min) => $min === null ? 'secondary'
            : (abs($min) <= 60 ? 'success' : (abs($min) <= 180 ? 'warning text-dark' : (abs($min) <= 720 ? 'info text-dark' : 'danger')));
    @endphp

    @if(session('estado'))
        <div class="alert alert-success py-2">{{ session('estado') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-database-check"></i> Predicciones guardadas</h1>
            <p class="text-muted small mb-1">
                Historial de <code>prediccion_resultados</code>: cada vez que el API predice, o cuando apretás
                <strong>"Guardar estas predicciones"</strong> en la tabla de aciertos, queda una fila con las
                <strong>tres fechas</strong> (rápida / normal / tardada), el corte, la evidencia y la confianza.
                Cuando la referencia consigue su <em>presenta para carga</em> real, se compara contra lo guardado
                (se toma la última predicción hecha <strong>antes</strong> de presentar) y sale el acierto de abajo.
            </p>
            <p class="text-muted small mb-0">
                <a href="{{ route('lupita.prediccion.aciertos') }}">&larr; Tabla de aciertos</a> ·
                <a href="{{ route('lupita.prediccion.parametros.ver') }}">Parámetros del motor</a> ·
                <a href="{{ route('lupita.prediccion.presenta') }}">Calculadora</a>
            </p>
        </div>
    </div>

    {{-- filtros --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.prediccion.guardadas') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ $filtros['desde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $filtros['hasta'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Origen</label>
                    <select name="origen" class="form-select">
                        <option value="">todos</option>
                        @foreach($origenes as $origen)
                            <option value="{{ $origen }}" @selected($filtros['origen'] === $origen)>{{ $origen }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Filas</label>
                    <select name="limite" class="form-select">
                        @foreach($limites as $opcion)
                            <option value="{{ $opcion }}" @selected((int) $filtros['limite'] === $opcion)>{{ $opcion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Ver</button>
                </div>
            </form>
        </div>
    </div>

    {{-- métricas --}}
    <div class="card mb-3 border-success">
        <div class="card-header bg-success text-white d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-database-check"></i> Acierto real de lo guardado</strong>
            <span class="small">
                {{ $g['guardadas'] ?? 0 }} predicciones guardadas en el rango ·
                {{ $g['n'] ?? 0 }} ya presentadas (comparables)
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-2">
                <div class="col-md-3">
                    <div class="text-muted small">Éxito dentro de rápido–tardado</div>
                    <div class="display-6 fw-bold text-success">{{ $g['pct_ventana'] ?? '—' }}%</div>
                    <div class="small text-muted">{{ $g['dentro_ventana'] ?? 0 }} de {{ $g['n'] ?? 0 }} ya presentadas</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Éxito a ±3 h</div>
                    <div class="display-6 fw-bold">{{ $g['pct_tolerancia'] ?? '—' }}%</div>
                    <div class="small text-muted">{{ $g['dentro_tolerancia'] ?? 0 }} de {{ $g['n'] ?? 0 }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Error mediano</div>
                    <div class="fs-3 fw-bold">
                        {{ $g['error_mediano'] === null ? '—' : (($g['error_mediano'] > 0 ? '+' : '') . $g['error_mediano']) }}
                    </div>
                    <div class="small text-muted">minutos</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Para que haya más filas</div>
                    <div class="small">
                        Andá a la <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de aciertos</a>, subí las
                        filas a 300-1000 y apretá <strong>"Guardar estas predicciones"</strong>. También se guardan
                        solas cada vez que alguien predice por el API.
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
                            <th>Evidencia</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($g['filas'] as $fila)
                            <tr>
                                <td><code>{{ $fila->ref }}</code></td>
                                <td class="small">{{ $fila->placa }}</td>
                                <td class="small text-nowrap">{{ $fila->corte->format('d/m/Y H:i') }}</td>
                                <td class="small text-success text-nowrap">
                                    {{ $fila->rapido ? $fila->rapido->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-primary fw-bold text-nowrap">
                                    {{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-warning text-nowrap">
                                    {{ $fila->tardado ? $fila->tardado->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-success fw-bold text-nowrap">
                                    {{ $fila->real ? $fila->real->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-end text-nowrap">
                                    @if($fila->error_min === null)
                                        —
                                    @else
                                        <span class="badge bg-{{ $colorError($fila->error_min) }}">{{ $signo($fila->error_min) }}</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">
                                    @if($fila->en_ventana === true)
                                        <span class="badge bg-success">dentro</span>
                                    @elseif($fila->en_ventana === false)
                                        <span class="badge bg-danger">afuera</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $fila->origen }}</td>
                                <td class="small text-muted">{{ $fila->evidencia }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted mt-2">
                    Se muestran hasta {{ (int) $filtros['limite'] }} filas; el acierto de arriba se calcula sobre todas
                    las guardadas del rango. Si una ref tiene varias predicciones, vale la <strong>última hecha antes
                    de presentar</strong>: es la que el despachador vio.
                </div>
            @else
                <div class="text-muted small">
                    Todavía no hay predicciones guardadas en el rango. Se llenan solas al usar
                    <a href="{{ route('lupita.prediccion.presenta') }}">la calculadora</a> o el API, o apretando
                    <strong>"Guardar estas predicciones"</strong> en la
                    <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de aciertos</a>.
                </div>
            @endif
        </div>
    </div>
@endsection
