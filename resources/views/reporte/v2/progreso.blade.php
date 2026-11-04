@extends($layout ?? 'layouts.reporte')
{{-- $layout = layouts.export cuando el reporte de progreso se descarga como .html;
   en pantalla usa layouts.reporte (Bootstrap claro autonoomo, igual que todo1) --}}

@section('title', 'Progreso de llamadas VAPI')

@section('heads')
    <style>
        /* Mismo lenguaje visual del reporte clasico (reporte/todo/todo1): lienzo
           claro, tarjetas de color y cabeceras azul oscuro. En pantalla viaja
           en layouts.reporte (Bootstrap claro); en la descarga .html va sobre
           el tema oscuro de layouts.export, de ahi el prefijo .reporte-todo. */
        .reporte-todo {
            background: #f4f6f9;
            color: #212529;
            border-radius: 14px;
            padding: 1.5rem;
        }

        .reporte-todo .card {
            background: #fff;
            color: #212529;
            border: 0;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .06);
        }

        .reporte-todo .report-card {
            transition: all .2s ease;
        }

        .reporte-todo .report-card:hover {
            box-shadow: 0 15px 30px rgba(0, 0, 0, .1);
            transform: translateY(-3px);
        }

        .reporte-todo .bg-exito {
            background: linear-gradient(145deg, #e6f7e6, #c8e6c9);
            border-left: 6px solid #2e7d32;
        }

        .reporte-todo .bg-fallo {
            background: linear-gradient(145deg, #ffebee, #ffcdd2);
            border-left: 6px solid #c62828;
        }

        .reporte-todo .bg-advertencia {
            background: linear-gradient(145deg, #fff8e1, #ffecb3);
            border-left: 6px solid #ff8f00;
        }

        .reporte-todo .text-muted {
            color: #6c757d !important;
        }

        .reporte-todo .table {
            --bs-table-color: #212529;
            --bs-table-hover-color: #212529;
            --bs-table-bg: transparent;
        }

        /* hover suave de las filas, identico al de todo1 */
        .reporte-todo .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, .02);
        }

        .reporte-todo .tabla-progreso th {
            background: #2c3e50;
            color: #fff;
            font-weight: 500;
            white-space: nowrap;
        }

        .reporte-todo .footer-note {
            font-size: .85rem;
            color: #5f6368;
            border-top: 1px dashed #ccc;
        }

        @media print {
            .no-print { display: none !important; }
            .reporte-todo { background: #fff !important; padding: 0 !important; }
            .reporte-todo .card { box-shadow: none !important; break-inside: avoid; }
        }
    </style>
@endsection

@php
    $t = $kpis;
    $p = $periodo;
    $v = $variacion;

    $titulo_periodo = 'Del ' . $llamadas->format_fecha($p->desde, 'd/m/Y')
        . ' al ' . $llamadas->format_fecha($p->hasta, 'd/m/Y');

    // filtros que se conservan al navegar y al descargar
    $params_base = array_filter([
        'fecha_inicio' => $p->desde,
        'fecha_fin' => $p->hasta,
        'llamada_tipo_id' => $filtros->llamada_tipo_id,
        'trt' => $filtros->trt_id,
    ], fn($x) => $x !== null && $x !== '');

    // [etiqueta, valor, icono fa (estilo todo1), color, variacion, subtexto, fondo tarjeta, fondo circulo]
    $tarjetas = [
        ['LLAMADAS', number_format($t->total), 'fas fa-phone-volume', '#1e2a3a', $v->total, 'vs ' . number_format($anterior->total) . ' periodo anterior', 'bg-white', 'bg-primary bg-opacity-10'],
        ['EXITOSAS', number_format($t->exitosas), 'fas fa-check-circle', '#2e7d32', $v->exitosas, $t->tasa_exito . '% de tasa de éxito', 'bg-exito', 'bg-success bg-opacity-10'],
        ['TASA DE ÉXITO', $t->tasa_exito . '%', 'fas fa-bullseye', '#ff8f00', $v->tasa_exito, 'puntos vs periodo previo', 'bg-advertencia', 'bg-warning bg-opacity-10'],
        ['CONTESTADAS', number_format($t->contestadas), 'fas fa-phone', '#0c7591', null, $t->tasa_contestacion . '% del total · ' . $t->buzon_de_voz . ' buzón', 'bg-white', 'bg-info bg-opacity-10'],
        ['COSTO TOTAL', '$' . number_format((float) $t->costo, 2), 'fas fa-money-bill-wave', '#b26a00', $v->costo, 'confirmación $' . number_format((float) $t->costo_confirmacion, 3), 'bg-white', 'bg-warning bg-opacity-10'],
        ['CONDUCTORES', number_format($t->conductores), 'fas fa-users', '#6f42c1', null, $t->conductores_exitosos . ' con al menos un éxito', 'bg-advertencia', 'bg-secondary bg-opacity-10'],
    ];

    // serie de cada granularidad para los graficos
    $serie_de = function (array $filas, string $campo) {
        return array_map(fn($o) => $o->$campo, $filas);
    };

    $sem_labels = $serie_de($semanas, 'etiqueta');
    $sem_total = $serie_de($semanas, 'total');
    $sem_exitosas = $serie_de($semanas, 'exitosas');
    $sem_tasa = array_map(fn($o) => $o->total ? $o->tasa_exito : null, $semanas);
    $sem_acum = $serie_de($semanas, 'acum_exitosas');

    $mes_labels = $serie_de($meses, 'etiqueta');
    $mes_total = $serie_de($meses, 'total');
    $mes_exitosas = $serie_de($meses, 'exitosas');
    $mes_tasa = array_map(fn($o) => $o->total ? $o->tasa_exito : null, $meses);
    $mes_acum = $serie_de($meses, 'acum_exitosas');

    // errores tecnicos (error_origen -1/1/2/3) apilados por cubo
    $err_sem = [
        'Desconocido' => $serie_de($semanas, 'error_desconocido'),
        'IA' => $serie_de($semanas, 'error_ia'),
        'Red' => $serie_de($semanas, 'error_red'),
        'Sistema' => $serie_de($semanas, 'error_sistema'),
    ];
    $err_mes = [
        'Desconocido' => $serie_de($meses, 'error_desconocido'),
        'IA' => $serie_de($meses, 'error_ia'),
        'Red' => $serie_de($meses, 'error_red'),
        'Sistema' => $serie_de($meses, 'error_sistema'),
    ];

    // color de la tasa: verde >=70, ambar >=45, rojo <45 (criterio del proyecto)
    $color_tasa = fn($tasa) => $tasa >= 70 ? '#2e7d32' : ($tasa >= 45 ? '#ff8f00' : '#c62828');

    // badge de variacion: flecha + valor. $es_tasa pinta puntos (pp) en vez de %.
    $delta = function ($val, bool $es_tasa = false) {
        if ($val === null) return '<span class="text-muted">—</span>';
        if ($val > 0) return '<span class="text-success fw-semibold"><i class="fas fa-arrow-up"></i> +'
            . number_format($val, 1) . ($es_tasa ? ' pp' : '%') . '</span>';
        if ($val < 0) return '<span class="text-danger fw-semibold"><i class="fas fa-arrow-down"></i> '
            . number_format($val, 1) . ($es_tasa ? ' pp' : '%') . '</span>';
        return '<span class="text-muted">= 0</span>';
    };

    // total del rango para el pie de cada tabla (los unicos valores DISTINCT del
    // periodo salen de kpis, no de sumar cubos)
    $total_rango = (object) [
        'total' => $t->total,
        'exitosas' => $t->exitosas,
        'fallidas' => $t->fallidas,
        'tasa_exito' => $t->tasa_exito,
        'contestadas' => $t->contestadas,
        'tasa_contestacion' => $t->tasa_contestacion,
        'buzon_de_voz' => $t->buzon_de_voz,
        'errores_tecnicos' => $t->errores_tecnicos,
        'conductores' => $t->conductores,
        'duracion_prom' => $t->duracion_prom_exitosa,
        'costo' => $t->costo,
        'costo_confirmacion' => $t->costo_confirmacion,
    ];

    // pie de la tabla mensual: cubre el rango ampliado a minimo 6 meses, no el
    // recorte del filtro, y sus totales DISTINCT salen de meses_kpis
    $mk = $meses_kpis;
    $total_meses = (object) [
        'total' => $mk->total,
        'exitosas' => $mk->exitosas,
        'tasa_exito' => $mk->tasa_exito,
        'contestadas' => $mk->contestadas,
        'buzon_de_voz' => $mk->buzon_de_voz,
        'errores_tecnicos' => $mk->errores_tecnicos,
        'conductores' => $mk->conductores,
        'duracion_prom' => $mk->duracion_prom_exitosa,
        'costo' => $mk->costo,
        'costo_confirmacion' => $mk->costo_confirmacion,
    ];
@endphp

@section('content')
    {{-- barra de acciones y filtros: propios del CRM, no viajan en el .html --}}
    @unless(($layout ?? null) === 'layouts.export')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1">
                <i class="fas fa-chart-line text-primary me-2"></i>Progreso de llamadas VAPI
            </h1>
            <span class="badge bg-light text-dark border p-2">
                <i class="far fa-calendar-alt me-2"></i>{{ $titulo_periodo }} · {{ $p->dias }} día(s)
            </span>
            @if($filtros->llamada_tipo_id !== null)
                <span class="badge bg-primary p-2">Tipo #{{ $filtros->llamada_tipo_id }}</span>
            @endif
            @if($filtros->trt_id !== null)
                <span class="badge bg-info text-dark p-2">TRT #{{ $filtros->trt_id }}</span>
            @endif
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('lupita.reportes.dashboard', $params_base) }}" class="btn btn-outline-info btn-sm">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="{{ route('lupita.reportes.reporte', $params_base) }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-chart-bar"></i> Reporte completo
            </a>
            <a href="{{ route('lupita.reportes.progreso.html', $params_base) }}" class="btn btn-success btn-sm">
                <i class="fas fa-download"></i> Descargar HTML
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimir / PDF
            </button>
        </div>
    </div>

    {{-- ============ FILTROS ============ --}}
    <form method="GET" action="{{ route('lupita.reportes.progreso') }}" class="mb-4 no-print">
        <fieldset class="border border-secondary rounded p-3">
            <legend class="float-none w-auto px-2 fs-6 text-muted">Filtros</legend>
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
                <div class="col-12 col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i> Aplicar</button>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-3">
                @php
                    $accesos = [
                        'Este año' => [date('Y-01-01'), date('Y-m-d')],
                        'Últimos 30 días' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
                        'Últimos 90 días' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
                        'Últimos 6 meses' => [date('Y-m-d', strtotime('-6 months')), date('Y-m-d')],
                        'Año anterior' => [date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year'))],
                    ];
                @endphp
                @foreach($accesos as $label => $rango)
                    <a href="{{ route('lupita.reportes.progreso', array_filter([
                        'fecha_inicio' => $rango[0],
                        'fecha_fin' => $rango[1],
                        'llamada_tipo_id' => $filtros->llamada_tipo_id,
                        'trt' => $filtros->trt_id,
                    ], fn($x) => $x !== null && $x !== '')) }}"
                       class="btn btn-outline-secondary btn-sm">{{ $label }}</a>
                @endforeach
            </div>
        </fieldset>
    </form>
    @endunless

    @if($t->total == 0)
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
            No hay llamadas en el periodo seleccionado.</div>
    @else

    <div class="reporte-todo">

        {{-- ============ ENCABEZADO ============ --}}
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h2 class="display-5 fw-bold mb-1" style="color:#1e2a3a;">
                    <i class="fas fa-chart-line me-3" style="color:#0d6efd;"></i>Progreso por semana y por mes
                </h2>
                <p class="lead mb-0">
                    Evolución de llamadas | Exitosas =
                    <span class="badge bg-success">{{ number_format($t->exitosas) }}</span>
                    <span class="text-muted ms-2">sobre {{ number_format($t->total) }} ({{ $t->tasa_exito }}%)</span>
                </p>
            </div>
            <div class="text-end">
                <span class="badge bg-dark p-3 fs-6">
                    <i class="far fa-calendar-alt me-2"></i>{{ $titulo_periodo }}
                </span>
            </div>
        </div>

        {{-- ============ RESUMEN: TARJETAS KPI ============ --}}
        <div class="row g-4 mb-4">
            @foreach($tarjetas as $r)
                <div class="col-md-6 col-xl-4">
                    <div class="card report-card h-100 border-0 {{ $r[6] }}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted text-uppercase fw-normal">{{ $r[0] }}</h6>
                                    <h2 class="fw-bold" style="color:{{ $r[3] }};">{{ $r[1] }}</h2>
                                </div>
                                <div class="{{ $r[7] }} p-3 rounded-circle align-self-start">
                                    <i class="{{ $r[2] }} fa-2x" style="color:{{ $r[3] }};"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                @if($r[4] !== null)
                                    {!! $delta($r[4]) !!} ·
                                @endif
                                {{ $r[5] }}
                            </small>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ============ PROGRESO SEMANAL ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h4 fw-bold"><i class="fas fa-calendar-check text-primary me-2"></i>Progreso por semana</h3>
                <p class="text-muted mb-3">{{ count($semanas) }} semana(s) con llamadas · semana ISO (lunes a domingo).</p>

                <div style="height:320px;">
                    <canvas id="chart_semanas"></canvas>
                </div>

                <h4 class="h6 fw-bold text-uppercase text-muted mt-4 mb-2">
                    <i class="fas fa-exclamation-circle text-danger me-2"></i>Errores técnicos dentro de las fallidas por semana
                </h4>
                <div style="height:220px;">
                    <canvas id="chart_err_semanas"></canvas>
                </div>

                <div class="table-responsive mt-4">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="tabla-progreso">
                        <tr>
                            <th>Semana</th>
                            <th class="text-end">Llamadas</th>
                            <th class="text-end">Δ llamadas</th>
                            <th class="text-end">Exitosas</th>
                            <th class="text-end">Tasa éxito</th>
                            <th class="text-end">Δ tasa</th>
                            <th class="text-end">Contestadas</th>
                            <th class="text-end">Buzón</th>
                            <th class="text-end">Errores técc.</th>
                            <th class="text-end">Conductores</th>
                            <th class="text-end">Dur. prom</th>
                            <th class="text-end">Costo</th>
                            <th class="text-end">$/confirm.</th>
                            <th class="text-end">Acum. exitosas</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($semanas as $o)
                            <tr>
                                <td>
                                    <strong>{{ $o->etiqueta }}</strong>
                                    <br><small class="text-muted">{{ $o->rango }}</small>
                                </td>
                                <td class="text-end">{{ number_format($o->total) }}</td>
                                <td class="text-end">{!! $delta($o->var_total) !!}</td>
                                <td class="text-end text-success fw-semibold">{{ number_format($o->exitosas) }}</td>
                                <td class="text-end fw-bold" style="color:{{ $color_tasa($o->tasa_exito) }};">{{ $o->tasa_exito }}%</td>
                                <td class="text-end">{!! $delta($o->var_tasa, true) !!}</td>
                                <td class="text-end">{{ number_format($o->contestadas) }}
                                    <br><small class="text-muted">{{ $o->tasa_contestacion }}%</small></td>
                                <td class="text-end">{{ number_format($o->buzon_de_voz) }}</td>
                                <td class="text-end">{{ number_format($o->errores_tecnicos) }}</td>
                                <td class="text-end">{{ number_format($o->conductores) }}</td>
                                <td class="text-end">{{ $llamadas::audio_duracion_format($o->duracion_prom) ?: '0 seg' }}</td>
                                <td class="text-end">${{ number_format($o->costo, 2) }}</td>
                                <td class="text-end">${{ number_format($o->costo_confirmacion, 3) }}</td>
                                <td class="text-end text-primary">{{ number_format($o->acum_exitosas) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot class="table-group-divider fw-bold border-top border-2">
                        <tr>
                            <td>TOTAL RANGO</td>
                            <td class="text-end">{{ number_format($total_rango->total) }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($total_rango->exitosas) }}</td>
                            <td class="text-end">{{ $total_rango->tasa_exito }}%</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($total_rango->contestadas) }}</td>
                            <td class="text-end">{{ number_format($total_rango->buzon_de_voz) }}</td>
                            <td class="text-end">{{ number_format($total_rango->errores_tecnicos) }}</td>
                            <td class="text-end">{{ number_format($total_rango->conductores) }}</td>
                            <td class="text-end">{{ $llamadas::audio_duracion_format($total_rango->duracion_prom) ?: '0 seg' }}</td>
                            <td class="text-end">${{ number_format($total_rango->costo, 2) }}</td>
                            <td class="text-end">${{ number_format($total_rango->costo_confirmacion, 3) }}</td>
                            <td class="text-end">{{ number_format($total_rango->exitosas) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- ============ PROGRESO MENSUAL ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h4 fw-bold"><i class="far fa-calendar-alt text-primary me-2"></i>Progreso por mes</h3>
                <p class="text-muted mb-3">{{ count($meses) }} mes(es) con llamadas · se muestran siempre al menos 6 meses hacia atrás.</p>

                <div style="height:320px;">
                    <canvas id="chart_meses"></canvas>
                </div>

                <h4 class="h6 fw-bold text-uppercase text-muted mt-4 mb-2">
                    <i class="fas fa-exclamation-circle text-danger me-2"></i>Errores técnicos dentro de las fallidas por mes
                </h4>
                <div style="height:220px;">
                    <canvas id="chart_err_meses"></canvas>
                </div>

                <div class="table-responsive mt-4">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="tabla-progreso">
                        <tr>
                            <th>Mes</th>
                            <th class="text-end">Llamadas</th>
                            <th class="text-end">Δ llamadas</th>
                            <th class="text-end">Exitosas</th>
                            <th class="text-end">Tasa éxito</th>
                            <th class="text-end">Δ tasa</th>
                            <th class="text-end">Contestadas</th>
                            <th class="text-end">Buzón</th>
                            <th class="text-end">Errores técc.</th>
                            <th class="text-end">Conductores</th>
                            <th class="text-end">Dur. prom</th>
                            <th class="text-end">Costo</th>
                            <th class="text-end">$/confirm.</th>
                            <th class="text-end">Acum. exitosas</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($meses as $o)
                            <tr>
                                <td>
                                    <strong>{{ $o->etiqueta }}</strong>
                                    <br><small class="text-muted">{{ $o->rango }}</small>
                                </td>
                                <td class="text-end">{{ number_format($o->total) }}</td>
                                <td class="text-end">{!! $delta($o->var_total) !!}</td>
                                <td class="text-end text-success fw-semibold">{{ number_format($o->exitosas) }}</td>
                                <td class="text-end fw-bold" style="color:{{ $color_tasa($o->tasa_exito) }};">{{ $o->tasa_exito }}%</td>
                                <td class="text-end">{!! $delta($o->var_tasa, true) !!}</td>
                                <td class="text-end">{{ number_format($o->contestadas) }}
                                    <br><small class="text-muted">{{ $o->tasa_contestacion }}%</small></td>
                                <td class="text-end">{{ number_format($o->buzon_de_voz) }}</td>
                                <td class="text-end">{{ number_format($o->errores_tecnicos) }}</td>
                                <td class="text-end">{{ number_format($o->conductores) }}</td>
                                <td class="text-end">{{ $llamadas::audio_duracion_format($o->duracion_prom) ?: '0 seg' }}</td>
                                <td class="text-end">${{ number_format($o->costo, 2) }}</td>
                                <td class="text-end">${{ number_format($o->costo_confirmacion, 3) }}</td>
                                <td class="text-end text-primary">{{ number_format($o->acum_exitosas) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot class="table-group-divider fw-bold border-top border-2">
                        <tr>
                            <td>TOTAL ≥ 6 MESES</td>
                            <td class="text-end">{{ number_format($total_meses->total) }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($total_meses->exitosas) }}</td>
                            <td class="text-end">{{ $total_meses->tasa_exito }}%</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($total_meses->contestadas) }}</td>
                            <td class="text-end">{{ number_format($total_meses->buzon_de_voz) }}</td>
                            <td class="text-end">{{ number_format($total_meses->errores_tecnicos) }}</td>
                            <td class="text-end">{{ number_format($total_meses->conductores) }}</td>
                            <td class="text-end">{{ $llamadas::audio_duracion_format($total_meses->duracion_prom) ?: '0 seg' }}</td>
                            <td class="text-end">${{ number_format($total_meses->costo, 2) }}</td>
                            <td class="text-end">${{ number_format($total_meses->costo_confirmacion, 3) }}</td>
                            <td class="text-end">{{ number_format($total_meses->exitosas) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- nota de criterio, solo en la version web/descarga --}}
        <div class="d-flex justify-content-between flex-wrap gap-2 footer-note pt-3">
            <span><i class="fas fa-database"></i> Criterio de éxito: <code>llamada_exitosa = 1</code>. Conductores = DISTINCT por cubo.</span>
            <span>Comparado con {{ $llamadas->format_fecha($periodo_anterior->desde, 'd/m/Y') }} - {{ $llamadas->format_fecha($periodo_anterior->hasta, 'd/m/Y') }}</span>
        </div>
    </div>

    @endif
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script>
        Chart.register(ChartDataLabels);
        Chart.defaults.color = '#495057';
        Chart.defaults.borderColor = 'rgba(0,0,0,.08)';

        // barras de llamadas + linea de % de exito sobre eje secundario
        function grafico_progreso(id, labels, total, exitosas, tasa) {
            const el = document.getElementById(id);
            if (!el || !labels.length) return;

            new Chart(el, {
                data: {
                    labels: labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Llamadas',
                            data: total,
                            backgroundColor: 'rgba(13,110,253,.45)',
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            type: 'bar',
                            label: 'Exitosas',
                            data: exitosas,
                            backgroundColor: 'rgba(46,125,50,.75)',
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: '% Éxito',
                            data: tasa,
                            borderColor: '#ff8f00',
                            backgroundColor: '#ff8f00',
                            borderWidth: 2,
                            tension: .35,
                            spanGaps: true,
                            yAxisID: 'y1',
                            datalabels: {
                                align: 'top',
                                anchor: 'end',
                                color: '#b26a00',
                                font: { weight: 'bold', size: 10 },
                                formatter: v => (v === null || v <= 0) ? '' : v + '%'
                            }
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12 } }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Llamadas' } },
                        y1: { beginAtZero: true, max: 100, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '% éxito' } }
                    }
                }
            });
        }

        grafico_progreso('chart_semanas', @json($sem_labels), @json($sem_total), @json($sem_exitosas), @json($sem_tasa));
        grafico_progreso('chart_meses', @json($mes_labels), @json($mes_total), @json($mes_exitosas), @json($mes_tasa));

        // barras apiladas con los 4 tipos de error tecnico (el origen 0 es
        // humano y queda fuera, mismo criterio que errores_tecnicos de los KPIs)
        function grafico_errores(id, labels, series) {
            const el = document.getElementById(id);
            if (!el || !labels.length) return;

            const colores = {
                'Desconocido': '#90a4ae',
                'IA': '#6f42c1',
                'Red': '#00838f',
                'Sistema': '#c62828'
            };

            new Chart(el, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: Object.keys(series).map(k => ({
                        label: k,
                        data: series[k],
                        backgroundColor: colores[k] || '#546e7a',
                        stack: 'err',
                        borderRadius: 3
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12 } },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                footer: items => 'Total: ' + items.reduce((s, i) => s + i.parsed.y, 0)
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Errores' } }
                    }
                }
            });
        }

        grafico_errores('chart_err_semanas', @json($sem_labels), @json($err_sem));
        grafico_errores('chart_err_meses', @json($mes_labels), @json($err_mes));
    </script>
@endsection
