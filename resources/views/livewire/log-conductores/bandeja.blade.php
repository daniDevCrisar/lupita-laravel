<!-- resources/views/livewire/log-conductores/bandeja.blade.php -->

<div>
    <div class="container-fluid py-3">

        {{-- ================= CABECERA ================= --}}
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h3 mb-0">Casos de conductores</h1>
                <small class="text-muted">
                    Reporte interno: numeros invalidos y conductores que no colaboran con las llamadas
                </small>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('lupita.conductores') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-list-ol me-1"></i> Ranking de conductores
                </a>
                <a href="{{ route('lupita.conductores.log') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-clock-history me-1"></i> Bitacora anterior
                </a>
            </div>
        </div>

        @if($aviso)
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-check-circle me-1"></i>{{ $aviso }}
                <button type="button" class="btn-close" wire:click="$set('aviso', '')"></button>
            </div>
        @endif

        {{-- ================= RESUMEN ================= --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0">{{ number_format($resumen->candidatos, 0, ',', '.') }}</div>
                        <small class="text-muted">Candidatos</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-warning h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-warning">{{ number_format($resumen->sin_caso, 0, ',', '.') }}</div>
                        <small class="text-muted">Sin caso abierto</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-info h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-info">{{ $resumen->cobertura }}%</div>
                        <small class="text-muted">Cobertura</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-primary h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-primary">{{ $resumen->abiertos }}</div>
                        <small class="text-muted">Casos abiertos</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-warning h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-warning">{{ $resumen->reportados }}</div>
                        <small class="text-muted">Reportados</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-success h-100">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-success">{{ $resumen->cerrados }}</div>
                        <small class="text-muted">
                            Cerrados
                            @if($resumen->cerrados > 0)
                                · {{ $resumen->mejorados }} mejoraron
                            @endif
                        </small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================= FILTROS ================= --}}
        <form wire:submit.prevent="aplicar" class="border border-secondary rounded p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="f_ini">Desde</label>
                    <input type="date" id="f_ini" class="form-control form-control-sm" wire:model="fecha_inicio">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="f_fin">Hasta</label>
                    <input type="date" id="f_fin" class="form-control form-control-sm" wire:model="fecha_fin">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" for="f_tipo">Tipo de llamada</label>
                    <select id="f_tipo" class="form-control form-control-sm" wire:model="llamada_tipo_id">
                        <option value="">Todas</option>
                        @foreach($tipos_llamada as $tipo)
                            <option value="{{ $tipo->id }}">{{ $tipo->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" for="f_cond">Conductor</label>
                    <input type="text" id="f_cond" class="form-control form-control-sm"
                           placeholder="nombre o id" wire:model="conductor">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="f_caso">Tipo de caso</label>
                    <select id="f_caso" class="form-control form-control-sm" wire:model="tipo_caso">
                        <option value="">Todos</option>
                        @foreach($tipos_caso as $clave => $tipo)
                            <option value="{{ $clave }}">{{ $tipo['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="f_estado">Estado del caso</label>
                    <select id="f_estado" class="form-control form-control-sm" wire:model="estado">
                        <option value="">Todos</option>
                        <option value="SIN_CASO">Sin caso abierto</option>
                        @foreach($estados as $clave => $item)
                            <option value="{{ $clave }}">{{ $item['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i> Filtrar
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="limpiar">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar
                    </button>
                    <span class="form-text mb-0">
                        Regla: minimo {{ $reglas['min'] }} llamadas validas ·
                        rechazo >= {{ $reglas['rechazo'] }}% sobre {{ $reglas['min_contestadas'] }} contestadas ·
                        buzon >= {{ $reglas['buzon'] }}% ·
                        numero equivocado: 1 llamada · otra persona >= {{ $reglas['dudoso'] }}% de las contestadas ·
                        score >= {{ $reglas['score'] }}
                    </span>
                </div>
            </div>
        </form>

        {{-- ================= PESTANAS ================= --}}
        <ul class="nav nav-tabs mb-0">
            <li class="nav-item">
                <button type="button" class="nav-link @if($tab === 'candidatos') active @endif"
                        wire:click="set_tab('candidatos')">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Candidatos ({{ $resumen->candidatos }})
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link @if($tab === 'casos') active @endif"
                        wire:click="set_tab('casos')">
                    <i class="bi bi-folder2-open me-1"></i>
                    Casos ({{ $resumen->casos }})
                </button>
            </li>
        </ul>

        {{-- ================= TABLA CANDIDATOS ================= --}}
        @if($tab === 'candidatos')
            <div class="table-responsive border border-top-0" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-dark table-hover mb-0 align-middle">
                    <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <th style="width: 70px;">Score</th>
                        <th>Conductor</th>
                        <th>Tipo / motivo</th>
                        <th class="text-end">Validas</th>
                        <th class="text-end">Buzon</th>
                        <th class="text-end">Contest.</th>
                        <th class="text-end">Equiv.</th>
                        <th class="text-end">Otra p.</th>
                        <th class="text-end">Exito</th>
                        <th class="text-end">Rechazo</th>
                        <th style="width: 130px;">Caso</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($candidatos as $c)
                        <tr>
                            <td>
                                <span class="badge bg-{{ $c->score >= 80 ? 'danger' : ($c->score >= 60 ? 'warning text-dark' : 'secondary') }}">
                                    {{ $c->score }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('lupita.llamadas') . '?' . http_build_query(['conductor' => $c->conductor_id]) }}"
                                   target="_blank" class="text-decoration-none">
                                    <i class="bi bi-person me-1"></i>{{ $c->conductor }}
                                </a>
                                <small class="text-muted d-block">#{{ $c->conductor_id }}</small>
                            </td>
                            <td>
                                @php $tipo = $tipos_caso[$c->tipo] ?? ['nombre' => $c->tipo, 'color' => 'secondary', 'icono' => 'bi-question']; @endphp
                                <span class="badge bg-{{ $tipo['color'] }}">
                                    <i class="bi {{ $tipo['icono'] }} me-1"></i>{{ $tipo['nombre'] }}
                                </span>
                                <small class="d-block text-muted">{{ $motivos[$c->motivo] ?? $c->motivo }}</small>
                            </td>
                            <td class="text-end">{{ $c->validas }}</td>
                            <td class="text-end">
                                {{ $c->buzon }}
                                <small class="text-muted d-block">{{ $c->pct_buzon }}%</small>
                            </td>
                            <td class="text-end">
                                {{ $c->contestadas }}
                                <small class="text-muted d-block">{{ $c->pct_contacto }}%</small>
                            </td>
                            <td class="text-end">
                                {{ $c->num_equivocado }}
                                <small class="text-muted d-block">
                                    @if($c->dudoso_confiable){{ $c->pct_equivocado }}%@else—@endif
                                </small>
                            </td>
                            <td class="text-end">
                                {{ $c->otra_persona }}
                                <small class="text-muted d-block">
                                    @if($c->dudoso_confiable){{ $c->pct_otra_persona }}%@else—@endif
                                </small>
                            </td>
                            <td class="text-end">
                                <span class="{{ $c->pct_exito >= 50 ? 'text-success' : 'text-danger' }}">{{ $c->pct_exito }}%</span>
                            </td>
                            <td class="text-end">
                                @if($c->rechazo_confiable)
                                    <span class="{{ $c->pct_rechazo >= 50 ? 'text-danger' : 'text-muted' }}">{{ $c->pct_rechazo }}%</span>
                                @else
                                    <span class="text-muted" title="Muy pocas llamadas contestadas">—</span>
                                @endif
                            </td>
                            <td>
                                @if($c->caso)
                                    <button type="button" class="btn btn-outline-info btn-sm w-100"
                                            wire:click="editar({{ $c->caso->id_log_conductor }})">
                                        <i class="bi bi-pencil me-1"></i>#{{ $c->caso->id_log_conductor }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-outline-warning btn-sm w-100"
                                            wire:click="abrir({{ $c->conductor_id }})">
                                        <i class="bi bi-folder-plus me-1"></i> Abrir caso
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">
                                Ningun conductor cruza los umbrales con estos filtros.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted">
                    {{ $candidatos->total() }} candidatos · pagina {{ $candidatos->currentPage() }} de {{ $candidatos->lastPage() }}
                </small>
                <div>{{ $candidatos->links() }}</div>
            </div>
        @endif

        {{-- ================= TABLA CASOS ================= --}}
        @if($tab === 'casos')
            <div class="table-responsive border border-top-0" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-dark table-hover mb-0 align-middle">
                    <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <th style="width: 70px;">#</th>
                        <th>Conductor</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th class="text-end">Score</th>
                        <th>Reportado</th>
                        <th>Compromiso</th>
                        <th>Resultado</th>
                        <th>Actualizado</th>
                        <th style="width: 150px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($casos as $caso)
                        @php $estado = $estados[$caso->status] ?? ['nombre' => $caso->status, 'color' => 'secondary']; @endphp
                        <tr>
                            <td>
                                <span class="badge bg-{{ $estado['color'] }}">{{ $caso->id_log_conductor }}</span>
                            </td>
                            <td>
                                <i class="bi bi-person me-1"></i>{{ $caso->conductor }}
                                <small class="d-block text-muted">#{{ $caso->id_conductor }} · {{ $caso->trt_nombres }}</small>
                            </td>
                            <td>
                                @php $tipo = $tipos_caso[$caso->tipo_caso] ?? ['nombre' => 'Sin clasificar', 'color' => 'secondary', 'icono' => 'bi-question']; @endphp
                                <span class="badge bg-{{ $tipo['color'] }}">
                                    <i class="bi {{ $tipo['icono'] }} me-1"></i>{{ $tipo['nombre'] }}
                                </span>
                                <small class="d-block text-muted">{{ $motivos[$caso->motivo] ?? '' }}</small>
                            </td>
                            <td>
                                <span class="badge bg-{{ $estado['color'] }}">{{ $estado['nombre'] }}</span>
                                @if($caso->eventos)
                                    <small class="d-block text-muted">{{ $caso->eventos }} eventos</small>
                                @endif
                            </td>
                            <td class="text-end">{{ $caso->score }}</td>
                            <td>
                                {{ $caso->fecha_reporte ? \Carbon\Carbon::parse($caso->fecha_reporte)->format('d/m/Y') : '—' }}
                            </td>
                            <td>
                                {{ $caso->fecha_compromiso ? \Carbon\Carbon::parse($caso->fecha_compromiso)->format('d/m/Y') : '—' }}
                            </td>
                            <td>
                                @if($caso->resultado)
                                    <span class="badge bg-{{ $caso->resultado === 'MEJORO' ? 'success' : ($caso->resultado === 'EMPEORO' ? 'danger' : 'secondary') }}">
                                        {{ $resultados[$caso->resultado] ?? $caso->resultado }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <small class="text-muted">{{ \Carbon\Carbon::parse($caso->updated_at)->format('d/m/Y H:i') }}</small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info"
                                            wire:click="editar({{ $caso->id_log_conductor }})">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @if($caso->status !== 'REPORTADO' && $caso->activo)
                                        <button type="button" class="btn btn-outline-warning"
                                                wire:click="reportar({{ $caso->id_log_conductor }})"
                                                wire:confirm="Marcar el caso #{{ $caso->id_log_conductor }} como reportado?">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                Todavia no hay casos registrados.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ================= MODAL DEL CASO ================= --}}
        @if($modal && $form)
            <div class="modal fade show d-block" style="background: rgba(0,0,0,0.75);" wire:click.self="cerrarModal">
                <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content bg-dark">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title">
                                <i class="bi bi-folder2-open me-2"></i>
                                @if($form['id_log_conductor'])
                                    Caso #{{ $form['id_log_conductor'] }} · {{ $form['conductor'] }}
                                @else
                                    Nuevo caso · {{ $form['conductor'] }}
                                @endif
                            </h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal"></button>
                        </div>

                        <div class="modal-body">
                            <form wire:submit.prevent="guardar">
                                {{-- Ficha --}}
                                <div class="row g-2 mb-3">
                                    <div class="col-md-4">
                                        <div class="p-2 border border-info rounded h-100">
                                            <small class="text-muted d-block">Conductor</small>
                                            <i class="bi bi-person me-1"></i>{{ $form['conductor'] }}
                                            <span class="text-muted">#{{ $form['id_conductor'] }}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 border border-secondary rounded h-100">
                                            <small class="text-muted d-block">Transportista</small>
                                            <i class="bi bi-truck me-1"></i>{{ $form['trt'] }}
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 border border-secondary rounded h-100">
                                            <small class="text-muted d-block">Periodo analizado</small>
                                            <i class="bi bi-calendar-range me-1"></i>
                                            {{ $form['fecha_inicio'] ? \Carbon\Carbon::parse($form['fecha_inicio'])->format('d/m/Y') : '—' }}
                                            @if($form['fecha_fin'])
                                                → {{ \Carbon\Carbon::parse($form['fecha_fin'])->format('d/m/Y') }}
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                {{-- Evidencia congelada --}}
                                @php $ev = $form['evidencia']; @endphp
                                <div class="border border-primary rounded p-2 mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <strong class="text-primary">
                                            <i class="bi bi-clipboard-data me-1"></i>Evidencia al abrir el caso
                                        </strong>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                wire:click="$toggle('ver_evidencia')">
                                            <i class="bi bi-braces me-1"></i> JSON
                                        </button>
                                    </div>

                                    @if($ev['reconstruida'] ?? false)
                                        <div class="alert alert-warning py-1 px-2 mb-2 small">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Caso anterior a la evidencia automatica: las cifras se reconstruyeron con las llamadas del periodo.
                                        </div>
                                    @endif

                                    <div class="row g-2 text-center">
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0">{{ $ev['metricas']['validas'] ?? 0 }}</div>
                                                <small class="text-muted">Validas</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-warning">{{ $ev['metricas']['buzon'] ?? 0 }}</div>
                                                <small class="text-muted">Buzon ({{ $ev['porcentajes']['buzon'] ?? 0 }}%)</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-info">{{ $ev['metricas']['contestadas'] ?? 0 }}</div>
                                                <small class="text-muted">Contestadas</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-success">{{ $ev['metricas']['exitosas'] ?? 0 }}</div>
                                                <small class="text-muted">Exitosas ({{ $ev['porcentajes']['exito'] ?? 0 }}%)</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-danger">{{ $ev['metricas']['rechazos'] ?? 0 }}</div>
                                                <small class="text-muted">Rechazos ({{ $ev['porcentajes']['rechazo'] ?? 0 }}%)</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-info">{{ $ev['metricas']['num_equivocado'] ?? 0 }}</div>
                                                <small class="text-muted">Num. equivocado ({{ $ev['porcentajes']['equivocado'] ?? 0 }}%)</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0 text-secondary">{{ $ev['metricas']['otra_persona'] ?? 0 }}</div>
                                                <small class="text-muted">Otra persona ({{ $ev['porcentajes']['otra_persona'] ?? 0 }}%)</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="border border-secondary rounded py-1">
                                                <div class="h5 mb-0">{{ $ev['metricas']['conducta'] ?? 0 }}</div>
                                                <small class="text-muted">Conducta</small>
                                            </div>
                                        </div>
                                    </div>

                                    @isset($ev['telefonos'])
                                        @if(count($ev['telefonos']))
                                            <div class="mt-2">
                                                <small class="text-muted me-1">Telefonos:</small>
                                                @foreach($ev['telefonos'] as $tlf)
                                                    <span class="badge bg-{{ $tlf['activo'] ? 'success' : 'dark' }}">
                                                        {{ ltrim($tlf['telefono'], '51') }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endisset

                                    @if($ver_evidencia)
                                        <pre class="mt-2 mb-0 p-2 bg-black bg-opacity-50 border border-secondary rounded" style="max-height: 220px; overflow: auto; font-size: .72rem;">{{ json_encode($ev, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    @endif
                                </div>

                                {{-- Comparativa actual --}}
                                @php $act = $form['actual']; @endphp
                                <div class="mb-3">
                                    <small class="text-muted">
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        Mismo periodo hoy: {{ $act->validas }} validas ·
                                        exito {{ $act->pct_exito }}% ·
                                        rechazo {{ $act->pct_rechazo }}% ·
                                        buzon {{ $act->pct_buzon }}% ·
                                        equivocado {{ $act->pct_equivocado }}% ·
                                        otra persona {{ $act->pct_otra_persona }}%
                                        @if($act->validas === 0)
                                            (sin llamadas nuevas)
                                        @endif
                                    </small>
                                </div>

                                {{-- Clasificacion y estado --}}
                                <div class="row g-2 mb-2">
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_tipo">Tipo de caso</label>
                                        <select id="m_tipo" class="form-control form-control-sm" wire:model.live="form.tipo_caso">
                                            @foreach($tipos_caso as $clave => $tipo)
                                                <option value="{{ $clave }}">{{ $tipo['nombre'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('form.tipo_caso')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_motivo">Motivo</label>
                                        <select id="m_motivo" class="form-control form-control-sm" wire:model="form.motivo">
                                            @foreach($motivos as $clave => $nombre)
                                                <option value="{{ $clave }}">{{ $nombre }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_estado">Estado</label>
                                        <select id="m_estado" class="form-control form-control-sm" wire:model.live="form.status">
                                            @foreach($estados as $clave => $item)
                                                <option value="{{ $clave }}">{{ $item['nombre'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('form.status')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_ubic">Ubicacion</label>
                                        <select id="m_ubic" class="form-control form-control-sm" wire:model="form.ubicacion">
                                            <option value="LIMA">Lima</option>
                                            <option value="PROVINCIA">Provincia</option>
                                        </select>
                                    </div>
                                </div>

                                {{-- Analisis --}}
                                <div class="mb-2">
                                    <label class="form-label mb-1" for="m_analisis">
                                        <i class="bi bi-file-earmark-text me-1"></i>Analisis
                                    </label>
                                    <textarea id="m_analisis" rows="3" class="form-control form-control-sm"
                                              wire:model="form.analisis"
                                              placeholder="Que patron se detecto y con que evidencia"></textarea>
                                    @error('form.analisis')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>

                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label mb-1" for="m_accion">
                                            <i class="bi bi-lightning me-1"></i>Accion tomada
                                        </label>
                                        <textarea id="m_accion" rows="2" class="form-control form-control-sm"
                                                  wire:model="form.accion"
                                                  placeholder="Que se hizo: llamada, mensaje, aviso al TRT"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1" for="m_respuesta">
                                            <i class="bi bi-chat-left-text me-1"></i>Respuesta obtenida
                                        </label>
                                        <textarea id="m_respuesta" rows="2" class="form-control form-control-sm"
                                                  wire:model="form.respuesta"
                                                  placeholder="Que respondio el conductor o el transportista"></textarea>
                                    </div>
                                </div>

                                {{-- Seguimiento --}}
                                <div class="row g-2 mb-2">
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_reporte">Fecha de reporte</label>
                                        <input type="date" id="m_reporte" class="form-control form-control-sm"
                                               wire:model="form.fecha_reporte">
                                        @error('form.fecha_reporte')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_compromiso">Compromiso</label>
                                        <input type="date" id="m_compromiso" class="form-control form-control-sm"
                                               wire:model="form.fecha_compromiso">
                                        @error('form.fecha_compromiso')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_resultado">Resultado</label>
                                        <select id="m_resultado" class="form-control form-control-sm"
                                                wire:model="form.resultado" @disabled(!in_array($form['status'], ['CERRADO', 'CANCELADO']))>
                                            <option value="">Sin definir</option>
                                            @foreach($resultados as $clave => $nombre)
                                                <option value="{{ $clave }}">{{ $nombre }}</option>
                                            @endforeach
                                        </select>
                                        @error('form.resultado')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="m_acuerdo">Acuerdo</label>
                                        <input type="text" id="m_acuerdo" class="form-control form-control-sm"
                                               wire:model="form.acuerdo" placeholder="Compromiso concreto">
                                    </div>
                                </div>

                                {{-- Historial --}}
                                @if(count($eventos))
                                    <div class="border border-secondary rounded p-2 mb-2">
                                        <small class="text-muted d-block mb-1">
                                            <i class="bi bi-clock-history me-1"></i>Historial del caso
                                        </small>
                                        <div style="max-height: 160px; overflow: auto;">
                                            @foreach($eventos as $evento)
                                                <div class="d-flex gap-2 small border-bottom border-secondary py-1">
                                                    <span class="text-muted" style="min-width: 120px;">
                                                        {{ \Carbon\Carbon::parse($evento->created_at)->format('d/m/Y H:i') }}
                                                    </span>
                                                    <span class="badge bg-secondary">{{ $evento->tipo }}</span>
                                                    <span>{{ $evento->estado }}</span>
                                                    <span class="text-muted flex-grow-1">{{ $evento->detalle }}</span>
                                                    <span class="text-muted">{{ $evento->usuario ?? '' }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="d-flex gap-2 border-top border-secondary pt-2">
                                    <button type="submit" class="btn btn-primary btn-sm px-3">
                                        <i class="bi bi-save me-1"></i> Guardar
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm px-3"
                                            wire:click="cerrarModal">
                                        <i class="bi bi-x-circle me-1"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>
