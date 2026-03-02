<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reporte->titulo }}</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }
        .report-card { border-radius: 20px; border: none; box-shadow: 0 8px 20px rgba(0,0,0,0.05); transition: all 0.2s ease; }
        .report-card:hover { box-shadow: 0 15px 30px rgba(0,0,0,0.1); transform: translateY(-3px); }
        .bg-exito { background: linear-gradient(145deg, #e6f7e6, #c8e6c9); border-left: 6px solid #2e7d32; }
        .bg-fallo { background: linear-gradient(145deg, #ffebee, #ffcdd2); border-left: 6px solid #c62828; }
        .bg-advertencia { background: linear-gradient(145deg, #fff8e1, #ffecb3); border-left: 6px solid #ff8f00; }
        .estrella { color: #ffc107; font-size: 1.1rem; }
        .peligro { color: #d32f2f; font-size: 1.2rem; }
        .tabla-conductores th { background-color: #2c3e50; color: white; font-weight: 500; }
        .badge-exito { background-color: #2e7d32; color: white; font-size: 0.8rem; }
        .badge-fallo { background-color: #c62828; color: white; }
        .badge-critico { background-color: #b71c1c; color: white; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.85; } 100% { opacity: 1; } }
        .footer-note { font-size: 0.85rem; color: #5f6368; border-top: 1px dashed #ccc; }
        .table-hover tbody tr:hover { background-color: rgba(0,0,0,0.02); }
        .tooltip-custom { border-bottom: 1px dotted #007bff; cursor: help; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Encabezado -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h1 class="display-5 fw-bold" style="color: #1e2a3a;"><i class="fas fa-phone-alt me-3" style="color: #0d6efd;"></i>{{ $reporte->titulo }}</h1>
                <p class="lead">Análisis de llamadas totales | Exitosas = <span class="badge bg-success">{{ $reporte->total->llamada_exitosa }}</span></p>
            </div>
            <div class="text-end">
                <span class="badge bg-dark p-3 fs-6"><i class="far fa-calendar-alt me-2"></i>Llamadas analizadas: 13 de febrero de 2026</span>
            </div>
        </div>

        <!-- RESUMEN EJECUTIVO (TARJETAS KPI) -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card report-card h-100 border-0 bg-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase fw-normal">Total llamadas</h6>
                                <h2 class="fw-bold">{{ $reporte->total->llamadas }}</h2>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle"><i class="fas fa-chart-bar fa-2x text-primary"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card report-card h-100 border-0 bg-exito">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase fw-normal">Exitosas</h6>
                                <h2 class="fw-bold text-success">{{ $reporte->total->llamada_exitosa }}</h2>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle"><i class="fas fa-check-circle fa-2x text-success"></i></div>
                        </div>
                        <small class="text-muted">{{ round(($reporte->total->llamada_exitosa / $reporte->total->llamadas)*100,0) }}% del total</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card report-card h-100 border-0 bg-fallo">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase fw-normal">Fallidas</h6>
                                <h2 class="fw-bold text-danger">{{$reporte->total->llamadas-$reporte->total->llamada_exitosa}}</h2>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded-circle"><i class="fas fa-times-circle fa-2x text-danger"></i></div>
                        </div>
                        <small class="text-muted">
                        {{ round((($reporte->total->llamadas-$reporte->total->llamada_exitosa)/ $reporte->total->llamadas)*100,0) }}% del total</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card report-card h-100 border-0 bg-advertencia">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase fw-normal">Conductores únicos</h6>
                                <h2 class="fw-bold" style="color:#b26a00;">{{  $reporte->total->conductores }}</h2>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle"><i class="fas fa-users fa-2x text-warning"></i></div>
                        </div>
                        <small class="text-muted">{{  $reporte->total->trts }} transportistas</small>
                    </div>
                </div>
            </div>
        </div>


        <!-- TOP 5 MEJORES CONDUCTORES -->
        <div class="card report-card mb-5">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h3 class="h4 fw-bold"><i class="fas fa-crown text-warning me-2"></i>Top 5 mejores conductores</h3>
                <p class="text-muted">Basado en éxito.</p>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="tabla-conductores">
                            <tr>
                                <th>#</th><th>Conductor</th><th>Transportista</th><th>Llamadas exitosas</th><th>Estrellas</th><th>Comportamiento</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reporte->mejores as $item)
                                <tr class="table-{{ $llamadas::color_porcentaje($item->tasa_exito) }}" >
                                    <td>{{ $item->conductor_id }}</td><td><strong>{{ $item->conductor }}</strong></td><td>{{ $item->trt }}</td><td>{{ $item->exitosas }}/{{ $item->total }}</td>
                                    <td><span class="text-success">{{ str_repeat('⭐', ($item->tasa_exito)/20  ) }} <br> Tasa de exito {{$item->tasa_exito}} %</span></td>
                                    <td>{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PEORES CONDUCTORES (múltiples fallos) -->
        <div class="card report-card mb-5">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h3 class="h4 fw-bold text-danger"><i class="fas fa-skull-crosswalk me-2"></i>Top 5 peores conductores</h3>
                <p class="text-muted">Múltiples intentos fallidos y problemas graves</p>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="tabla-conductores">
                            <tr><th>#id</th><th>Conductor</th><th>Transportista</th><th>Intentos fallidos</th><th>Nivel de riesgo</th><th>Problema principal</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($reporte->peores as $item)
                                <tr class="table-{{ $llamadas::color_porcentaje($item->tasa_exito) }}" >
                                    <td>{{ $item->conductor_id }}</td><td><strong>{{ $item->conductor }}</strong></td><td>{{ $item->trt }}</td><td>{{ $item->fallidas }}/{{ $item->total }}</td>
                                    <td><span class="text-danger">{{ str_repeat('🔴', (100-$item->tasa_exito)/20  ) }} <br>Tasa de exito {{$item->tasa_exito}} %</span></td>
                                    <td>{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- PEORES TRANSPORTISTAS -->
        <div class="card report-card mb-5">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h3 class="h4 fw-bold text-danger-emphasis"><i class="fas fa-truck-moving me-2" style="color:#b71c1c;"></i>Top 5 transportistas con más conductores problemáticos</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="tabla-conductores">
                            <tr><th>Transportista</th><th>Conductores únicos</th><th>Conductores fallidos (0 exitosas)</th><th>% problemático</th><th>Casos críticos</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>Grupo de Inversiones y Transportes Mendoza</strong></td><td>4</td><td>4</td><td><span class="badge bg-danger">100%</span></td><td>WILMER MERCADO (4 fallos), MARCOS CELESTINO (fallo)</td></tr>
                            <tr><td><strong>TRANSPORT COMPANY ALCANTARA SAC</strong></td><td>2</td><td>2</td><td><span class="badge bg-danger">100%</span></td><td>JOSE PUMAJULCA (3 fallos) y otro</td></tr>
                            <tr><td><strong>TRANSPORTES SAN PEDRO DE MARAÑON S.A.C.</strong></td><td>3</td><td>3</td><td><span class="badge bg-danger">100%</span></td><td>Roger Ramos (3 fallos), Jerson (??), Juver Sulca (buzón)</td></tr>
                            <tr><td><strong>Empresa de Transporte Vigsan EIRL</strong></td><td>4</td><td>3</td><td><span class="badge bg-warning text-dark">75%</span></td><td>LUIS CHUGNA (2 fallos), otros sin éxito</td></tr>
                            <tr><td><strong>TRANSPORTE FLASH & RAPIDASH S.A.C.</strong></td><td>3</td><td>2</td><td><span class="badge bg-warning text-dark">66%</span></td><td>BRANDON PARRA (buzón), WILDER ALVAREZ (?)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-light border mt-3 mb-0">
                    <i class="fas fa-lightbulb me-2 text-warning"></i> <strong>Otros transportistas con 100% problemáticos (17 en total):</strong> Inversiones y transportes Marisa EIRL, LOGISTICA INVERSIONES CAMPOS, GRUPO SANTO TORIBIO LOGISTICA, etc.
                </div>
            </div>
        </div>

        <!-- ANÁLISIS DE CORRELACIONES Y PROBLEMAS FRECUENTES -->
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="card report-card h-100">
                    <div class="card-header bg-white border-0 pt-4">
                        <h4 class="h5 fw-bold"><i class="fas fa-chart-pie me-2"></i>Motivos de fallo más comunes</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="badge bg-secondary me-2">1</span> Buzón de voz / No contesta</span>
                                <span class="badge bg-danger rounded-pill">22 casos</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="badge bg-secondary me-2">2</span> La IA se confunde / no escucha</span>
                                <span class="badge bg-warning rounded-pill">14 casos</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="badge bg-secondary me-2">3</span> Conductor cuelga abruptamente</span>
                                <span class="badge bg-info rounded-pill">8 casos</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="badge bg-secondary me-2">4</span> Error técnico / conexión Twilio</span>
                                <span class="badge bg-dark rounded-pill">7 casos</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="badge bg-secondary me-2">5</span> Mala señal / no escucha conductor</span>
                                <span class="badge bg-secondary rounded-pill">3 casos</span>
                            </li>
                        </ul>
                        <hr>
                        <p class="mb-0"><i class="fas fa-phone-slash me-1 text-danger"></i> <strong>Casos repetidos de error de conexión:</strong> WILMER MERCADO (3), JOSE PUMAJULCA (1), Roger Ramos (1).</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card report-card h-100">
                    <div class="card-header bg-white border-0 pt-4">
                        <h4 class="h5 fw-bold"><i class="fas fa-clipboard-list me-2"></i>Correlaciones con éxito</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Cuando la llamada es exitosa, es muy frecuente que:</strong></p>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: 95%;" role="progressbar">conductor_confirma (95%)</div>
                        </div>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-info" style="width: 68%;" role="progressbar">conductor_da_motivos (68%)</div>
                        </div>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-warning" style="width: 27%;" role="progressbar">conversación_fluida (27%)</div>
                        </div>
                        <p class="mt-3"><strong>Casos donde éxito = 0 pero conductor contestó:</strong> 14 llamadas (conductor cuelga, IA se confunde, o no escucha).</p>
                        <p class="mb-0 text-muted fst-italic">* El 73% de fallos se debe a problemas ajenos al conductor (buzón, técnico, IA).</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECOMENDACIONES ESPECÍFICAS -->
        <div class="card report-card mb-4 bg-light">
            <div class="card-body p-4">
                <h3 class="h4 fw-bold mb-3"><i class="fas fa-list-check me-2 text-primary"></i>Recomendaciones inmediatas</h3>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>Conductores con 3+ fallos:</strong> Revisar números (Wilmer Mercado, José Pumajulca, Roger Ramos). Posible teléfono erróneo o spam.</li>
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>Transportistas 100% fallo:</strong> Reunión con 17 empresas (ej. Grupo Mendoza, Alcantara) para validar datos de contacto.</li>
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>Buzón de voz (22 casos):</strong> Implementar estrategia de reintento en diferente horario o SMS previo.</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>Errores técnicos (7 Twilio):</strong> Coordinar con proveedor VAPI / verificar saldo o configuración de carrier.</li>
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>Mejores conductores:</strong> Usar como referencia para incentivos (Santos, Jorge Sánchez) y mejorar guión.</li>
                            <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><strong>IA se confunde (14):</strong> Revisar entrenamiento fonético para nombres y ruido de fondo.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- FOOTER / NOTAS ACLARATORIAS -->
        <div class="footer-note pt-3 d-flex justify-content-between">
            <span><i class="far fa-file-excel me-1"></i> Datos: reporte.xlsx (hoja FILTRADO) | 82 registros procesados.</span>
            <span><i class="fas fa-database me-1"></i> Indicador único de éxito: <code>llamada_exitosa = 1</code> (se ignora "exitosa_segun_ia").</span>
        </div>
    </div>

    <!-- Bootstrap JS (opcional para tooltips, pero solo para funcionalidad completa) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>