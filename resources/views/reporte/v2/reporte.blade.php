@extends($layout ?? 'layouts.app')
{{-- $layout = layouts.export cuando el reporte se descarga como .html --}}

@section('title', 'Reporte VAPI')

@section('heads')
    <style>
        /* El reporte reproduce el reporte clasico (reporte/todo/todo1): lienzo
           claro, tarjetas de color y cabeceras azul oscuro, montado sobre el
           tema oscuro del CRM. Todo va prefijado con .reporte-todo para no
           tocar el resto del sistema. */
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

        /* tarjetas de estado: mismos degradados que todo1 */
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

        .reporte-todo .tabla-conductores th {
            background: #2c3e50;
            color: #fff;
            font-weight: 500;
        }

        .reporte-todo .progress {
            background: #e9ecef;
        }

        .reporte-todo .heatmap-cell {
            text-align: center;
            font-size: .8rem;
            padding: 5px;
            border-radius: 5px;
        }

        .reporte-todo .footer-note {
            font-size: .85rem;
            color: #5f6368;
            border-top: 1px dashed #ccc;
        }

        /* el reporte esta pensado tambien para imprimirse o guardarse en PDF */
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

    // mismo titulo que todo1 segun el tipo elegido en los filtros
    $titulos_tipo = [
        1 => 'Reporte de Confirmaciones VAPI',
        2 => 'Reporte de Espera fuera de planta para carga VAPI',
        3 => 'Reporte de Espera dentro de planta para carga VAPI',
        5 => 'Reporte de Espera fuera de planta para descarga VAPI',
        6 => 'Reporte de Espera dentro de planta para descarga VAPI',
    ];
    $titulo_reporte = $titulos_tipo[(int) ($filtros->llamada_tipo_id ?? 0)] ?? 'Reporte de llamadas totales VAPI';

    $titulo_periodo = $p->dias === 1
        ? $llamadas->format_fecha($p->desde, 'd/m/Y') . ' ' . $llamadas->get_fecha_day_name($p->desde)
        : 'Del ' . $llamadas->format_fecha($p->desde, 'd/m/Y') . ' al ' . $llamadas->format_fecha($p->hasta, 'd/m/Y');

    // filtros que se conservan al navegar entre reportes
    $params_base = array_filter([
        'fecha_inicio' => $p->desde,
        'fecha_fin' => $p->hasta,
        'llamada_tipo_id' => $filtros->llamada_tipo_id,
        'trt' => $filtros->trt_id,
    ], fn($x) => $x !== null && $x !== '');

    $params_descarga = $params_base + ['min_llamadas' => $min_llamadas];

    $fallidas = (int) $t->fallidas;
    $exitosas_100 = $t->total ? round($t->exitosas / $t->total * 100) : 0;
    $fallidas_100 = $t->total ? round($fallidas / $t->total * 100) : 0;

    // [etiqueta, valor, subtexto, fondo, color del numero, icono]
    $resumen = [
        ['Total llamadas', number_format($t->total), '$' . number_format((float) $t->costo, 2), '', '#1e2a3a', 'bi-telephone-volume text-info'],
        ['Exitosas', number_format($t->exitosas), $exitosas_100 . '% del total ($' . number_format((float) $t->costo_exitosa, 2) . ')', 'bg-exito', '#2e7d32', 'bi-check-circle-fill text-success'],
        ['Fallidas', number_format($fallidas), $fallidas_100 . '% del total ($' . number_format((float) $t->costo_fallida, 2) . ')', 'bg-fallo', '#c62828', 'bi-x-circle-fill text-danger'],
        ['Conductores únicos', number_format($t->conductores), $t->trts . ' transportistas', 'bg-advertencia', '#b26a00', 'bi-people-fill text-warning'],
        ['Llamadas contestadas', number_format($t->contestadas), $t->buzon_de_voz . ' buzón de voz', 'bg-exito', '#2e7d32', 'bi-telephone-outbound-fill text-success'],
        ['Conductores confirmados', number_format($t->conductores_exitosos), ($t->duracion_prom_exitosa ?: 0) . 's promedio de llamada', 'bg-exito', '#2e7d32', 'bi-patch-check-fill text-success'],
        ['Llamadas no contestadas', number_format((int) $t->razon_5_ocupado + (int) $t->razon_3_no_contesta), (int) $t->razon_3_no_contesta . ' no contesta · ' . (int) $t->razon_5_ocupado . ' ocupado', 'bg-fallo', '#c62828', 'bi-telephone-x-fill text-danger'],
        ['Contestadas sin confirmación', number_format($t->contestadas_fallidas), ($t->duracion_prom_fallida ?: 0) . 's promedio de llamada', 'bg-fallo', '#c62828', 'bi-exclamation-triangle-fill text-danger'],
    ];

    // reparto del tiempo de audio: exitosas vs fallidas
    $dur_total = (float) $t->audio_duracion_total;
    $dur_exitosas = $dur_total ? round((float) $t->audio_duracion_exitosas / $dur_total * 100) : 0;
    $dur_fallidas = $dur_total ? round((float) $t->audio_duracion_fallidas / $dur_total * 100) : 0;
    $dur_resto = max(0, 100 - $dur_exitosas - $dur_fallidas);

    // embudo de conversion: [etiqueta, emoji, valor, porcentaje, color]
    $p_contestadas = $t->total ? round($t->contestadas / $t->total * 100) : 0;
    $embudo_conversacion = max(0, $t->contestadas - $t->conductor_contesta_pero_no_habla - $t->solo_cuelga);
    $p_conversacion = $t->total ? round($embudo_conversacion / $t->total * 100) : 0;
    $da_motivos_100 = $t->total ? round($t->conductor_da_motivos / $t->total * 100, 1) : 0;
    $fluida_100 = $t->total ? round($t->conversacion_fluida / $t->total * 100, 1) : 0;
    $embudo = [
        ['Totales', '📞', $t->total, 100, 'success'],
        ['Contestadas', '✅', $t->contestadas, $p_contestadas, 'primary'],
        ['Conversación', '💬', $embudo_conversacion, $p_conversacion, 'info'],
        ['Exitosas', '🎯', $t->exitosas, $exitosas_100, 'warning'],
        ['Da motivos', '🧠', $t->conductor_da_motivos, $da_motivos_100, 'danger'],
        ['Conversación fluida', '🗣️', $t->conversacion_fluida, $fluida_100, 'secondary'],
    ];

    // motivos de fallo (mismo criterio que todo1)
    $fallo_contacto_total = (int) $t->buzon_de_voz + (int) $t->razon_3_no_contesta + (int) $t->razon_5_ocupado;
    $fallo_contacto_100 = $fallidas ? round($fallo_contacto_total / $fallidas * 100, 1) : 0;
    $fallo_no_coopera_total = (int) $t->conductor_contesta_pero_no_habla + (int) $t->solo_cuelga;
    $fallo_no_coopera_100 = $fallidas ? round($fallo_no_coopera_total / $fallidas * 100, 1) : 0;
    $errores_tecnicos = (int) $t->error_desconocido + (int) $t->error_red + (int) $t->error_sistema + (int) $t->error_ia;
    $errores_tecnicos_100 = $fallidas ? round($errores_tecnicos / $fallidas * 100, 1) : 0;
    $fallo_otros_total = max(0, $fallidas - $fallo_contacto_total - $fallo_no_coopera_total - $errores_tecnicos);
    $fallo_otros_100 = $fallidas ? round($fallo_otros_total / $fallidas * 100, 1) : 0;
    $datos_fallidos = [$fallo_contacto_total, $fallo_no_coopera_total, $errores_tecnicos, $fallo_otros_total];

    // serie temporal (el controller ya la agrupa por dia, semana o mes)
    $serie_labels = $serie['labels'];
    $serie_exitosas = $serie['exitosas'];
    $serie_fallidas = array_map(fn($total, $exito) => max(0, $total - $exito), $serie['total'], $serie['exitosas']);
    $serie_tasa = $serie['tasa'];
    // un solo punto no dibuja una linea: se cambia a barras
    $serie_corta = count($serie_labels) < 2;

    // mapa de calor dia de la semana x hora
    $heat = $heatmap['matriz'];
    $heat_max = max(1, (int) $heatmap['maximo']);
    $heat_exito_max = 1;
    foreach ($heat as $fila) {
        foreach ($fila as $celda) {
            $heat_exito_max = max($heat_exito_max, (int) $celda['exitosas']);
        }
    }
    $dias_cortos = [1 => 'Dom', 2 => 'Lun', 3 => 'Mar', 4 => 'Mié', 5 => 'Jue', 6 => 'Vie', 7 => 'Sáb'];

    // transportistas con mas conductores problematicos
    $trts_problema = array_values(array_filter(
        $trts,
        fn($x) => (int) $x->conductores > 0 && ((int) $x->conductores - (int) $x->conductores_con_exito) > 0
    ));
    usort($trts_problema, function ($a, $b) {
        $pa = ($a->conductores - $a->conductores_con_exito) / max(1, $a->conductores);
        $pb = ($b->conductores - $b->conductores_con_exito) / max(1, $b->conductores);
        return $pb <=> $pa;
    });
    $trts_problema = array_slice($trts_problema, 0, 5);

    // Venn IA vs humano
    $venn_ia_total = $ia->ambos_si + $ia->solo_ia;
    $venn_humano_total = $ia->ambos_si + $ia->solo_humano;
    $venn_dif_ia = $venn_ia_total ? round($ia->solo_ia / $venn_ia_total * 100, 1) : 0;
    $venn_int_ia = $venn_ia_total ? round($ia->ambos_si / $venn_ia_total * 100, 1) : 0;
    $venn_int_humano = $venn_humano_total ? round($ia->ambos_si / $venn_humano_total * 100, 1) : 0;
    $venn_dif_humano = $venn_humano_total ? round($ia->solo_humano / $venn_humano_total * 100, 1) : 0;

    // rutas (solo cuando el periodo es un dia)
    $max_ruta = 1;
    if ($rutas) {
        foreach ($rutas->lista_detalle as $item) {
            $max_ruta = max($max_ruta, (int) $item->veces_usada);
        }
    }
@endphp

@section('content')
    {{-- la barra de acciones y los filtros son del CRM: no viajan en el .html descargado --}}
    @unless(($layout ?? null) === 'layouts.export')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
        <div>
            <span class="text-white-50">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Reporte en pantalla
            </span>
            <span class="badge bg-dark border border-secondary p-2 ms-1">
                <i class="bi bi-calendar-range me-2"></i>{{ $titulo_periodo }} · {{ $p->dias }} día(s)
            </span>
            @if($filtros->trt_id !== null)
                <span class="badge bg-info text-dark p-2">TRT #{{ $filtros->trt_id }}</span>
            @endif
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('lupita.reportes.dashboard', $params_base) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-speedometer2"></i> Abrir dashboard
            </a>
            <a href="{{ route('lupita.reportes.reporte.html', $params_descarga) }}" class="btn btn-success btn-sm">
                <i class="bi bi-download"></i> Descargar HTML
            </a>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="bi bi-printer"></i> Imprimir / PDF
            </button>
            <a href="{{ route('lupita.reportes.dia', $params_base) }}" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-text"></i> Un día (correo)
            </a>
            <a href="{{ route('lupita.reportes.rango', $params_base) }}" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Rango (correo)
            </a>
        </div>
    </div>

    {{-- ============ FILTROS ============ --}}
    <form method="GET" action="{{ route('lupita.reportes.reporte') }}" class="mb-4 no-print">
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
                <h2 class="display-6 fw-bold mb-1" style="color:#1e2a3a;">
                    <i class="bi bi-telephone-fill text-primary me-3"></i>{{ $titulo_reporte }}
                </h2>
                <p class="lead mb-0">
                    Análisis de llamadas totales | Exitosas =
                    <span class="badge bg-success">{{ number_format($t->exitosas) }}</span>
                </p>
            </div>
            <div class="text-end">
                <span class="badge bg-dark p-3 fs-6">
                    <i class="bi bi-calendar-alt me-2"></i>Llamadas analizadas: {{ $titulo_periodo }}
                </span>
            </div>
        </div>

        {{-- ============ RESUMEN: 8 TARJETAS ============ --}}
        <div class="row g-4 mb-4">
            @foreach($resumen as $r)
                <div class="col-md-6 col-xl-3">
                    <div class="card report-card h-100 {{ $r[3] }}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted text-uppercase fw-normal">{{ $r[0] }}</h6>
                                    <h2 class="fw-bold" style="color:{{ $r[4] }};">{{ $r[1] }}</h2>
                                </div>
                                <div class="bg-white bg-opacity-50 p-3 rounded-circle align-self-start">
                                    <i class="bi {{ $r[5] }} fs-3"></i>
                                </div>
                            </div>
                            <small class="text-muted">{{ $r[2] }}</small>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ============ REPARTO DEL AUDIO ============ --}}
        @if($dur_total > 0)
            <div class="mb-5">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Reparto del tiempo de audio ({{ $llamadas::audio_duracion_format($t->audio_duracion_total) }} en total)</span>
                    <span>Exitosas {{ $dur_exitosas }}% · Fallidas {{ $dur_fallidas }}%</span>
                </div>
                <div class="progress" style="height:26px;">
                    @if($dur_exitosas > 0)
                        <div class="progress-bar progress-bar-striped bg-success" style="width:{{ $dur_exitosas }}%;">
                            {{ $llamadas::audio_duracion_format($t->audio_duracion_exitosas) }}
                        </div>
                    @endif
                    @if($dur_fallidas > 0)
                        <div class="progress-bar bg-danger" style="width:{{ $dur_fallidas }}%;">
                            {{ $llamadas::audio_duracion_format($t->audio_duracion_fallidas) }}
                        </div>
                    @endif
                    @if($dur_resto > 0)
                        <div class="progress-bar bg-secondary" style="width:{{ $dur_resto }}%;"></div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ============ TOP 5 MEJORES CONDUCTORES ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h4 fw-bold"><i class="bi bi-trophy-fill text-warning me-2"></i>Top 5 mejores conductores</h3>
                <p class="text-muted mb-3">Basado en éxito (mínimo {{ $min_llamadas }} llamadas).</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="tabla-conductores">
                        <tr>
                            <th>#</th>
                            <th>Conductor</th>
                            <th>Transportista</th>
                            <th>Llamadas exitosas</th>
                            <th>Estrellas</th>
                            <th>Comportamiento</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($mejores as $item)
                            <tr>
                                <td>{{ $item->conductor_id }}</td>
                                <td><strong>{{ $item->conductor }}</strong></td>
                                <td>{{ $item->trt }}</td>
                                <td><span class="text-success fw-bold">{{ $item->exitosas }}</span>/{{ $item->total }}</td>
                                <td>
                                    <span class="text-warning">{{ str_repeat('⭐', max(0, (int) round($item->tasa_exito / 20))) }}</span>
                                    <br><small class="text-muted">Tasa de éxito {{ $item->tasa_exito }}%</small>
                                </td>
                                <td class="small">{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">Sin conductores con {{ $min_llamadas }} llamadas o más.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ============ PEORES CONDUCTORES ============ --}}
        @if($fallidas)
            <div class="card report-card mb-4">
                <div class="card-body">
                    <h3 class="h4 fw-bold text-danger"><i class="bi bi-exclamation-octagon-fill me-2"></i>Top 5 peores conductores</h3>
                    <p class="text-muted mb-3">Múltiples intentos fallidos y problemas graves (mínimo {{ $min_llamadas }} llamadas).</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="tabla-conductores">
                            <tr>
                                <th>#id</th>
                                <th>Conductor</th>
                                <th>Transportista</th>
                                <th>Intentos fallidos</th>
                                <th>Nivel de riesgo</th>
                                <th>Problema principal</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($peores as $item)
                                <tr>
                                    <td>{{ $item->conductor_id }}</td>
                                    <td><strong>{{ $item->conductor }}</strong></td>
                                    <td>{{ $item->trt }}</td>
                                    <td><span class="text-danger fw-bold">{{ $item->fallidas }}</span>/{{ $item->total }}</td>
                                    <td>
                                        <span class="text-danger">{{ str_repeat('🔴', max(0, (int) round((100 - $item->tasa_exito) / 20))) }}</span>
                                        <br><small class="text-muted">Tasa de éxito {{ $item->tasa_exito }}%</small>
                                    </td>
                                    <td class="small">{!! $llamadas::top_peores_ordenar_etiquetas($item) !!}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted text-center">Sin conductores con {{ $min_llamadas }} llamadas o más.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ============ PEORES TRANSPORTISTAS ============ --}}
            @if(count($trts_problema))
                <div class="card report-card mb-4">
                    <div class="card-body">
                        <h3 class="h4 fw-bold text-danger">🚛 Top 5 transportistas con más conductores problemáticos</h3>
                        <div class="table-responsive mt-3">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="tabla-conductores">
                                <tr>
                                    <th>Transportista</th>
                                    <th>Conductores únicos</th>
                                    <th>Conductores sin éxito</th>
                                    <th>% problemático</th>
                                    <th>Llamadas</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($trts_problema as $item)
                                    @php
                                        $sin_exito = (int) $item->conductores - (int) $item->conductores_con_exito;
                                        $problematicos = round($sin_exito / max(1, (int) $item->conductores) * 100, 1);
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $item->trt }}</strong></td>
                                        <td>{{ $item->conductores }}</td>
                                        <td><span class="text-danger fw-bold">{{ $sin_exito }}</span>/{{ $item->conductores }}</td>
                                        <td>
                                            <span class="badge bg-{{ $llamadas::color_porcentaje(100 - $problematicos) }}">{{ $problematicos }}%</span>
                                        </td>
                                        <td>
                                            <span class="text-success fw-bold">{{ $item->exitosas }}</span>/{{ $item->total }}
                                            <br><small class="text-muted">Tasa de éxito {{ $item->tasa_exito }}%</small>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        {{-- ============ EMBUDO DE CONVERSION ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h5 fw-bold"><i class="bi bi-funnel-fill me-2"></i>Embudo de Conversión</h3>
                <p class="text-muted small">Análisis por etapa</p>
                @foreach($embudo as $paso)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-{{ $paso[4] }} rounded-pill">{{ $paso[1] }}</span>
                            <span class="small fw-semibold">{{ $paso[0] }}</span>
                        </div>
                        <div>
                            <span class="badge bg-{{ $paso[4] }} {{ $paso[4] === 'warning' ? 'text-dark' : '' }}">{{ number_format($paso[2]) }}</span>
                            <span class="badge bg-secondary ms-1">{{ $paso[3] }}%</span>
                        </div>
                    </div>
                    <div class="progress mb-3" style="height:20px;">
                        <div class="progress-bar bg-{{ $paso[4] }} {{ $paso[4] === 'warning' ? 'text-dark' : '' }}"
                             style="width:{{ min(100, max(0, (float) $paso[3])) }}%">{{ $paso[3] }}%</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ============ ETAPAS LOGISTICAS ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h5 fw-bold mb-3"><i class="bi bi-table me-2"></i>Resumen de éxito por etapa logística</h3>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="tabla-conductores">
                        <tr>
                            <th>#</th>
                            <th>Etapa</th>
                            <th>Llamadas</th>
                            <th>Exitosas</th>
                            <th>Días ({{ $p->dias }})</th>
                            <th>Tasa de éxito</th>
                        </tr>
                        </thead>
                        <tbody>
                        @for($i = 1; $i < 7; $i++)
                            @php
                                $etapa = $etapas[$i] ?? null;
                                $tipo = $etapa ? ($llamadas::$tipos_llamada[(int) $etapa->tipo] ?? null) : null;
                            @endphp
                            @if($etapa && $tipo)
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ $tipo->color }}">{{ $i }}</span>
                                    </td>
                                    <td>{{ $tipo->emoji }} {{ $tipo->nombre }}</td>
                                    <td>{{ number_format($etapa->total) }}</td>
                                    <td><strong class="text-success">{{ number_format($etapa->exitosas) }}</strong></td>
                                    <td><span class="badge bg-{{ $tipo->color }}">{{ $etapa->dias }}</span></td>
                                    <td>
                                        <div class="progress d-inline-flex" style="height:8px;width:100px;vertical-align:middle;">
                                            <div class="progress-bar bg-{{ $tipo->color }}" style="width:{{ $etapa->porcentaje }}%"></div>
                                        </div>
                                        &nbsp;{{ $etapa->porcentaje }}%
                                    </td>
                                </tr>
                            @endif
                        @endfor
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ============ PROGRESO DE EXITO ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <h3 class="h5 fw-bold"><i class="bi bi-graph-up-arrow me-2"></i>Progreso de éxito en llamadas</h3>
                <p class="text-muted small">
                    @if($serie['granularidad'] === 'dia')
                        Día a día
                    @elseif($serie['granularidad'] === 'semana')
                        Agrupado por semana
                    @else
                        Agrupado por mes
                    @endif
                </p>
                <div style="height:400px">
                    <canvas id="canvas_semana"></canvas>
                </div>
            </div>
        </div>

        {{-- ============ MAPA DE CALOR ============ --}}
        <div class="card report-card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div>
                        <h3 class="h4 fw-bold mb-0"><i class="bi bi-fire text-danger me-2"></i>Mapa de calor</h3>
                        <p class="text-muted small mb-0">Análisis de llamadas por día de la semana y hora del día.</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm no-print" onclick="alternar_mapa()">
                        <i class="bi bi-arrow-repeat me-1"></i> Totales / Exitosas
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered text-center align-middle mb-2" id="mapa_total" style="min-width:900px;">
                        <thead>
                        <tr>
                            <th class="bg-dark text-white">Hora</th>
                            @foreach([2, 3, 4, 5, 6, 7, 1] as $dow)
                                <th class="bg-dark text-white">{{ $dias_cortos[$dow] }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @for($h = 0; $h < 24; $h++)
                            <tr>
                                <td class="fw-bold">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</td>
                                @foreach([2, 3, 4, 5, 6, 7, 1] as $dow)
                                    @php
                                        $valor = (int) ($heat[$dow][$h]['total'] ?? 0);
                                        $alfa = $valor ? max(0.18, round($valor / $heat_max, 2)) : 0.04;
                                    @endphp
                                    <td class="heatmap-cell" style="background:rgba(13,110,253,{{ $alfa }});color:{{ $alfa >= 0.45 ? '#fff' : '#1e2a3a' }};"
                                        title="{{ $valor }} llamadas">{{ $valor ?: '' }}</td>
                                @endforeach
                            </tr>
                        @endfor
                        </tbody>
                    </table>

                    <table class="table table-sm table-bordered text-center align-middle mb-0 d-none" id="mapa_exito" style="min-width:900px;">
                        <thead>
                        <tr>
                            <th class="bg-dark text-white">Hora</th>
                            @foreach([2, 3, 4, 5, 6, 7, 1] as $dow)
                                <th class="bg-dark text-white">{{ $dias_cortos[$dow] }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @for($h = 0; $h < 24; $h++)
                            <tr>
                                <td class="fw-bold">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</td>
                                @foreach([2, 3, 4, 5, 6, 7, 1] as $dow)
                                    @php
                                        $valor = (int) ($heat[$dow][$h]['exitosas'] ?? 0);
                                        $alfa = $valor ? max(0.18, round($valor / $heat_exito_max, 2)) : 0.04;
                                    @endphp
                                    <td class="heatmap-cell" style="background:rgba(46,125,50,{{ $alfa }});color:{{ $alfa >= 0.45 ? '#fff' : '#1e2a3a' }};"
                                        title="{{ $valor }} llamadas exitosas">{{ $valor ?: '' }}</td>
                                @endforeach
                            </tr>
                        @endfor
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mb-0">Intensidad = cantidad de llamadas del bloque. Azul: totales, verde: exitosas.</p>
            </div>
        </div>

        @if($fallidas)
            {{-- ============ POR QUE FALLAN ============ --}}
            <div class="mb-3">
                <div class="d-flex align-items-center gap-3">
                    <span class="fs-2"><i class="bi bi-question-octagon text-danger"></i></span>
                    <h2 class="display-6 fw-semibold mb-0" style="color:#12263a;">¿Por qué el {{ $fallidas_100 }}% de las llamadas son fallidas?</h2>
                </div>
                <p class="lead ps-5 text-muted mb-0">Análisis que incorpora las etiquetas de llamada.</p>
            </div>

            <div class="card report-card mb-4">
                <div class="card-body">
                    <div style="height:420px">
                        <canvas id="chart_fallidos"></canvas>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                {{-- FALLO DE CONTACTO --}}
                <div class="col-lg-6">
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="fs-2 text-warning"><i class="bi bi-mailbox2"></i></span>
                                <h3 class="h4 mb-0">Fallo de contacto
                                    <span class="badge bg-warning text-dark ms-2">{{ number_format($fallo_contacto_total) }} fallos</span>
                                </h3>
                            </div>
                            <p class="mt-2"><strong>{{ $fallo_contacto_100 }}% de los fallos</strong> – el conductor no responde o la llamada va a buzón.</p>
                            <div class="ms-3 small">
                                <div><i class="bi bi-voicemail me-1"></i> buzón de voz: <strong>{{ number_format($t->buzon_de_voz) }}</strong></div>
                                <div><i class="bi bi-telephone-x me-1"></i> no contesta: <strong>{{ number_format($t->razon_3_no_contesta) }}</strong></div>
                                <div><i class="bi bi-hourglass me-1"></i> ocupado: <strong>{{ number_format($t->razon_5_ocupado) }}</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- CONDUCTOR NO COOPERA --}}
                <div class="col-lg-6">
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="fs-2 text-danger"><i class="bi bi-person-fill-slash"></i></span>
                                <h3 class="h4 mb-0">Conductor no coopera
                                    <span class="badge bg-danger ms-2">{{ number_format($fallo_no_coopera_total) }} fallos</span>
                                </h3>
                            </div>
                            <p class="mt-2"><strong>{{ $fallo_no_coopera_100 }}% de los fallos</strong> – el conductor contesta pero no facilita el objetivo.</p>
                            <div class="ms-3 small">
                                <div><i class="bi bi-mic-mute me-1"></i> contesta pero no habla: <strong>{{ number_format($t->conductor_contesta_pero_no_habla) }}</strong></div>
                                <div><i class="bi bi-telephone-x me-1"></i> colgó directamente: <strong>{{ number_format($t->solo_cuelga) }}</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ERRORES TECNICOS --}}
                <div class="col-lg-6">
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="fs-2 text-info"><i class="bi bi-tools"></i></span>
                                <h3 class="h4 mb-0">Errores técnicos
                                    <span class="badge bg-info text-dark ms-2">{{ number_format($errores_tecnicos) }} fallos</span>
                                </h3>
                            </div>
                            <p class="mt-2"><strong>{{ $errores_tecnicos_100 }}% de los fallos</strong> – errores referentes a la IA que no necesariamente conllevan a un fallo.</p>
                            <div class="ms-3 small">
                                <div class="mb-1">
                                    <i class="bi bi-robot me-1 text-info"></i> Error de IA: <strong>{{ number_format($t->error_ia) }}</strong>
                                    <div class="text-muted" style="font-size:.78rem;">
                                        se confunde: {{ $t->ia_se_confunde }},
                                        no escucha: {{ $t->ia_no_escucha }},
                                        interpretación: {{ $t->ia_error_interpretacion }},
                                        dice variable: {{ $t->ia_dice_variable }},
                                        mala pronunciación: {{ $t->ia_mala_pronunciacion }},
                                        cuelga en plena llamada: {{ $t->ia_cuelga_en_plena_llamada }}
                                    </div>
                                </div>
                                <div><i class="bi bi-wifi me-1"></i> Error de red: <strong>{{ number_format($t->error_red) }}</strong></div>
                                <div><i class="bi bi-question-circle me-1"></i> Error desconocido: <strong>{{ number_format($t->error_desconocido) }}</strong></div>
                                <div><i class="bi bi-hdd me-1"></i> Error de sistema: <strong>{{ number_format($t->error_sistema) }}</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- OTROS --}}
                <div class="col-lg-6">
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="fs-2 text-secondary"><i class="bi bi-hdd-stack-fill"></i></span>
                                <h3 class="h4 mb-0">Otros
                                    <span class="badge bg-secondary ms-2">{{ number_format($fallo_otros_total) }} fallos</span>
                                </h3>
                            </div>
                            <p class="mt-2"><strong>{{ $fallo_otros_100 }}% de los fallos</strong>:</p>
                            <ul class="list-unstyled ms-3 small mb-2">
                                <li><i class="bi bi-volume-mute me-2 text-danger"></i> Conductor no escucha: {{ number_format($t->conductor_no_escucha) }}</li>
                                <li><i class="bi bi-reception-1 me-2 text-warning"></i> Conductor mala señal: {{ number_format($t->conductor_mala_senal) }}</li>
                                <li><i class="bi bi-emoji-frown me-2 text-secondary"></i> Conducta inapropiada: {{ number_format($t->conductor_conducta_inapropiada) }}</li>
                                <li><i class="bi bi-question-circle me-2 text-info"></i> Confusión en llamada: {{ number_format($t->confusion_en_llamada) }}</li>
                                <li><i class="bi bi-person-x me-2 text-secondary"></i> Contesta otra persona: {{ number_format($t->contesta_otra_persona) }}</li>
                                <li><i class="bi bi-check2-square me-2 text-primary"></i> Confirmación parcial: {{ number_format($t->confirmacion_parcial) }}</li>
                                <li><i class="bi bi-telephone-minus me-2 text-dark"></i> Número equivocado: {{ number_format($t->numero_equivocado) }}</li>
                            </ul>
                            <span class="small text-muted">* Una llamada puede tener 1 o múltiples etiquetas.</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ VENN IA VS USUARIO ============ --}}
        @if($venn_ia_total || $venn_humano_total)
            <style>
                .reporte-todo .circulo-a,
                .reporte-todo .circulo-b {
                    width: 28vw;
                    height: 28vw;
                    max-width: 360px;
                    max-height: 360px;
                    min-width: 170px;
                    min-height: 170px;
                    position: relative;
                }
                .reporte-todo .circulo-a { right: -4vw; }
                .reporte-todo .circulo-b { right: 4vw; }
            </style>
            <div class="card report-card mb-4">
                <div class="card-body">
                    <h3 class="h4 fw-bold mb-3"><i class="bi bi-intersect text-secondary me-2"></i>Análisis de llamadas exitosas según IA vs Usuario</h3>

                    <div class="d-flex justify-content-center align-items-center position-relative mb-4" style="min-height:200px;">
                        <div class="bg-danger bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center circulo-a">
                            <span class="fw-bold text-danger text-center">
                                <i class="bi bi-robot"></i> IA<br>
                                <small>{{ number_format($venn_ia_total) }}</small>
                            </span>
                        </div>
                        <div class="bg-info bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center circulo-b">
                            <span class="fw-bold text-info text-center">
                                <i class="bi bi-person"></i> USUARIO<br>
                                <small>{{ number_format($venn_humano_total) }}</small>
                            </span>
                        </div>
                        <div class="position-absolute bg-white px-3 py-1 rounded-pill shadow-sm"
                             style="top:50%;left:50%;transform:translate(-50%,-50%);z-index:1;">
                            <i class="bi bi-intersect text-success"></i>
                            <strong class="text-success">{{ number_format($ia->ambos_si) }}</strong>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="tabla-conductores">
                            <tr>
                                <th>#</th>
                                <th>Región</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-center">Porcentaje</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>1</td>
                                <td><i class="bi bi-circle text-danger"></i> IA</td>
                                <td class="text-center">{{ number_format($venn_ia_total) }}</td>
                                <td class="text-center text-danger"><b>100%</b></td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><i class="bi bi-circle-fill text-danger"></i> IA sin coincidencia con el humano</td>
                                <td class="text-center">{{ number_format($ia->solo_ia) }}</td>
                                <td class="text-center text-danger"><b>{{ $venn_dif_ia }}%</b></td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><i class="bi bi-intersect text-success"></i> Intersección (ambos la marcan exitosa)</td>
                                <td class="text-center text-success"><b>{{ number_format($ia->ambos_si) }}</b></td>
                                <td class="text-center">
                                    <b class="text-danger"><i class="bi bi-robot"></i> {{ $venn_int_ia }}%</b>
                                    <b class="text-info"><i class="bi bi-person"></i> {{ $venn_int_humano }}%</b>
                                </td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><i class="bi bi-circle-fill text-info"></i> USUARIO sin coincidencia con la IA</td>
                                <td class="text-center">{{ number_format($ia->solo_humano) }}</td>
                                <td class="text-center text-info"><b>{{ $venn_dif_humano }}%</b></td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td><i class="bi bi-circle text-info"></i> USUARIO</td>
                                <td class="text-center">{{ number_format($venn_humano_total) }}</td>
                                <td class="text-center text-info"><b>100%</b></td>
                            </tr>
                            <tr>
                                <td>6</td>
                                <td><i class="bi bi-x-circle text-secondary"></i> Ambos la marcan fallida</td>
                                <td class="text-center">{{ number_format($ia->ambos_no) }}</td>
                                <td class="text-center text-muted">
                                    {{ number_format($ia->evaluadas) }} evaluadas · acuerdo {{ $ia->acuerdo }}%
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ RUTAS (solo un dia) ============ --}}
        @if($rutas)
            <div class="card report-card mb-4">
                <div class="card-body">
                    <h3 class="h4 fw-bold mb-3"><i class="bi bi-signpost-2 text-primary me-2"></i>Llamadas exitosas por ruta</h3>
                    <p class="text-muted">
                        Total de referencias procesadas (IA): <strong>{{ number_format((int) $rutas->resumen->ref_total) }}</strong><br>
                        Referencias con ruta identificada: <strong>{{ number_format((int) $rutas->resumen->ref_total_ruta) }}</strong><br>
                        Diversidad de rutas (distintas): <strong>{{ number_format((int) $rutas->resumen->ruta_distintas) }}</strong>
                    </p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="tabla-conductores">
                            <tr>
                                <th>#</th>
                                <th>Ruta</th>
                                <th class="text-center">Concurrencia</th>
                                <th class="text-center">Llamadas exitosas</th>
                                <th style="min-width:120px;">Peso</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($rutas->lista_detalle as $item)
                                @php
                                    $loc_destino_nombre = $item->loc_destino_nombre ?? ($item->ubg_destino_nombre ?? '');
                                    $loc_origen_nombre = $item->loc_origen_nombre ?? ($item->ubg_origen_nombre ?? '');
                                @endphp
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $loc_origen_nombre }} - {{ $loc_destino_nombre }}</td>
                                    <td class="text-center">{{ number_format((int) $item->veces_usada) }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-{{ $item->total_exito ? 'success' : 'danger' }}">{{ number_format((int) $item->total_exito) }}</span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height:6px;">
                                            <div class="progress-bar bg-info" style="width:{{ max(2, round($item->veces_usada / $max_ruta * 100)) }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-muted text-center">Sin rutas identificadas en el día.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ FOOTER ============ --}}
        <div class="footer-note pt-3 d-flex justify-content-between flex-wrap gap-2">
            <span><i class="bi bi-database me-1"></i> Datos: Reporte generado con LUPITA</span>
            <span><i class="bi bi-check2-circle me-1"></i> Indicador único de éxito: <code>llamada_exitosa = 1</code> (se ignora "exitosa según IA").</span>
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

        function alternar_mapa() {
            const total = document.getElementById('mapa_total');
            const exito = document.getElementById('mapa_exito');
            if (!total || !exito) return;
            total.classList.toggle('d-none');
            exito.classList.toggle('d-none');
        }

        const canvasSemana = document.getElementById('canvas_semana');
        if (canvasSemana) {
            // con un solo punto la linea queda vacia: se dibujan barras
            const serieCorta = @json($serie_corta);
            const comun = serieCorta
                ? { type: 'bar', borderWidth: 0, borderRadius: 6, maxBarThickness: 70 }
                : { type: 'line', borderWidth: 2, tension: .35 };

            new Chart(canvasSemana, {
                data: {
                    labels: @json($serie_labels),
                    datasets: [
                        {
                            ...comun,
                            label: 'Exitosas',
                            data: @json($serie_exitosas),
                            borderColor: '#2e7d32',
                            backgroundColor: serieCorta ? '#2e7d32' : 'rgba(46,125,50,.15)',
                            fill: !serieCorta,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        },
                        {
                            ...comun,
                            label: 'Fallidas',
                            data: @json($serie_fallidas),
                            borderColor: '#c62828',
                            backgroundColor: serieCorta ? '#c62828' : 'rgba(198,40,40,.08)',
                            fill: !serieCorta,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        },
                        {
                            type: 'line',
                            label: '% Éxito',
                            data: @json($serie_tasa),
                            borderColor: '#0d6efd',
                            backgroundColor: '#0d6efd',
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
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16, usePointStyle: true } },
                        datalabels: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Llamadas' } },
                        y1: { beginAtZero: true, max: 100, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '% éxito' } }
                    }
                }
            });
        }

        const canvasFallidos = document.getElementById('chart_fallidos');
        if (canvasFallidos) {
            new Chart(canvasFallidos, {
                type: 'pie',
                data: {
                    labels: ['Fallo de contacto', 'Conductor no coopera', 'Errores técnicos', 'Otros'],
                    datasets: [{
                        data: @json($datos_fallidos),
                        backgroundColor: ['#F2A007', '#E04B59', '#10CBF0', '#0F71F2'],
                        borderColor: 'transparent',
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'left', labels: { boxWidth: 12, padding: 12 } },
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 14 },
                            formatter: (value, ctx) => {
                                if (!value) return '';
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = (value / total) * 100;
                                // las porciones chicas no caben la etiqueta: se omiten
                                return pct < 5 ? '' : pct.toFixed(1) + '%';
                            }
                        }
                    }
                }
            });
        }
    </script>
@endsection
