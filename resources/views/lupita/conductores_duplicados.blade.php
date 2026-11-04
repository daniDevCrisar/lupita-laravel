@extends('layouts.app')

@section('title', 'Conductores duplicados')

@section('content')
    <div class="py-3" x-data="dupApp()">

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h2 class="h4 mb-0"><i class="bi bi-people-fill me-2"></i>Conductores duplicados</h2>
            <a href="{{ route('lupita.conductores') }}" class="btn btn-outline-light btn-sm ms-auto">
                <i class="bi bi-arrow-left"></i> Lista de conductores
            </a>
        </div>

        <p class="text-secondary small mb-3">
            Grupos con el <strong>mismo nombre</strong> que todavía tienen datos repartidos: solo aparecen los que
            tienen más de un id activo o con llamadas, referencias o teléfonos. Marca el grupo y el sistema absorbe
            <strong>todos sus ids en uno solo</strong>: se queda el activo con más llamadas (o el más antiguo si
            empatan) y el resto desaparece de las listas. Los absorbidos quedan con <code>activo = 0</code>: no se
            borra nada, y cuando ya está todo en uno el grupo deja de aparecer aquí. Los nombres parecidos que no son
            idénticos solo se fusionan si los marcas a mano.
        </p>

        {{-- Resumen de la fusion anterior --}}
        @if(session('fusion'))
            @php($f = session('fusion'))
            <div class="alert alert-{{ $f['grupos'] ? 'success' : 'warning' }} border-0">
                <div class="fw-bold mb-1">
                    <i class="bi bi-check2-circle me-1"></i>
                    {{ $f['grupos'] ? 'Fusión terminada' : 'No se fusionó ningún grupo' }}
                </div>
                <div class="small">
                    {{ number_format($f['grupos']) }} grupos ·
                    {{ number_format($f['conductores']) }} conductores absorbidos
                    @if(!empty($f['parecidos']))({{ $f['parecidos'] }} con nombre parecido)@endif ·
                    {{ number_format($f['llamadas']) }} llamadas reasignadas ·
                    {{ number_format($f['referencias']) }} referencias ·
                    {{ number_format($f['telefonos']) }} teléfonos ·
                    {{ number_format($f['logs']) }} registros de historial
                </div>
                @if(!empty($f['renombrados']))
                    <div class="small mt-2">
                        <span class="text-secondary">Nombre actualizado (se conservó el más completo):</span>
                        @foreach($f['renombrados'] as $renombrado)
                            <div class="font-monospace">{{ $renombrado }}</div>
                        @endforeach
                    </div>
                @endif
                @if(!empty($f['errores']))
                    <ul class="small mb-0 mt-2">
                        @foreach($f['errores'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Totales del listado actual --}}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body py-2">
                        <div class="text-secondary small">Grupos</div>
                        <div class="fs-4 fw-bold">{{ number_format($totales['grupos']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body py-2">
                        <div class="text-secondary small">Conductores en los grupos</div>
                        <div class="fs-4 fw-bold">{{ number_format($totales['conductores']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body py-2">
                        <div class="text-secondary small">Ids sobrantes</div>
                        <div class="fs-4 fw-bold text-warning">{{ number_format($totales['sobrantes']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body py-2">
                        <div class="text-secondary small">Llamadas involucradas</div>
                        <div class="fs-4 fw-bold">{{ number_format($totales['llamadas']) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filtros --}}
        <form method="GET" action="{{ route('lupita.conductores.duplicados') }}" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label for="q" class="form-label small text-secondary mb-1">Buscar por nombre</label>
                <input type="text" id="q" name="q" value="{{ $q }}" class="form-control form-control-sm"
                       placeholder="Ej. JUAN CHAVEZ" autocomplete="off">
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="solo_activos" value="1"
                           id="solo_activos" @checked($solo_activos)>
                    <label class="form-check-label small" for="solo_activos">
                        Solo grupos con más de un conductor activo
                    </label>
                </div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filtrar</button>
                <a href="{{ route('lupita.conductores.duplicados') }}" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
            </div>
        </form>

        <form method="POST" action="{{ route('lupita.conductores.duplicados.fusionar') }}"
              @submit.prevent="enviar($event)">
            @csrf
            <input type="hidden" name="q" value="{{ $q }}">
            <input type="hidden" name="solo_activos" value="{{ $solo_activos ? 1 : 0 }}">

            {{-- Barra de acciones --}}
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded border border-secondary bg-dark">
                <button type="button" class="btn btn-outline-info btn-sm" @click="marcarTodos()">
                    <i class="bi bi-check2-all"></i> Marcar todos los grupos
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="limpiar()">
                    Limpiar selección
                </button>
                <span class="small text-secondary ms-2">
                    <span class="fw-bold text-light" x-text="gruposListos">0</span> grupos marcados:
                    <span class="fw-bold text-light" x-text="totalAbsorber">0</span> conductores a absorber
                    <span class="text-warning" x-show="totalParecidos > 0">
                        (<span x-text="totalParecidos"></span> con nombre parecido)
                    </span>
                </span>
                <div class="form-check form-switch ms-auto mb-0">
                    <input class="form-check-input" type="checkbox" name="mover_logs" value="1" id="mover_logs" checked>
                    <label class="form-check-label small" for="mover_logs">Mover también el historial (log)</label>
                </div>
                <button type="submit" class="btn btn-danger btn-sm" :disabled="totalAbsorber === 0">
                    <i class="bi bi-arrow-down-up"></i> Fusionar seleccionados
                </button>
            </div>

            @forelse($grupos as $gi => $grupo)
                <div class="card bg-dark border-secondary mb-3"
                     :class="aplicar[{{ $gi }}] ? 'border-danger' : ''">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="fusion[{{ $gi }}][nombres]" value="{{ $grupo['nombres'] }}">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="aplicar-{{ $gi }}"
                                   name="fusion[{{ $gi }}][aplicar]" value="1"
                                   x-model="aplicar[{{ $gi }}]">
                            <label class="form-check-label" for="aplicar-{{ $gi }}">
                                <i class="bi bi-arrow-down-up"></i> Fusionar
                            </label>
                        </div>
                        <span class="fw-bold">{{ $grupo['nombres'] }}</span>
                        <span class="badge bg-danger" x-show="aplicar[{{ $gi }}]" style="display:none">
                            absorbe {{ $grupo['conductores'] - 1 }}
                        </span>
                        <span class="badge bg-secondary">{{ $grupo['conductores'] }} ids</span>
                        <span class="badge bg-primary">{{ number_format($grupo['llamadas']) }} llamadas</span>
                        <span class="badge bg-info text-dark">{{ number_format($grupo['referencias']) }} refs</span>
                        <span class="badge bg-success">{{ number_format($grupo['telefonos']) }} tel.</span>
                        @if($grupo['logs'])
                            <span class="badge bg-warning text-dark">{{ $grupo['logs'] }} logs</span>
                        @endif
                        <span class="ms-auto small text-secondary d-none d-lg-inline">
                            absorbe
                            <strong class="text-light" x-text="'#' + (destino[{{ $gi }}] || '{{ $grupo['sugerido'] }}')"></strong>
                            <span x-text="destino[{{ $gi }}] ? '(nombre parecido)' : '({{ $grupo['sugerido_por'] }})'"></span>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-dark table-hover align-middle mb-0">
                            <thead>
                            <tr class="small text-secondary">
                                <th style="width:90px">ID</th>
                                <th style="width:110px">Acción</th>
                                <th>Nombre en la base</th>
                                <th>Estado</th>
                                <th class="text-end">Llamadas</th>
                                <th class="text-end">Refs</th>
                                <th class="text-end">Tel.</th>
                                <th class="text-end">Logs</th>
                                <th>Última llamada</th>
                                <th>Creado</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($grupo['filas'] as $fila)
                                <tr :class="aplicar[{{ $gi }}] && {{ $fila['id'] === $grupo['sugerido'] ? 'true' : 'false' }} ? 'table-active' : ''">
                                    <td class="fw-bold">#{{ $fila['id'] }}</td>
                                    <td>
                                        @if($fila['id'] === $grupo['sugerido'])
                                            <span class="badge bg-danger" title="Absorbe al resto del grupo"
                                                  x-show="!destino[{{ $gi }}]" style="display:none">
                                                <i class="bi bi-arrow-down-up"></i> absorbe
                                            </span>
                                            <span class="text-secondary small"
                                                  x-show="destino[{{ $gi }}]" style="display:none">se absorbe</span>
                                        @else
                                            <span class="text-secondary small">se absorbe</span>
                                        @endif
                                    </td>
                                    <td class="small font-monospace text-light" style="white-space: normal">
                                        {{ $fila['nombres'] }}
                                    </td>
                                    <td>
                                        @if($fila['activo'])
                                            <span class="badge bg-success">activo</span>
                                        @else
                                            <span class="badge bg-secondary">inactivo</span>
                                        @endif
                                        @if($fila['id'] === $grupo['sugerido'])
                                            <span class="badge bg-info text-dark"
                                                  x-show="!destino[{{ $gi }}]" style="display:none">destino</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($fila['llamadas']) }}</td>
                                    <td class="text-end">{{ number_format($fila['referencias']) }}</td>
                                    <td class="text-end">{{ number_format($fila['telefonos']) }}</td>
                                    <td class="text-end">{{ number_format($fila['logs']) }}</td>
                                    <td class="small text-nowrap">
                                        {{ $fila['ultima'] ? substr($fila['ultima'], 0, 16) : '—' }}
                                    </td>
                                    <td class="small text-nowrap">
                                        {{ $fila['creado'] ? substr($fila['creado'], 0, 10) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Parecidos: se pueden absorber, pero solo marcandolos a mano. --}}
                    @if($grupo['parecidos'])
                        <div class="px-3 py-2 border-top border-secondary small">
                            <div class="d-flex flex-wrap align-items-center gap-1">
                                <span class="text-secondary me-1">
                                    <i class="bi bi-search"></i> Parecidos fuera del grupo; marca para fusionarlo también:
                                </span>
                                @foreach($grupo['parecidos'] as $parecido)
                                    <label class="badge bg-dark border border-secondary fw-normal text-light d-inline-flex align-items-center gap-1 mb-0"
                                           title="Coincidencia de palabras: {{ $parecido['similitud'] }}%">
                                        <input class="form-check-input mt-0" type="checkbox"
                                               name="fusion[{{ $gi }}][parecidos][]" value="{{ $parecido['id'] }}"
                                               x-model="parecidos[{{ $gi }}]"
                                               @change="ajustarDestino({{ $gi }})">
                                        #{{ $parecido['id'] }} {{ $parecido['nombres'] }}
                                        <span class="text-warning fw-bold">{{ $parecido['texto'] }}%</span>
                                        @unless($parecido['activo'])
                                            <span class="text-secondary">(inactivo)</span>
                                        @endunless
                                    </label>
                                @endforeach
                            </div>

                            {{-- Por defecto absorbe el id del grupo; se puede cambiar
                                 a uno de los parecidos marcados. --}}
                            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                                <span class="text-secondary"><i class="bi bi-arrow-down-up"></i> Quién absorbe:</span>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="radio"
                                           name="fusion[{{ $gi }}][principal]" id="abs-{{ $gi }}-grupo" value=""
                                           x-model="destino[{{ $gi }}]">
                                    <label class="form-check-label" for="abs-{{ $gi }}-grupo">
                                        #{{ $grupo['sugerido'] }} {{ $grupo['nombres'] }}
                                        <span class="text-secondary">(el del grupo)</span>
                                    </label>
                                </div>
                                @foreach($grupo['parecidos'] as $parecido)
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio"
                                               name="fusion[{{ $gi }}][principal]"
                                               id="abs-{{ $gi }}-{{ $parecido['id'] }}" value="{{ $parecido['id'] }}"
                                               x-model="destino[{{ $gi }}]"
                                               @change="marcarParecido({{ $gi }}, '{{ $parecido['id'] }}')">
                                        <label class="form-check-label" for="abs-{{ $gi }}-{{ $parecido['id'] }}">
                                            #{{ $parecido['id'] }} {{ $parecido['nombres'] }}
                                            <span class="text-warning">{{ $parecido['texto'] }}%</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>

                            <div class="text-info mt-1" style="display:none" x-show="parecidos[{{ $gi }}].length > 0">
                                <i class="bi bi-exclamation-triangle"></i>
                                Se fusionará con un nombre distinto. Quedará como
                                <strong x-text="nombreFinal({{ $gi }})"></strong>.
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="alert alert-dark border border-secondary">
                    <i class="bi bi-check2-circle me-1"></i>
                    No hay conductores con el mismo nombre
                    {{ $solo_activos ? 'y más de un activo' : '' }}@if($q) que coincidan con «{{ $q }}»@endif.
                </div>
            @endforelse

            @if(count($grupos))
                <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 mb-4">
                    <span class="small text-secondary">
                        <span class="fw-bold text-light" x-text="totalAbsorber">0</span> conductores seleccionados
                        en <span class="fw-bold text-light" x-text="gruposListos">0</span> grupos
                    </span>
                    <button type="submit" class="btn btn-danger" :disabled="totalAbsorber === 0">
                        <i class="bi bi-arrow-down-up"></i> Fusionar seleccionados
                    </button>
                </div>
            @endif
        </form>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js" defer></script>
    <script>
        function dupApp() {
            return {
                grupos: @json($js_grupos),
                aplicar: @json(array_fill(0, count($js_grupos), false)),
                parecidos: @json(array_fill(0, count($js_grupos), [])),
                // Id que absorbe: vacio = el del grupo; si no, un parecido marcado.
                destino: @json(array_fill(0, count($js_grupos), '')),

                get totalParecidos() {
                    return this.parecidos.reduce((total, ids) => total + (ids ? ids.length : 0), 0);
                },

                get totalDestinoParecido() {
                    return this.destino.filter((id, i) => this.aplicar[i] && id).length;
                },

                // Absorbidos = tamano del grupo + parecidos menos el que absorbe.
                get totalAbsorber() {
                    let total = 0;
                    this.grupos.forEach((grupo, i) => {
                        if (!this.aplicar[i]) return;
                        total += grupo.conductores + (this.parecidos[i] || []).length - 1;
                    });
                    return total;
                },

                get gruposListos() {
                    return this.aplicar.filter(Boolean).length;
                },

                // Nombre que quedara: el mas completo entre el del grupo y los
                // parecidos marcados (la misma regla que aplica el servidor).
                nombreFinal(i) {
                    let final = this.grupos[i].nombres;
                    const marcados = this.parecidos[i] || [];

                    this.grupos[i].parecidos.forEach(p => {
                        if (marcados.includes(String(p.id)) && this.masCompleto(p.nombres, final)) {
                            final = p.nombres;
                        }
                    });

                    return final;
                },

                masCompleto(nuevo, actual) {
                    const a = nuevo.trim().split(/\s+/).filter(Boolean);
                    const b = actual.trim().split(/\s+/).filter(Boolean);

                    if (a.length !== b.length) return a.length > b.length;

                    return nuevo.length > actual.length;
                },

                // Marca todos los grupos: el destino lo decide el servidor.
                marcarTodos() {
                    this.aplicar = this.grupos.map(() => true);
                },

                // Al marcar o desmarcar un parecido hay que marcarlo en el grupo y,
                // si deja de estar marcado, devolver el destino al del grupo.
                ajustarDestino(i) {
                    this.aplicar[i] = true;
                    this.$nextTick(() => {
                        if (this.destino[i] && !(this.parecidos[i] || []).includes(this.destino[i])) {
                            this.destino[i] = '';
                        }
                    });
                },

                // Elegir un parecido como absorbente lo incluye automaticamente.
                marcarParecido(i, id) {
                    id = String(id);
                    if (!this.parecidos[i]) this.parecidos[i] = [];
                    if (!this.parecidos[i].includes(id)) this.parecidos[i].push(id);
                    this.aplicar[i] = true;
                },

                limpiar() {
                    this.aplicar = this.grupos.map(() => false);
                    this.parecidos = this.grupos.map(() => []);
                    this.destino = this.grupos.map(() => '');
                },

                enviar(evento) {
                    if (this.gruposListos === 0) {
                        alert('Marca al menos un grupo para fusionar.');
                        return;
                    }

                    let texto = 'Se van a fusionar ' + this.gruposListos + ' grupos: '
                        + this.totalAbsorber + ' conductores quedarán inactivos.\n\n'
                        + 'En cada grupo todo pasa al id que absorbe y el resto se desactiva.';

                    if (this.totalParecidos > 0) {
                        texto += '\n\nOJO: ' + this.totalParecidos + ' de ellos tiene(n) un nombre distinto. '
                            + 'Esos grupos quedarán con el nombre más completo de los dos.';
                    }

                    if (this.totalDestinoParecido > 0) {
                        texto += '\n\nOJO: en ' + this.totalDestinoParecido + ' grupo(s) absorbe un id con nombre parecido.';
                    }

                    texto += '\n\n¿Fusionar ahora?';

                    if (window.confirm(texto)) {
                        evento.target.submit();
                    }
                }
            };
        }
    </script>
@endsection
