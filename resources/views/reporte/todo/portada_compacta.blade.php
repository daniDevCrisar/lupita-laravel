<!DOCTYPE html>
<html lang="es">
<head>
    @php
        $fecha_rango = $llamadas->format_fecha($fecha_str, 'd/m/Y') . ' ' . $llamadas->get_fecha_day_name($fecha_str);
        $rt = $resumen->total;
        $g_total    = (int) ($rt->llamadas ?? 0);
        $g_exitosas = (int) ($rt->llamada_exitosa ?? 0);
        $g_fallidas = $g_total - $g_exitosas;
        $g_pct      = $g_total ? round($g_exitosas / $g_total * 100) : 0;
        $titulo_nivel = $nivel === 'medio' ? 'Resumen Ejecutivo (Medio)' : 'Resumen Ejecutivo';

        // filas de la tabla KPI unica (resumen + cada tipo)
        $filas = [];
        $filas[] = ['label' => 'RESUMEN · todas las llamadas', 't' => $resumen->total, 'url' => null, 'bold' => true];
        foreach ($tipos as $tipo) {
            $filas[] = ['label' => '#' . $tipo->id . ' · ' . $tipo->titulo, 't' => $tipo->total, 'url' => $tipo->url, 'bold' => false];
        }

        // datos para los charts
        $chart_labels = [];
        $chart_pct = [];
        $chart_colors = [];
        foreach ($filas as $f) {
            $ft = $f['t'];
            $tt = (int) ($ft->llamadas ?? 0);
            $ex = (int) ($ft->llamada_exitosa ?? 0);
            $pct = $tt ? round($ex / $tt * 100) : 0;
            $chart_labels[] = $f['bold'] ? 'RESUMEN' : '#' . trim(explode('·', $f['label'])[0], '# ');
            $chart_pct[] = $pct;
            $chart_colors[] = $pct >= 70 ? '#2e7d32' : ($pct >= 45 ? '#ff8f00' : '#c62828');
        }

        // duracion de llamadas (exitosas vs fallidas)
        $dur_total = $rt->audio_duracion_total ?? 0;
        $dur_ex = $rt->audio_duracion_exitosas ?? 0;
        $dur_fa = $rt->audio_duracion_fallidas ?? 0;
        $dur_ex_pct = $dur_total ? round($dur_ex / $dur_total * 100) : 0;
        $dur_fa_pct = $dur_total ? round($dur_fa / $dur_total * 100) : 0;
    @endphp
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titulo_nivel }} VAPI - {{ $fecha_rango }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }
        .report-card { border-radius: 20px; border: none; box-shadow: 0 8px 20px rgba(0,0,0,0.05); transition: all 0.2s ease; }
        .report-card:hover { box-shadow: 0 15px 30px rgba(0,0,0,0.1); transform: translateY(-3px); }
        .tabla-conductores th { background-color: #2c3e50; color: white; font-weight: 500; }
        .table-hover tbody tr:hover { background-color: rgba(13,110,253,0.07); }
        .footer-note { font-size: 0.85rem; color: #5f6368; border-top: 1px dashed #ccc; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width: 980px;">

    <!-- ============ ENCABEZADO ============ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1" style="color:#1e2a3a;">
                <i class="fas fa-chart-line me-2" style="color:#0d6efd;"></i>{{ $titulo_nivel }} VAPI
            </h1>
            <span class="badge bg-dark p-2"><i class="far fa-calendar-alt me-2"></i>{{ $fecha_rango }}</span>
        </div>
        <a href="{{ route('lupita.reporte.seleccion') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Otro reporte
        </a>
    </div>

    <!-- ============ KPIs EN UNA SOLA TABLA ============ -->
    <div class="card report-card mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3"><i class="bi bi-clipboard-data text-primary me-2"></i>KPIs · Resumen y por tipo de llamada</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="tabla-conductores">
                    <tr>
                        <th>Ámbito</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Exitosas</th>
                        <th class="text-center">Fallidas</th>
                        <th class="text-center">% Éxito</th>
                        <th class="text-center">Conduct.</th>
                        <th class="text-center">Contest.</th>
                        <th class="text-center">Confirm.</th>
                        <th class="text-end">Costo</th>
                        <th class="text-center">Detalle</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($filas as $f)
                        @php
                            $ft = $f['t'];
                            $total = (int) ($ft->llamadas ?? 0);
                            $exit = (int) ($ft->llamada_exitosa ?? 0);
                            $fall = $total - $exit;
                            $pct = $total ? round($exit / $total * 100) : 0;
                        @endphp
                        <tr class="{{ $f['bold'] ? 'table-primary' : '' }}">
                            <td class="{{ $f['bold'] ? 'fw-bold' : '' }}">{{ $f['label'] }}</td>
                            <td class="text-center">{{ $total }}</td>
                            <td class="text-center text-success fw-bold">{{ $exit }}</td>
                            <td class="text-center text-danger">{{ $fall }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ $llamadas::color_porcentaje($pct) }}"
                                      data-bs-toggle="tooltip" title="{{ $exit }}/{{ $total }} llamadas exitosas">{{ $pct }}%</span>
                            </td>
                            <td class="text-center">{{ $ft->conductores }}</td>
                            <td class="text-center">{{ $ft->contestadas }}</td>
                            <td class="text-center">{{ $ft->conductores_exitosos }}</td>
                            <td class="text-end text-muted">${{ round($ft->costo ?? 0, 2) }}</td>
                            <td class="text-center">
                                @if($f['url'])
                                    <a href="{{ $f['url'] }}" target="_blank" class="btn btn-primary btn-sm py-0">Ver</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============ DURACION de llamadas exitosas y fallidas ============ --}}
    @if($dur_total)
        <div class="card report-card mb-4">
            <div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-stopwatch text-info me-2"></i>Duración de llamadas · Exitosas vs Fallidas</h6>
                <div class="progress mb-2" style="height: 28px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar"
                         style="width: {{ $dur_ex_pct }}%;" aria-valuenow="{{ $dur_ex_pct }}"
                         data-bs-toggle="tooltip" title="Duración total en llamadas exitosas">
                        {{ $llamadas::audio_duracion_format($dur_ex) }} ({{ $dur_ex_pct }}%)
                    </div>
                    <div class="progress-bar bg-danger" role="progressbar"
                         style="width: {{ $dur_fa_pct }}%;" aria-valuenow="{{ $dur_fa_pct }}"
                         data-bs-toggle="tooltip" title="Duración total en llamadas fallidas">
                        {{ $llamadas::audio_duracion_format($dur_fa) }} ({{ $dur_fa_pct }}%)
                    </div>
                </div>
                <small class="text-muted">Total analizado: {{ $llamadas::audio_duracion_format($dur_total) }}</small>
            </div>
        </div>
    @endif

    <!-- ============ CHARTS ============ -->
    <div class="row g-4 mb-4">
        <div class="col-md-7">
            <div class="card report-card h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart text-primary me-2"></i>% Éxito por ámbito</h6>
                    <div style="height:280px;"><canvas id="chart_exito"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card report-card h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart text-success me-2"></i>Exitosas vs Fallidas (resumen)</h6>
                    <div style="height:280px;"><canvas id="chart_donut"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    @if($nivel === 'medio' && $rutas)
        <!-- ============ ETAPA LOGISTICA ============ -->
        <div class="mb-4">
            @include('reporte.todo.partials.etapa-logistica', ['etapas' => $resumen->etapa_logistica, 'dias_total' => 1])
        </div>

        <!-- ============ TOP 5 RUTAS ============ -->
        <div class="card report-card mb-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3"><i class="fas fa-crown text-warning me-2"></i>Top 5 Rutas más Concurridas</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="tabla-conductores">
                        <tr>
                            <th>#</th>
                            <th>Ruta</th>
                            <th class="text-center">Concurrencia</th>
                            <th class="text-center">Exitosas</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rutas->lista_detalle as $item)
                            @php
                                $loc_destino_nombre = $item->loc_destino_nombre ?? $item->ubg_destino_nombre;
                                $loc_origen_nombre = $item->loc_origen_nombre ?? $item->ubg_origen_nombre;
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><b>{{ 'RUTA ' . $loc_origen_nombre . ' - ' . $loc_destino_nombre }}</b></td>
                                <td class="text-center">{{ $item->veces_usada }}</td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $item->total_exito ? 'success' : 'danger' }}">{{ $item->total_exito }}</span>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- ============ FOOTER ============ -->
    <div class="footer-note pt-3 mt-4 d-flex justify-content-between flex-wrap gap-2">
        <span><i class="far fa-file-excel me-1"></i> Reporte Generado con LUPITA</span>
        <span><i class="fas fa-database me-1"></i> Criterio de éxito: <code>llamada_exitosa = 1</code></span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
    Chart.register(ChartDataLabels);
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

    new Chart(document.getElementById('chart_exito'), {
        type: 'bar',
        data: {
            labels: @json($chart_labels),
            datasets: [{
                label: '% Éxito',
                data: @json($chart_pct),
                backgroundColor: @json($chart_colors),
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: { anchor: 'end', align: 'end', color: '#333', font: { weight: 'bold' }, formatter: v => v + '%' }
            },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });

    new Chart(document.getElementById('chart_donut'), {
        type: 'doughnut',
        data: {
            labels: ['Exitosas', 'Fallidas'],
            datasets: [{
                data: [{{ $g_exitosas }}, {{ $g_fallidas }}],
                backgroundColor: ['#2e7d32', '#c62828'],
                borderColor: 'transparent',
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold', size: 16 },
                    formatter: (value, ctx) => {
                        if (value === 0) return '';
                        let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        return ((value / total) * 100).toFixed(1) + '%';
                    }
                }
            }
        }
    });
</script>
</body>
</html>
