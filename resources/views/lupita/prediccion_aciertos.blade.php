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

    {{-- avisos de las acciones (guardar predicciones, etc.): sin esto el POST
         volvía a la misma pantalla y parecía que no hacía nada --}}
    @if(session('estado'))
        <div class="alert alert-success py-2">{{ session('estado') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

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
                límite (hasta 1000) para ver el rango completo y calibrar con muestra suficiente.
                La columna <strong>Calibra con</strong> dice de quién sale el hueco: la <em>placa</em>, o el
                <em>conductor</em> cuando ese chofer tiene al menos {{ (int) ($b->parametros->min_pares_entidad ?? 3) }}
                pares propios y al menos la mitad de los pares de la placa
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
                    <label class="form-label" title="Cuántas refs del rango se evalúan (las más recientes). No es cuántas se muestran.">Refs a evaluar</label>
                    <select name="limite" class="form-select">
                        @foreach(\App\Http\Controllers\PrediccionController::FILAS_ACIERTOS as $opcion)
                            <option value="{{ $opcion }}" @selected((int) $filtros['limite'] === $opcion)>
                                {{ $opcion === 0 ? 'Todas (' . \App\Prediccion\Servicio::TOPE_EVALUACION . ')' : $opcion }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" title="Filas donde el camión tardó más de N horas en presentarse: se ven pero no cuentan en las métricas">Tope de horizonte</label>
                    <select name="horizonte_max_h" class="form-select">
                        @foreach(\App\Http\Controllers\PrediccionController::HORIZONTES_ACIERTOS as $opcion)
                            <option value="{{ $opcion }}" @selected((int) ($filtros['horizonte_max_h'] ?? 0) === $opcion)>
                                {{ $opcion === 0 ? 'sin tope' : $opcion . ' h' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="solo_dentro_tope" value="1"
                               id="solo_dentro_tope" @checked($solo_dentro_tope ?? false)>
                        <label class="form-check-label small" for="solo_dentro_tope">
                            No mostrar las que están fuera del tope
                        </label>
                    </div>
                    <button class="btn btn-primary w-100 mt-2"><i class="bi bi-arrow-repeat"></i> Correr la tabla</button>
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

            {{-- Guardar las predicciones de ESTA corrida en prediccion_resultados --}}
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                <form method="POST" action="{{ route('lupita.prediccion.aciertos.guardar') }}" class="d-flex align-items-center gap-2">
                    @csrf
                    <input type="hidden" name="fecha_inicio" value="{{ $filtros['desde'] }}">
                    <input type="hidden" name="fecha_fin" value="{{ $filtros['hasta'] }}">
                    <input type="hidden" name="placa" value="{{ $filtros['placa'] }}">
                    <input type="hidden" name="limite" value="{{ $filtros['limite'] }}">
                    <input type="hidden" name="horizonte_max_h" value="{{ $filtros['horizonte_max_h'] ?? 0 }}">
                    <input type="hidden" name="solo_dentro_tope" value="{{ ($solo_dentro_tope ?? false) ? 1 : 0 }}">
                    <button class="btn btn-sm btn-success">
                        <i class="bi bi-database-add"></i> Guardar estas predicciones
                    </button>
                </form>
                <div class="text-muted small">
                    guarda lo que el motor dijo para cada ref (con el corte que usa la tabla) en
                    <code>prediccion_resultados</code>, para comparar después contra la hora real.
                    Se puede apretar varias veces: no duplica.
                    @if(($guardadas['guardadas'] ?? 0) > 0)
                        <span class="badge bg-secondary">ya hay {{ $guardadas['guardadas'] }} guardadas en este rango</span>
                    @else
                        <span class="badge bg-warning text-dark">no hay nada guardado en este rango</span>
                    @endif
                </div>
            </div>
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

                    {{-- cuarta fecha: la normal corregida por el sesgo de su clase --}}
                    @php $probable = $b->resumen_probable ?? null; @endphp
                    <div class="border-top mt-2 pt-2 small">
                        <span class="fw-semibold">Cuarta fecha (<code>presenta_probable</code>):</span>
                        @if(!($b->sesgo_activo ?? false))
                            <span class="text-muted">apagada — la cuarta fecha es igual a la normal.</span>
                            <a href="{{ route('lupita.prediccion.parametros.ver') }}">medir el sesgo por clase</a>
                        @else
                            normal MAE <strong>{{ $fmt($motor->mae_min) }}</strong>
                            · ±3h <strong>{{ $motor->pct_180 ?? '—' }}%</strong>
                            contra
                            probable MAE <strong class="text-info">{{ $fmt($probable->mae_min ?? null) }}</strong>
                            · ±3h <strong class="text-info">{{ $probable->pct_180 ?? '—' }}%</strong>
                            @if(($probable->mae_min ?? null) !== null && ($motor->mae_min ?? null) !== null)
                                @php $mejora = (int) $motor->mae_min - (int) $probable->mae_min; @endphp
                                <span class="badge bg-{{ $mejora > 0 ? 'success' : ($mejora < 0 ? 'danger' : 'secondary') }}">
                                    {{ $mejora > 0 ? 'mejora ' . $mejora : ($mejora < 0 ? 'empeora ' . abs($mejora) : 'empata') }} min
                                </span>
                            @endif

                            {{-- quinta fecha: el consenso (promedio de rápida, normal y cuarta) --}}
                            @php $consenso = $b->resumen_consenso ?? null; @endphp
                            @if(($consenso->mae_min ?? null) !== null)
                                <div class="mt-1">
                                    <span class="fw-semibold">Consenso (<code>presenta_consenso</code>):</span>
                                    MAE <strong class="text-secondary">{{ $fmt($consenso->mae_min) }}</strong>
                                    · ±1h <strong>{{ $consenso->pct_60 ?? '—' }}%</strong>
                                    · ±3h <strong>{{ $consenso->pct_180 ?? '—' }}%</strong>
                                    <span class="text-muted">(promedio de la rápida, la normal y la cuarta)</span>
                                </div>
                            @endif

                            {{-- carrera de las seis: en muchos viajes gana la rápida o la tardada --}}
                            @php $carrera = $b->carrera ?? null; @endphp
                            @if($carrera !== null && ($carrera->n ?? 0) > 0)
                                <div class="mt-1">
                                    <span class="text-muted">más cerca de las seis ({{ $carrera->n }} filas):</span>
                                    <span class="badge bg-success">rápido {{ $carrera->pct_rapido ?? '—' }}% ({{ $carrera->rapido }})</span>
                                    <span class="badge bg-primary">normal {{ $carrera->pct_normal ?? '—' }}% ({{ $carrera->normal }})</span>
                                    <span class="badge bg-warning text-dark">tardado {{ $carrera->pct_tardado ?? '—' }}% ({{ $carrera->tardado }})</span>
                                    <span class="badge bg-info text-dark">probable {{ $carrera->pct_probable ?? '—' }}% ({{ $carrera->probable }})</span>
                                    <span class="badge bg-secondary">consenso {{ $carrera->pct_consenso ?? '—' }}% ({{ $carrera->consenso }})</span>
                                    <span class="badge bg-danger">perfecta {{ $carrera->pct_perfecta ?? '—' }}% ({{ $carrera->perfecta }})</span>
                                </div>
                            @endif
                        @endif
                    </div>

                    {{-- SEXTA fecha: el escenario perfecto. Se muestra siempre, tenga el
                         sesgo por clase prendido o apagado: es otra cuenta, no una banda. --}}
                    @php $perfecta = $b->resumen_perfecta ?? null; @endphp
                    @if(($perfecta->mae_min ?? null) !== null)
                        <div class="border-top mt-2 pt-2 small">
                            <span class="fw-semibold">Sexta fecha (<code>presenta_perfecta</code>):</span>
                            MAE <strong class="text-danger">{{ $fmt($perfecta->mae_min) }}</strong>
                            · ±1h <strong class="text-danger">{{ $perfecta->pct_60 ?? '—' }}%</strong>
                            · ±3h <strong>{{ $perfecta->pct_180 ?? '—' }}%</strong>
                            · ±6h <strong>{{ $perfecta->pct_360 ?? '—' }}%</strong>
                            · sesgo <strong>{{ $signo($perfecta->sesgo_min ?? null) }}</strong>
                            @if(($motor->mae_min ?? null) !== null)
                                @php $mejoraPerfecta = (int) $motor->mae_min - (int) $perfecta->mae_min; @endphp
                                <span class="badge bg-{{ $mejoraPerfecta > 0 ? 'success' : ($mejoraPerfecta < 0 ? 'danger' : 'secondary') }}">
                                    {{ $mejoraPerfecta > 0 ? 'mejora ' . $mejoraPerfecta : ($mejoraPerfecta < 0 ? 'empeora ' . abs($mejoraPerfecta) : 'empata') }} min de MAE contra la normal
                                </span>
                            @endif
                            <div class="text-muted">
                                viaje y vuelta con el p25 de la ruta medido, sin espera ni descanso del conductor,
                                sin franjas inactivas y con el ruteo en vía libre. Es un PISO optimista: se equivoca
                                para el lado temprano (sesgo negativo), no reemplaza a la normal.
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- El historial de predicciones guardadas tiene su propia pantalla --}}
    <div class="text-end mb-3">
        <a class="btn btn-sm btn-outline-success" href="{{ route('lupita.prediccion.guardadas') }}">
            <i class="bi bi-database-check"></i> Ver las predicciones guardadas
        </a>
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
                {{-- la tabla dibuja como máximo 100 filas --}}
                @if(($filas_candidatas ?? 0) > count($filas_tabla ?? []))
                    <span class="badge bg-light text-dark">
                        mostrando {{ count($filas_tabla ?? []) }} de {{ $filas_candidatas }}
                    </span>
                @endif
                @if(($b->horizonte_max_h ?? 0) > 0)
                    · <span class="badge bg-warning text-dark">
                        {{ $b->fuera_tope }} de {{ count($b->filas) }} fuera del tope de {{ $b->horizonte_max_h }} h
                    </span>
                @endif
            </span>
        </div>

        @if(($b->horizonte_max_h ?? 0) > 0)
            <div class="px-3 pt-2">
                <div class="alert alert-warning py-2 mb-0 small">
                    <strong>Tope de horizonte: {{ $b->horizonte_max_h }} h.</strong>
                    {{ $b->fuera_tope }} fila(s) quedan <strong>fuera de las métricas</strong> (se ven en la tabla con
                    la etiqueta <span class="badge bg-secondary">fuera del tope</span>): son casos donde el camión tardó
                    más que eso en volver a presentarse y el motor no tiene con qué acertar.
                    Medido: con horizonte de hasta 6 h el MAE es 338 min y ±3 h 57 %; pasando 24 h, ±3 h 6 %.
                    @if(($b->resumen_motor->n ?? 0) > 0)
                        Con el tope, las métricas se calculan sobre
                        <strong>{{ $b->resumen_motor->n }}</strong> filas.
                    @else
                        <strong>Ninguna fila entra en las métricas</strong> con ese tope: subilo o ampliá el rango.
                    @endif
                </div>
            </div>
        @endif

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
                    <th class="text-secondary">Consenso</th>
                    <th class="text-danger">Perfecta<br><span class="fw-normal small">6.ª fecha</span></th>
                    <th class="text-primary">Predicho</th>
                    <th class="text-info">Base ajustada<br><span class="fw-normal small">4.ª fecha</span></th>
                    <th class="text-warning">Tardado</th>
                    <th class="text-success">Real<br><span class="fw-normal small">presenta_para_carga</span></th>
                    <th class="text-end">Error</th>
                    <th>Calibra<br><span class="fw-normal small">con</span></th>
                    <th>Evidencia</th>
                    <th>API</th>
                </tr>
                </thead>
                <tbody>
                @forelse($filas_tabla as $fila)
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
                    <tr class="{{ ($fila->fuera_tope ?? false) ? 'table-secondary' : '' }}">
                        <td><code>{{ $fila->ref }}</code>
                            <div class="text-muted" style="font-size:.7rem">← {{ $fila->ancla_ref }}</div>
                            @if($fila->fuera_tope ?? false)
                                <span class="badge bg-secondary" title="Queda fuera de las métricas por el tope de horizonte">
                                    fuera del tope
                                </span>
                            @endif
                        </td>
                        <td class="small">{{ $fila->placa }}</td>
                        <td class="small">
                            {{ $fila->ancla->format('d/m H:i') }}
                            <div class="text-muted" style="font-size:.7rem">
                                horizonte {{ \App\Prediccion\Tiempo::hhmm((int) ($fila->horizonte_min ?? 0)) }}
                            </div>
                        </td>
                        <td class="small text-success text-nowrap">
                            {{ ($fila->rapido ?? null) ? $fila->rapido->format('d/m H:i') : '—' }}
                        </td>
                        {{-- 5.ª fecha: el promedio de rápida, normal y base ajustada --}}
                        <td class="small text-secondary text-nowrap">
                            {{ ($fila->consenso ?? null) ? $fila->consenso->format('d/m H:i') : '—' }}
                            @if(($fila->error_consenso_min ?? null) !== null)
                                <span class="badge bg-{{ $colorError($fila->error_consenso_min) }}">
                                    {{ $signo($fila->error_consenso_min) }}
                                </span>
                            @endif
                        </td>
                        {{-- 6.ª fecha: p25 de la ruta, sin descanso, sin franjas y en vía libre --}}
                        <td class="small text-danger text-nowrap">
                            {{ ($fila->perfecta ?? null) ? $fila->perfecta->format('d/m H:i') : '—' }}
                            @if(($fila->error_perfecta_min ?? null) !== null)
                                <span class="badge bg-{{ $colorError($fila->error_perfecta_min) }}">
                                    {{ $signo($fila->error_perfecta_min) }}
                                </span>
                            @endif
                        </td>
                        <td class="small text-primary fw-bold text-nowrap">
                            {{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}
                            @if($fila->dentro)
                                <div class="text-muted fw-normal" style="font-size:.65rem">dentro de rápido–tardado</div>
                            @endif
                        </td>
                        {{-- 4.ª fecha: la normal corregida por el sesgo medido de su clase --}}
                        <td class="small text-info text-nowrap">
                            @if(($fila->probable ?? null) === null)
                                <span class="text-muted">—</span>
                            @else
                                <span class="{{ ($fila->sesgo_min ?? 0) !== 0 ? 'fw-bold' : '' }}">
                                    {{ $fila->probable->format('d/m H:i') }}
                                </span>
                                @if(($fila->sesgo_min ?? 0) !== 0)
                                    <div class="text-muted" style="font-size:.65rem">
                                        {{ ($fila->sesgo_min > 0 ? '−' : '+') . abs((int) $fila->sesgo_min) }} min
                                        · {{ $fila->sesgo_clase }}
                                    </div>
                                @else
                                    <div class="text-muted" style="font-size:.65rem">igual a la normal</div>
                                @endif
                                @if($fila->error_probable_min !== null)
                                    <span class="badge bg-{{ $colorError($fila->error_probable_min) }}">
                                        {{ $signo($fila->error_probable_min) }}
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td class="small text-warning text-nowrap">
                            {{ ($fila->tardado ?? null) ? $fila->tardado->format('d/m H:i') : '—' }}
                        </td>
                        <td class="small text-success fw-bold text-nowrap">
                            {{ $fila->real->format('d/m/Y H:i') }}
                            @php
                                // cuál de las CUATRO quedó más cerca de la real en ESTA fila
                                $masCerca = \App\Prediccion\Aciertos::mas_cerca($fila);
                            @endphp
                            @if($masCerca !== null)
                                <div class="text-muted fw-normal" style="font-size:.65rem">
                                    más cerca: {{ $masCerca->nombre }} ({{ $masCerca->abs_min }} min)
                                    @if($masCerca->empates !== [])
                                        · empata
                                        {{ implode(' y ', array_map(
                                            fn ($clave) => \App\Prediccion\Aciertos::FECHAS[$clave] ?? $clave,
                                            $masCerca->empates)) }}
                                    @endif
                                </div>
                            @endif
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
                            @if($fila->cerca ?? false)
                                <span class="badge bg-info text-dark" style="font-size:.6rem"
                                      title="descargó en el mismo local o distrito de la planta donde carga{{ !empty($fila->cerca_regimen) ? ' (régimen: ' . $fila->cerca_regimen . ')' : '' }}">
                                    cerca
                                </span>
                            @endif
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

    {{-- Los parámetros del motor viven en su propia pantalla: acá sólo el acceso --}}
    <div class="text-end mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('lupita.prediccion.parametros.ver') }}">
            <i class="bi bi-sliders"></i> Parámetros del motor y calibración
        </a>
    </div>
@endsection
