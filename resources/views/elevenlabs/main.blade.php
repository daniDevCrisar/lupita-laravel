@extends('layouts.app')

@section('title', 'Eleven Labs')

@section('heads')
    <style>
        /* Mismo estilo del reproductor que /lupita/audio (procesar_audio.blade.php) */
        .btn-circular {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .reproductor-sticky {
            position: sticky;
            top: 0;
            z-index: 5;
            background-color: var(--bs-body-bg);
            border-bottom: 1px solid var(--bs-border-color);
        }

        /* Valores largos (JSON de variables) sin desbordar la tabla */
        .valor-pre {
            max-height: 130px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 11px;
            margin: 0;
        }

        .tabla-vars td {
            vertical-align: top;
        }

        .tabla-vars td:first-child {
            width: 32%;
            font-family: monospace;
            font-size: 12px;
            color: var(--bs-info);
        }

        /* Burbujas de la conversación */
        .burbuja {
            max-width: 80%;
            border-radius: .5rem;
        }

        .burbuja-texto {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .conversacion-scroll {
            max-height: 58vh;
            overflow-y: auto;
        }
    </style>
@endsection

@section('content')
    <div class="container-fluid px-3">

        {{-- ============================ CABECERA ============================ --}}
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h3 mb-0">
                    <i class="bi bi-telephone-inbound"></i> Eleven Labs
                    <span class="badge bg-secondary align-middle ms-1">solo lectura</span>
                </h1>
                <p class="text-muted small mb-0">
                    Llamadas de la API de Conversational AI. Esta pantalla <strong>no importa nada</strong>:
                    muestra la data cruda del rango y el detalle completo de cada llamada.
                </p>
            </div>

            <button type="button" class="btn btn-outline-info btn-sm" id="btn_abrir_equivalencias"
                    title="Que asistente corresponde a que tipo de llamada y cuales entran al lote">
                <i class="bi bi-diagram-3"></i> Equivalencias
                <span class="badge bg-secondary ms-1" id="badge_agentes_total">{{ count($agentesDetalle) }}</span>
            </button>
        </div>

        {{-- ============================ AVISOS ============================ --}}
        @if(session('elevenlabs_ok'))
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-check-circle"></i> {{ session('elevenlabs_ok') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('elevenlabs_error'))
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-exclamation-triangle"></i> {{ session('elevenlabs_error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @unless($configurado)
            <div class="alert alert-warning py-2">
                <i class="bi bi-key"></i>
                Falta <code>ELEVENLABS_API_KEY</code> en el <code>.env</code>.
                Agrega la key y limpia la configuración (<code>php artisan config:clear</code>).
            </div>
        @endunless

        @if($agentesVacios)
            <div class="alert alert-info py-2">
                <i class="bi bi-info-circle"></i>
                Todavía no hay asistentes en el catálogo local. Abre <strong>Equivalencias</strong> y pulsa
                <strong>Sincronizar asistentes</strong> para traerlos; si no, el filtro estará vacío.
            </div>
        @endif

        {{-- ============================ FILTROS ============================ --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <i class="bi bi-funnel"></i> Rango y asistente
            </div>
            <div class="card-body py-3">
                <div class="row g-2 align-items-end">

                    <div class="col-6 col-md-2">
                        <label for="fecha_desde" class="form-label small mb-1">Fecha desde</label>
                        <input type="date" class="form-control form-control-sm" id="fecha_desde"
                               value="{{ $desde->format('Y-m-d') }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="hora_desde" class="form-label small mb-1">Hora desde</label>
                        <input type="time" class="form-control form-control-sm" id="hora_desde"
                               value="{{ $desde->format('H:i') }}">
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="fecha_hasta" class="form-label small mb-1">Fecha hasta</label>
                        <input type="date" class="form-control form-control-sm" id="fecha_hasta"
                               value="{{ $hasta->format('Y-m-d') }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="hora_hasta" class="form-label small mb-1">Hora hasta</label>
                        <input type="time" class="form-control form-control-sm" id="hora_hasta"
                               value="{{ $hasta->format('H:i') }}">
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Asistentes</label>

                        {{-- Seleccion multiple: la API solo filtra por UN agent_id, asi que
                             con 0 o 1 seleccionado se filtra en el servidor y con 2+ se
                             trae el rango completo y se filtra aqui. --}}
                        <div class="dropdown">
                            <button class="btn btn-outline-light btn-sm dropdown-toggle w-100 text-start"
                                    type="button" id="btn_asistentes"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                    aria-expanded="false">
                                <span id="txt_asistentes">Todos los asistentes</span>
                            </button>

                            <div class="dropdown-menu p-2" style="min-width:340px; max-height:340px; overflow:auto;"
                                 aria-labelledby="btn_asistentes">

                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill"
                                            id="btn_agentes_todos">Todos</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill"
                                            id="btn_agentes_ninguno">Ninguno</button>
                                </div>

                                <div id="lista_asistentes"></div>

                                <div class="border-top mt-2 pt-2 d-grid">
                                    <button type="button" class="btn btn-primary btn-sm" id="btn_filtrar_agentes">
                                        <i class="bi bi-funnel"></i> Aplicar filtro
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-1 d-grid">
                        <button type="button" class="btn btn-primary btn-sm" id="btn_buscar">
                            <i class="bi bi-search"></i> Buscar
                        </button>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_hoy">Hoy</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_ayer">Ayer</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_7d">Últimos 7 días</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_30d">Últimos 30 días</button>

                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm" id="btn_previsualizar"
                                title="Traduce las llamadas del rango y dice cuáles entrarían al lote. Desde ahí mismo se importa.">
                            <i class="bi bi-list-check"></i> Previsualizar / importar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ RESULTADOS ============================ --}}
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-table"></i> Llamadas del rango</span>
                <span class="small text-muted" id="resumen">—</span>
            </div>
            <div class="card-body p-0">

                <div id="aviso_rango" class="alert alert-warning rounded-0 mb-0 py-2 d-none"></div>
                <div id="aviso_error" class="alert alert-danger rounded-0 mb-0 py-2 d-none"></div>

                <div id="cargando" class="text-center text-muted py-5 d-none">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Consultando la API de Eleven Labs…
                </div>

                <div id="vacio" class="text-center text-muted py-5 d-none">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    No hay llamadas en ese rango.
                </div>

                <div class="table-responsive" id="contenedor_tabla">
                    <table class="table table-sm table-hover align-middle mb-0" id="tabla">
                        <thead class="table-dark">
                        <tr>
                            <th class="text-nowrap">Inicio</th>
                            <th>Asistente</th>
                            <th class="text-end">Dur.</th>
                            <th>Estado</th>
                            <th>Dir.</th>
                            <th>IA</th>
                            <th>Motivo de fin</th>
                            <th class="text-end">Msj</th>
                            <th class="text-end">Créditos</th>
                            <th class="text-end">USD</th>
                            <th>Tipo local</th>
                            <th class="text-nowrap">conversation_id</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="cuerpo_tabla"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <p class="text-muted small mt-3 mb-4">
            Documentación del módulo en <code>z-eleven-labs/plan_modulo_eleven_labs.md</code>.
            Leer la API <strong>no consume créditos</strong>: los créditos del listado son el costo que ya
            tuvo la llamada.
        </p>
    </div>

    {{-- ==================== MODAL PREVISUALIZACIÓN DEL LOTE ==================== --}}
    <div class="modal fade" id="modal_previsualizacion" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header py-2">
                    <div class="me-auto">
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-list-check"></i> Previsualización del lote
                        </h5>
                        <div class="small text-muted">
                            Qué se escribiría en cada llamada. <strong>No escribe nada.</strong>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">

                    <div id="preview_cargando" class="text-center text-muted py-5 d-none">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Traduciendo las llamadas…
                    </div>

                    <div id="preview_error" class="alert alert-danger py-2 d-none"></div>
                    <div id="preview_resumen" class="mb-3"></div>
                    <div id="preview_errores_api"></div>

                    <div id="preview_importando" class="d-none mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Importando al lote…</span>
                            <span id="preview_importando_texto"></span>
                        </div>
                        <div class="progress" style="height:18px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 id="preview_importando_barra" style="width:0%"></div>
                        </div>
                    </div>

                    <div id="preview_resultado" class="alert alert-success py-2 d-none"></div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-dark">
                            <tr>
                                <th class="text-nowrap">Inicio</th>
                                <th>Asistente</th>
                                <th>¿Entra?</th>
                                <th>Motivo / avisos</th>
                                <th class="text-center">Tipo</th>
                                <th>Ref</th>
                                <th>Origen → Destino</th>
                                <th>Teléfono</th>
                                <th>Conductor</th>
                                <th>Fecha prometida</th>
                                <th>Razón</th>
                                <th class="text-end">Costo</th>
                            </tr>
                            </thead>
                            <tbody id="preview_cuerpo"></tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <small class="text-muted me-auto" id="preview_pie">
                        La importación crea el lote pero no las llamadas: eso lo hace «Procesar lote».
                    </small>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-success btn-sm d-none" id="btn_importar_lote">
                        <i class="bi bi-box-arrow-in-down"></i> Importar al lote
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ====================== MODAL DETALLE (tabs) ====================== --}}
    <div class="modal fade" id="modal_detalle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header py-2">
                    <div class="me-auto">
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-telephone"></i> Detalle de la llamada
                        </h5>
                        <div class="small text-muted">
                            <code id="det_id"></code>
                            <span id="det_subtitulo" class="ms-2"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-0">

                    {{-- Reproductor: mismo look que /lupita/audio --}}
                    <div class="reproductor-sticky px-3 py-2">
                        <div id="player_sin_audio" class="alert alert-secondary py-2 mb-2 d-none">
                            <i class="bi bi-volume-mute"></i> Esta llamada no tiene grabación.
                        </div>

                        <div id="player_caja">
                            <audio id="det_audio" controls preload="none" class="w-100">
                                <source id="det_audio_source" src="" type="audio/mpeg">
                                Tu navegador no soporta audio.
                            </audio>

                            <div class="d-flex justify-content-center align-items-center gap-3 mt-2" style="height:40px;">
                                <button class="btn btn-outline-light btn-circular" id="btn_audio_reset"
                                        title="Volver al inicio">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button class="btn btn-outline-info btn-circular" id="btn_audio_atras"
                                        title="Retroceder 5 segundos">
                                    <i class="bi bi-skip-backward-fill"></i>
                                </button>
                                <button class="btn btn-outline-info btn-circular" id="btn_audio_adelante"
                                        title="Avanzar 5 segundos">
                                    <i class="bi bi-skip-forward-fill"></i>
                                </button>
                                <button class="btn btn-outline-info btn-circular" id="btn_audio_velocidad"
                                        title="Velocidad de reproducción">
                                    <i class="bi bi-speedometer2"></i>
                                </button>
                                <button class="btn btn-outline-success btn-circular" id="btn_audio_descargar"
                                        title="Descargar el MP3">
                                    <i class="bi bi-download"></i>
                                </button>
                            </div>

                            <ul class="list-group list-group-flush mt-2">
                                <li class="list-group-item small py-1">
                                    Duración: <span class="fw-bold text-info" id="card_audio_duracion"></span>
                                    <span class="text-muted ms-3">Velocidad: <span id="card_velocidad">1x</span></span>
                                </li>
                                <li class="list-group-item small py-1 text-info" id="card_razon_f"></li>
                            </ul>
                        </div>
                    </div>

                    {{-- Tabs --}}
                    <ul class="nav nav-tabs px-3 pt-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="btn_tab_resumen" data-bs-toggle="tab"
                                    data-bs-target="#tab_resumen" type="button" role="tab">
                                <i class="bi bi-card-text"></i> Resumen
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_conversacion" data-bs-toggle="tab"
                                    data-bs-target="#tab_conversacion" type="button" role="tab">
                                <i class="bi bi-chat-dots"></i> Conversación
                                <span class="badge bg-secondary" id="badge_turnos"></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_entrada" data-bs-toggle="tab"
                                    data-bs-target="#tab_entrada" type="button" role="tab">
                                <i class="bi bi-input-cursor-text"></i> Entrada
                                <span class="badge bg-secondary" id="badge_vars"></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_analisis" data-bs-toggle="tab"
                                    data-bs-target="#tab_analisis" type="button" role="tab">
                                <i class="bi bi-clipboard-data"></i> Análisis
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_metadatos" data-bs-toggle="tab"
                                    data-bs-target="#tab_metadatos" type="button" role="tab">
                                <i class="bi bi-info-circle"></i> Metadatos y costo
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_json" data-bs-toggle="tab"
                                    data-bs-target="#tab_json" type="button" role="tab">
                                <i class="bi bi-braces"></i> JSON
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content px-3 py-3">

                        <div class="tab-pane fade show active" id="tab_resumen" role="tabpanel"></div>
                        <div class="tab-pane fade" id="tab_conversacion" role="tabpanel"></div>
                        <div class="tab-pane fade" id="tab_entrada" role="tabpanel"></div>
                        <div class="tab-pane fade" id="tab_analisis" role="tabpanel"></div>
                        <div class="tab-pane fade" id="tab_metadatos" role="tabpanel"></div>

                        <div class="tab-pane fade" id="tab_json" role="tabpanel">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <button type="button" class="btn btn-outline-info btn-sm" id="btn_copiar_json">
                                    <i class="bi bi-clipboard"></i> Copiar JSON
                                </button>
                                <span class="small text-muted" id="json_bytes"></span>
                                <span class="small text-muted ms-auto">
                                    Tal cual lo devuelve <code>/v1/convai/conversations/{id}</code>
                                </span>
                            </div>
                            <pre id="json_texto" class="p-3 rounded mb-0"
                                 style="max-height:56vh; overflow:auto; background:#0b0b0b; color:#c9d1d9; font-size:12px; white-space:pre;"></pre>
                        </div>
                    </div>

                    <div id="det_cargando" class="text-center text-muted py-5">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Trayendo el detalle de la llamada…
                    </div>

                    <div id="det_error" class="alert alert-danger mx-3 d-none"></div>
                </div>

                <div class="modal-footer py-2">
                    <small class="text-muted me-auto">
                        Solo lectura. Nada de esto se escribe todavía en <code>llamadas</code>.
                    </small>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== MODAL EQUIVALENCIAS ==================== --}}
    <div class="modal fade" id="modal_equivalencias" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header py-2">
                    <div class="me-auto">
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-diagram-3"></i> Equivalencias
                        </h5>
                        <div class="small text-muted">
                            Asistentes, traducción de campos y reglas de valor. Todo esto es lo que usa la
                            importación al lote.
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-0">

                    <div class="px-3 pt-2">
                        <div id="equiv_error" class="alert alert-danger py-2 d-none"></div>
                        <div id="equiv_ok" class="alert alert-success py-2 d-none"></div>
                    </div>

                    <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="btn_tab_asistentes" data-bs-toggle="tab"
                                    data-bs-target="#tab_asistentes" type="button" role="tab">
                                <i class="bi bi-person-badge"></i> Asistentes
                                <span class="badge bg-secondary" id="badge_n_asistentes">{{ count($agentesDetalle) }}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_campos" data-bs-toggle="tab"
                                    data-bs-target="#tab_campos" type="button" role="tab">
                                <i class="bi bi-arrow-left-right"></i> Campos
                                <span class="badge bg-secondary" id="badge_n_campos">—</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_reglas" data-bs-toggle="tab"
                                    data-bs-target="#tab_reglas" type="button" role="tab">
                                <i class="bi bi-signpost-split"></i> Reglas
                                <span class="badge bg-secondary" id="badge_n_reglas">—</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_politica" data-bs-toggle="tab"
                                    data-bs-target="#tab_politica" type="button" role="tab">
                                <i class="bi bi-sliders"></i> Política
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="btn_tab_probar" data-bs-toggle="tab"
                                    data-bs-target="#tab_probar" type="button" role="tab">
                                <i class="bi bi-clipboard-check"></i> Probar
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content px-3 py-3">

                        {{-- ---------- Asistentes ---------- --}}
                        <div class="tab-pane fade show active" id="tab_asistentes" role="tabpanel">
                            <div class="alert alert-secondary py-2 small">
                                <i class="bi bi-info-circle"></i>
                                El tipo de llamada es el mismo que usa el lote (<code>tipos_llamada</code>):
                                @foreach($tipos as $idTipo => $nombreTipo)
                                    <strong>{{ $idTipo }}</strong> {{ \Illuminate\Support\Str::lower($nombreTipo) }}@if(!$loop->last), @endif
                                @endforeach.
                                Si eliges <em>Fuera del lote</em>, ese asistente se muestra pero no se podrá importar.
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-dark">
                                    <tr>
                                        <th>Asistente</th>
                                        <th style="width:230px;">Tipo de llamada</th>
                                        <th class="text-center" style="width:80px;">Al lote</th>
                                        <th class="text-center" style="width:80px;">Activo</th>
                                    </tr>
                                    </thead>
                                    <tbody id="equiv_cuerpo"></tbody>
                                </table>
                            </div>
                        </div>

                        {{-- ---------- Campos ---------- --}}
                        <div class="tab-pane fade" id="tab_campos" role="tabpanel">
                            <div class="alert alert-secondary py-2 small">
                                <i class="bi bi-info-circle"></i>
                                Cada fila es una columna del contrato del lote (<code>tmp_lotes_det</code>).
                                <strong>campo</strong>: se lee una ruta del JSON · <strong>constante</strong>: valor fijo ·
                                <strong>regla</strong>: lo resuelve la pestaña Reglas ·
                                <strong>calculado</strong>: lo hace el código y no se edita.
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <input type="text" class="form-control form-control-sm" style="max-width:280px"
                                       id="buscar_campo" placeholder="Filtrar columnas…">
                                <span class="small text-muted align-self-center" id="resumen_campos"></span>
                                <span class="small text-muted align-self-center ms-auto" id="rutas_json_estado"></span>
                            </div>

                            {{-- Se llena con las rutas reales del JSON al pulsar "Probar" --}}
                            <datalist id="rutas_json"></datalist>

                            <div class="table-responsive" style="max-height:52vh;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-dark" style="position:sticky; top:0; z-index:2;">
                                    <tr>
                                        <th style="width:200px;">Columna destino</th>
                                        <th style="width:96px;">Origen</th>
                                        <th>Ruta en el JSON / valor constante</th>
                                        <th style="width:190px;">Transformación</th>
                                        <th style="width:150px;">Por defecto</th>
                                        <th class="text-center" style="width:70px;">Activo</th>
                                    </tr>
                                    </thead>
                                    <tbody id="campos_cuerpo"></tbody>
                                </table>
                            </div>
                        </div>

                        {{-- ---------- Reglas ---------- --}}
                        <div class="tab-pane fade" id="tab_reglas" role="tabpanel">
                            <div class="alert alert-secondary py-2 small">
                                <i class="bi bi-info-circle"></i>
                                <em>Si la ruta cumple la condición, escribe el valor en la columna.</em>
                                Se evalúan por prioridad (menor primero) y para una misma columna gana la
                                primera que se cumple. El valor de <code>razon_finalizacion</code> se elige de la
                                lista real, porque el pipeline compara exacto y en mayúsculas.
                                <br>
                                <i class="bi bi-lightbulb"></i>
                                El selector de <strong>Ruta disparador</strong> trae las rutas comunes; si antes
                                pulsas <strong>Probar</strong>, se agregan <strong>todas las rutas que trae el JSON
                                real</strong> con su valor, y al elegir una se propone ese valor como condición.
                            </div>

                            <div class="mb-2 small text-muted" id="leyenda_reglas"></div>

                            <div class="table-responsive" style="max-height:48vh;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-dark" style="position:sticky; top:0; z-index:2;">
                                    <tr>
                                        <th style="width:70px;">Prior.</th>
                                        <th style="width:210px;">Ruta disparador</th>
                                        <th style="width:120px;">Condición</th>
                                        <th style="width:170px;">Valor</th>
                                        <th style="width:180px;">→ Columna</th>
                                        <th>→ Valor</th>
                                        <th class="text-center" style="width:70px;">Activo</th>
                                        <th class="text-center" style="width:50px;"></th>
                                    </tr>
                                    </thead>
                                    <tbody id="reglas_cuerpo"></tbody>
                                </table>
                            </div>

                            <button type="button" class="btn btn-outline-info btn-sm mt-2" id="btn_regla_nueva">
                                <i class="bi bi-plus-lg"></i> Agregar regla
                            </button>
                        </div>

                        {{-- ---------- Política ---------- --}}
                        <div class="tab-pane fade" id="tab_politica" role="tabpanel">
                            <div class="alert alert-secondary py-2 small">
                                <i class="bi bi-info-circle"></i>
                                Qué llamadas entran al lote. Un estado que no esté marcado acá
                                <strong>no se importa</strong>: en la previsualización aparece con el motivo
                                «fuera de la política de importación». Se aplica desde la próxima importación.
                            </div>

                            <div id="politica_cuerpo"></div>

                            <div class="alert alert-warning py-2 small mb-0 mt-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                Ojo con <strong>initiated</strong>: son conversaciones que todavía no
                                terminaron, así que entrarían al lote sin transcripción ni análisis.
                            </div>
                        </div>

                        {{-- ---------- Probar ---------- --}}
                        <div class="tab-pane fade" id="tab_probar" role="tabpanel">
                            <div class="alert alert-secondary py-2 small">
                                <i class="bi bi-info-circle"></i>
                                Resuelve una llamada real contra la configuración <strong>guardada</strong> y
                                muestra qué se escribiría en cada columna. No escribe nada.
                                Las pruebas se hacen con la tabla del rango que ya cargaste.
                            </div>

                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-12 col-md-8">
                                    <label class="form-label small mb-1">Llamada</label>
                                    <select class="form-select form-select-sm" id="probar_conversacion">
                                        <option value="">— Primero busca un rango en la pantalla —</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4 d-grid">
                                    <button type="button" class="btn btn-primary btn-sm" id="btn_probar">
                                        <i class="bi bi-play-fill"></i> Probar
                                    </button>
                                </div>
                            </div>

                            <div id="probar_estado" class="small text-muted mb-2"></div>
                            <div id="probar_error" class="alert alert-danger py-2 d-none"></div>

                            <div class="table-responsive" style="max-height:48vh;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-dark" style="position:sticky; top:0; z-index:2;">
                                    <tr>
                                        <th style="width:210px;">Columna</th>
                                        <th style="width:110px;">Origen del valor</th>
                                        <th>Valor</th>
                                    </tr>
                                    </thead>
                                    <tbody id="probar_cuerpo"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-info btn-sm me-auto" id="btn_equiv_sincronizar"
                            title="Trae los asistentes del workspace de Eleven Labs">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar asistentes
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btn_equiv_guardar">
                        <i class="bi bi-floppy"></i> Guardar todo
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== MODAL BUSCADOR DE RUTAS DEL JSON ==================== --}}
    <div class="modal fade" id="modal_rutas" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header py-2">
                    <div class="me-auto">
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-search"></i> Buscar en el JSON
                        </h5>
                        <div class="small text-muted" id="rutas_destino_etiqueta"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">

                    {{-- Si todavia no hay rutas cargadas, se pide una llamada de ejemplo --}}
                    <div id="rutas_sin_datos">
                        <div class="alert alert-secondary py-2 small">
                            <i class="bi bi-info-circle"></i>
                            Elige una llamada del rango que ya cargaste para leer sus rutas reales.
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-8">
                                <label class="form-label small mb-1">Llamada de ejemplo</label>
                                <select class="form-select form-select-sm" id="rutas_conversacion"></select>
                            </div>
                            <div class="col-12 col-md-4 d-grid">
                                <button type="button" class="btn btn-primary btn-sm" id="btn_rutas_traer">
                                    <i class="bi bi-download"></i> Traer rutas
                                </button>
                            </div>
                        </div>
                        <div id="rutas_traer_error" class="alert alert-danger py-2 mt-2 d-none"></div>
                    </div>

                    {{-- Ya hay rutas: buscador + lista --}}
                    <div id="rutas_con_datos" class="d-none">
                        <div class="d-flex gap-2 align-items-center mb-2">
                            <input type="text" class="form-control form-control-sm" id="buscar_ruta"
                                   placeholder="Buscar por ruta o por valor… (ej. carrier, origen, 1188055)">
                            <span class="small text-muted text-nowrap" id="rutas_contador"></span>
                        </div>
                        <div id="rutas_lista" style="max-height:52vh; overflow:auto;"></div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <small class="text-muted me-auto">
                        Al elegir una ruta se escribe en el campo que abrió este buscador.
                    </small>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            'use strict';

            const $ = (id) => document.getElementById(id);

            const urlConversaciones = @json(route('elevenlabs.conversaciones'));
            const urlDetalleBase = @json(url('/eleven-labs/conversaciones'));
            const urlAudioBase = @json(url('/eleven-labs/audio'));
            const urlEquivalencias = @json(route('elevenlabs.equivalencias'));
            const urlEquivalenciasGuardar = @json(route('elevenlabs.equivalencias.guardar'));
            const urlMapeo = @json(route('elevenlabs.mapeo'));
            const urlMapeoProbar = @json(route('elevenlabs.mapeo.probar'));
            const urlMapeoRutas = @json(route('elevenlabs.mapeo.rutas'));
            const urlPrevisualizar = @json(route('elevenlabs.previsualizar'));
            const urlImportar = @json(route('elevenlabs.importar'));
            // pantalla del lote: la misma del flujo Excel (importar.excel.lote/{lote_id})
            const urlLoteBase = @json(url('/importar/excel'));
            const urlSincronizar = @json(route('elevenlabs.asistentes.sincronizar'));
            const csrfToken = @json(csrf_token());
            const TIPOS = @json($tipos);
            const PUEDE_EDITAR = @json($puedeEditar);

            const modalEl = $('modal_detalle');
            const modal = new bootstrap.Modal(modalEl);
            const audio = $('det_audio');

            const modalEquivEl = $('modal_equivalencias');
            const modalEquiv = new bootstrap.Modal(modalEquivEl);

            const modalPreviewEl = $('modal_previsualizacion');
            const modalPreview = new bootstrap.Modal(modalPreviewEl);

            const modalRutasEl = $('modal_rutas');
            const modalRutas = new bootstrap.Modal(modalRutasEl);

            // filas del ultimo listado, para pintar la cabecera del modal sin esperar
            const filasPorId = {};
            let detalleActual = null;
            let jsonActual = '';
            let velocidad = 1;

            // catalogo agente -> tipo de llamada (vive en elevenlabs_agentes)
            let catalogoAgentes = @json($agentesDetalle);

            // equivalencias de traduccion (elevenlabs_mapeos_campo / _regla)
            let catalogoCampos = [];
            let catalogoReglas = [];
            let catalogos = {
                transformaciones: {},
                comparaciones: {},
                razones: [],
                columnas_regla: [],
                rutas_disparador: [],
                opciones_valor: {},
                columnas_max: {},
            };
            let reglasEliminar = [];

            // politica del modulo (elevenlabs_opciones): que estados entran al lote
            let catalogoOpciones = [];

            // rutas hoja del JSON real, descubiertas al pulsar "Probar":
            // { "metadata.termination_reason": {ruta, valor, tipo}, ... }
            let rutasJson = {};

            // input del campo que abrio el buscador de rutas
            let inputRutaDestino = null;

            // asistentes marcados en el filtro; vacio = todos
            const seleccionados = new Set();
            let temporizadorFiltro = null;

            // ------------------------------------------------------------------ utils

            function esc(t) {
                return String(t === null || t === undefined ? '' : t)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function mmss(seg) {
                const s = Math.max(0, Number(seg) || 0);
                const m = Math.floor(s / 60);
                const r = Math.floor(s % 60);
                return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
            }

            function durHumana(seg) {
                const s = Number(seg) || 0;
                const m = Math.floor(s / 60);
                const r = s % 60;
                return m > 0 ? (m + 'm ' + r + 's') : (r + 's');
            }

            function nf(n, dec) {
                const v = Number(n);
                if (!isFinite(v)) return '—';
                return v.toLocaleString('es-PE', {
                    minimumFractionDigits: dec || 0,
                    maximumFractionDigits: dec || 0,
                });
            }

            function badge(texto, color) {
                if (!texto) return '<span class="text-muted">—</span>';
                return '<span class="badge bg-' + (color || 'secondary') + '">' + esc(texto) + '</span>';
            }

            function badgeEstado(status) {
                const colores = {done: 'success', failed: 'danger', initiated: 'warning'};
                return badge(status || '—', colores[status] || 'secondary');
            }

            function badgeIa(v) {
                if (!v) return '<span class="text-muted">—</span>';
                return badge(v, v === 'success' ? 'success' : (v === 'failure' ? 'danger' : 'secondary'));
            }

            function badgeTipo(tipoId, incluir) {
                if (tipoId === null || tipoId === undefined) {
                    return '<span class="badge bg-dark text-muted border border-secondary">fuera del lote</span>';
                }
                return '<span class="badge bg-' + (incluir ? 'primary' : 'secondary') + '">tipo ' + tipoId + '</span>';
            }

            /**
             * Pinta un valor: texto corto en linea, objetos o textos largos en un
             * <pre> con scroll (hay variables de 3 KB, como el historial entero).
             */
            function valor(v) {
                if (v === null || v === undefined) return '<span class="text-muted">—</span>';

                if (typeof v === 'boolean') {
                    return '<span class="badge bg-' + (v ? 'success' : 'secondary') + '">' + (v ? 'true' : 'false') + '</span>';
                }

                if (typeof v === 'object') {
                    const txt = JSON.stringify(v, null, 2);
                    if (txt === '[]' || txt === '{}') return '<span class="text-muted">vacío</span>';
                    return '<pre class="valor-pre">' + esc(txt) + '</pre>';
                }

                const s = String(v);
                if (s === '') return '<span class="text-muted">—</span>';
                if (s.length > 120) return '<pre class="valor-pre">' + esc(s) + '</pre>';

                return esc(s);
            }

            function filaTabla(clave, valorHtml) {
                return '<tr><td>' + esc(clave) + '</td><td>' + valorHtml + '</td></tr>';
            }

            function setAviso(el, texto) {
                if (!texto) {
                    el.classList.add('d-none');
                    el.textContent = '';
                    return;
                }
                el.textContent = texto;
                el.classList.remove('d-none');
            }

            // ------------------------------------------------------------------ rango

            function hoyISO(offsetDias) {
                const d = new Date();
                d.setDate(d.getDate() + (offsetDias || 0));
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
                    + '-' + String(d.getDate()).padStart(2, '0');
            }

            function aplicarRango(diasAtras) {
                $('fecha_desde').value = hoyISO(-diasAtras);
                $('hora_desde').value = '00:00';
                $('fecha_hasta').value = hoyISO(0);
                $('hora_hasta').value = '23:59';
            }

            function parametros() {
                const p = new URLSearchParams();
                p.set('fecha_desde', $('fecha_desde').value);
                p.set('hora_desde', $('hora_desde').value || '00:00');
                p.set('fecha_hasta', $('fecha_hasta').value);
                p.set('hora_hasta', $('hora_hasta').value || '23:59');

                // Los asistentes van TODOS al servidor: la API acepta un agent_id por
                // consulta, asi que el backend hace una consulta por asistente y solo
                // devuelve los pedidos. Antes se filtraba en el navegador y se colaban
                // llamadas de otros asistentes.
                Array.from(seleccionados).forEach((id) => p.append('agentes[]', id));

                return p;
            }

            // ------------------------------------------------- filtro de asistentes

            function renderListaAsistentes() {
                const caja = $('lista_asistentes');
                if (!caja) return;

                // Solo los asistentes ACTIVOS: uno inactivo no debe poder filtrarse
                // ni entrar al lote (sigue existiendo en el catalogo para no perder
                // su configuracion, pero no se ofrece).
                const activos = catalogoAgentes.filter((ag) => ag.activo);

                // Si un asistente se desactivo, se saca de la seleccion
                Array.from(seleccionados).forEach((id) => {
                    if (!activos.some((ag) => ag.agent_id === id)) seleccionados.delete(id);
                });

                if (!activos.length) {
                    caja.innerHTML = '<div class="small text-muted px-1">'
                        + 'No hay asistentes activos. Usa <strong>Equivalencias</strong>.'
                        + '</div>';
                    return;
                }

                caja.innerHTML = activos.map((ag) => {
                    const id = 'chk_agente_' + ag.agent_id;
                    const marcado = seleccionados.has(ag.agent_id) ? ' checked' : '';
                    const tipo = ag.tipo_llamada_id === null
                        ? '<span class="text-muted">fuera del lote</span>'
                        : '<span class="text-info">tipo ' + ag.tipo_llamada_id + '</span>';

                    return '<div class="form-check d-flex align-items-start gap-2 py-1">'
                        + '<input class="form-check-input mt-1 chk-agente" type="checkbox" value="'
                        + esc(ag.agent_id) + '" id="' + esc(id) + '"' + marcado + '>'
                        + '<label class="form-check-label small flex-grow-1" for="' + esc(id) + '">'
                        + esc(ag.nombre || ag.agent_id)
                        + '<br><span class="text-muted" style="font-size:11px">' + tipo + '</span>'
                        + '</label>'
                        + '</div>';
                }).join('');

                caja.querySelectorAll('.chk-agente').forEach((chk) => {
                    chk.addEventListener('change', () => {
                        if (chk.checked) seleccionados.add(chk.value);
                        else seleccionados.delete(chk.value);
                        actualizarEtiquetaAsistentes();
                        // Se aplica solo: el usuario no debería tener que recordar
                        // pulsar "Aplicar filtro" para dejar de ver otros asistentes.
                        aplicarFiltroDiferido();
                    });
                });
            }

            /**
             * Vuelve a consultar el listado con el filtro actual, con un pequeño
             * retraso para no disparar una consulta por cada checkbox marcado.
             */
            function aplicarFiltroDiferido() {
                clearTimeout(temporizadorFiltro);
                temporizadorFiltro = setTimeout(buscar, 450);
            }

            function actualizarEtiquetaAsistentes() {
                const total = seleccionados.size;
                let texto;

                if (total === 0) {
                    texto = 'Todos los asistentes';
                } else if (total === 1) {
                    const unico = Array.from(seleccionados)[0];
                    const ag = catalogoAgentes.find((a) => a.agent_id === unico);
                    texto = ag ? (ag.nombre || unico) : unico;
                } else {
                    texto = total + ' asistentes';
                }

                $('txt_asistentes').textContent = texto;
            }

            // ----------------------------------------------------------------- buscar

            async function buscar() {
                $('cargando').classList.remove('d-none');
                $('vacio').classList.add('d-none');
                $('contenedor_tabla').classList.add('d-none');
                setAviso($('aviso_error'), '');
                setAviso($('aviso_rango'), '');
                $('resumen').textContent = '—';

                try {
                    const respuesta = await fetch(urlConversaciones + '?' + parametros().toString(), {
                        headers: {'Accept': 'application/json'},
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        setAviso($('aviso_error'), datos.mensaje || 'Error consultando la API.');
                        return;
                    }

                    pintarFilas(datos.filas, datos.meta);

                } catch (e) {
                    setAviso($('aviso_error'), 'No se pudo consultar: ' + e.message);
                } finally {
                    $('cargando').classList.add('d-none');
                }
            }

            function pintarFilas(filas, meta) {
                const cuerpo = $('cuerpo_tabla');
                cuerpo.innerHTML = '';
                Object.keys(filasPorId).forEach((k) => delete filasPorId[k]);

                // El filtro por asistente YA lo hizo el servidor (una consulta por
                // asistente), asi que aqui no se vuelve a filtrar: lo que llega es
                // exactamente lo pedido.
                const marcados = Array.from(seleccionados);

                // Asistentes que la API no devolvio: se avisa, no se esconden.
                const fallidos = (meta.agentes_fallidos && Object.keys(meta.agentes_fallidos)) || [];
                if (fallidos.length) {
                    setAviso($('aviso_rango'),
                        'La API no devolvió ' + fallidos.length + ' asistente(s) del filtro: '
                        + fallidos.join(', '));
                }

                // Llamadas descartadas por ser de un asistente desactivado. Se dice,
                // porque si no la tabla parece vacia sin motivo.
                const excluidas = parseInt(meta.excluidas_inactivas || 0, 10);
                if (excluidas > 0 && !fallidos.length) {
                    setAviso($('aviso_rango'),
                        'No se muestran ' + excluidas + ' llamada(s) de asistentes desactivados. '
                        + 'Reactívalos en Equivalencias → Asistentes si quieres verlas.');
                }

                if (!filas.length) {
                    $('vacio').classList.remove('d-none');
                    $('contenedor_tabla').classList.add('d-none');

                    if (marcados.length) {
                        $('vacio').innerHTML = '<i class="bi bi-funnel fs-3 d-block mb-2"></i>'
                            + 'No hay llamadas en ese rango para ' +
                            (marcados.length === 1 ? 'ese asistente' : 'esos ' + marcados.length + ' asistentes') + '.';
                    } else {
                        $('vacio').innerHTML = '<i class="bi bi-inbox fs-3 d-block mb-2"></i>'
                            + 'No hay llamadas en ese rango.';
                    }

                    $('resumen').textContent = '0 llamadas · ' + meta.desde_local + ' → ' + meta.hasta_local;
                    return;
                }

                $('contenedor_tabla').classList.remove('d-none');

                let creditos = 0;
                let usd = 0;

                const trozos = filas.map((f) => {
                    creditos += f.cost;
                    usd += f.cost_fiat;
                    filasPorId[f.conversation_id] = f;

                    return '<tr data-cid="' + esc(f.conversation_id) + '">'
                        + '<td class="text-nowrap small">' + esc(f.inicio_local) + '</td>'
                        + '<td class="small">' + esc(f.agent_name || f.agent_id) + '</td>'
                        + '<td class="text-end text-nowrap small">' + durHumana(f.call_duration_secs) + '</td>'
                        + '<td>' + badgeEstado(f.status) + '</td>'
                        + '<td class="small">' + esc(f.direction || '—') + '</td>'
                        + '<td>' + badgeIa(f.call_successful) + '</td>'
                        + '<td class="small">' + esc(f.termination_reason || '—') + '</td>'
                        + '<td class="text-end small">' + f.message_count + '</td>'
                        + '<td class="text-end small">' + nf(f.cost) + '</td>'
                        + '<td class="text-end small">' + nf(f.cost_fiat, 4) + '</td>'
                        + '<td class="celda-tipo">' + badgeTipo(f.tipo_llamada_id, f.incluir_en_lote) + '</td>'
                        + '<td class="small text-muted" style="font-family:monospace">' + esc(f.conversation_id) + '</td>'
                        + '<td class="text-end text-nowrap">'
                        + '<button type="button" class="btn btn-outline-light btn-sm btn-detalle" '
                        + 'data-id="' + esc(f.conversation_id) + '" data-tab="resumen" '
                        + 'title="Ver el detalle completo de esta llamada">'
                        + '<i class="bi bi-eye"></i> Detalle</button> '
                        + '<button type="button" class="btn btn-outline-info btn-sm btn-detalle" '
                        + 'data-id="' + esc(f.conversation_id) + '" data-tab="json" '
                        + 'title="Ver el JSON completo de esta llamada">'
                        + '<i class="bi bi-braces"></i></button>'
                        + '</td>'
                        + '</tr>';
                });

                cuerpo.innerHTML = trozos.join('');

                let resumen = filas.length + ' llamadas';

                if (marcados.length) {
                    resumen += ' de ' + (marcados.length === 1 ? '1 asistente' : marcados.length + ' asistentes');
                }

                resumen += ' · ' + meta.desde_local + ' → ' + meta.hasta_local
                    + ' · ' + nf(creditos) + ' créditos'
                    + ' · USD ' + nf(usd, 2);

                if (meta.paginas > 1) {
                    resumen += ' · ' + meta.paginas + ' páginas';
                }
                $('resumen').textContent = resumen;

                if (meta.tope) {
                    setAviso($('aviso_rango'),
                        'Se alcanzó el tope de páginas (' + meta.paginas + '). Acorta el rango: hay más llamadas de las que se trajeron.');
                } else if (meta.has_more) {
                    setAviso($('aviso_rango'),
                        'La API todavía reporta más resultados en este rango. Acorta el rango para verlos todos.');
                }

                cuerpo.querySelectorAll('.btn-detalle').forEach((btn) => {
                    btn.addEventListener('click', () => abrirDetalle(btn.dataset.id, btn.dataset.tab));
                });

                // la pestana Probar del modal de equivalencias usa estas llamadas
                renderProbarSelector();

                // y el buscador de rutas tambien: se refresca al cargar el listado
                // para que no se quede con "no hay llamadas" de una carga anterior
                renderRutasConversacion();
            }

            // ------------------------------------------------------------ modal detalle

            async function abrirDetalle(conversationId, tabInicial) {
                detalleActual = null;
                jsonActual = '';

                $('det_id').textContent = conversationId;
                $('det_subtitulo').innerHTML = '';
                $('det_error').classList.add('d-none');
                $('det_cargando').classList.remove('d-none');
                // Se limpian solo las pestañas que se generan desde JS. La de JSON
                // es marcado fijo (su <pre> y el botón de copiar viven ahí), así que
                // vaciarla borraría esos nodos.
                ['tab_resumen', 'tab_conversacion', 'tab_entrada', 'tab_analisis', 'tab_metadatos']
                    .forEach((id) => { $(id).innerHTML = ''; });
                $('json_texto').textContent = '';
                $('json_bytes').textContent = '';
                $('badge_turnos').textContent = '';
                $('badge_vars').textContent = '';
                $('card_audio_duracion').textContent = '';
                $('card_razon_f').textContent = '';
                $('player_sin_audio').classList.add('d-none');
                $('player_caja').classList.add('d-none');

                // cabecera con lo que ya sabemos del listado
                const fila = filasPorId[conversationId];
                if (fila) {
                    $('det_subtitulo').innerHTML = esc(fila.agent_name || '')
                        + ' · ' + esc(fila.inicio_local)
                        + ' · ' + badgeEstado(fila.status)
                        + ' · ' + durHumana(fila.call_duration_secs);
                    $('card_audio_duracion').textContent = durHumana(fila.call_duration_secs) + ' (' + fila.call_duration_secs + ' seg)';
                    $('card_razon_f').textContent = 'Razón de finalización: ' + (fila.termination_reason || '—');
                }

                modal.show();
                activarTab(tabInicial);

                try {
                    const respuesta = await fetch(
                        urlDetalleBase + '/' + encodeURIComponent(conversationId) + '/json',
                        {headers: {'Accept': 'application/json'}}
                    );
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        $('det_error').textContent = datos.mensaje || 'Error trayendo el detalle.';
                        $('det_error').classList.remove('d-none');
                        return;
                    }

                    detalleActual = datos.datos || {};
                    montarDetalle(detalleActual, datos.has_audio);

                } catch (e) {
                    $('det_error').textContent = 'No se pudo traer el detalle: ' + e.message;
                    $('det_error').classList.remove('d-none');
                } finally {
                    $('det_cargando').classList.add('d-none');
                }
            }

            function activarTab(nombre) {
                const mapa = {
                    resumen: 'btn_tab_resumen',
                    conversacion: 'btn_tab_conversacion',
                    entrada: 'btn_tab_entrada',
                    analisis: 'btn_tab_analisis',
                    metadatos: 'btn_tab_metadatos',
                    json: 'btn_tab_json',
                };
                const boton = $(mapa[nombre] || 'btn_tab_resumen');
                if (boton) bootstrap.Tab.getOrCreateInstance(boton).show();
            }

            function montarDetalle(d, hasAudio) {
                const meta = d.metadata || {};
                const llamada = meta.phone_call || {};

                // ---- reproductor
                if (hasAudio) {
                    $('player_caja').classList.remove('d-none');
                    $('player_sin_audio').classList.add('d-none');
                    audio.src = urlAudioBase + '/' + encodeURIComponent(d.conversation_id || $('det_id').textContent);
                    audio.playbackRate = velocidad;
                } else {
                    $('player_caja').classList.add('d-none');
                    $('player_sin_audio').classList.remove('d-none');
                }

                $('card_audio_duracion').textContent = durHumana(meta.call_duration_secs)
                    + ' (' + (meta.call_duration_secs || 0) + ' seg)';
                $('card_razon_f').textContent = 'Razón de finalización: ' + (meta.termination_reason || '—');

                // ---- conversación
                const turnos = Array.isArray(d.transcript) ? d.transcript : [];
                $('badge_turnos').textContent = turnos.length;
                $('tab_conversacion').innerHTML = renderConversacion(turnos);

                // ---- entrada
                const entrada = d.conversation_initiation_client_data || {};
                const vars = entrada.dynamic_variables || {};
                $('badge_vars').textContent = Object.keys(vars).length;
                $('tab_entrada').innerHTML = renderEntrada(entrada, vars);

                // ---- resumen / análisis / metadatos
                $('tab_resumen').innerHTML = renderResumen(d, meta, llamada, turnos);
                $('tab_analisis').innerHTML = renderAnalisis(d.analysis || {});
                $('tab_metadatos').innerHTML = renderMetadatos(d, meta, llamada);

                // ---- json
                jsonActual = JSON.stringify(d, null, 2);
                $('json_texto').textContent = jsonActual;
                $('json_bytes').textContent = (jsonActual.length / 1024).toFixed(1) + ' KB';
            }

            // ---------------------------------------------------------------- resumen

            function renderResumen(d, meta, llamada, turnos) {
                const analisis = d.analysis || {};
                const usuario = llamada.external_number || '';
                const agente = llamada.agent_number || '';

                let html = '<div class="row g-3">';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-telephone"></i> Llamada</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('Asistente', esc(d.agent_name || '—'))
                    + filaTabla('Estado', badgeEstado(d.status))
                    + filaTabla('Dirección', esc(llamada.direction || '—'))
                    + filaTabla('Resultado', badgeIa(analisis.call_successful))
                    + filaTabla('Inicio', esc(meta.start_time_unix_secs
                        ? new Date(meta.start_time_unix_secs * 1000).toLocaleString('es-PE') : '—'))
                    + filaTabla('Duración', esc(durHumana(meta.call_duration_secs)))
                    + filaTabla('Turnos', turnos.length + ' (' + (d.user_message_count || 0) + ' del usuario)')
                    + '</table></div></div></div>';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-person-lines-fill"></i> Partes y costo</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('Número externo', esc(usuario || '—'))
                    + filaTabla('Número del agente', esc(agente || '—'))
                    + filaTabla('Resultado del marcado', esc(llamada.call_result || '—'))
                    + filaTabla('Créditos', nf(meta.cost) + ' créditos')
                    + filaTabla('Costo', 'USD ' + nf(meta.cost_fiat, 6))
                    + filaTabla('Motivo de fin', esc(meta.termination_reason || '—'))
                    + filaTabla('Idioma', esc(meta.main_language || d.main_language || '—'))
                    + '</table></div></div></div>';

                const resumen = analisis.transcript_summary || '';
                if (resumen) {
                    html += '<div class="col-12"><div class="card"><div class="card-header py-2 small">'
                        + '<i class="bi bi-card-text"></i> Resumen de la conversación'
                        + (analisis.call_summary_title ? ' <span class="text-muted">· ' + esc(analisis.call_summary_title) + '</span>' : '')
                        + '</div><div class="card-body py-2 small burbuja-texto">' + esc(resumen) + '</div></div></div>';
                }

                if (meta.error) {
                    html += '<div class="col-12"><div class="alert alert-danger py-2 mb-0 small">'
                        + '<i class="bi bi-exclamation-triangle"></i> <strong>Error:</strong> '
                        + esc(meta.error.reason || '') + ' <span class="text-muted">(' + esc(meta.error.error_type || '') + ', code ' + esc(meta.error.code || '') + ')</span>'
                        + '</div></div>';
                }

                html += '</div>';

                return html;
            }

            // ----------------------------------------------------------- conversación

            function renderConversacion(turnos) {
                if (!turnos.length) {
                    return '<div class="alert alert-secondary py-2 mb-0">Esta llamada no tiene transcripción.</div>';
                }

                const bloques = turnos.map((t) => {
                    const esAgente = t.role === 'agent';
                    const color = esAgente ? 'primary' : 'secondary';
                    // `message` viene truncado con "..." en las respuestas del agente;
                    // `original_message` trae el texto completo... cuando existe: en
                    // esta cuenta viene null en casi todos los turnos, incluso los de
                    // agente, asi que el fallback es obligatorio.
                    const texto = (t.original_message && String(t.original_message).length)
                        ? t.original_message
                        : (t.message || '');

                    let herramientas = '';
                    (t.tool_calls || []).forEach((tc) => {
                        const nombre = tc.tool_name || tc.name || (tc.function && tc.function.name) || 'tool';
                        herramientas += '<div class="text-info"><i class="bi bi-gear"></i> llamó <code>'
                            + esc(nombre) + '</code></div>';
                    });
                    (t.tool_results || []).forEach((tr) => {
                        const nombre = tr.tool_name || tr.name || 'tool';
                        const error = tr.is_error ? ' <span class="badge bg-danger">error</span>' : '';
                        herramientas += '<div><i class="bi bi-arrow-return-right"></i> <code>'
                            + esc(nombre) + '</code>' + error + '</div>';
                    });

                    // Turnos que SOLO ejecutan herramientas (sin texto): en esta cuenta
                    // son la mitad del historial de una llamada larga. Se pintan como
                    // una linea discreta en vez de una burbuja vacia, para no tapar la
                    // conversacion real.
                    if (!texto) {
                        return '<div class="d-flex justify-content-center mb-1">'
                            + '<div class="text-muted text-center" style="font-size:11px">'
                            + '<i class="bi bi-tools"></i> ' + mmss(t.time_in_call_secs)
                            + (herramientas || ' <span>(turno sin texto)</span>')
                            + '</div></div>';
                    }

                    let cabecera = '<span class="badge bg-' + color + '">' + esc(t.role || '?') + '</span>'
                        + ' <span class="text-muted">' + mmss(t.time_in_call_secs) + '</span>';

                    if (t.interrupted) {
                        cabecera += ' <span class="badge bg-warning text-dark">interrumpido</span>';
                    }
                    if (t.producing_llm) {
                        cabecera += ' <span class="text-muted" style="font-size:11px">' + esc(t.producing_llm) + '</span>';
                    }

                    return '<div class="d-flex mb-2 ' + (esAgente ? 'justify-content-start' : 'justify-content-end') + '">'
                        + '<div class="card burbuja border-' + color + '">'
                        + '<div class="card-header py-1 px-2 small bg-transparent">' + cabecera + '</div>'
                        + '<div class="card-body py-2 px-2 small burbuja-texto">' + esc(texto) + '</div>'
                        + (herramientas ? '<div class="card-footer py-1 px-2 bg-transparent" style="font-size:11px">' + herramientas + '</div>' : '')
                        + '</div></div>';
                });

                return '<div class="conversacion-scroll pe-1">' + bloques.join('') + '</div>';
            }

            // ---------------------------------------------------------------- entrada

            function renderEntrada(entrada, vars) {
                if (!Object.keys(entrada).length) {
                    return '<div class="alert alert-secondary py-2 mb-0">Esta llamada no trae datos de entrada.</div>';
                }

                // Las `system__*` las pone ElevenLabs (historial, hora, ids): van
                // aparte y cerradas para no tapar las variables del negocio.
                const negocio = {};
                const sistema = {};
                Object.keys(vars).forEach((k) => {
                    if (k.indexOf('system__') === 0) sistema[k] = vars[k];
                    else negocio[k] = vars[k];
                });

                let html = '';

                html += '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">'
                    + '<span class="badge bg-primary">' + Object.keys(negocio).length + ' variables de negocio</span>'
                    + '<span class="badge bg-dark">' + Object.keys(sistema).length + ' del sistema</span>'
                    + '<input type="text" class="form-control form-control-sm ms-auto" style="max-width:260px" '
                    + 'id="buscar_var" placeholder="Filtrar variables…">'
                    + '</div>';

                html += '<table class="table table-sm table-hover tabla-vars mb-3"><tbody id="tabla_negocio">';
                Object.keys(negocio).sort().forEach((k) => {
                    html += '<tr data-var="' + esc(k.toLowerCase()) + '">'
                        + '<td>' + esc(k) + '</td><td>' + valor(negocio[k]) + '</td></tr>';
                });
                if (!Object.keys(negocio).length) {
                    html += '<tr><td colspan="2" class="text-muted">Sin variables de negocio.</td></tr>';
                }
                html += '</tbody></table>';

                // Otras partes del "input" que no son variables
                const otras = {};
                Object.keys(entrada).forEach((k) => {
                    if (k === 'dynamic_variables') return;
                    const v = entrada[k];
                    const vacio = v === null || v === undefined
                        || (Array.isArray(v) && v.length === 0)
                        || (typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0);
                    if (!vacio) otras[k] = v;
                });

                if (Object.keys(otras).length) {
                    html += '<div class="card mb-3"><div class="card-header py-2 small">'
                        + '<i class="bi bi-sliders"></i> Configuración con la que se inició la llamada</div>'
                        + '<div class="card-body py-2"><table class="table table-sm mb-0 small tabla-vars"><tbody>';
                    Object.keys(otras).sort().forEach((k) => {
                        html += filaTabla(k, valor(otras[k]));
                    });
                    html += '</tbody></table></div></div>';
                }

                if (Object.keys(sistema).length) {
                    html += '<details><summary class="small text-muted">Variables del sistema ('
                        + Object.keys(sistema).length + ') — las pone ElevenLabs</summary>'
                        + '<table class="table table-sm tabla-vars mt-2 mb-0"><tbody>';
                    Object.keys(sistema).sort().forEach((k) => {
                        html += filaTabla(k, valor(sistema[k]));
                    });
                    html += '</tbody></table></details>';
                }

                return html;
            }

            // --------------------------------------------------------------- análisis

            function renderAnalisis(a) {
                let html = '<div class="row g-3">';

                html += '<div class="col-12 col-lg-5"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-clipboard-check"></i> Veredicto</div><div class="card-body py-2 small">'
                    + '<table class="table table-sm mb-0">'
                    + filaTabla('Resultado', badgeIa(a.call_successful))
                    + filaTabla('Score', a.call_success_score === null || a.call_success_score === undefined
                        ? '<span class="text-muted">—</span>' : esc(a.call_success_score))
                    + filaTabla('Título', esc(a.call_summary_title || '—'))
                    + filaTabla('Criterios evaluados', String((a.evaluation_criteria_results || []).length))
                    + filaTabla('Datos recolectados', String((a.data_collection_results || []).length))
                    + '</table></div></div></div>';

                const s = a.sentiment_analysis;
                html += '<div class="col-12 col-lg-7"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-emoji-neutral"></i> Sentimiento</div><div class="card-body py-2 small">';
                if (s) {
                    html += '<table class="table table-sm mb-0">'
                        + filaTabla('General', esc(s.overall_label || '—'))
                        + filaTabla('Score', esc(s.overall_sentiment_score))
                        + filaTabla('Frustración', esc(s.overall_frustration_score))
                        + filaTabla('Turnos del usuario analizados', esc(s.num_scored_user_turns))
                        + filaTabla('Sentimiento mínimo', esc(s.min_user_sentiment_score))
                        + filaTabla('Frustración máxima', esc(s.max_user_frustration_score))
                        + '</table>';
                } else {
                    html += '<span class="text-muted">Esta llamada no tiene análisis de sentimiento.</span>';
                }
                html += '</div></div></div>';

                const resumen = a.transcript_summary || '';
                html += '<div class="col-12"><div class="card"><div class="card-header py-2 small">'
                    + '<i class="bi bi-card-text"></i> Resumen (transcript_summary)</div>'
                    + '<div class="card-body py-2 small burbuja-texto">'
                    + (resumen ? esc(resumen) : '<span class="text-muted">sin resumen</span>')
                    + '</div></div></div>';

                if ((a.data_collection_results || []).length) {
                    html += '<div class="col-12"><div class="card"><div class="card-header py-2 small">'
                        + '<i class="bi bi-database"></i> Datos recolectados</div><div class="card-body py-2">'
                        + '<pre class="valor-pre">' + esc(JSON.stringify(a.data_collection_results, null, 2)) + '</pre>'
                        + '</div></div></div>';
                }

                if ((a.evaluation_criteria_results || []).length) {
                    html += '<div class="col-12"><div class="card"><div class="card-header py-2 small">'
                        + '<i class="bi bi-list-check"></i> Criterios de evaluación</div><div class="card-body py-2">'
                        + '<pre class="valor-pre">' + esc(JSON.stringify(a.evaluation_criteria_results, null, 2)) + '</pre>'
                        + '</div></div></div>';
                }

                html += '</div>';

                return html;
            }

            // -------------------------------------------------------------- metadatos

            function renderMetadatos(d, meta, llamada) {
                const carga = meta.charging || {};

                let html = '<div class="row g-3">';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-telephone"></i> Llamada</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('conversation_id', '<code>' + esc(d.conversation_id || '—') + '</code>')
                    + filaTabla('agent_id', '<code>' + esc(d.agent_id || '—') + '</code>')
                    + filaTabla('user_id', esc(d.user_id || '—'))
                    + filaTabla('status', badgeEstado(d.status))
                    + filaTabla('Producto', esc(d.conversation_product || '—'))
                    + filaTabla('Entorno', esc(d.environment || '—'))
                    + filaTabla('Origen del inicio', esc(meta.conversation_initiation_source || '—'))
                    + filaTabla('Zona horaria', esc(meta.timezone || '—'))
                    + filaTabla('Hiding reason', esc(d.hiding_reason || '—'))
                    + '</table></div></div></div>';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-phone"></i> Telefonía</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('Dirección', esc(llamada.direction || '—'))
                    + filaTabla('Número externo', esc(llamada.external_number || '—'))
                    + filaTabla('Número del agente', esc(llamada.agent_number || '—'))
                    + filaTabla('Resultado', esc(llamada.call_result || '—'))
                    + filaTabla('Proveedor', esc(llamada.type || '—'))
                    + filaTabla('phone_number_id', '<code>' + esc(llamada.phone_number_id || '—') + '</code>')
                    + filaTabla('call_sid', '<code>' + esc(llamada.call_sid || '—') + '</code>')
                    + filaTabla('stream_sid', '<code>' + esc(llamada.stream_sid || '—') + '</code>')
                    + '</table></div></div></div>';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-clock-history"></i> Tiempos</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('Inicio', esc(meta.start_time_unix_secs
                        ? new Date(meta.start_time_unix_secs * 1000).toLocaleString('es-PE') + ' (' + meta.start_time_unix_secs + ')' : '—'))
                    + filaTabla('Aceptada', esc(meta.accepted_time_unix_secs
                        ? new Date(meta.accepted_time_unix_secs * 1000).toLocaleString('es-PE') : '—'))
                    + filaTabla('Duración', esc(durHumana(meta.call_duration_secs) + ' (' + (meta.call_duration_secs || 0) + ' seg)'))
                    + filaTabla('Espera en cola', esc(meta.queue_wait_secs === null ? '—' : meta.queue_wait_secs))
                    + filaTabla('Motivo de fin', esc(meta.termination_reason || '—'))
                    + '</table></div></div></div>';

                html += '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small">'
                    + '<i class="bi bi-cash-coin"></i> Costo</div><div class="card-body py-2"><table class="table table-sm mb-0 small">'
                    + filaTabla('Créditos', nf(meta.cost))
                    + filaTabla('USD', nf(meta.cost_fiat, 6))
                    + filaTabla('Cargo de la llamada', nf(carga.call_charge))
                    + filaTabla('Cargo de LLM', nf(carga.llm_charge))
                    + filaTabla('Precio de LLM (USD)', nf(carga.llm_price, 6))
                    + filaTabla('Cargo de plataforma', nf(carga.platform_charge))
                    + filaTabla('TTS: audio generado', esc((carga.tts_usage && carga.tts_usage.total_audio_output_seconds) || 0) + ' seg')
                    + filaTabla('TTS: caracteres', esc((carga.tts_usage && carga.tts_usage.total_characters) || 0))
                    + filaTabla('ASR: llamadas', esc((carga.asr_usage && carga.asr_usage.total_transcription_calls) || 0))
                    + filaTabla('Modelo TTS', esc((carga.tts_usage && carga.tts_usage.primary_tts_model) || '—'))
                    + '</table></div></div></div>';

                if (meta.error) {
                    html += '<div class="col-12"><div class="alert alert-danger py-2 mb-0 small">'
                        + '<strong>Error de la llamada:</strong> ' + esc(meta.error.reason || '')
                        + ' <span class="text-muted">(' + esc(meta.error.error_type || '') + ', code ' + esc(meta.error.code || '') + ')</span>'
                        + '</div></div>';
                }

                if ((meta.warnings || []).length) {
                    html += '<div class="col-12"><div class="alert alert-warning py-2 mb-0 small">'
                        + '<strong>Avisos:</strong> ' + esc((meta.warnings || []).join(' | '))
                        + '</div></div>';
                }

                html += '<div class="col-12"><details><summary class="small text-muted">'
                    + 'Bloques crudos (charging, features_usage, deletion_settings, feedback)</summary>'
                    + '<pre class="valor-pre mt-2">' + esc(JSON.stringify({
                        charging: meta.charging,
                        features_usage: meta.features_usage,
                        deletion_settings: meta.deletion_settings,
                        feedback: meta.feedback,
                        visited_agents: d.visited_agents,
                        tag_ids: d.tag_ids,
                    }, null, 2)) + '</pre></details></div>';

                html += '</div>';

                return html;
            }

            // --------------------------------------------------- previsualización

            const MAX_PREVIEW = 25;

            /**
             * Etapa 4b: manda traducir las llamadas listadas y muestra cuáles
             * entrarían al lote y por qué las demás no. No escribe nada.
             */
            async function previsualizar() {
                const ids = Object.keys(filasPorId).slice(0, MAX_PREVIEW);

                $('preview_error').classList.add('d-none');
                $('preview_errores_api').innerHTML = '';
                $('preview_resumen').innerHTML = '';
                $('preview_cuerpo').innerHTML = '';
                $('preview_resultado').classList.add('d-none');
                $('preview_resultado').innerHTML = '';
                $('preview_importando').classList.add('d-none');
                $('preview_pie').textContent = 'La importación crea el lote pero no las llamadas: eso lo hace «Procesar lote».';

                modalPreview.show();

                if (!ids.length) {
                    $('preview_error').textContent = 'Primero busca un rango con llamadas.';
                    $('preview_error').classList.remove('d-none');
                    return;
                }

                $('preview_cargando').classList.remove('d-none');

                try {
                    const respuesta = await fetch(urlPrevisualizar, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({conversation_ids: ids}),
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        $('preview_error').textContent = datos.mensaje || 'No se pudo previsualizar.';
                        $('preview_error').classList.remove('d-none');
                        return;
                    }

                    pintarPrevisualizacion(datos);

                } catch (e) {
                    $('preview_error').textContent = 'No se pudo previsualizar: ' + e.message;
                    $('preview_error').classList.remove('d-none');
                } finally {
                    $('preview_cargando').classList.add('d-none');
                }
            }

            function pintarPrevisualizacion(datos) {
                const r = datos.resumen || {};
                const motivos = datos.motivos_texto || {};
                const total = Object.keys(filasPorId).length;
                const nota = total > MAX_PREVIEW
                    ? ' <span class="text-warning">(se previsualizan las primeras ' + MAX_PREVIEW + ' de ' + total + ')</span>'
                    : '';

                let resumen = '<div class="d-flex flex-wrap gap-2 align-items-center">'
                    + '<span class="badge bg-primary">' + (r.total || 0) + ' traducidas</span>'
                    + '<span class="badge bg-success">' + (r.incluidas || 0) + ' entrarían al lote</span>'
                    + '<span class="badge bg-secondary">' + (r.fuera || 0) + ' quedarían fuera</span>'
                    + (r.avisos ? '<span class="badge bg-warning text-dark">' + r.avisos + ' avisos</span>' : '')
                    + '<span class="small text-muted">' + nota + '</span>'
                    + '</div>';

                if (r.motivos && Object.keys(r.motivos).length) {
                    resumen += '<div class="small text-muted mt-2">Motivos: '
                        + Object.keys(r.motivos).map((k) =>
                            esc(motivos[k] || k) + ' (' + r.motivos[k] + ')').join(' · ')
                        + '</div>';
                }

                $('preview_resumen').innerHTML = resumen;

                if ((datos.errores || []).length) {
                    $('preview_errores_api').innerHTML = '<div class="alert alert-warning py-2 small">'
                        + datos.errores.map((e) => '<div><code>' + esc(e.conversation_id) + '</code>: '
                            + esc(e.mensaje) + '</div>').join('')
                        + '</div>';
                }

                $('preview_cuerpo').innerHTML = (datos.llamadas || []).map((l) => {
                    const c = l.clave || {};

                    const motivoHtml = (l.motivos || []).map((m) =>
                        '<div class="text-danger" style="font-size:11px">' + esc(motivos[m] || m) + '</div>').join('')
                        + (l.avisos || []).map((a) =>
                            '<div class="text-warning" style="font-size:11px">' + esc(a) + '</div>').join('');

                    return '<tr>'
                        + '<td class="text-nowrap small">' + esc(l.inicio_local) + '</td>'
                        + '<td class="small">' + esc(l.agente) + '</td>'
                        + '<td>' + (l.incluye
                            ? '<span class="badge bg-success">sí</span>'
                            : '<span class="badge bg-secondary">no</span>') + '</td>'
                        + '<td>' + (motivoHtml || '<span class="text-muted small">—</span>') + '</td>'
                        + '<td class="text-center small">' + (c.llamada_tipo || '<span class="text-muted">—</span>') + '</td>'
                        + '<td class="small">' + esc(c.ref || '—') + '</td>'
                        + '<td class="small">' + esc((c.origen || '—') + ' → ' + (c.destino || '—')) + '</td>'
                        + '<td class="small">' + esc(c.telefono || '—') + '</td>'
                        + '<td class="small">' + esc(c.conductor || '—') + '</td>'
                        + '<td class="small text-nowrap">' + esc(c.fecha_prometida || '—') + '</td>'
                        + '<td class="small">' + esc(c.razon_finalizacion || '—') + '</td>'
                        + '<td class="text-end small">' + esc(c.costo || '—') + '</td>'
                        + '</tr>';
                }).join('');
            }

            // ---------------------------------------------------- importar al lote

            const MAX_BLOQUE = 25;

            /**
             * Importa las llamadas listadas al lote, en bloques de 25 y con progreso.
             *
             * Va por bloques porque cada llamada cuesta una consulta de detalle a la
             * API: 200 llamadas en un solo request tumbarian el timeout.
             */
            async function importarLote() {
                const ids = Object.keys(filasPorId);

                if (!ids.length) return;

                const totalBloques = Math.ceil(ids.length / MAX_BLOQUE);

                if (!confirm('Se van a importar ' + ids.length + ' llamada(s) al lote.\n\n'
                        + 'Las que no cumplan (sin conductor, sin tipo, duplicadas) se omiten y se informan.\n\n¿Continuar?')) {
                    return;
                }

                const boton = $('btn_importar_lote');
                const original = boton.innerHTML;
                boton.disabled = true;
                boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Importando…';

                $('preview_resultado').classList.add('d-none');
                $('preview_importando').classList.remove('d-none');
                $('preview_importando_barra').style.width = '0%';

                let loteId = null;
                let insertadas = 0;
                let omitidas = 0;
                let duplicadas = 0;
                const motivos = {};
                const errores = [];

                const rango = ($('resumen').textContent || '').split('·').slice(1, 2).join('').trim();

                try {
                    for (let i = 0; i < totalBloques; i++) {
                        const bloque = ids.slice(i * MAX_BLOQUE, (i + 1) * MAX_BLOQUE);

                        $('preview_importando_texto').textContent =
                            'bloque ' + (i + 1) + ' de ' + totalBloques + ' · ' + bloque.length + ' llamadas';
                        $('preview_importando_barra').style.width = Math.round((i / totalBloques) * 100) + '%';

                        const respuesta = await fetch(urlImportar, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                conversation_ids: bloque,
                                lote_id: loteId,
                                rango_texto: rango,
                            }),
                        });

                        // Se lee como texto y se intenta parsear: si el servidor
                        // devuelve HTML (sesion expirada, 403, 500) hay que decirlo,
                        // no tragarselo con un "no se pudo" generico.
                        const textoRespuesta = await respuesta.text();
                        let datos = {};

                        try {
                            datos = JSON.parse(textoRespuesta);
                        } catch (e) {
                            datos = {};
                        }

                        if (!respuesta.ok || !datos.ok) {
                            let detalle = datos.mensaje || datos.message || '';

                            if (datos.errors) {
                                detalle += ' ' + Object.keys(datos.errors)
                                    .map((k) => k + ': ' + [].concat(datos.errors[k]).join(', '))
                                    .join(' | ');
                            }

                            if (!detalle) {
                                detalle = 'HTTP ' + respuesta.status + ' — '
                                    + textoRespuesta.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
                            }

                            throw new Error(detalle);
                        }

                        loteId = datos.lote_id;
                        insertadas += datos.insertadas;
                        omitidas += datos.omitidas;
                        duplicadas += datos.duplicadas;

                        Object.keys(datos.motivos || {}).forEach((k) => {
                            motivos[k] = (motivos[k] || 0) + datos.motivos[k];
                        });

                        (datos.errores || []).forEach((e) => errores.push(e));

                        $('preview_importando_barra').style.width = Math.round(((i + 1) / totalBloques) * 100) + '%';
                    }

                    const textos = {
                        no_marcado_para_lote: 'asistente no marcado para el lote',
                        sin_tipo: 'asistente sin tipo de llamada',
                        estado_no_final: 'llamada no terminada',
                        sin_conductor: 'sin conductor',
                        ya_importada: 'ya estaba en llamadas',
                        ya_en_el_lote: 'ya estaba en el lote',
                    };

                    let resumen = '<strong>Lote creado.</strong> '
                        + insertadas + ' llamada(s) en el lote'
                        + (omitidas ? ' · ' + omitidas + ' omitida(s)' : '')
                        + (duplicadas ? ' · ' + duplicadas + ' duplicada(s)' : '')
                        + (errores.length ? ' · ' + errores.length + ' con error de API' : '');

                    const detalleMotivos = Object.keys(motivos).map((k) =>
                        (textos[k] || k) + ': ' + motivos[k]);

                    if (detalleMotivos.length) {
                        resumen += '<div class="small mt-1">' + esc(detalleMotivos.join(' · ')) + '</div>';
                    }

                    resumen += '<div class="mt-2">'
                        + '<a class="btn btn-sm btn-primary" href="' + esc(datos_url_lote(loteId)) + '">'
                        + '<i class="bi bi-box-arrow-up-right"></i> Ir al lote y «Procesar lote»</a></div>';

                    const caja = $('preview_resultado');
                    caja.innerHTML = resumen;
                    caja.classList.remove('d-none');

                    $('preview_pie').textContent = 'Lote ' + loteId
                        + '. El siguiente paso es procesarlo para crear las llamadas.';

                } catch (e) {
                    $('preview_error').textContent = 'No se pudo importar: ' + e.message;
                    $('preview_error').classList.remove('d-none');
                } finally {
                    $('preview_importando').classList.add('d-none');
                    boton.disabled = false;
                    boton.innerHTML = original;
                }
            }

            /** URL de la pantalla del lote (la de siempre, la del flujo Excel). */
            function datos_url_lote(loteId) {
                return urlLoteBase + '/' + encodeURIComponent(loteId);
            }

            // -------------------------------------------------------- equivalencias

            function equivMensaje(tipo, texto) {
                const ok = $('equiv_ok');
                const error = $('equiv_error');

                ok.classList.add('d-none');
                error.classList.add('d-none');

                if (!texto) return;

                const el = tipo === 'ok' ? ok : error;
                el.textContent = texto;
                el.classList.remove('d-none');
            }

            function renderEquivalencias() {
                const cuerpo = $('equiv_cuerpo');
                if (!cuerpo) return;

                if (!catalogoAgentes.length) {
                    cuerpo.innerHTML = '<tr><td colspan="4" class="text-muted small">'
                        + 'No hay asistentes sincronizados. Pulsa <strong>Sincronizar asistentes</strong>.'
                        + '</td></tr>';
                    return;
                }

                const opcionesTipo = ['<option value="">— Fuera del lote —</option>']
                    .concat(Object.keys(TIPOS).map((id) =>
                        '<option value="' + id + '">' + id + ' · ' + esc(TIPOS[id]) + '</option>'))
                    .join('');

                cuerpo.innerHTML = catalogoAgentes.map((ag) => {
                    const deshabilitado = PUEDE_EDITAR ? '' : ' disabled';

                    return '<tr data-agent="' + esc(ag.agent_id) + '">'
                        + '<td>'
                        + '<div class="small">' + esc(ag.nombre || '(sin nombre)') + '</div>'
                        + '<div class="text-muted" style="font-family:monospace;font-size:11px">'
                        + esc(ag.agent_id) + '</div>'
                        + '</td>'
                        + '<td><select class="form-select form-select-sm sel-tipo"' + deshabilitado + '>'
                        + opcionesTipo + '</select></td>'
                        + '<td class="text-center"><input type="checkbox" class="form-check-input chk-lote"'
                        + (ag.incluir_en_lote ? ' checked' : '') + deshabilitado + '></td>'
                        + '<td class="text-center"><input type="checkbox" class="form-check-input chk-activo"'
                        + (ag.activo ? ' checked' : '') + deshabilitado + '></td>'
                        + '</tr>';
                }).join('');

                // Marcar la opcion correcta sin depender del reemplazo de string
                cuerpo.querySelectorAll('tr[data-agent]').forEach((tr) => {
                    const ag = catalogoAgentes.find((a) => a.agent_id === tr.dataset.agent);
                    if (!ag) return;
                    const select = tr.querySelector('.sel-tipo');
                    select.value = ag.tipo_llamada_id === null ? '' : String(ag.tipo_llamada_id);
                });
            }

            // --------------------------------------------------- mapeo de campos

            function opcionesSelect(mapa, actual) {
                return Object.keys(mapa).map((k) =>
                    '<option value="' + esc(k) + '"' + (String(actual) === String(k) ? ' selected' : '') + '>'
                    + esc(mapa[k]) + '</option>').join('');
            }

            function renderCampos() {
                const cuerpo = $('campos_cuerpo');
                if (!cuerpo) return;

                const deshabilitado = PUEDE_EDITAR ? '' : ' disabled';

                cuerpo.innerHTML = catalogoCampos.map((c) => {
                    const maximo = (catalogos.columnas_max || {})[c.campo_destino];
                    const etiqueta = '<div><code class="small">' + esc(c.campo_destino) + '</code>'
                        + '<span class="text-muted ms-1" style="font-size:10.5px">'
                        + (typeof maximo === 'number' ? maximo + ' car.' : '') + '</span></div>'
                        + (c.descripcion
                            ? '<div class="text-muted" style="font-size:10.5px;line-height:1.25">'
                              + esc(c.descripcion) + '</div>'
                            : '');

                    let celdas;

                    if (c.tipo_origen === 'calculado' || c.tipo_origen === 'regla') {
                        const destino = c.tipo_origen === 'regla'
                            ? 'Lo resuelve la pestaña <strong>Reglas</strong>'
                            : 'Lo calcula el código';
                        celdas = '<td colspan="4" class="small text-muted">'
                            + '<i class="bi bi-lock"></i> ' + destino + '</td>'
                            + '<td></td>';
                    } else {
                        const esConstante = c.tipo_origen === 'constante';
                        const valor = esConstante ? (c.valor_constante || '') : (c.ruta_origen || '');

                        // Un campo admite VARIAS rutas candidatas, una por linea: se
                        // prueban en orden y gana la primera que trae valor. Asi un
                        // mismo campo sirve para asistentes que nombran distinto la
                        // variable (carrier_name / nombre_transportista / driver_name).
                        const candidatas = esConstante
                            ? 0
                            : valor.split('\n').filter((l) => l.trim() !== '' && l.trim()[0] !== '#').length;

                        const filas = Math.min(5, Math.max(2, candidatas));

                        const editor = esConstante
                            ? '<input type="text" class="form-control form-control-sm in-origen" '
                              + 'value="' + esc(valor) + '"' + deshabilitado + ' placeholder="valor fijo">'
                            : '<textarea class="form-control form-control-sm in-origen" rows="' + filas + '"'
                              + deshabilitado + ' spellcheck="false" style="font-family:monospace;font-size:11.5px"'
                              + ' placeholder="una ruta por línea, en orden&#10;'
                              + 'conversation_initiation_client_data.dynamic_variables.reference&#10;'
                              + 'conversation_initiation_client_data.dynamic_variables.trip_code">'
                              + esc(valor) + '</textarea>';

                        celdas = '<td colspan="1">'
                            + '<div class="d-flex gap-1 align-items-start">'
                            + '<div class="flex-grow-1">' + editor
                            + (esConstante
                                ? ''
                                : '<div class="text-muted" style="font-size:10.5px">'
                                  + (candidatas > 1
                                      ? candidatas + ' rutas candidatas, se prueban en orden'
                                      : (candidatas === 1 ? '1 ruta' : 'sin ruta configurada'))
                                  + '</div>')
                            + '</div>'
                            + (esConstante
                                ? ''
                                : '<button type="button" class="btn btn-outline-info btn-sm btn-buscar-ruta"'
                                  + deshabilitado + ' title="Agregar una ruta buscándola en el JSON de una llamada">'
                                  + '<i class="bi bi-search"></i></button>')
                            + '</div>'
                            + '</td>'
                            + '<td>'
                            + (esConstante
                                ? '<span class="small text-muted">—</span>'
                                : '<select class="form-select form-select-sm in-transf"' + deshabilitado + '>'
                                  + opcionesSelect(catalogos.transformaciones, c.transformacion) + '</select>')
                            + '</td>'
                            + '<td><input type="text" class="form-control form-control-sm in-defecto" '
                            + 'value="' + esc(c.valor_por_defecto || '') + '"' + deshabilitado + '></td>'
                            + '<td></td>';
                    }

                    // `activo` solo tiene efecto en las filas editables (campo y
                    // constante): es un interruptor para apagar la columna sin perder
                    // su configuracion. En las de regla y las calculadas no hace nada,
                    // asi que no se muestra para no confundir.
                    const casillaActivo = (c.tipo_origen === 'campo' || c.tipo_origen === 'constante')
                        ? '<td class="text-center"><input type="checkbox" class="form-check-input in-activo"'
                          + (c.activo ? ' checked' : '') + deshabilitado
                          + ' title="Apaga la columna sin borrar su configuración"></td>'
                        : '<td class="text-center text-muted" title="Lo maneja la pestaña Reglas">'
                          + (c.tipo_origen === 'regla' ? 'reglas' : '—') + '</td>';

                    return '<tr data-campo="' + esc(c.campo_destino) + '" data-tipo="' + esc(c.tipo_origen) + '">'
                        + '<td>' + etiqueta + '</td>'
                        + '<td>' + badge(c.tipo_origen, c.tipo_origen === 'calculado' ? 'dark' : 'secondary') + '</td>'
                        + celdas
                        + casillaActivo
                        + '</tr>';
                }).join('');

                const editables = catalogoCampos.filter((c) => c.tipo_origen === 'campo' || c.tipo_origen === 'constante').length;
                $('resumen_campos').textContent = catalogoCampos.length + ' columnas · '
                    + editables + ' editables · '
                    + catalogoCampos.filter((c) => c.tipo_origen === 'regla').length + ' por regla · '
                    + catalogoCampos.filter((c) => c.tipo_origen === 'calculado').length + ' calculadas';
                $('badge_n_campos').textContent = catalogoCampos.length;

                // Cada fila de tipo campo tiene su boton para buscar la ruta en el JSON
                cuerpo.querySelectorAll('.btn-buscar-ruta').forEach((boton) => {
                    boton.addEventListener('click', () => {
                        const fila = boton.closest('tr');
                        abrirSelectorRutas(
                            fila.querySelector('.in-origen'),
                            'Se va a escribir en «' + fila.dataset.campo + '»'
                        );
                    });
                });
            }

            // -------------------------------------------- buscador de rutas del JSON

            /**
             * Llena el desplegable de llamadas de ejemplo del buscador de rutas.
             *
             * Se reconstruye CADA VEZ: antes solo se llenaba si estaba vacío, así
             * que si abrías la lupa antes de que terminara de cargar el listado, el
             * desplegable se quedaba con "no hay llamadas" para siempre aunque las
             * llamadas llegaran después.
             */
            function renderRutasConversacion() {
                const select = $('rutas_conversacion');
                if (!select) return;

                const ids = Object.keys(filasPorId);
                const previo = select.value;

                select.innerHTML = ids.length
                    ? ids.map((id) => {
                        const f = filasPorId[id];
                        return '<option value="' + esc(id) + '">'
                            + esc(f.inicio_local) + ' · ' + esc(f.agent_name || f.agent_id)
                            + '</option>';
                    }).join('')
                    : '<option value="">— Primero busca un rango en la pantalla —</option>';

                // Se conserva lo que estaba elegido si sigue en la lista
                if (previo && ids.indexOf(previo) !== -1) {
                    select.value = previo;
                }
            }

            function abrirSelectorRutas(input, etiqueta) {
                inputRutaDestino = input;

                $('rutas_destino_etiqueta').textContent = etiqueta || '';

                $('rutas_traer_error').classList.add('d-none');

                renderRutasConversacion();

                if (Object.keys(rutasJson).length) {
                    mostrarRutas();
                } else {
                    $('rutas_sin_datos').classList.remove('d-none');
                    $('rutas_con_datos').classList.add('d-none');
                }

                modalRutas.show();

                if (Object.keys(rutasJson).length) {
                    setTimeout(() => $('buscar_ruta').focus(), 300);
                }
            }

            function mostrarRutas() {
                $('rutas_sin_datos').classList.add('d-none');
                $('rutas_con_datos').classList.remove('d-none');
                $('buscar_ruta').value = '';
                renderListaRutas('');
            }

            function renderListaRutas(filtro) {
                const lista = $('rutas_lista');
                const texto = (filtro || '').toLowerCase();
                const rutas = Object.keys(rutasJson).sort();

                const visibles = rutas.filter((ruta) => {
                    if (texto === '') return true;
                    return ruta.toLowerCase().indexOf(texto) !== -1
                        || String(rutasJson[ruta].valor || '').toLowerCase().indexOf(texto) !== -1;
                });

                $('rutas_contador').textContent = visibles.length + ' de ' + rutas.length;

                if (!visibles.length) {
                    lista.innerHTML = '<div class="text-muted small p-2">Ninguna ruta coincide.</div>';
                    return;
                }

                // Tope visual: 300 filas es mas que suficiente y no congela el modal
                lista.innerHTML = '<div class="list-group list-group-flush">'
                    + visibles.slice(0, 300).map((ruta) => {
                        const dato = rutasJson[ruta];
                        return '<button type="button" class="list-group-item list-group-item-action py-1 px-2 btn-ruta"'
                            + ' data-ruta="' + esc(ruta) + '">'
                            + '<div class="small" style="font-family:monospace;color:#0dcaf0">' + esc(ruta) + '</div>'
                            + '<div class="text-muted" style="font-size:11px;word-break:break-word">'
                            + (dato.valor === '' ? '(vacío)' : esc(dato.valor)) + '</div>'
                            + '</button>';
                    }).join('')
                    + '</div>';

                lista.querySelectorAll('.btn-ruta').forEach((boton) => {
                    boton.addEventListener('click', () => {
                        if (inputRutaDestino) {
                            const ruta = boton.dataset.ruta;

                            if (inputRutaDestino.tagName === 'TEXTAREA') {
                                // Varias rutas candidatas: se AGREGA al final.
                                const actual = inputRutaDestino.value.trim();
                                inputRutaDestino.value = actual === '' ? ruta : actual + '\n' + ruta;
                            } else if (inputRutaDestino.tagName === 'SELECT') {
                                // Selector de la pestaña Reglas: se reconstruyen las
                                // opciones con la ruta elegida (aunque no estuviera en la
                                // lista) y se deja seleccionada.
                                inputRutaDestino.innerHTML = opcionesRuta(ruta);
                                inputRutaDestino.value = ruta;
                                inputRutaDestino.dispatchEvent(new Event('change'));
                            } else {
                                inputRutaDestino.value = ruta;
                            }
                        }
                        modalRutas.hide();
                    });
                });
            }

            async function traerRutas() {
                const id = $('rutas_conversacion').value;
                const boton = $('btn_rutas_traer');
                const original = boton.innerHTML;

                $('rutas_traer_error').classList.add('d-none');

                if (!id) {
                    $('rutas_traer_error').textContent = 'No hay ninguna llamada para leer.';
                    $('rutas_traer_error').classList.remove('d-none');
                    return;
                }

                boton.disabled = true;
                boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Trayendo…';

                try {
                    const respuesta = await fetch(urlMapeoRutas, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({conversation_id: id}),
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        $('rutas_traer_error').textContent = datos.mensaje || 'No se pudieron traer las rutas.';
                        $('rutas_traer_error').classList.remove('d-none');
                        return;
                    }

                    Object.assign(rutasJson, datos.rutas || {});
                    refrescarSelectoresRutas();

                    const estado = $('rutas_json_estado');
                    if (estado) estado.textContent = Object.keys(rutasJson).length + ' rutas del JSON disponibles';

                    mostrarRutas();
                    setTimeout(() => $('buscar_ruta').focus(), 100);

                } catch (e) {
                    $('rutas_traer_error').textContent = 'No se pudieron traer las rutas: ' + e.message;
                    $('rutas_traer_error').classList.remove('d-none');
                } finally {
                    boton.disabled = false;
                    boton.innerHTML = original;
                }
            }

            // -------------------------------------------------- mapeo de reglas

            function opcionesValorRegla(regla, campo) {
                // El valor destino depende de la columna: no todas admiten texto
                // libre. `razon_finalizacion` se compara exacto contra
                // razones_finalizacion.name; las banderas (error_tecnico_llamada,
                // conductor_cuelga, conductor_no_contesta) y `entro_llamada` son
                // tinyint en `llamadas`: solo 1 / 0 / vacío.
                const cfg = catalogos.opciones_valor ? catalogos.opciones_valor[campo] : null;
                const actual = String(regla.valor_destino || '');

                if (!cfg || cfg.tipo === 'texto') {
                    return '<input type="text" class="form-control form-control-sm in-valor-destino" value="'
                        + esc(actual) + '" title="' + esc((cfg && cfg.nota) || '') + '">';
                }

                let opciones;

                if (cfg.tipo === 'razones') {
                    opciones = catalogos.razones.map((r) => ({
                        valor: r.name,
                        texto: r.name + ' — ' + r.nombre,
                    }));
                } else {
                    opciones = cfg.opciones.map((v) => ({
                        valor: v,
                        texto: v === '' ? '(vacío)' : v,
                    }));
                }

                // Si el valor guardado no está en el dominio, se agrega para no
                // perderlo al guardar; se marca para que se vea que hay que revisarlo.
                if (actual !== '' && !opciones.some((o) => o.valor === actual)) {
                    opciones.unshift({
                        valor: actual,
                        texto: actual + ' (fuera de la lista, se conserva)',
                    });
                }

                return '<select class="form-select form-select-sm in-valor-destino" title="'
                    + esc(cfg.nota || '') + '">'
                    + opciones.map((o) => '<option value="' + esc(o.valor) + '"'
                        + (o.valor === actual ? ' selected' : '') + '>' + esc(o.texto) + '</option>').join('')
                    + '</select>';
            }

            /**
             * Opciones del selector de ruta disparador.
             *
             * Se mezclan las rutas fijas (las que casi siempre se usan) con las que
             * se descubrieron del JSON real al pulsar Probar, y al lado de cada una
             * va el valor que traía la llamada: es lo que hace fácil escribir la
             * condición sin adivinar.
             */
            function opcionesRuta(rutaActual) {
                const comunes = catalogos.rutas_disparador.slice();
                const enComunes = new Set(comunes);
                const descubiertas = Object.keys(rutasJson).sort().filter((r) => !enComunes.has(r));

                // Una ruta ya guardada que no esté en ninguna lista no se pierde
                if (rutaActual && !enComunes.has(rutaActual) && descubiertas.indexOf(rutaActual) === -1) {
                    descubiertas.unshift(rutaActual);
                }

                const opcion = (ruta) => {
                    const dato = rutasJson[ruta];
                    const valor = dato && dato.valor !== '' ? ' — ' + dato.valor : '';
                    return '<option value="' + esc(ruta) + '"'
                        + (String(rutaActual) === String(ruta) ? ' selected' : '') + '>'
                        + esc(ruta) + esc(valor) + '</option>';
                };

                let html = '<optgroup label="Comunes">' + comunes.map(opcion).join('') + '</optgroup>';

                if (descubiertas.length) {
                    html += '<optgroup label="Encontradas en el JSON (' + descubiertas.length + ')">'
                        + descubiertas.map(opcion).join('') + '</optgroup>';
                }

                return html;
            }

            /** Refresca los selectores y el datalist sin perder lo ya escrito. */
            function refrescarSelectoresRutas() {
                const datos = $('rutas_json');
                if (datos) {
                    datos.innerHTML = Object.keys(rutasJson).sort().map((ruta) =>
                        '<option value="' + esc(ruta) + '">' + esc(rutasJson[ruta].valor || '') + '</option>'
                    ).join('');
                }

                document.querySelectorAll('#reglas_cuerpo tr[data-idx]').forEach((tr) => {
                    const select = tr.querySelector('.in-ruta');
                    if (!select) return;

                    const elegido = select.value;
                    select.innerHTML = opcionesRuta(elegido);
                    select.value = elegido;
                });
            }

            /**
             * Un color por columna destino, para distinguirlas de un vistazo en la
             * tabla de reglas. Son colores fijos (no clases) porque hacen falta más
             * de los 8 contextuales de Bootstrap, y dos columnas distintas no pueden
             * parecer la misma.
             */
            const COLOR_COLUMNA = {
                type: '#0dcaf0',
                razon_finalizacion: '#0d6efd',
                entro_llamada: '#198754',
                error_tecnico_llamada: '#dc3545',
                conductor_cuelga: '#ffc107',
                conductor_no_contesta: '#fd7e14',
                error_origen: '#adb5bd',
            };

            function colorColumna(campo) {
                return COLOR_COLUMNA[campo] || '#6c757d';
            }

            /** Pinta la fila y el selector de columna con el color de esa columna. */
            function pintarColorColumna(tr) {
                const select = tr.querySelector('.in-campo');
                if (!select) return;

                const color = colorColumna(select.value);
                tr.style.borderLeft = '4px solid ' + color;
                select.style.borderColor = color;
                select.style.color = color;
                select.style.fontWeight = '600';

                const celdaValor = tr.querySelector('[data-celda-valor]');
                if (celdaValor) celdaValor.style.borderLeft = '2px solid ' + color;
            }

            /** Leyenda de colores encima de la tabla de reglas. */
            function renderLeyendaReglas() {
                const leyenda = $('leyenda_reglas');
                if (!leyenda) return;

                leyenda.innerHTML = catalogos.columnas_regla.map((campo) =>
                    '<span class="badge me-1 mb-1" style="background:' + colorColumna(campo)
                    + ';color:#fff;font-weight:600">' + esc(campo) + '</span>'
                ).join('');
            }

            /** Las condiciones de "vacío" no comparan contra un valor. */
            function comparacionSinValor(comparacion) {
                return comparacion === 'vacio' || comparacion === 'no_vacio';
            }

            /** "en otro caso" no mira ninguna ruta: solo el orden (prioridad). */
            function comparacionSinRuta(comparacion) {
                return comparacion === 'else';
            }

            function renderReglas() {
                const cuerpo = $('reglas_cuerpo');
                if (!cuerpo) return;

                const deshabilitado = PUEDE_EDITAR ? '' : ' disabled';

                renderLeyendaReglas();

                cuerpo.innerHTML = catalogoReglas.map((r, i) => {
                    return '<tr data-idx="' + i + '" data-id="' + (r.id || '') + '">'
                        + '<td><input type="number" class="form-control form-control-sm in-prioridad" '
                        + 'value="' + esc(r.prioridad) + '" style="width:70px'
                        + (comparacionSinRuta(r.comparacion) ? ';border-color:#ffc107' : '') + '"'
                        + (comparacionSinRuta(r.comparacion)
                            ? ' title="«En otro caso» debe ir AL FINAL: si tiene prioridad menor que otra regla de esta columna, la anula"'
                            : '')
                        + deshabilitado + '></td>'
                        + '<td><div class="d-flex gap-1 align-items-start">'
                        + '<select class="form-select form-select-sm in-ruta"'
                        + (comparacionSinRuta(r.comparacion)
                            ? ' disabled title="«En otro caso» no compara ninguna ruta: se aplica cuando ninguna otra regla de esta columna cumplió"'
                            : deshabilitado)
                        + '>'
                        + opcionesRuta(r.ruta_origen)
                        + '</select>'
                        + '<button type="button" class="btn btn-outline-info btn-sm btn-buscar-ruta-regla"'
                        + (comparacionSinRuta(r.comparacion) ? ' disabled' : deshabilitado)
                        + ' title="Buscar la ruta disparador en el JSON de una llamada">'
                        + '<i class="bi bi-search"></i></button>'
                        + '</div></td>'
                        + '<td><select class="form-select form-select-sm in-comparacion"' + deshabilitado + '>'
                        + opcionesSelect(catalogos.comparaciones, r.comparacion) + '</select></td>'
                        + '<td><input type="text" class="form-control form-control-sm in-valor-origen" value="'
                        + esc(r.valor_origen || '') + '"'
                        + (comparacionSinValor(r.comparacion) ? ' disabled title="Esta condición no compara un valor"' : deshabilitado)
                        + '></td>'
                        + '<td><select class="form-select form-select-sm in-campo"' + deshabilitado + '>'
                        + catalogos.columnas_regla.map((x) => '<option value="' + esc(x) + '"'
                            + (String(r.campo_destino) === String(x) ? ' selected' : '') + '>' + esc(x)
                            + '</option>').join('')
                        + '</select></td>'
                        + '<td data-celda-valor>' + opcionesValorRegla(r, r.campo_destino) + '</td>'
                        + '<td class="text-center"><input type="checkbox" class="form-check-input in-activo"'
                        + (r.activo ? ' checked' : '') + deshabilitado + '></td>'
                        + '<td class="text-center">'
                        + '<button type="button" class="btn btn-outline-danger btn-sm btn-borrar-regla"'
                        + ' title="Quitar la regla"' + deshabilitado + '><i class="bi bi-trash"></i></button>'
                        + '</td>'
                        + '</tr>';
                }).join('');

                cuerpo.querySelectorAll('tr[data-idx]').forEach((tr) => {
                    pintarColorColumna(tr);

                    tr.querySelector('.btn-borrar-regla').addEventListener('click', () => borrarRegla(tr));

                    // Lupa: buscar la ruta disparador en el JSON real
                    tr.querySelector('.btn-buscar-ruta-regla').addEventListener('click', () => {
                        abrirSelectorRutas(
                            tr.querySelector('.in-ruta'),
                            'Ruta disparador de la regla (prioridad ' + tr.querySelector('.in-prioridad').value + ')'
                        );
                    });

                    tr.querySelector('.in-campo').addEventListener('change', () => {
                        pintarColorColumna(tr);

                        const idx = Number(tr.dataset.idx);
                        const celda = tr.querySelector('[data-celda-valor]');
                        const datos = leerReglaDeFila(tr);
                        celda.innerHTML = opcionesValorRegla(
                            {valor_destino: catalogoReglas[idx] ? catalogoReglas[idx].valor_destino : ''},
                            datos.campo_destino
                        );
                    });

                    // Al elegir la ruta, si el valor esta vacio se propone el que trajo
                    // la llamada: es casi siempre lo que se quiere comparar.
                    tr.querySelector('.in-ruta').addEventListener('change', (ev) => {
                        const entrada = tr.querySelector('.in-valor-origen');
                        if (!entrada || entrada.disabled || entrada.value !== '') return;

                        const dato = rutasJson[ev.target.value];
                        if (dato && dato.valor !== '') entrada.value = dato.valor;
                    });

                    // "está vacío" / "no está vacío" no comparan contra un valor, y
                    // "en otro caso" no compara ninguna ruta: se deshabilitan para
                    // que no confundan.
                    tr.querySelector('.in-comparacion').addEventListener('change', (ev) => {
                        const aplicaValor = !comparacionSinValor(ev.target.value);
                        const aplicaRuta = !comparacionSinRuta(ev.target.value);

                        const valor = tr.querySelector('.in-valor-origen');
                        if (valor) {
                            valor.disabled = !aplicaValor;
                            valor.title = aplicaValor ? '' : 'Esta condición no compara un valor';
                        }

                        const ruta = tr.querySelector('.in-ruta');
                        if (ruta) {
                            ruta.disabled = !aplicaRuta;
                            ruta.title = aplicaRuta
                                ? ''
                                : '«En otro caso» no compara ninguna ruta: se aplica cuando ninguna otra regla de esta columna cumplió';
                        }

                        const lupa = tr.querySelector('.btn-buscar-ruta-regla');
                        if (lupa) lupa.disabled = !aplicaRuta;
                    });
                });

                $('badge_n_reglas').textContent = catalogoReglas.length;
            }

            function leerReglaDeFila(tr) {
                return {
                    id: tr.dataset.id ? Number(tr.dataset.id) : null,
                    prioridad: Number(tr.querySelector('.in-prioridad').value) || 100,
                    ruta_origen: tr.querySelector('.in-ruta').value,
                    comparacion: tr.querySelector('.in-comparacion').value,
                    valor_origen: tr.querySelector('.in-valor-origen').value,
                    campo_destino: tr.querySelector('.in-campo').value,
                    valor_destino: tr.querySelector('.in-valor-destino').value,
                    activo: tr.querySelector('.in-activo').checked,
                };
            }

            function borrarRegla(tr) {
                if (tr.dataset.id) reglasEliminar.push(Number(tr.dataset.id));

                const idx = Number(tr.dataset.idx);
                catalogoReglas.splice(idx, 1);
                renderReglas();
            }

            function agregarRegla() {
                catalogoReglas.push({
                    id: null,
                    prioridad: 100,
                    ruta_origen: catalogos.rutas_disparador[0] || 'metadata.termination_reason',
                    comparacion: 'igual',
                    valor_origen: '',
                    campo_destino: catalogos.columnas_regla[0] || 'razon_finalizacion',
                    valor_destino: catalogos.razones.length ? catalogos.razones[0].name : '',
                    activo: true,
                });
                renderReglas();
            }

            // -------------------------------------------------------- cargar mapeo

            async function cargarMapeo() {
                try {
                    const respuesta = await fetch(urlMapeo, {headers: {'Accept': 'application/json'}});
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        equivMensaje('error', datos.mensaje || 'No se pudo leer la configuración.');
                        return;
                    }

                    catalogoAgentes = datos.agentes || [];
                    catalogoCampos = datos.campos || [];
                    catalogoReglas = datos.reglas || [];
                    catalogoOpciones = datos.opciones || [];
                    catalogos = {
                        transformaciones: datos.transformaciones || {},
                        comparaciones: datos.comparaciones || {},
                        razones: datos.razones || [],
                        columnas_regla: datos.columnas_regla || [],
                        rutas_disparador: datos.rutas_disparador || [],
                        opciones_valor: datos.opciones_valor || {},
                        columnas_max: datos.columnas_max || {},
                    };
                    reglasEliminar = [];

                    renderEquivalencias();
                    renderCampos();
                    renderReglas();
                    renderPolitica();
                    renderListaAsistentes();
                    actualizarEtiquetaAsistentes();
                    renderProbarSelector();
                    $('badge_agentes_total').textContent = catalogoAgentes.length;
                    $('badge_n_asistentes').textContent = catalogoAgentes.length;

                } catch (e) {
                    equivMensaje('error', 'No se pudo leer la configuración: ' + e.message);
                }
            }

            function abrirEquivalencias() {
                equivMensaje('', '');
                if (PUEDE_EDITAR) {
                    $('btn_equiv_guardar').classList.remove('d-none');
                    $('btn_equiv_sincronizar').classList.remove('d-none');
                }
                renderEquivalencias();
                modalEquiv.show();
                cargarMapeo();
            }

            // -------------------------------------------------------- guardar todo

            // ----------------------------------------------------- politica (Etapa 6)

            /**
             * Dibuja las opciones del modulo (tabla elevenlabs_opciones).
             * Hoy es una sola: que estados de llamada entran al lote.
             */
            function renderPolitica() {
                const contenedor = $('politica_cuerpo');
                if (!contenedor) return;

                if (!catalogoOpciones.length) {
                    contenedor.innerHTML = '<div class="text-muted small">No hay opciones configuradas.</div>';
                    return;
                }

                contenedor.innerHTML = catalogoOpciones.map((opcion) => {
                    const valores = opcion.valores || [];
                    const casillas = Object.entries(opcion.opciones || {}).map(([valor, etiqueta]) => {
                        const id = 'pol_' + opcion.clave + '_' + valor;
                        return `<div class="form-check">
                            <input class="form-check-input in-politica" type="checkbox" id="${esc(id)}"
                                   data-clave="${esc(opcion.clave)}" value="${esc(valor)}"
                                   ${valores.includes(valor) ? 'checked' : ''}
                                   ${PUEDE_EDITAR ? '' : 'disabled'}>
                            <label class="form-check-label small" for="${esc(id)}">${esc(etiqueta)}</label>
                        </div>`;
                    }).join('');

                    return `<div class="mb-2">
                        <div class="fw-semibold small mb-1">${esc(opcion.etiqueta)}</div>
                        ${casillas}
                        ${opcion.ayuda ? `<div class="text-muted small mt-1">${esc(opcion.ayuda)}</div>` : ''}
                    </div>`;
                }).join('');
            }

            /**
             * Lee las casillas de la pestaña Politica como { clave: [valores] }.
             *
             * Se leen del DOM (no de catalogoOpciones) para que el guardado mande lo
             * que el usuario dejo marcado y no lo que vino del servidor.
             */
            function leerOpcionesDeTabla() {
                const opciones = {};

                document.querySelectorAll('#politica_cuerpo .in-politica').forEach((casilla) => {
                    const clave = casilla.dataset.clave;
                    if (!opciones[clave]) opciones[clave] = [];
                    if (casilla.checked) opciones[clave].push(casilla.value);
                });

                return opciones;
            }

            function leerCamposDeTabla() {
                const filas = [];

                document.querySelectorAll('#campos_cuerpo tr[data-campo]').forEach((tr) => {
                    const tipo = tr.dataset.tipo;
                    if (tipo === 'calculado' || tipo === 'regla') return;

                    const origen = tr.querySelector('.in-origen');
                    const transf = tr.querySelector('.in-transf');

                    filas.push({
                        campo_destino: tr.dataset.campo,
                        ruta_origen: tipo === 'campo' ? origen.value : null,
                        valor_constante: tipo === 'constante' ? origen.value : null,
                        transformacion: transf ? transf.value : 'ninguna',
                        valor_por_defecto: tr.querySelector('.in-defecto').value,
                        activo: tr.querySelector('.in-activo').checked,
                    });
                });

                return filas;
            }

            async function guardarTodo() {
                const agentes = [];
                document.querySelectorAll('#equiv_cuerpo tr[data-agent]').forEach((tr) => {
                    agentes.push({
                        agent_id: tr.dataset.agent,
                        tipo_llamada_id: tr.querySelector('.sel-tipo').value || null,
                        incluir_en_lote: tr.querySelector('.chk-lote').checked,
                        activo: tr.querySelector('.chk-activo').checked,
                    });
                });

                const reglas = [];
                document.querySelectorAll('#reglas_cuerpo tr[data-idx]').forEach((tr) => {
                    reglas.push(leerReglaDeFila(tr));
                });

                const cuerpo = {
                    agentes: agentes,
                    campos: leerCamposDeTabla(),
                    reglas: reglas,
                    reglas_eliminar: reglasEliminar,
                    opciones: leerOpcionesDeTabla(),
                };

                const boton = $('btn_equiv_guardar');
                const original = boton.innerHTML;
                boton.disabled = true;
                boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando…';
                equivMensaje('', '');

                try {
                    const respuesta = await fetch(urlEquivalenciasGuardar, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(cuerpo),
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        const detalle = datos.errors
                            ? Object.values(datos.errors).flat().join(' ')
                            : (datos.message || 'No se pudo guardar.');
                        equivMensaje('error', detalle);
                        return;
                    }

                    catalogoAgentes = datos.agentes || catalogoAgentes;
                    catalogoCampos = datos.campos || catalogoCampos;
                    catalogoReglas = datos.reglas || catalogoReglas;
                    catalogoOpciones = datos.opciones || catalogoOpciones;
                    reglasEliminar = [];

                    renderEquivalencias();
                    renderCampos();
                    renderReglas();
                    renderPolitica();
                    renderListaAsistentes();
                    actualizarEtiquetaAsistentes();
                    actualizarTiposEnTabla();
                    renderProbarSelector();

                    const r = datos.resumen || {};
                    equivMensaje('ok', 'Guardado · asistentes: ' + (r.asistentes || 0)
                        + ' · campos: ' + (r.campos || 0)
                        + ' · reglas: ' + ((r.reglas && r.reglas.actualizadas) || 0) + ' actualizadas, '
                        + ((r.reglas && r.reglas.creadas) || 0) + ' nuevas, '
                        + ((r.reglas && r.reglas.eliminadas) || 0) + ' borradas.'
                        + ' · política: ' + (r.opciones || 0) + ' opción(es).');

                } catch (e) {
                    equivMensaje('error', 'No se pudo guardar: ' + e.message);
                } finally {
                    boton.disabled = false;
                    boton.innerHTML = original;
                }
            }

            // -------------------------------------------------------------- probar

            function renderProbarSelector() {
                const select = $('probar_conversacion');
                if (!select) return;

                const filas = Object.values(filasPorId);
                if (!filas.length) {
                    select.innerHTML = '<option value="">— Primero busca un rango en la pantalla —</option>';
                    return;
                }

                const previo = select.value;
                select.innerHTML = filas.map((f) =>
                    '<option value="' + esc(f.conversation_id) + '">'
                    + esc(f.inicio_local) + ' · ' + esc(f.agent_name || f.agent_id)
                    + ' · ' + durHumana(f.call_duration_secs) + '</option>').join('');

                if (previo && filas.some((f) => f.conversation_id === previo)) {
                    select.value = previo;
                }
            }

            const COLOR_ESTADO = {
                campo: 'success',
                constante: 'secondary',
                regla: 'primary',
                defecto: 'warning',
                sin_regla: 'warning',
                calculado: 'dark',
            };

            async function probarMapeo() {
                const id = $('probar_conversacion').value;
                if (!id) {
                    $('probar_error').textContent = 'Elige una llamada (primero busca un rango en la pantalla).';
                    $('probar_error').classList.remove('d-none');
                    return;
                }

                $('probar_error').classList.add('d-none');
                $('probar_estado').textContent = 'Resolviendo…';
                $('probar_cuerpo').innerHTML = '';

                try {
                    const respuesta = await fetch(urlMapeoProbar, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({conversation_id: id}),
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        $('probar_error').textContent = datos.mensaje || 'No se pudo probar.';
                        $('probar_error').classList.remove('d-none');
                        $('probar_estado').textContent = '';
                        return;
                    }

                    $('probar_cuerpo').innerHTML = (datos.filas || []).map((f) => {
                        let celda;

                        // Largo real del valor: asi se ve si quedo en el tope de la
                        // columna (255, por ejemplo) o si se recorto antes.
                        const largo = f.valor === ''
                            ? ''
                            : '<span class="text-muted ms-1" style="font-size:10.5px">('
                              + f.valor.length + ' car.)</span>';

                        if (f.valor === '') {
                            celda = '<span class="text-muted">vacío</span>';
                        } else if (f.campo === 'audio') {
                            // el link lo sirve este backend; se ofrece escucharlo ahi mismo
                            celda = '<a href="' + esc(f.valor) + '" target="_blank" rel="noopener" '
                                + 'class="btn btn-outline-info btn-sm">'
                                + '<i class="bi bi-play-circle"></i> Escuchar</a>'
                                + '<div class="text-muted" style="font-size:10.5px">' + esc(f.valor) + '</div>';
                        } else if (f.valor.length > 120) {
                            // mensajes_conten puede pesar varios KB: no puede ir en una celda
                            celda = '<pre class="valor-pre">' + esc(f.valor) + '</pre>' + largo;
                        } else {
                            celda = '<span style="font-family:monospace">' + esc(f.valor) + '</span>' + largo;
                        }

                        return '<tr>'
                            + '<td><code class="small">' + esc(f.campo) + '</code></td>'
                            + '<td>' + badge(f.estado, COLOR_ESTADO[f.estado] || 'secondary')
                            + '<div class="text-muted" style="font-size:10.5px">' + esc(f.nota) + '</div></td>'
                            + '<td class="small">' + celda + '</td>'
                            + '</tr>';
                    }).join('');

                    const resumen = {};
                    (datos.filas || []).forEach((f) => { resumen[f.estado] = (resumen[f.estado] || 0) + 1; });

                    // Descubrimiento de rutas: con esto el selector de "ruta disparador"
                    // deja de ser una lista fija y pasa a ofrecer las rutas reales.
                    if (datos.rutas) {
                        Object.assign(rutasJson, datos.rutas);
                        refrescarSelectoresRutas();

                        const total = Object.keys(rutasJson).length;
                        const estado = $('rutas_json_estado');
                        if (estado) {
                            estado.textContent = total + ' rutas del JSON disponibles';
                        }
                    }

                    $('probar_estado').textContent = esc(datos.agente || '')
                        + ' · ' + esc(datos.inicio_local || '')
                        + ' · ' + Object.keys(resumen).map((k) => k + ': ' + resumen[k]).join(' · ');

                } catch (e) {
                    $('probar_error').textContent = 'No se pudo probar: ' + e.message;
                    $('probar_error').classList.remove('d-none');
                    $('probar_estado').textContent = '';
                }
            }

            async function sincronizarAsistentes() {
                const boton = $('btn_equiv_sincronizar');
                const original = boton.innerHTML;
                boton.disabled = true;
                boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sincronizando…';
                equivMensaje('', '');

                try {
                    const respuesta = await fetch(urlSincronizar, {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        equivMensaje('error', datos.mensaje || 'No se pudo sincronizar.');
                        return;
                    }

                    catalogoAgentes = datos.agentes || [];
                    renderEquivalencias();
                    renderListaAsistentes();
                    actualizarEtiquetaAsistentes();
                    $('badge_agentes_total').textContent = catalogoAgentes.length;

                    const r = datos.resumen || {};
                    equivMensaje('ok', 'Sincronizado: ' + (r.vistos || 0) + ' asistentes vistos, '
                        + (r.nuevos || 0) + ' nuevos, ' + (r.actualizados || 0) + ' actualizados.');

                } catch (e) {
                    equivMensaje('error', 'No se pudo sincronizar: ' + e.message);
                } finally {
                    boton.disabled = false;
                    boton.innerHTML = original;
                }
            }

            /** Repinta la columna "Tipo local" de la tabla sin volver a pedir la API. */
            function actualizarTiposEnTabla() {
                document.querySelectorAll('#cuerpo_tabla tr[data-cid]').forEach((tr) => {
                    const fila = filasPorId[tr.dataset.cid];
                    if (!fila) return;

                    const agente = catalogoAgentes.find((a) => a.agent_id === fila.agent_id);
                    const tipo = agente && agente.tipo_llamada_id !== null ? agente.tipo_llamada_id : null;
                    const incluir = agente ? agente.incluir_en_lote : false;

                    fila.tipo_llamada_id = tipo;
                    fila.incluir_en_lote = incluir;

                    const celda = tr.querySelector('.celda-tipo');
                    if (celda) celda.innerHTML = badgeTipo(tipo, incluir);
                });
            }

            // --------------------------------------------------------------- eventos

            $('btn_buscar').addEventListener('click', buscar);

            // ---- filtro multiple de asistentes
            $('btn_filtrar_agentes').addEventListener('click', () => {
                const instancia = bootstrap.Dropdown.getInstance($('btn_asistentes'));
                if (instancia) instancia.hide();
                buscar();
            });

            $('btn_agentes_todos').addEventListener('click', () => {
                // Solo los activos: los inactivos no se ofrecen en el filtro
                catalogoAgentes.filter((ag) => ag.activo).forEach((ag) => seleccionados.add(ag.agent_id));
                renderListaAsistentes();
                actualizarEtiquetaAsistentes();
                aplicarFiltroDiferido();
            });

            $('btn_agentes_ninguno').addEventListener('click', () => {
                seleccionados.clear();
                renderListaAsistentes();
                actualizarEtiquetaAsistentes();
                aplicarFiltroDiferido();
            });

            // ---- equivalencias
            $('btn_abrir_equivalencias').addEventListener('click', abrirEquivalencias);
            $('btn_equiv_guardar').addEventListener('click', guardarTodo);
            $('btn_equiv_sincronizar').addEventListener('click', sincronizarAsistentes);
            $('btn_regla_nueva').addEventListener('click', agregarRegla);
            $('btn_probar').addEventListener('click', probarMapeo);

            // filtro de columnas en la pestana Campos
            $('buscar_campo').addEventListener('input', (ev) => {
                const texto = ev.target.value.toLowerCase();
                document.querySelectorAll('#campos_cuerpo tr[data-campo]').forEach((tr) => {
                    tr.style.display = tr.dataset.campo.toLowerCase().indexOf(texto) === -1 ? 'none' : '';
                });
            });

            if (!PUEDE_EDITAR) {
                $('btn_equiv_guardar').classList.add('d-none');
                $('btn_equiv_sincronizar').classList.add('d-none');
                $('btn_regla_nueva').classList.add('d-none');
            } else {
                // Importar exige el permiso `importar eleven labs`
                $('btn_importar_lote').classList.remove('d-none');
            }

            // ---- buscador de rutas del JSON
            $('btn_rutas_traer').addEventListener('click', traerRutas);
            $('buscar_ruta').addEventListener('input', (ev) => renderListaRutas(ev.target.value));

            // ---- previsualización del lote
            $('btn_previsualizar').addEventListener('click', previsualizar);
            $('btn_importar_lote').addEventListener('click', importarLote);

            $('btn_hoy').addEventListener('click', () => { aplicarRango(0); buscar(); });

            $('btn_ayer').addEventListener('click', () => {
                $('fecha_desde').value = hoyISO(-1);
                $('hora_desde').value = '00:00';
                $('fecha_hasta').value = hoyISO(-1);
                $('hora_hasta').value = '23:59';
                buscar();
            });
            $('btn_7d').addEventListener('click', () => { aplicarRango(6); buscar(); });
            $('btn_30d').addEventListener('click', () => { aplicarRango(29); buscar(); });

            // ---- controles del reproductor (mismo comportamiento que /lupita/audio)
            $('btn_audio_atras').addEventListener('click', () => {
                if (audio.readyState) audio.currentTime = Math.max(0, audio.currentTime - 5);
            });
            $('btn_audio_adelante').addEventListener('click', () => {
                if (audio.readyState) audio.currentTime = audio.currentTime + 5;
            });
            $('btn_audio_reset').addEventListener('click', () => {
                audio.currentTime = 0;
            });

            const velocidades = [1, 1.25, 1.5, 2];
            $('btn_audio_velocidad').addEventListener('click', () => {
                const i = velocidades.indexOf(velocidad);
                velocidad = velocidades[(i + 1) % velocidades.length];
                audio.playbackRate = velocidad;
                $('card_velocidad').textContent = velocidad + 'x';
            });

            $('btn_audio_descargar').addEventListener('click', async () => {
                const id = $('det_id').textContent;
                if (!id) return;

                try {
                    const respuesta = await fetch(urlAudioBase + '/' + encodeURIComponent(id));
                    if (!respuesta.ok) throw new Error('HTTP ' + respuesta.status);

                    const blob = await respuesta.blob();
                    const blobUrl = window.URL.createObjectURL(blob);
                    const enlace = document.createElement('a');
                    enlace.href = blobUrl;
                    enlace.download = id + '.mp3';
                    enlace.click();
                    window.URL.revokeObjectURL(blobUrl);
                } catch (e) {
                    alert('No se pudo descargar el audio: ' + e.message);
                }
            });

            // ---- filtro de variables de entrada
            document.addEventListener('input', (ev) => {
                if (!ev.target || ev.target.id !== 'buscar_var') return;

                const texto = ev.target.value.toLowerCase();
                const cuerpo = $('tabla_negocio');
                if (!cuerpo) return;

                cuerpo.querySelectorAll('tr[data-var]').forEach((tr) => {
                    tr.style.display = tr.dataset.var.indexOf(texto) === -1 ? 'none' : '';
                });
            });

            // ---- copiar el JSON
            $('btn_copiar_json').addEventListener('click', async () => {
                if (!jsonActual) return;
                const btn = $('btn_copiar_json');
                const original = btn.innerHTML;

                try {
                    await navigator.clipboard.writeText(jsonActual);
                    btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                } catch (e) {
                    const rango = document.createRange();
                    rango.selectNodeContents($('json_texto'));
                    const sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(rango);
                    btn.innerHTML = '<i class="bi bi-textarea-t"></i> Seleccionado';
                }

                setTimeout(() => { btn.innerHTML = original; }, 1500);
            });

            // Al cerrar el modal se corta el audio: si no, sigue sonando a escondidas.
            modalEl.addEventListener('hidden.bs.modal', () => {
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
            });

            // Arranque: filtro de asistentes y primera consulta del dia
            renderListaAsistentes();
            actualizarEtiquetaAsistentes();
            buscar();
        })();
    </script>
@endsection
