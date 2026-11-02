@extends('layouts.app')

@php
    use App\Database\DBTiemposRuta;

    // Escala de los marcadores del mapa segun el maximo de viajes por departamento.
    $maxDep = 1;
    foreach ($mapa['origen'] as $v) { $maxDep = max($maxDep, (int) $v); }
    foreach ($mapa['destino'] as $v) { $maxDep = max($maxDep, (int) $v); }

    $tamano = function ($veces) use ($maxDep) {
        $veces = (int) $veces;
        if ($veces <= 0) return 0;
        // radio entre 8 y 26 px proporcional al maximo
        return (int) round(8 + ($veces / $maxDep) * 18);
    };
@endphp

@section('title', 'Consulta de Referencias')

@section('content')

    <div class="row">
        <div class="col-12">
            <h1><i class="bi bi-signpost-split"></i> Consulta de Referencias (viajes)</h1>
        </div>
    </div>

    {{-- ===================== FILTROS ===================== --}}
    <div class="row">
        <form method="GET" class="mb-3">
            {{-- Flag de envio: sin este campo la pantalla no consulta la base al abrirse --}}
            <input type="hidden" name="filtrar" value="1">
            <fieldset class="border p-3 rounded">
                <legend class="float-none w-auto px-2 fs-6">Filtros</legend>
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio"
                               value="{{ request('fecha_inicio') }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin"
                               value="{{ request('fecha_fin') }}" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="etapa">Etapa del rango</label>
                        <select id="etapa" name="etapa" class="form-select">
                            <option value="">Llamadas (cualquier etapa)</option>
                            @foreach($etapas as $cod => $etiqueta)
                                <option value="{{ $cod }}" {{ request('etapa') === $cod ? 'selected' : '' }}>
                                    {{ $etiqueta }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-white-50">El rango se aplica a la fecha de la etapa elegida.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="q">Buscar</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            {{-- textarea: asi se puede pegar una lista de refs una por linea --}}
                            <textarea id="q" name="q" rows="2" class="form-control"
                                      placeholder="ref, placa o conductor; o pegue una lista de refs">{{ request('q') }}</textarea>
                        </div>
                        <small class="text-white-50">Varias refs: separadas por coma, espacio o una por linea.</small>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="ruta">Ruta</label>
                        <input type="text" id="ruta" name="ruta" value="{{ request('ruta') }}"
                               class="form-control" placeholder="origen o destino...">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="sin_fin" name="sin_fin"
                                   {{ request('sin_fin') ? 'checked' : '' }}>
                            <label class="form-check-label" for="sin_fin">
                                <i class="bi bi-hourglass-split"></i> Solo sin fin de descargue (ciclo no cerrado)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="completas" name="completas"
                                   {{ request('completas') ? 'checked' : '' }}>
                            <label class="form-check-label" for="completas">
                                <i class="bi bi-check2-all"></i> Solo completas (todas las fechas de etapa)
                            </label>
                        </div>
                    </div>
                    <div class="col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-funnel"></i> Filtrar
                        </button>
                        <a href="{{ route('lupita.referencias') }}" class="btn btn-secondary">Limpiar</a>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>

    @if(!$filtrando)
        {{-- Sin filtro aplicado no se consulta la BD: la pantalla arranca vacia --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-funnel fs-1 text-info"></i>
                        <h2 class="h5 mt-3 text-light">Aplique un filtro para consultar</h2>
                        <p class="text-white-50 mb-0">
                            La pantalla no consulta la base al abrirse. Elija un rango de fechas,
                            una ref o una ruta y presione <strong class="text-light">Filtrar</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @else
    @if(!empty($noEncontradas))
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <div class="fw-bold mb-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    {{ count($noEncontradas) }} ref(s) de la lista no existen en referencias:
                </div>
                @foreach($noEncontradas as $nf)
                    <span class="badge bg-dark border border-warning me-1 mb-1">{{ $nf }}</span>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-dark text-nowrap" id="btn_copiar_no_enc"
                    title="Copiar las refs no encontradas, una por linea">
                <i class="bi bi-clipboard"></i> Copiar
            </button>
        </div>
    @endif

    {{-- ===================== KPIs ===================== --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-white-50">Total viajes</div>
                    <div class="fs-2 fw-bold text-light">{{ $kpis->total }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-white-50">% con ruta</div>
                    <div class="fs-2 fw-bold text-info">{{ $kpis->pct_ruta }}%</div>
                    <small class="text-white-50">{{ $kpis->con_ruta }} de {{ $kpis->total }}</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-white-50">% Ciclo completo</div>
                    <div class="fs-2 fw-bold text-success">{{ $kpis->pct_cerrados }}%</div>
                    <small class="text-white-50">{{ $kpis->cerrados }} cerrados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-white-50">% Etapas completadas</div>
                    <div class="fs-2 fw-bold text-warning">{{ $kpis->pct_etapas }}%</div>
                    <small class="text-white-50">prom. {{ $kpis->etapas_prom }}/7</small>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== MAPA + TOP RUTAS ===================== --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-geo-alt"></i> Mapa por ruta · Origen y Destino
                </div>
                <div class="card-body">
                    <div class="position-relative" style="max-width:520px;margin:0 auto;">
                        <img src="{{ asset('images/peru.png') }}" alt="Mapa Perú" class="w-100 rounded">

                        @foreach($depas as $cod => $dep)
                            @php
                                $o = $mapa['origen'][$cod] ?? 0;
                                $d = $mapa['destino'][$cod] ?? 0;
                                $so = $tamano($o);
                                $sd = $tamano($d);
                                // bajada de la etiqueta: media del punto mas grande + margen
                                $bajada = (int) round(max($so, $sd) / 2) + 3;
                            @endphp

                            @if($o > 0)
                                <span title="{{ $dep['nombre'] }} · Origen: {{ $o }}"
                                      style="position:absolute;left:{{ $dep['x'] }}%;top:{{ $dep['y'] }}%;
                                             width:{{ $so }}px;height:{{ $so }}px;border-radius:50%;
                                             background:rgba(40,167,69,.75);border:1px solid #fff;
                                             transform:translate(-50%,-50%);"></span>
                            @endif
                            @if($d > 0)
                                <span title="{{ $dep['nombre'] }} · Destino: {{ $d }}"
                                      style="position:absolute;left:calc({{ $dep['x'] }}% + {{ round($sd/2) }}px);
                                             top:{{ $dep['y'] }}%;width:{{ $sd }}px;height:{{ $sd }}px;border-radius:50%;
                                             background:rgba(23,162,184,.75);border:1px solid #fff;
                                             transform:translate(-50%,-50%);"></span>
                            @endif
                            @if($o > 0 || $d > 0)
                                <span style="position:absolute;left:{{ $dep['x'] }}%;top:calc({{ $dep['y'] }}% + {{ $bajada }}px);
                                             transform:translate(-50%,0);font-size:9px;line-height:1;color:#fff;
                                             text-shadow:0 1px 2px #000,0 0 2px #000;white-space:nowrap;pointer-events:none;">
                                    {{ $dep['nombre'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                    <div class="text-center mt-2 small text-white-50">
                        <span class="badge" style="background:rgba(40,167,69,.75)">Origen</span>
                        <span class="badge" style="background:rgba(23,162,184,.75)">Destino</span>
                        · el tamano del punto indica volumen de viajes · pasa el cursor para ver cantidades
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-list-ol"></i> Top rutas (más viajes)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-secondary">
                            <tr>
                                <th>#</th>
                                <th>Ruta</th>
                                <th class="text-end">Viajes</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($topRutas as $i => $r)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td class="text-light">{{ DBTiemposRuta::nombre_ruta($r) }}</td>
                                    <td class="text-end"><span class="badge bg-primary">{{ $r->veces }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-white-50 py-3">Sin rutas en el filtro</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== LISTADO ===================== --}}
    @php
        // Refs visibles en la pagina actual para el endpoint batch.
        $refsPagina = $referencias->getCollection()->pluck('ref')->filter()->values()->all();
        $urlApiPagina = route('api.referencias.llamadas.batch', ['refs' => implode(',', $refsPagina)]);

        // Ordenamiento: conservo filtros del request, alterno direccion y reseteo pagina.
        $ordenActual = request('ordenar_por');
        $dirActual = request('orden') === 'asc' ? 'asc' : 'desc';
        $ordenUrl = function ($campo) use ($ordenActual, $dirActual) {
            $nueva = ($ordenActual === $campo) ? ($dirActual === 'desc' ? 'asc' : 'desc') : 'desc';
            $q = array_merge(request()->query(), ['ordenar_por' => $campo, 'orden' => $nueva]);
            unset($q['page']);
            return route('lupita.referencias', $q);
        };
        $icono = function ($campo) use ($ordenActual, $dirActual) {
            if ($ordenActual !== $campo) return 'bi-arrow-down-up text-white-50';
            return $dirActual === 'asc' ? 'bi-sort-up text-info' : 'bi-sort-down text-info';
        };
    @endphp
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0"><i class="bi bi-table"></i> Listado de viajes</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('lupita.referencias.mapa', request()->query()) }}" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-play-circle"></i> Ver mapa animado
                </a>
                <a href="{{ $urlApiPagina }}" target="_blank" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-braces"></i> Ver API de esta pagina
                    <span class="badge bg-secondary">{{ count($refsPagina) }} refs</span>
                </a>
            </div>
        </div>
        <div class="col-12">{{ $referencias->links() }}</div>
        <div class="col-12">
            <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark">
                    <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <th>Ref</th>
                        <th>Conductor</th>
                        <th>Trt</th>
                        <th>Placa</th>
                        <th>Ruta (Origen - Destino)</th>
                        <th class="text-center"><a class="text-reset text-decoration-none" href="{{ $ordenUrl('etapas') }}" title="Ordenar por etapas">Etapas <i class="bi {{ $icono('etapas') }}"></i></a></th>
                        <th class="text-center"><a class="text-reset text-decoration-none" href="{{ $ordenUrl('llamadas') }}" title="Ordenar por llamadas">Llamadas <i class="bi {{ $icono('llamadas') }}"></i></a></th>
                        <th class="text-center"><a class="text-reset text-decoration-none" href="{{ $ordenUrl('exitosas') }}" title="Ordenar por llamadas exitosas">Exitosas <i class="bi {{ $icono('exitosas') }}"></i></a></th>
                        <th><a class="text-reset text-decoration-none" href="{{ $ordenUrl('ultima') }}" title="Ordenar por última fecha">Última fecha <i class="bi {{ $icono('ultima') }}"></i></a></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($referencias as $row)
                        @php
                            $rutaTexto = DBTiemposRuta::nombre_ruta($row);
                            $completadas = (int) $row->etapas_completadas;
                            // vapi_id de las llamadas del ref, listados por salto de linea (la pagina los espera asi).
                            $idsLlamada = implode("\n", array_filter(explode(',', (string) ($row->vapi_ids ?? ''))));
                            $marcas = [
                                'presenta_para_carga', 'inicio_de_carga', 'fin_de_carga',
                                'inicio_ruta', 'qr_llegada_destino', 'inicio_descargue', 'fin_descargue',
                            ];
                        @endphp
                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                            <td class="fw-bold text-light">{{ $row->ref }}</td>
                            <td>{{ $row->conductor_nombre ?: '—' }}</td>
                            <td class="small">{{ $row->trt_nombre ?: '—' }}</td>
                            <td>{{ $row->placa ?: '—' }}</td>
                            <td class="text-light">{{ $rutaTexto !== '' ? $rutaTexto : 'Sin ruta' }}</td>
                            <td>
                                @foreach($marcas as $m)
                                    <i class="bi {{ $row->$m ? 'bi-check-circle-fill text-success' : 'bi-circle text-white-50' }}"
                                       title="{{ str_replace('_',' ',ucfirst($m)) }}"></i>
                                @endforeach
                                <small class="text-white-50 ms-1">{{ $completadas }}/7</small>
                            </td>
                            <td class="text-center"><span class="badge bg-primary">{{ $row->llamadas_total }}</span></td>
                            <td class="text-center">
                                @if($row->llamadas_exitosas > 0)
                                    <span class="badge bg-success">{{ $row->llamadas_exitosas }}</span>
                                @else
                                    <span class="text-white-50">—</span>
                                @endif
                            </td>
                            <td class="small">{{ $row->ultima_fecha ? \Illuminate\Support\Carbon::parse($row->ultima_fecha)->format('d/m/Y H:i') : '—' }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('lupita.llamadas', ['ids' => $idsLlamada]) }}"
                                   target="_blank" class="btn btn-sm btn-outline-info"
                                   title="Ver llamadas de {{ $row->ref }}"><i class="bi bi-telephone"></i></a>
                                <a href="{{ route('api.referencias.llamadas', $row->ref) }}"
                                   target="_blank" class="btn btn-sm btn-outline-light"
                                   title="Ver API JSON de {{ $row->ref }}"><i class="bi bi-braces"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-white-50 py-4">No hay referencias para el filtro.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-12">{{ $referencias->links() }}</div>
    </div>
    @endif
@endsection

@section('scripts')
    @if(!empty($noEncontradas))
        <script>
            (function () {
                // refs no encontradas: se copian una por linea (listo para pegar en Excel/tceflexia)
                const refs = @json(array_values($noEncontradas));
                const btn = document.getElementById('btn_copiar_no_enc');
                if (!btn) return;

                const texto = refs.join('\n');

                const avisar = () => {
                    const original = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                    setTimeout(() => { btn.innerHTML = original; }, 1500);
                };

                // respaldo para navegadores sin clipboard API (http sin TLS)
                const copiarClasico = () => {
                    const ta = document.createElement('textarea');
                    ta.value = texto;
                    ta.style.position = 'fixed';
                    ta.style.top = '-1000px';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); avisar(); } catch (e) {}
                    document.body.removeChild(ta);
                };

                btn.addEventListener('click', () => {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(texto).then(avisar).catch(copiarClasico);
                    } else {
                        copiarClasico();
                    }
                });
            })();
        </script>
    @endif
@endsection
