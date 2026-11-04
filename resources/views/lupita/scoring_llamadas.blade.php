@extends('layouts.app')

@section('title', 'Llamadas del rango')

@section('heads')
    @livewireStyles
@endsection

@section('content')

    @php
        use App\Database\DBPalabrasLlamadas as Palabras;
        use App\Database\DBScoringLlamadas as Scoring;

        $umbral = $datos->umbral;
        $total = (int) $datos->total;
        $offset = (int) $datos->offset;
        $limite = (int) $datos->limite;
        $filas = $datos->filas;

        $desde = $total ? $offset + 1 : 0;
        $hasta = min($offset + $limite, $total);

        // enlaces que conservan todos los filtros y cambian una sola cosa
        $query = request()->query();
        $url_con = function (array $mas) use ($query) {
            return url()->current() . '?' . http_build_query(array_merge($query, $mas));
        };

        $version_actual = Scoring::pesos_version();
        $obsoletas_vistas = collect($filas)
            ->filter(fn($f) => (string) $f->pesos_version !== $version_actual)
            ->count();

        // colores del badge de motivo
        $color_motivo = ['ok' => 'secondary', 'buzon' => 'warning', 'sin_texto' => 'info'];

        // vapi_ids en el orden de la tabla: el modal los recorre con las flechas
        $ids = array_values(array_map(static fn($f) => (string) $f->vapi_id, $filas));
    @endphp

    <div class="row mb-3">
        <div class="col-12">
            <h1><i class="bi bi-list-ul"></i> Llamadas del rango</h1>
            <p class="text-light small mb-0">
                Listado de las llamadas ya puntuadas en el rango filtrado, con el veredicto del
                <strong class="text-white">modelo</strong> frente al del <strong class="text-white">humano</strong>.
                Se filtra y pagina en SQL, así que recorre todas las del rango (no solo 200).
                <a href="{{ route('lupita.scoring.ayuda') }}" class="text-info">¿Cómo funciona el puntaje?</a>
            </p>
        </div>
    </div>

    {{-- ============ filtros ============ --}}
    <div class="row mb-3">
        <div class="col-12">
            <form method="GET" class="card bg-dark-subtle border-secondary">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small" for="fecha_inicio">Fecha inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio"
                                   value="{{ request('fecha_inicio') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="fecha_fin">Fecha fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin"
                                   value="{{ request('fecha_fin') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="llamada_tipo_id">Tipo</label>
                            <select name="llamada_tipo_id" id="llamada_tipo_id" class="form-control form-control-sm">
                                <option value="">Todos</option>
                                @foreach($tipos as $tipo)
                                    @continue((int) $tipo->id === 0)
                                    <option value="{{ $tipo->id }}"
                                            @selected((string) request('llamada_tipo_id') === (string) $tipo->id)>
                                        {{ $tipo->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="conductor">Conductor</label>
                            <input type="text" id="conductor" name="conductor"
                                   value="{{ request('conductor') }}" class="form-control form-control-sm"
                                   placeholder="nombre o id">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="umbral">Umbral de éxito</label>
                            <select name="umbral" id="umbral" class="form-control form-control-sm">
                                @foreach(Scoring::$umbrales as $u)
                                    <option value="{{ $u }}" @selected($umbral === $u)>{{ $u }} %</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small" for="puntaje_min">Puntaje mín.</label>
                            <input type="number" min="0" max="100" step="1" id="puntaje_min" name="puntaje_min"
                                   value="{{ request('puntaje_min') }}" class="form-control form-control-sm"
                                   placeholder="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="puntaje_max">Puntaje máx.</label>
                            <input type="number" min="0" max="100" step="1" id="puntaje_max" name="puntaje_max"
                                   value="{{ request('puntaje_max') }}" class="form-control form-control-sm"
                                   placeholder="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="cruce">Modelo vs humano</label>
                            <select name="cruce" id="cruce" class="form-control form-control-sm">
                                @foreach([
                                    '' => 'Todos',
                                    'coincide' => 'Solo coinciden',
                                    'desacuerda' => 'Solo desacuerdan',
                                    'fp' => 'Falso positivo (modelo éxito / humano fallida)',
                                    'fn' => 'Falso negativo (modelo fallida / humano éxito)',
                                    'humano_e' => 'Humano dijo éxito',
                                    'humano_f' => 'Humano dijo fallida',
                                    'modelo_e' => 'Modelo dijo éxito',
                                    'modelo_f' => 'Modelo dijo fallida',
                                ] as $val => $txt)
                                    <option value="{{ $val }}" @selected((string) request('cruce') === $val)>
                                        {{ $txt }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="motivo">Motivo</label>
                            <select name="motivo" id="motivo" class="form-control form-control-sm">
                                <option value="">Todos</option>
                                @foreach(Scoring::$motivos as $val => $txt)
                                    <option value="{{ $val }}" @selected((string) request('motivo') === $val)>
                                        {{ $txt }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="palabra">Palabra que disparó</label>
                            <input type="text" id="palabra" name="palabra"
                                   value="{{ request('palabra') }}" class="form-control form-control-sm"
                                   placeholder="ej: todo bien">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="audio_codigo">Análisis de audio (revisor)</label>
                            <input type="text" id="audio_codigo" name="audio_codigo" list="lista_codigos"
                                   value="{{ request('audio_codigo') }}" class="form-control form-control-sm"
                                   placeholder="código que escribió el revisor">
                            <datalist id="lista_codigos">
                                @foreach($codigos->lista as $c)
                                    <option value="{{ $c->codigo }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="transcripcion_codigo">Análisis de transcripción (revisor)</label>
                            <input type="text" id="transcripcion_codigo" name="transcripcion_codigo" list="lista_codigos"
                                   value="{{ request('transcripcion_codigo') }}" class="form-control form-control-sm"
                                   placeholder="ej: BUZON, NO HABLA...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="orden">Ordenar por</label>
                            <select name="orden" id="orden" class="form-control form-control-sm">
                                @foreach(['' => 'Más recientes', 'puntaje_desc' => 'Mayor puntaje', 'puntaje_asc' => 'Menor puntaje'] as $val => $txt)
                                    <option value="{{ $val }}" @selected((string) request('orden') === $val)>{{ $txt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input type="hidden" name="omitir_buzon" value="0">
                                <input class="form-check-input" type="checkbox" value="1" id="omitir_buzon"
                                       name="omitir_buzon" @checked((string) request('omitir_buzon') === '1')>
                                <label class="form-check-label small" for="omitir_buzon">Omitir buzón de voz</label>
                            </div>
                        </div>

                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-funnel"></i> Filtrar
                            </button>
                            <a href="{{ route('lupita.scoring.llamadas') }}" class="btn btn-sm btn-secondary">Limpiar</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ============ resumen ============ --}}
    <div class="row mb-2">
        <div class="col-12 d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-secondary">{{ number_format($total) }} llamadas</span>
            <span class="small text-light">
                mostrando {{ number_format($desde) }}–{{ number_format($hasta) }} de {{ number_format($total) }}
            </span>
            @if($obsoletas_vistas > 0)
                <span class="small text-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    {{ $obsoletas_vistas }} en esta página tienen el puntaje obsoleto (los pesos cambiaron)
                </span>
            @endif
            @if((string) request('cruce') === 'desacuerda')
                <a href="{{ $url_con(['cruce' => '', 'offset' => 0]) }}" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-x-octagon"></i> Mostrando solo desacuerdos · ver todos
                </a>
            @else
                <a href="{{ $url_con(['cruce' => 'desacuerda', 'offset' => 0]) }}" class="btn btn-sm btn-warning">
                    <i class="bi bi-x-octagon"></i> Ver solo desacuerdos
                </a>
            @endif
            <a href="{{ route('lupita.scoring', request()->query()) }}" class="btn btn-sm btn-outline-info ms-auto">
                <i class="bi bi-graph-up"></i> Ver métricas del rango
            </a>
        </div>
    </div>

    {{-- ============ tabla ============ --}}
    <div class="row">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="thead-lupita">
                    <tr class="small">
                        <th>Fecha</th>
                        <th>An. transcripción</th>
                        <th>TRT</th>
                        <th class="text-end">Puntaje</th>
                        <th>Modelo</th>
                        <th>Humano</th>
                        <th class="text-center">¿Coincide?</th>
                        <th>Motivo</th>
                        <th>Palabras que dispararon</th>
                        <th title="Nota que los humanos suelen escribir en una llamada con estas mismas palabras. Es solo una sugerencia en pantalla: no se guarda en el análisis de audio de la llamada.">
                            Análisis sugerido <i class="bi bi-lightbulb"></i>
                        </th>
                        <th>Análisis audio</th>
                        <th>Ver</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($filas as $f)
                        @php
                            $humano = (int) $f->humano_exitosa === 1;
                            $tiene_puntaje = $f->puntaje !== null;
                            $modelo_exito = $tiene_puntaje && (float) $f->puntaje >= $umbral;
                            $coincide = $tiene_puntaje && ($modelo_exito === $humano);
                        @endphp
                        <tr style="{{ ($tiene_puntaje && !$coincide) ? 'background-color: rgba(220,53,69,.12);' : '' }}">
                            <td class="small text-light text-nowrap">{{ (string) $f->created_at }}</td>
                            <td class="small text-muted" style="max-width: 160px;">
                                {{ trim((string) $f->analisis_transcripcion) !== '' ? $f->analisis_transcripcion : '—' }}
                            </td>
                            <td class="small">{{ $f->trt }}</td>
                            <td class="text-end text-nowrap">
                                @if($tiene_puntaje)
                                    <span class="badge bg-{{ Scoring::color_puntaje((float) $f->puntaje) }}">
                                        {{ number_format((float) $f->puntaje, 1) }} %
                                    </span>
                                    <div class="small text-muted">base {{ number_format((float) $f->base_pct, 1) }} %</div>
                                @else
                                    <span class="badge bg-secondary">—</span>
                                @endif
                            </td>
                            <td class="small">
                                @if($tiene_puntaje)
                                    <span class="text-{{ $modelo_exito ? 'success' : 'danger' }}">
                                        {{ $modelo_exito ? 'éxito' : 'fallida' }}
                                    </span>
                                @else
                                    <span class="text-muted">no opina</span>
                                @endif
                            </td>
                            <td class="small">
                                <span class="text-{{ $humano ? 'success' : 'danger' }}">
                                    {{ $humano ? 'éxito' : 'fallida' }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($tiene_puntaje)
                                    <span class="{{ $coincide ? 'text-success' : 'text-danger' }}">
                                        {{ $coincide ? '✓' : '✗' }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $color_motivo[$f->motivo] ?? 'secondary' }}">
                                    {{ Scoring::$motivos[$f->motivo] ?? $f->motivo }}
                                </span>
                            </td>
                            <td class="small text-muted" style="max-width: 260px;">
                                {{ $f->disparos_texto !== '' ? $f->disparos_texto : '—' }}
                            </td>
                            @php
                                // la sugerencia es lectura nada mas: vive en scoring_llamadas y
                                // el analisis de audio lo sigue escribiendo el revisor
                                $sugerida = trim((string) ($f->nota_sugerida ?? ''));
                                $nota_humana = trim((string) $f->analisis_audio);
                                $coincide_nota = $sugerida !== '' && $nota_humana !== ''
                                    && mb_strtoupper($sugerida) === mb_strtoupper($nota_humana);
                            @endphp
                            <td class="small" style="max-width: 170px;">
                                @if($sugerida !== '')
                                    <span class="text-info">{{ $sugerida }}</span>
                                    <div class="text-muted">
                                        {{ number_format((int) $f->nota_sugerida_n) }} de
                                        {{ number_format((int) $f->nota_sugerida_total) }}
                                        @if($coincide_nota)
                                            <span class="text-success">· coincide ✓</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small text-muted" style="max-width: 160px;">
                                {{ trim((string) $f->analisis_audio) !== '' ? $f->analisis_audio : '—' }}
                            </td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                                        onclick="abrirDetalleLlamada({{ $loop->index }})"
                                        title="Ver la conversación y los pesos">
                                    <i class="bi bi-file-text"></i>
                                </button>
                                @if(trim((string) $f->audio_link) !== '')
                                    <a href="{{ $f->audio_link }}" target="_blank" rel="noopener"
                                       class="btn btn-sm btn-outline-info py-0" title="Escuchar la llamada">
                                        <i class="bi bi-play-circle"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">
                                No hay llamadas puntuadas con estos filtros.
                                Puede que falte correr <strong class="text-light">Calcular rango</strong> en
                                <a href="{{ route('lupita.scoring.pesos', request()->query()) }}" class="text-info">Palabras y pesos</a>.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============ paginacion ============ --}}
    @if($total > $limite)
        <div class="row">
            <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                @if($offset > 0)
                    <a href="{{ $url_con(['offset' => max(0, $offset - $limite)]) }}"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-chevron-left"></i> Anteriores
                    </a>
                @endif

                <span class="small text-muted">
                    página {{ intdiv($offset, $limite) + 1 }} de {{ (int) ceil($total / $limite) }}
                </span>

                @if($offset + $limite < $total)
                    <a href="{{ $url_con(['offset' => $offset + $limite]) }}"
                       class="btn btn-sm btn-outline-secondary">
                        Siguientes <i class="bi bi-chevron-right"></i>
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- detalle de una llamada: conversacion + pesos, cargado via Livewire --}}
    <livewire:scoring-detalle />

@endsection

@section('scripts')
    @livewireScripts
    <script>
        // vapi_ids en el orden de la tabla; el modal los recorre con las flechas
        const SCORING_IDS = @js($ids);

        function abrirDetalleLlamada(indice) {
            Livewire.dispatch('abrirDetalle', { ids: SCORING_IDS, indice: indice });
        }
    </script>
@endsection
