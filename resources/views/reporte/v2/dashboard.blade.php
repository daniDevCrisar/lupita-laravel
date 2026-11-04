@extends($layout ?? 'layouts.app')
{{-- $layout lo sobrescribe el controller al descargar el dashboard como .html
     (layouts.export): misma vista, sin navbar y con estilos propios. --}}

@section('title', 'Dashboard VAPI')

@php
    $t = $kpis;
    $p = $periodo;
    $v = $variacion;

    // la descarga .html usa el layout claro estilo todo1: remapea el tema oscuro
    $es_export = ($layout ?? null) === 'layouts.export';

    $titulo_periodo = $p->dias === 1
        ? $llamadas->format_fecha($p->desde, 'd/m/Y') . ' ' . $llamadas->get_fecha_day_name($p->desde)
        : 'Del ' . $llamadas->format_fecha($p->desde, 'd/m/Y') . ' al ' . $llamadas->format_fecha($p->hasta, 'd/m/Y');

    // filtros que se conservan al navegar entre reportes
    $params_base = array_filter([
        'fecha_inicio' => $p->desde,
        'fecha_fin' => $p->hasta,
        'llamada_tipo_id' => $filtros->llamada_tipo_id,
        'trt' => $filtros->trt_id,
    ], fn($v) => $v !== null && $v !== '');

    // la descarga conserva tambien el minimo de llamadas de los rankings
    $params_descarga = $params_base + ['min_llamadas' => $min_llamadas];

    // fila de KPIs con su variacion contra el periodo anterior
    // [etiqueta, valor, icono, color icono, variacion, subtexto, menor_es_mejor]
    $tarjetas = [
        ['LLAMADAS', number_format($t->total), 'bi-telephone-fill', 'text-info', $v->total, 'vs ' . $anterior->total . ' previas', false],
        ['EXITOSAS', number_format($t->exitosas), 'bi-check-circle-fill', 'text-success', $v->exitosas, $t->tasa_exito . '% del total', false],
        ['TASA DE ÉXITO', $t->tasa_exito . '%', 'bi-graph-up-arrow', 'text-success', $v->tasa_exito, 'puntos vs periodo previo', false],
        ['COSTO TOTAL', '$' . number_format((float) $t->costo, 2), 'bi-cash-coin', 'text-warning', $v->costo, 'exitosas $' . number_format((float) $t->costo_exitosa, 2), true],
        ['COSTO POR CONFIRMACIÓN', '$' . number_format((float) $t->costo_confirmacion, 3), 'bi-piggy-bank', 'text-warning', $v->costo_confirmacion, 'cada llamada exitosa', true],
        ['DURACIÓN PROM. EXITOSA', $llamadas::audio_duracion_format($t->duracion_prom_exitosa) ?: '0 seg', 'bi-stopwatch', 'text-info', null, 'fallida sin buzón ' . ($t->duracion_prom_fallida ?: 0) . ' seg', true],
    ];

    // serie temporal (el controller ya la agrupa por dia, semana o mes)
    $serie_labels = $serie['labels'];
    $serie_total = $serie['total'];
    $serie_exitosas = $serie['exitosas'];
    $serie_tasa = $serie['tasa'];
    $serie_detalle = $serie['granularidad'] === 'dia'
        ? 'día a día'
        : ($serie['granularidad'] === 'semana' ? 'agrupado por semana' : 'agrupado por mes');

    // horas y ventana dorada
    $horas_labels = [];
    $horas_total = [];
    $horas_tasa = [];
    $horas_colores = [];
    for ($h = 0; $h < 24; $h++) {
        $hora = $por_hora[$h];
        $horas_labels[] = str_pad($h, 2, '0', STR_PAD_LEFT);
        $horas_total[] = $hora->total;
        // sin llamadas en la hora: null para no dibujar un 0% enganoso
        $horas_tasa[] = $hora->total ? $hora->tasa_exito : null;
        $en_ventana = $ventana->inicio !== null && $ventana->inicio <= $ventana->fin
            ? ($h >= $ventana->inicio && $h <= $ventana->fin)
            : ($ventana->inicio !== null && ($h >= $ventana->inicio || $h <= $ventana->fin));
        $horas_colores[] = $en_ventana ? '#ffc107' : 'rgba(13,110,253,.55)';
    }

    // tipos con datos para el donut (el id desambigua colores parecidos)
    $tipos_datos = array_values(array_filter($tipos, fn($x) => $x->total > 0));
    $donut_labels = array_map(fn($x) => '#' . $x->id . ' ' . $x->nombre, $tipos_datos);
    $donut_data = array_map(fn($x) => $x->total, $tipos_datos);
    // paleta fija: los colores del catalogo repiten verdes y el donut se confunde
    $paleta_donut = ['#0d6efd', '#20c997', '#ffc107', '#fd7e14', '#6f42c1', '#e83e8c', '#17a2b8'];
    $donut_colores = [];
    foreach (array_keys($tipos_datos) as $i) {
        $donut_colores[] = $paleta_donut[$i % count($paleta_donut)];
    }

    // dias de semana (lunes a domingo); null si el dia no tuvo llamadas
    $dow_labels = [];
    $dow_valores = [];
    $dow_totales = [];
    $dow_colores = [];
    foreach ([2, 3, 4, 5, 6, 7, 1] as $dow) {
        $d = $por_dia_semana[$dow];
        $dow_labels[] = $d->nombre;
        $dow_valores[] = $d->total ? $d->tasa_exito : null;
        $dow_totales[] = $d->total;
        $dow_colores[] = !$d->total
            ? ($es_export ? 'rgba(0,0,0,.08)' : 'rgba(255,255,255,.12)')
            : ($d->tasa_exito >= 70 ? '#2e7d32' : ($d->tasa_exito >= 45 ? '#ff8f00' : '#c62828'));
    }

    // etiquetas negativas / positivas del periodo (cada grupo con su escala)
    $etiquetas_negativas = array_filter($etiquetas, fn($e) => $e->tipo === 0 && $e->grupo !== 3 && $e->total > 0);
    uasort($etiquetas_negativas, fn($a, $b) => $b->total <=> $a->total);
    $etiquetas_positivas = array_filter($etiquetas, fn($e) => $e->tipo === 1 && $e->total > 0);
    uasort($etiquetas_positivas, fn($a, $b) => $b->total <=> $a->total);

    // array_filter conserva las claves string: array_values evita que el spread
    // se interprete como argumentos nombrados de max().
    $volumen_negativas = array_map(fn($e) => $e->total, array_values($etiquetas_negativas));
    $volumen_positivas = array_map(fn($e) => $e->total, array_values($etiquetas_positivas));
    $max_negativa = max(array_merge([1], $volumen_negativas));
    $max_positiva = max(array_merge([1], $volumen_positivas));
    $total_negativas = array_sum($volumen_negativas);
    $total_positivas = array_sum($volumen_positivas);

    // costo por confirmación por transportista (top 8 con confirmaciones)
    $trts_cpc = array_values(array_filter($trts, fn($x) => $x->exitosas > 0));
    usort($trts_cpc, fn($a, $b) => $a->costo_confirmacion <=> $b->costo_confirmacion);
    $trts_cpc = array_slice($trts_cpc, 0, 8);

    // pizza "por qué fallan" con la clasificacion exclusiva estilo todo1
    $fallos_pct = $t->total ? round($fallos->fallidas / $t->total * 100) : 0;
    $pizza_labels = ['Fallo de contacto', 'Conductor no coopera', 'Errores técnicos', 'Otros'];
    $pizza_fallos = [$fallos->f_contacto, $fallos->f_no_coopera, $fallos->f_tecnicos, $fallos->f_otros];
    $pizza_colores = ['#F2A007', '#E04B59', '#10CBF0', '#0F71F2'];
@endphp

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h3 fw-bold mb-1">
                <i class="bi bi-speedometer2 text-info me-2"></i>Dashboard VAPI
            </h1>
            <span class="badge bg-dark border border-secondary p-2">
                <i class="bi bi-calendar-range me-2"></i>{{ $titulo_periodo }} · {{ $p->dias }} día(s)
            </span>
            @if($filtros->llamada_tipo_id !== null)
                <span class="badge bg-primary p-2">Tipo #{{ $filtros->llamada_tipo_id }}</span>
            @endif
            @if($filtros->trt_id !== null)
                <span class="badge bg-info text-dark p-2">TRT #{{ $filtros->trt_id }}</span>
            @endif
        </div>
        <div class="d-flex gap-2 flex-wrap no-print">
            <a href="{{ route('lupita.reportes.reporte', $params_base) }}" class="btn btn-outline-info btn-sm">
                <i class="bi bi-file-earmark-bar-graph"></i> Reporte completo
            </a>
            <a href="{{ route('lupita.reportes.progreso', $params_base) }}" class="btn btn-outline-info btn-sm">
                <i class="bi bi-graph-up-arrow"></i> Progreso semana/mes
            </a>
            <a href="{{ route('lupita.reportes.dashboard.html', $params_descarga) }}" class="btn btn-success btn-sm">
                <i class="bi bi-download"></i> Descargar HTML
            </a>
            <a href="{{ route('lupita.reportes.dia', $params_base) }}" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-text"></i> Reporte del día (correo)
            </a>
            <a href="{{ route('lupita.reportes.rango', $params_base) }}" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Reporte del rango (correo)
            </a>
        </div>
    </div>

    {{-- ============ FILTROS ============ --}}
    <form method="GET" action="{{ route('lupita.reportes.dashboard') }}" class="mb-4 no-print">
        <fieldset class="border border-secondary rounded p-3">
            <legend class="float-none w-auto px-2 fs-6 text-white-50">Filtros</legend>
            <div class="row g-3">
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="fecha_inicio">Desde</label>
                    <input type="date" id="fecha_inicio" name="fecha_inicio" value="{{ $p->desde }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="fecha_fin">Hasta</label>
                    <input type="date" id="fecha_fin" name="fecha_fin" value="{{ $p->hasta }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="llamada_tipo_id">Tipo de llamada</label>
                    <select id="llamada_tipo_id" name="llamada_tipo_id" class="form-select form-select-sm">
                        <option value="">Todos los tipos</option>
                        @foreach($catalogo_tipos as $tipo)
                            <option value="{{ $tipo->id }}" @selected((string) $filtros->llamada_tipo_id === (string) $tipo->id)>
                                {{ $tipo->emoji }} {{ $tipo->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="trt">Transportista</label>
                    <select id="trt" name="trt" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach($catalogo_trts as $trt)
                            <option value="{{ $trt->id }}" @selected((string) $filtros->trt_id === (string) $trt->id)>
                                {{ $trt->nombres }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small" for="min_llamadas">Mín. llam.</label>
                    <input type="number" id="min_llamadas" name="min_llamadas" min="1" value="{{ $min_llamadas }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-3">
                @php
                    $accesos = [
                        'Hoy' => [date('Y-m-d'), date('Y-m-d')],
                        'Ayer' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                        'Últimos 7 días' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
                        'Últimos 30 días' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
                        'Este mes' => [date('Y-m-01'), date('Y-m-d')],
                        'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                    ];
                @endphp
                @foreach($accesos as $label => $rango)
                    @php
                        $query = array_filter([
                            'fecha_inicio' => $rango[0],
                            'fecha_fin' => $rango[1],
                            'llamada_tipo_id' => $filtros->llamada_tipo_id,
                            'trt' => $filtros->trt_id,
                            'min_llamadas' => $min_llamadas,
                        ], fn($v) => $v !== null && $v !== '');
                    @endphp
                    <a href="{{ route('lupita.reportes.dashboard', $query) }}"
                       class="btn btn-sm {{ $p->desde === $rango[0] && $p->hasta === $rango[1] ? 'btn-info' : 'btn-outline-light' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </fieldset>
    </form>

    @if($t->total == 0)
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
            No hay llamadas en el periodo seleccionado.</div>
    @else

        {{-- ============ KPI PRINCIPALES ============ --}}
        <div class="row g-3 mb-3">
            @foreach($tarjetas as $card)
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card bg-dark border-secondary h-100">
                        <div class="card-body py-3 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span class="text-white-50 text-uppercase small lh-sm d-block" style="min-height:2.4em;">{{ $card[0] }}</span>
                                <i class="bi {{ $card[2] }} {{ $card[3] }} fs-5"></i>
                            </div>
                            <div class="fs-4 fw-bold text-white mt-1">{{ $card[1] }}</div>
                            <div class="small text-white-50 mt-auto pt-2">
                                @if($card[4] !== null)
                                    @php
                                        // en costo y duracion bajar es bueno: el color se invierte
                                        $sube = $card[4] > 0;
                                        $bueno = !empty($card[6]) ? !$sube : $sube;
                                        $clase = $card[4] == 0 ? 'text-white-50' : ($bueno ? 'text-success' : 'text-danger');
                                    @endphp
                                    <span class="{{ $clase }} fw-bold">
                                        <i class="bi bi-{{ $sube ? 'arrow-up-right' : 'arrow-down-right' }}"></i>
                                        {{ $sube ? '+' : '' }}{{ $card[4] }}%
                                    </span><br>
                                @endif
                                {{ $card[5] }}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ============ HALLAZGOS ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card bg-warning bg-opacity-10 border-warning h-100">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-warning text-uppercase small fw-bold"><i class="bi bi-brightness-high"></i> Hora dorada</span>
                            @if($ventana->rango)
                                <span class="badge bg-warning text-dark">{{ $ventana->rango }}</span>
                            @endif
                        </div>
                        @if($ventana->rango)
                            <div class="fs-3 fw-bold text-white mt-1">{{ $ventana->tasa }}%<span class="fs-6 text-white-50"> de éxito</span></div>
                            <small class="text-white-50">
                                {{ $ventana->exitosas }} exitosas de {{ $ventana->total }} llamadas en la mejor franja de 3 horas.
                                Fuera de ella el promedio es {{ $t->tasa_exito }}%.
                            </small>
                        @else
                            <p class="text-white-50 small mb-0 mt-2">Sin volumen suficiente por hora para calcular la franja.</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-danger bg-opacity-10 border-danger h-100">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-danger text-uppercase small fw-bold"><i class="bi bi-voicemail"></i> Fuga en buzón</span>
                            <span class="badge bg-danger">{{ $t->buzon_de_voz }} llamadas</span>
                        </div>
                        <div class="fs-3 fw-bold text-white mt-1">{{ $t->buzon_tiempo_pct }}%<span class="fs-6 text-white-50"> del tiempo</span></div>
                        <small class="text-white-50">
                            Se consumieron {{ $llamadas::audio_duracion_format($t->buzon_duracion) ?: '0 seg' }} en buzones
                            y costaron ${{ number_format((float) $t->buzon_costo, 2) }} sin producir confirmaciones.
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info bg-opacity-10 border-info h-100">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-info text-uppercase small fw-bold"><i class="bi bi-robot"></i> Acuerdo IA / humano</span>
                            <span class="badge bg-info text-dark">{{ $ia->evaluadas }} evaluadas</span>
                        </div>
                        <div class="fs-3 fw-bold text-white mt-1">{{ $ia->acuerdo }}%</div>
                        <small class="text-white-50">
                            {{ $ia->solo_ia }} llamadas la IA las dio por exitosas y el humano no; {{ $ia->solo_humano }} al revés.
                            Sesgo optimista de la IA: {{ $ia->ia_optimista }}%.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ SERIE + DONUT ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-3"><i class="bi bi-graph-up text-info me-2"></i>Evolución del periodo
                            <span class="text-white-50 small fw-normal">· {{ $serie_detalle }}</span></h6>
                        <div style="height:300px;"><canvas id="chart_serie"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-3"><i class="bi bi-pie-chart text-success me-2"></i>Llamadas por tipo</h6>
                        <div style="height:300px;"><canvas id="chart_tipos"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ HORAS + DIAS ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-3">
                            <i class="bi bi-clock-history text-warning me-2"></i>Llamadas por hora
                            @if($ventana->rango)
                                <span class="badge bg-warning text-dark ms-2">dorada {{ $ventana->rango }}</span>
                            @endif
                        </h6>
                        <div style="height:280px;"><canvas id="chart_horas"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-3"><i class="bi bi-calendar-week text-primary me-2"></i>% éxito por día</h6>
                        <div style="height:280px;"><canvas id="chart_dow"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ HEATMAP ============ --}}
        @include('reporte.v2.partials.heatmap', ['heatmap' => $heatmap, 'claro' => $es_export])

        {{-- ============ TIPO + COSTO POR CONFIRMACION ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-3"><i class="bi bi-table text-info me-2"></i>Detalle por tipo de llamada</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="thead-lupita">
                                <tr>
                                    <th>Ámbito</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Exitosas</th>
                                    <th class="text-center text-nowrap">% Éxito</th>
                                    <th class="text-center text-nowrap">Conduct.</th>
                                    <th class="text-end">Costo</th>
                                    <th class="text-end text-nowrap">$/Confirm.</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="table-primary">
                                    <td class="fw-bold">RESUMEN · todas</td>
                                    <td class="text-center">{{ $t->total }}</td>
                                    <td class="text-center text-success fw-bold">{{ $t->exitosas }}</td>
                                    <td class="text-center"><span class="badge bg-{{ $t->tasa_exito >= 70 ? 'success' : ($t->tasa_exito >= 45 ? 'warning' : 'danger') }}">{{ $t->tasa_exito }}%</span></td>
                                    <td class="text-center">{{ $t->conductores }}</td>
                                    <td class="text-end">${{ number_format((float) $t->costo, 2) }}</td>
                                    <td class="text-end text-warning">${{ number_format((float) $t->costo_confirmacion, 3) }}</td>
                                </tr>
                                @foreach($tipos as $tipo)
                                    <tr>
                                        <td>{{ $tipo->emoji }} #{{ $tipo->id }} · {{ $tipo->nombre }}</td>
                                        <td class="text-center">{{ $tipo->total }}</td>
                                        <td class="text-center text-success">{{ $tipo->exitosas }}</td>
                                        <td class="text-center">
                                            @if($tipo->total)
                                                <span class="badge bg-{{ $tipo->tasa_exito >= 70 ? 'success' : ($tipo->tasa_exito >= 45 ? 'warning' : 'danger') }}">{{ $tipo->tasa_exito }}%</span>
                                            @else
                                                <span class="text-white-50">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $tipo->conductores }}</td>
                                        <td class="text-end text-white-50">${{ number_format((float) $tipo->costo, 2) }}</td>
                                        <td class="text-end text-warning">
                                            {{ $tipo->exitosas ? '$' . number_format((float) $tipo->costo_confirmacion, 3) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-light mb-1"><i class="bi bi-piggy-bank text-warning me-2"></i>Costo por confirmación · transportistas</h6>
                        <p class="text-white-50 small mb-3">Cuánto cuesta cada llamada exitosa. Más bajo es mejor.</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="thead-lupita">
                                <tr>
                                    <th>Transportista</th>
                                    <th class="text-center text-nowrap">Confirm.</th>
                                    <th class="text-end">Costo</th>
                                    <th class="text-end text-nowrap">$/Confirm.</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($trts_cpc as $trt)
                                    <tr>
                                        <td class="text-light">{{ $trt->trt }}</td>
                                        <td class="text-center text-success">{{ $trt->exitosas }}</td>
                                        <td class="text-end text-white-50">${{ number_format((float) $trt->costo, 2) }}</td>
                                        <td class="text-end">
                                            <span class="badge bg-{{ $trt->costo_confirmacion <= ($t->costo_confirmacion ?: 1) ? 'success' : 'danger' }}">
                                                ${{ number_format((float) $trt->costo_confirmacion, 3) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-white-50 text-center">Sin confirmaciones en el periodo.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ RANKINGS ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card bg-dark border-success h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-success mb-3"><i class="bi bi-crown"></i> Mejores conductores</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="thead-lupita">
                                <tr>
                                    <th>#</th>
                                    <th>Conductor</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Éxito</th>
                                    <th class="text-center text-nowrap">% Éxito</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($mejores as $c)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td class="text-light">{{ $c->conductor }}
                                            <div class="small text-white-50">{{ $c->trt }}</div>
                                        </td>
                                        <td class="text-center">{{ $c->total }}</td>
                                        <td class="text-center text-success fw-bold">{{ $c->exitosas }}</td>
                                        <td class="text-center"><span class="badge bg-success">{{ $c->tasa_exito }}%</span></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card bg-dark border-danger h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-octagon"></i> Peores conductores</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="thead-lupita">
                                <tr>
                                    <th>#</th>
                                    <th>Conductor</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Fallidas</th>
                                    <th class="text-center text-nowrap">% Éxito</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($peores as $c)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td class="text-light">{{ $c->conductor }}
                                            <div class="small text-white-50">{{ $c->trt }}</div>
                                        </td>
                                        <td class="text-center">{{ $c->total }}</td>
                                        <td class="text-center text-danger fw-bold">{{ $c->fallidas }}</td>
                                        <td class="text-center"><span class="badge bg-danger">{{ $c->tasa_exito }}%</span></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ ETIQUETAS ============ --}}
        <div class="row g-3 mb-3 align-items-start">
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h6 class="fw-bold text-danger mb-1">
                            <i class="bi bi-question-octagon me-1"></i>¿Por qué el {{ $fallos_pct }}% de las llamadas son fallidas?
                        </h6>
                        <p class="text-white-50 small mb-3">Análisis que incorpora las etiquetas de llamada: cada fallida cae en un solo cubo, por prioridad.</p>
                        @if($fallos->fallidas > 0)
                            <div style="height:300px;">
                                <canvas id="chart_fallidos"></canvas>
                            </div>
                            <div class="d-flex flex-wrap gap-3 small mt-2">
                                <span><i class="bi bi-circle-fill" style="color:#F2A007;"></i> Fallo de contacto: <strong>{{ number_format($fallos->f_contacto) }}</strong></span>
                                <span><i class="bi bi-circle-fill" style="color:#E04B59;"></i> No coopera: <strong>{{ number_format($fallos->f_no_coopera) }}</strong></span>
                                <span><i class="bi bi-circle-fill" style="color:#10CBF0;"></i> Errores técnicos: <strong>{{ number_format($fallos->f_tecnicos) }}</strong></span>
                                <span><i class="bi bi-circle-fill" style="color:#0F71F2;"></i> Otros: <strong>{{ number_format($fallos->f_otros) }}</strong></span>
                            </div>
                        @else
                            <p class="text-white-50 small mb-0">Sin llamadas fallidas en el periodo.</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary">
                    <div class="card-body">
                        <h6 class="fw-bold text-success mb-3"><i class="bi bi-emoji-smile"></i> Señales positivas</h6>
                        @forelse($etiquetas_positivas as $e)
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-light">{{ $e->texto }}</span>
                                    <span class="text-white-50">{{ $e->total }}</span>
                                </div>
                                <div class="progress" style="height:6px;">
                                    <div class="progress-bar bg-success" style="width:{{ max(2, round($e->total / $max_positiva * 100)) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-white-50 small mb-0">Sin etiquetas en el periodo.</p>
                        @endforelse
                        <p class="text-white-50 small mb-0 mt-3 border-top border-secondary pt-2">
                            {{ number_format($total_positivas) }} señales positivas registradas en el periodo.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ IA VS HUMANO ============ --}}
        <div class="card bg-dark border-secondary mb-4">
            <div class="card-body">
                <h6 class="fw-bold text-light mb-3"><i class="bi bi-robot text-info me-2"></i>IA vs humano · coincidencia de criterio</h6>
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <table class="table table-sm table-bordered text-center align-middle mb-0">
                            <thead class="thead-lupita">
                            <tr>
                                <th></th>
                                <th>Humano: exitosa</th>
                                <th>Humano: fallida</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <th class="text-start">IA: exitosa</th>
                                <td class="text-success fw-bold">{{ $ia->ambos_si }}</td>
                                <td class="text-warning fw-bold">{{ $ia->solo_ia }}</td>
                            </tr>
                            <tr>
                                <th class="text-start">IA: fallida</th>
                                <td class="text-info fw-bold">{{ $ia->solo_humano }}</td>
                                <td class="text-danger fw-bold">{{ $ia->ambos_no }}</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <p class="text-white-50 small mb-1">Coinciden en {{ $ia->acuerdo }}% de {{ $ia->evaluadas }} llamadas evaluadas.</p>
                        <p class="text-white-50 small mb-3">
                            La IA marca como exitosas {{ $ia->solo_ia + $ia->ambos_si }} llamadas,
                            el humano {{ $ia->ambos_si + $ia->solo_humano }}.
                            Cuando la IA dice exitosa se equivoca {{ $ia->ia_optimista }}% de las veces.
                        </p>
                        <div class="progress" style="height:22px;">
                            <div class="progress-bar bg-success" style="width:{{ $ia->acuerdo }}%">{{ $ia->acuerdo }}% acuerdo</div>
                            <div class="progress-bar bg-danger" style="width:{{ max(0, 100 - $ia->acuerdo) }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ RUTAS (al final: esperas por ubicacion + tiempo de ruta) ============ --}}
        @include('reporte.v2.partials.bloque-rutas')

        <div class="d-flex justify-content-between flex-wrap gap-2 text-white-50 small border-top border-secondary pt-3">
            <span><i class="bi bi-database"></i> Criterio de éxito: <code>llamada_exitosa = 1</code></span>
            <span>Comparado con {{ $llamadas->format_fecha($periodo_anterior->desde, 'd/m/Y') }} - {{ $llamadas->format_fecha($periodo_anterior->hasta, 'd/m/Y') }}</span>
        </div>
    @endif
@endsection

@section('heads')
    @if($es_export)
    {{-- el dashboard se construyo con tarjetas oscuras del CRM: en la descarga
         adoptan el lienzo claro y los estilos personalizados de todo1
         (report-card redondeada con sombra) sin tocar la vista web --}}
    <style>
        .card.bg-dark {
            background-color: #fff !important;
            color: #212529 !important;
            border: none !important;
            border-radius: 20px !important;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .05) !important;
        }
        .border-secondary { border-color: #dee2e6 !important; }
        .text-light { color: #212529 !important; }
        .text-white { color: #212529 !important; }
        .text-white-50 { color: #6c757d !important; }
        .badge.bg-dark { background-color: #2c3e50 !important; color: #fff !important; }
        .table { --bs-table-color: #212529; --bs-table-hover-color: #212529; }
        .table-primary { --bs-table-bg: rgba(13,110,253,.09); --bs-table-color: #212529; }
        .table-hover tbody tr:hover { background-color: rgba(0, 0, 0, .02); }
        .progress { background-color: #e9ecef; }
        .form-control, .form-select { background-color: #fff; color: #212529; }
    </style>
    @endif
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script>
        Chart.register(ChartDataLabels);
        Chart.defaults.color = '{{ $es_export ? '#495057' : '#dee2e6' }}';
        Chart.defaults.borderColor = '{{ $es_export ? 'rgba(0,0,0,.08)' : 'rgba(255,255,255,.08)' }}';

        const serie = document.getElementById('chart_serie');
        if (serie) {
            new Chart(serie, {
                data: {
                    labels: @json($serie_labels),
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Llamadas',
                            data: @json($serie_total),
                            backgroundColor: 'rgba(13,110,253,.55)',
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            type: 'bar',
                            label: 'Exitosas',
                            data: @json($serie_exitosas),
                            backgroundColor: 'rgba(46,125,50,.75)',
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: '% Éxito',
                            data: @json($serie_tasa),
                            borderColor: '#ffc107',
                            backgroundColor: '#ffc107',
                            borderWidth: 2,
                            tension: .35,
                            spanGaps: true,
                            yAxisID: 'y1',
                            datalabels: { display: false }
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12 } },
                        datalabels: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Llamadas' } },
                        y1: { beginAtZero: true, max: 100, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '% éxito' } }
                    }
                }
            });
        }

        const tipos = document.getElementById('chart_tipos');
        if (tipos) {
            new Chart(tipos, {
                type: 'doughnut',
                data: {
                    labels: @json($donut_labels),
                    datasets: [{
                        data: @json($donut_data),
                        backgroundColor: @json($donut_colores),
                        borderColor: '#212529',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 11 },
                            formatter: (value, ctx) => {
                                if (!value) return '';
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                return ((value / total) * 100).toFixed(0) + '%';
                            }
                        }
                    }
                }
            });
        }

        const horas = document.getElementById('chart_horas');
        if (horas) {
            new Chart(horas, {
                data: {
                    labels: @json($horas_labels),
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Llamadas',
                            data: @json($horas_total),
                            backgroundColor: @json($horas_colores),
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: '% Éxito',
                            data: @json($horas_tasa),
                            borderColor: '#20c997',
                            borderWidth: 2,
                            tension: .35,
                            spanGaps: true,
                            yAxisID: 'y1',
                            datalabels: { display: false }
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12 } },
                        datalabels: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Llamadas' } },
                        y1: { beginAtZero: true, max: 100, position: 'right', grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        const dow = document.getElementById('chart_dow');
        if (dow) {
            new Chart(dow, {
                type: 'bar',
                data: {
                    labels: @json($dow_labels),
                    datasets: [{
                        label: '% Éxito',
                        data: @json($dow_valores),
                        totales: @json($dow_totales),
                        backgroundColor: @json($dow_colores),
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.raw === null
                                    ? 'Sin llamadas ese día'
                                    : ctx.raw + '% de éxito · ' + ctx.dataset.totales[ctx.dataIndex] + ' llamadas'
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            anchor: 'end',
                            align: 'start',
                            font: { weight: 'bold', size: 11 },
                            formatter: v => (v === null || v === undefined || v <= 0) ? '' : v + '%'
                        }
                    },
                    scales: { x: { beginAtZero: true, max: 100 } }
                }
            });
        }

        // pizza de fallidas: mismo criterio y colores que el reporte todo1
        const fallidos = document.getElementById('chart_fallidos');
        if (fallidos) {
            new Chart(fallidos, {
                type: 'pie',
                data: {
                    labels: @json($pizza_labels),
                    datasets: [{
                        data: @json($pizza_fallos),
                        backgroundColor: @json($pizza_colores),
                        borderColor: 'transparent',
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'left', labels: { boxWidth: 12 } },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.parsed + ' (' + (ctx.parsed / ctx.dataset.data.reduce((a, b) => a + b, 0) * 100).toFixed(1) + '%)'
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 13 },
                            formatter: (value, ctx) => {
                                if (!value) return '';
                                const totalV = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                return ((value / totalV) * 100).toFixed(1) + '%';
                            }
                        }
                    }
                }
            });
        }
    </script>
@endsection
