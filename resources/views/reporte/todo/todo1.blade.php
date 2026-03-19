<!DOCTYPE html>
<html lang="es">
<head>
    @php
        if (request('fecha_inicio') and request('fecha_fin'))
            $fecha_rango= $llamadas->format_fecha(request('fecha_inicio'),'d/m/Y')  . ' hasta ' . $llamadas->format_fecha(request('fecha_fin'),'d/m/Y');
        else
            $fecha_rango= $llamadas->format_fecha(request('fecha_inicio'),'d/m/Y');
    @endphp

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reporte->titulo . ' - ' . $fecha_rango }}</title>
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
                <span class="badge bg-dark p-3 fs-6">
                <i class="far fa-calendar-alt me-2"></i>Llamadas analizadas: {{ $fecha_rango }}</span>
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
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle"><i class="fas fa-phone-volume fa-3x text-info opacity-75"></i></div>
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
                        @php
                            $exitosas_100=round(($reporte->total->llamada_exitosa / $reporte->total->llamadas)*100,0);
                        @endphp
                        <small class="text-muted">{{ $exitosas_100 }}% del total</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card report-card h-100 border-0 bg-fallo">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase fw-normal">Fallidas</h6>
                                @php
                                    $fallidas=$reporte->total->llamadas-$reporte->total->llamada_exitosa;
                                    $fallidas_100=round((($fallidas)/ $reporte->total->llamadas)*100,0);
                                @endphp
                                <h2 class="fw-bold text-danger">{{$fallidas}}</h2>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded-circle"><i class="fas fa-times-circle fa-2x text-danger"></i></div>
                        </div>
                        <small class="text-muted">
                        {{ $fallidas_100 }}% del total</small>
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

    @if($reporte->total->llamada_exitosa)
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
                                @if($item->exitosas)
                                    <tr class="table-{{ $llamadas::color_porcentaje($item->tasa_exito) }}" >
                                        <td>{{ $item->conductor_id }}</td><td><strong>{{ $item->conductor }}</strong></td><td>{{ $item->trt }}</td>
                                        <td><span class='text-success fw-bold'>{{ $item->exitosas }}</span>/{{ $item->total }}</td>
                                        <td><span class="text-success">{{ str_repeat('⭐', ($item->tasa_exito)/20  ) }} <br> Tasa de exito {{$item->tasa_exito}} %</span></td>
                                        <td>{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                                    </tr>
                                @endif
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    @endif

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
                                @if($item->fallidas)
                                    <tr class="table-{{ $llamadas::color_porcentaje($item->tasa_exito) }}" >
                                        <td>{{ $item->conductor_id }}</td><td><strong>{{ $item->conductor }}</strong></td>
                                        <td>{{ $item->trt }}</td><td><span class='text-danger fw-bold'>{{ $item->fallidas }}</span>/{{ $item->total }}</td>
                                        <td><span class="text-danger">{{ str_repeat('🔴', (100-$item->tasa_exito)/20  ) }} <br>Tasa de exito {{$item->tasa_exito}} %</span></td>
                                        <td>{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                                    </tr>
                                @endif
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
                            <tr><th>Transportista</th><th>Conductores únicos</th><th>Conductores fallidos (0 exitos) / con un fallo</th><th>% problemático</th><th>Nivel de riesgo</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($reporte->peores_trts as $item)
                                @php $problematicos= round((($item->conductores  - $item->conductores_con_exito)/$item->conductores)*100,1) @endphp
                                <tr class="table-{{ $llamadas::color_porcentaje($item->tasa_exito) }}" >
                                    <tr><td><strong>{{ $item->trt }}</strong></td>
                                    <td>{{ $item->conductores  }}</td>
                                    <td><span class='text-danger fw-bold'>{{ $item->conductores  - $item->conductores_con_exito }} </span>/ {{ $item->conductores_con_fallo }}</td>
                                    <td><span class="badge bg-{{ $llamadas::color_porcentaje(100-$problematicos) }}">{{  $problematicos }}%</span></td>
                                    <td><span class="text-danger">{{ str_repeat('🔴', (100-$item->tasa_exito)/20  ) }} <br>Tasa de exito {{$item->tasa_exito}} %</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


    <div class="row  g-4 mb-5">


        <!-- correlacion de  exito -->
        @if($reporte->total->llamada_exitosa)
            @php
                $da_motivos_100= round(($reporte->total->conductor_da_motivos /$reporte->total->llamada_exitosa)*100,0) ;
                $fluida_100= round(($reporte->total->conversacion_fluida /$reporte->total->llamada_exitosa)*100,0);
            @endphp
            <div class="col-lg-12">
                <div class="card report-card h-100">
                    <div class="card-header bg-white border-0 pt-4">
                        <h4 class="h5 fw-bold"><i class="fas fa-clipboard-list me-2"></i>Correlaciones con éxito</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Cuando la llamada es exitosa, es muy frecuente que:</strong></p>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: 100%;" role="progressbar">conductor_confirma (100%)</div>
                        </div>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-info" style="width: {{ $da_motivos_100 }}%;" role="progressbar">conductor_da_motivos ({{ $da_motivos_100 }}%)</div>
                        </div>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-warning" style="width: {{ $fluida_100 }}%;" role="progressbar">conversación_fluida ({{ $fluida_100 }}%)</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
        <!-- por q el porcentaje de fallo -->
    <div class="mb-5">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="display-5"><i class="bi bi-question-octagon text-danger"></i></span>
            <h1 class="display-6 fw-semibold" style="color: #12263a;">¿Por qué el {{ $fallidas_100 }}% de las llamadas son fallidas?</h1>
        </div>
        <p class="lead ps-5 text-secondary">Análisis que incorpora las etiquetas de llamada.</p>
    </div>

        <!-- ===== ANÁLISIS POR ETIQUETA ===== -->
    <div class="row g-4 mb-5">
        <!-- FALLO DE CONTACTO -->
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                @php
                    $total=$reporte->total->buzon_de_voz + $reporte->total->razon_3_no_contesta + $reporte->total->razon_5_ocupado;
                    $fallo_contacto_100= round(($total/$fallidas)*100,1);
                @endphp
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="fs-2 text-warning"><i class="bi bi-mailbox2"></i></span>
                    <h3 class="h4 mb-0">Fallo de contacto <span class="badge bg-warning bg-opacity-15 text-dark ms-3">{{ $total }} fallos</span></h3>
                </div>
                <p><strong>{{  $fallo_contacto_100 }}% de los fallos</strong> – el conductor no responde o la llamada va a buzón.</p>
                <div class="ms-3">
                    <span class="etiqueta"><i class="bi bi-voicemail me-1"></i> buzón de voz: <strong>{{ $reporte->total->buzon_de_voz }}</strong></span> <br>
                    <span class="etiqueta"><i class="bi bi-telephone-x me-1"></i> no contesta: <strong>{{ $reporte->total->razon_3_no_contesta }}</strong></span>
                    <br>
                    <span class="etiqueta">
                        <i class="bi bi-hourglass me-1"></i> ocupado: <strong>{{ $reporte->total->razon_5_ocupado}}</strong></span>
                </div>
                <hr>
                <h6>📌 Causa principal:</h6>
                <ul>
                    <li>Números no atendidos en ese horario o contactos desactualizados.</li>
                    <li>Conductores no contestan adrede.</li>
                </ul>
            </div>
        </div>

        <!-- NO COOPERA -->
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                @php
                    $solo_cuelga=$reporte->total->cuelga_analisis;
                    if($reporte->total->solo_cuelga > $reporte->total->cuelga_analisis) $solo_cuelga=$reporte->total->solo_cuelga;
                    $total=$reporte->total->conductor_contesta_pero_no_habla+ $solo_cuelga;
                    $fallo_no_copera_100= round(($total/$fallidas)*100,1);
                @endphp
                    <span class="fs-2 text-danger"><i class="bi bi-person-fill-slash"></i></span>
                    <h3 class="h4 mb-0">Conductor no coopera <span class="badge bg-danger bg-opacity-10 text-danger ms-3">{{ $total }} fallos</span></h3>
                </div>
                <p><strong>{{$fallo_no_copera_100}}% de los fallos</strong> – el conductor contesta pero no facilita el objetivo.</p>
                <div class="ms-3">
                    <span class="etiqueta etiqueta-conductor" style="font-size:1rem;"><i class="bi bi-mic-mute me-1"></i> contesta pero no habla: <strong>{{ $reporte->total->conductor_contesta_pero_no_habla}}</strong></span> <br>
                    <span class="etiqueta etiqueta-conductor" style="font-size:1rem;">
                        <i class="bi bi-telephone-x me-1"></i> colgo directamente: <strong>{{ $solo_cuelga }}</strong></span>
                </div>
                <hr>
                <h6>📌 Patrón crítico:</h6>
                <ul>
                    <li>Contesta pero no emite palabra.</li>
                    <li>Cuelga sin intención de dialogar.</li>
                </ul>
            </div>
        </div>

        <!-- IA -->
        <div class="col-lg-6">
            <div class="card p-4 border-info border-2">
                <div class="d-flex align-items-center gap-3 mb-3">
                @php
                    $fallo_ia_100= round(($reporte->total->error_ia/$fallidas)*100,1);
                @endphp
                    <span class="fs-2 text-info"><i class="bi bi-robot"></i></span>
                    <h3 class="h4 mb-0">Error de IA<span class="badge bg-info ms-3">{{ $reporte->total->error_ia }} fallos</span></h3>
                </div>
                <p><strong>{{ $fallo_ia_100 }}% de los fallos.</strong> <br>
                Errores referentes a la ia en todas las llamadas no necesariamente con llevan a un fallo:</p>
                <code>ia_se_confunde = {{ $reporte->total->ia_se_confunde }} <br>
                ia_no_escucha = {{ $reporte->total->ia_no_escucha }} <br>
                ia_error_interpretacion = {{ $reporte->total->ia_error_interpretacion }} <br>
                ia_dice_variable = {{ $reporte->total->ia_dice_variable }} <br>
                ia_mala_pronunciacion = {{ $reporte->total->ia_mala_pronunciacion }} <br>
                </code>

                <hr>
                <p class="mt-2">Aunque representan un porcentaje pequeño, es importante documentarlos para mejorar el modelo de voz</p>
            </div>
        </div>

        <!-- OTROS -->
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                @php
                    $total_3=$reporte->total->buzon_de_voz + $reporte->total->razon_3_no_contesta + $reporte->total->error_ia +$reporte->total->conductor_contesta_pero_no_habla+ $reporte->total->cuelga_analisis;
                    $total=$fallidas-$total_3;
                    $fallo_otros_100= round(($total/$fallidas)*100,1);
                @endphp
                <div class="d-flex align-items-center gap-3 mb-3">
                    <i class="bi bi-hdd-stack-fill text-secondary me-1"></i></span>
                    <h3 class="h4 mb-0">Otros <span class="badge bg-primary bg-opacity-15 text-white ms-3">{{ $total }} fallos</span></h3>
                </div>

                <p><strong>{{  $fallo_otros_100 }}% de los fallos</strong>:</p>
                <div class="ms-3">
                    <ul class="etiqueta list-unstyled">
                        <li><i class="bi bi-volume-mute me-2 text-danger"></i>
                            Conductor no escucha: {{ $reporte->total->conductor_no_escucha }}</li>
                        <li><i class="bi bi-reception-1 me-2 text-warning"></i>
                            Conductor mala señal: {{ $reporte->total->conductor_mala_senal }}</li>
                        <li><i class="bi bi-question-circle me-2 text-info"></i>
                            Confusión en llamada: {{ $reporte->total->confusion_en_llamada }}</li>
                        <li><i class="bi bi-person-x me-2 text-secondary"></i>
                            Contesta otra persona: {{ $reporte->total->contesta_otra_persona }}</li>
                        <li><i class="bi bi-check2-square me-2 text-primary"></i>
                            Confirmacion Parcial: {{ $reporte->total->confirmacion_parcial }}</li>
                        <li><i class="bi bi-telephone-minus me-2 text-dark"></i>
                            Número equivocado: {{ $reporte->total->numero_equivocado }}</li>
                        <li><i class="{{ $llamadas::icon_exito(-1,true) }}"></i>
                            Error desconocido:{{ $reporte->total->error_desconocido }}</li>
                        <li><i class="{{ $llamadas::icon_exito(2,true) }}"></i>
                            Error de red:{{ $reporte->total->error_red }}</li>
                        <li><i class="{{ $llamadas::icon_exito(3,true) }}"></i>
                            Error de sistema:{{ $reporte->total->error_sistema }}</li>
                        <li>otros motivos...</li>

                    </ul>
                </div>
                <hr>
                <h6>📌 Algunos errores se deben a factores desconocido no especificados en las etiquetas</h6>
            </div>
        </div>
    </div>

        <!-- FOOTER / NOTAS ACLARATORIAS -->
        <div class="footer-note pt-3 d-flex justify-content-between">
            <span><i class="far fa-file-excel me-1"></i> Datos: Reporte Generado con LUPITA</span>
            <span><i class="fas fa-database me-1"></i> Indicador único de éxito: <code>llamada_exitosa = 1</code> (se ignora "exitosa_segun_ia").</span>
        </div>
    </div>

    <!-- Bootstrap JS ----------------------------------- -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
