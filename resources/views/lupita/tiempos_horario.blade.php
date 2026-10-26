@extends('layouts.app')

@section('title', 'Tiempo de ruta por horario')

@section('content')

    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-start">
            <div>
                <h1>Tiempo de ruta por horario</h1>
                <p class="text-muted small mb-2">
                    Duración de <em>inicio de ruta</em> - <em>QR llegada a destino</em> (solo el recorrido
                    origen - destino), agrupada por la <strong>hora de partida</strong>.
                    El valor principal es la <strong>mediana</strong>; el promedio se muestra al costado porque
                    los viajes de varios días lo distorsionan.
                </p>
            </div>
            <a href="{{ route('lupita.tiempos') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-signpost-split"></i> Tiempos por ruta
            </a>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <input type="hidden" name="rutas_tocadas" id="rutas_tocadas" value="">
            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">Filtros de búsqueda</legend>

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
                        <label class="form-label" for="conductores_buscar">Conductor</label>
                        <input type="search" id="conductores_buscar" class="form-control form-control-sm mb-1"
                               placeholder="Buscar conductor..." autocomplete="off">
                        <select name="conductor" id="conductor" class="form-control">
                            <option value="" data-buscar="todos los conductores">Todos los conductores</option>
                            @foreach($conductores as $item)
                                @continue($item->conductor_id === null)
                                <option value="{{ $item->conductor_id }}"
                                        data-buscar="{{ mb_strtolower($item->nombre) }}"
                                    @selected((string) $conductor_sel === (string) $item->conductor_id)>
                                    {{ $item->nombre }} ({{ $item->viajes }})
                                </option>
                            @endforeach
                        </select>
                        <div id="conductores_contador" class="form-text"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="rutas_buscar">Rutas</label>
                        <div class="dropdown" id="ms_rutas">
                            <button type="button" id="ms_rutas_boton"
                                    class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                <span id="ms_rutas_texto" class="text-truncate">
                                    {{ count($rutas_sel) === 0 ? 'Todas (' . count($rutas) . ')' : (count($rutas_sel) === 1 ? '1 ruta seleccionada' : count($rutas_sel) . ' rutas seleccionadas') }}
                                </span>
                                <i class="bi bi-chevron-down ms-2"></i>
                            </button>
                            <div class="dropdown-menu w-100 p-2" style="max-height: 380px; overflow-y: auto;"
                                 aria-labelledby="ms_rutas_boton">
                                <input type="search" id="rutas_buscar" class="form-control form-control-sm mb-1"
                                       placeholder="Buscar por ruta, origen o destino..." autocomplete="off">
                                <div id="ms_rutas_contador" class="text-muted small mb-2"></div>
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill"
                                            id="ms_rutas_todas">Todas
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill"
                                            id="ms_rutas_limpiar">Ninguna
                                    </button>
                                </div>
                                <div id="ms_rutas_lista">
                                    @foreach($rutas as $ruta)
                                        @php
                                            $nombre_ruta = $nombres_rutas[$ruta->id] ?? $ruta->id;
                                        @endphp
                                        <label class="dropdown-item rounded px-2 py-1 text-wrap"
                                               style="display: flex; gap: .5rem; align-items: flex-start;"
                                               data-buscar="{{ mb_strtolower($nombre_ruta . ' ' . $ruta->id) }}">
                                            <input class="form-check-input mt-1 ms-0 flex-shrink-0" type="checkbox"
                                                   value="{{ $ruta->id }}" data-nombre="{{ $nombre_ruta }}"
                                                   @checked(in_array($ruta->id, $rutas_sel, true))>
                                            <span class="small lh-sm text-wrap">
                                                {{ $nombre_ruta }}
                                                <span class="d-block text-muted" style="font-size: .75rem;">{{ $ruta->id }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <div id="ms_rutas_vacio" class="text-muted small text-center py-2" style="display: none;">
                                    Sin coincidencias
                                </div>
                            </div>
                        </div>
                        <div id="ms_rutas_inputs"></div>
                        <div class="form-text">
                            {{ $conductor_sel ? 'Solo las rutas de ese conductor' : 'Sin selección = todas las rutas' }}
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="granularidad">Agrupar por</label>
                        <select name="granularidad" id="granularidad" class="form-control">
                            <option value="hora" @selected($horario::granularidad() === 'hora')>
                                Hora (24)
                            </option>
                            <option value="turno" @selected($horario::granularidad() === 'turno')>
                                Turno ({{ count($turnos) }})
                            </option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="dia_semana">Día de la semana</label>
                        <select name="dia_semana" id="dia_semana" class="form-control">
                            <option value="">Todos</option>
                            @foreach($dias as $indice => $dia)
                                <option value="{{ $indice }}" @selected((string) request('dia_semana') === (string) $indice)>
                                    {{ $dia }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="tope">Tope de duración</label>
                        <select name="tope" id="tope" class="form-control">
                            @foreach($topes as $valor => $etiqueta)
                                <option value="{{ $valor }}"
                                    @selected((string) $horario::tope() === (string) $valor)>
                                    {{ $etiqueta }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="min_viajes">Mínimo de viajes</label>
                        <select name="min_viajes" id="min_viajes" class="form-control">
                            @foreach([1, 3, 5, 10] as $opcion)
                                <option value="{{ $opcion }}"
                                    @selected((string) $horario::min_viajes() === (string) $opcion)>
                                    {{ $opcion }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="{{ url()->current() }}" class="btn btn-secondary">
                            Limpiar
                        </a>
                    </div>

                </div>
            </fieldset>
        </form>
    </div>

    @if($conductor_sel)
        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small">
                <i class="bi bi-person-badge"></i>
                Solo los viajes de <strong>{{ $conductor_nombre !== '' ? $conductor_nombre : ('conductor #' . $conductor_sel) }}</strong>
                @if(count($rutas_sel) > 0)
                    · sus {{ count($rutas_sel) }} rutas estan marcadas en el filtro
                @endif
            </div>
            <a href="{{ route('lupita.tiempos.horario', request()->except(['conductor', 'ruta_id', 'rutas_tocadas'])) }}"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-circle"></i> Quitar conductor
            </a>
        </div>
    @endif

    @php
        $resumen = $datos->resumen;
        $esHora = $horario::granularidad() === 'hora';
        $minViajes = $horario::min_viajes();
        $medianaGlobal = $resumen->mediana;

        // ---- filas ordenadas por hora (0-23) o por turno
        $filas = [];
        if ($esHora) {
            for ($h = 0; $h < 24; $h++) {
                $filas[(string) $h] = null;
            }
        } else {
            foreach (array_keys($turnos) as $clave) {
                $filas[$clave] = null;
            }
        }
        foreach ($datos->por_horario as $fila) {
            $filas[(string) $fila->clave] = $fila;
        }

        $valido = function ($fila) use ($minViajes) {
            return $fila && (int) $fila->viajes >= $minViajes && $fila->mediana !== null;
        };

        $etiqueta = function ($clave) use ($esHora, $turnos, $horario) {
            if ($esHora) {
                return $horario::etiqueta_hora((int) $clave);
            }

            return ($turnos[$clave]['nombre'] ?? $clave) . ' · ' . ($turnos[$clave]['rango'] ?? '');
        };

        $compacto = function ($minutos) {
            if ($minutos === null) {
                return '—';
            }

            $minutos = (int) $minutos;

            if ($minutos >= 1440) {
                return intdiv($minutos, 1440) . 'd ' . intdiv($minutos % 1440, 60) . 'h';
            }
            if ($minutos >= 60) {
                return intdiv($minutos, 60) . 'h' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
            }

            return $minutos . 'm';
        };

        // ---- extremos (solo celdas con muestra suficiente)
        $masRapida = null;
        $masLenta = null;
        foreach ($filas as $clave => $fila) {
            if (!$valido($fila)) {
                continue;
            }
            if ($masRapida === null || $fila->mediana < $masRapida['fila']->mediana) {
                $masRapida = ['clave' => $clave, 'fila' => $fila];
            }
            if ($masLenta === null || $fila->mediana > $masLenta['fila']->mediana) {
                $masLenta = ['clave' => $clave, 'fila' => $fila];
            }
        }

        // ---- matrices del mapa de calor
        $matrizDia = [];
        foreach ($datos->matriz_dia as $celda) {
            $matrizDia[(int) $celda->dia_semana][(string) $celda->eje] = $celda;
        }

        $matrizRuta = [];
        foreach ($datos->matriz_ruta as $celda) {
            $matrizRuta[$celda->ruta_id][(int) $celda->hora] = $celda;
        }

        $referencia = $medianaGlobal > 0 ? $medianaGlobal : null;
        $ejeEtiquetas = $esHora ? range(0, 23) : array_keys($turnos);

        // ---- detalle de las 24 horas (solo se usa cuando la vista esta agrupada por turno)
        $filasHora = [];
        for ($h = 0; $h < 24; $h++) {
            $filasHora[(string) $h] = null;
        }
        foreach (($datos->por_hora ?? []) as $filaHora) {
            $filasHora[(string) $filaHora->clave] = $filaHora;
        }

        $chartHoraLabels = [];
        $chartHoraMedianas = [];
        $chartHoraPromedios = [];
        $chartHoraViajes = [];
        foreach ($filasHora as $hora => $filaHora) {
            $chartHoraLabels[] = str_pad((string) $hora, 2, '0', STR_PAD_LEFT) . 'h';
            $chartHoraMedianas[] = $valido($filaHora) ? (float) $filaHora->mediana : null;
            $chartHoraPromedios[] = $filaHora ? (float) $filaHora->promedio : null;
            $chartHoraViajes[] = $filaHora ? (int) $filaHora->viajes : 0;
        }

        // ---- datos del grafico
        $chartLabels = [];
        $chartMedianas = [];
        $chartPromedios = [];
        $chartViajes = [];
        foreach ($filas as $clave => $fila) {
            $chartLabels[] = $esHora
                ? str_pad((string) $clave, 2, '0', STR_PAD_LEFT) . 'h'
                : ($turnos[$clave]['nombre'] ?? $clave);
            $chartMedianas[] = $valido($fila) ? (float) $fila->mediana : null;
            $chartPromedios[] = $fila ? (float) $fila->promedio : null;
            $chartViajes[] = $fila ? (int) $fila->viajes : 0;
        }
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-md">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes analizados</div>
                    <div class="fs-4 fw-bold">{{ number_format($resumen->viajes, 0, ',', '.') }}</div>
                    <div class="small text-muted">{{ $resumen->rutas }} rutas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-success h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Mediana general</div>
                    <div class="fs-4 fw-bold">{{ \App\Database\DBTiemposRuta::formatear_minutos($medianaGlobal) }}</div>
                    <div class="small text-muted">
                        promedio {{ \App\Database\DBTiemposRuta::formatear_minutos($resumen->promedio) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">{{ $esHora ? 'Hora' : 'Turno' }} más rápida</div>
                    <div class="fs-4 fw-bold">
                        {{ $masRapida ? $compacto($masRapida['fila']->mediana) : '—' }}
                    </div>
                    <div class="small text-muted">
                        {{ $masRapida ? $etiqueta($masRapida['clave']) : 'sin muestra suficiente' }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-danger h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">{{ $esHora ? 'Hora' : 'Turno' }} más lenta</div>
                    <div class="fs-4 fw-bold">
                        {{ $masLenta ? $compacto($masLenta['fila']->mediana) : '—' }}
                    </div>
                    <div class="small text-muted">
                        {{ $masLenta ? $etiqueta($masLenta['clave']) : 'sin muestra suficiente' }}
                    </div>
                </div>
            </div>
        </div>
        @if($horario::tope() > 0)
            <div class="col-6 col-md">
                <div class="card border-warning h-100">
                    <div class="card-body py-2">
                        <div class="text-muted small">Fuera del tope</div>
                        <div class="fs-4 fw-bold">{{ number_format($datos->descartados, 0, ',', '.') }}</div>
                        <div class="small text-muted">
                            viajes de más de {{ intdiv($horario::tope(), 60) }} h
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if($resumen->viajes == 0)
        <div class="alert alert-warning">
            No hay viajes con <em>inicio de ruta</em> y <em>QR llegada a destino</em> para los filtros seleccionados.
        </div>
    @else

        {{-- ================= GRAFICO ================= --}}
        <div class="card mb-3">
            <div class="card-header py-2">
                <i class="bi bi-bar-chart-line"></i> Duración por {{ $esHora ? 'hora de partida' : 'turno' }}
                <span class="text-muted small ms-2">barras = mediana · línea = promedio</span>
            </div>
            <div class="card-body py-2">
                <div style="height: 280px;">
                    <canvas id="chart_horario"></canvas>
                </div>
            </div>
        </div>

        @unless($esHora)
            {{-- ================= GRAFICO COMPLEMENTARIO: 24 HORAS ================= --}}
            <div class="card mb-3">
                <div class="card-header py-2">
                    <i class="bi bi-bar-chart"></i> Detalle por hora dentro de los turnos
                    <span class="text-muted small ms-2">el turno esconde el detalle: aquí están las 24 horas</span>
                </div>
                <div class="card-body py-2">
                    <div style="height: 260px;">
                        <canvas id="chart_hora"></canvas>
                    </div>
                </div>
            </div>
        @endunless

        {{-- ================= TABLA ================= --}}
        <div class="card mb-3">
            <div class="card-header py-2">
                <i class="bi bi-table"></i> Detalle por {{ $esHora ? 'hora' : 'turno' }}
                <span class="text-muted small ms-2">muestra mínima: {{ $minViajes }} viajes</span>
            </div>
            <div class="table-responsive" style="max-height: 620px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark small mb-0">
                    <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <th>{{ $esHora ? 'Hora' : 'Turno' }}</th>
                        <th class="text-center">Viajes</th>
                        <th class="text-center" data-bs-toggle="tooltip" title="Valor principal">Mediana</th>
                        <th class="text-center">Promedio</th>
                        <th class="text-center" data-bs-toggle="tooltip" title="Percentil 25">P25</th>
                        <th class="text-center" data-bs-toggle="tooltip" title="Percentil 75">P75</th>
                        <th class="text-center">Mín</th>
                        <th class="text-center">Máx</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Diferencia contra la mediana general">± General</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($filas as $clave => $fila)
                        <tr class="{{ $valido($fila) ? '' : 'table-secondary' }}">
                            <td>
                                {{ $etiqueta($clave) }}
                                @unless($valido($fila))
                                    <span class="badge bg-secondary ms-1">muestra insuficiente</span>
                                @endunless
                            </td>
                            <td class="text-center fw-bold">
                                {{ $fila ? number_format($fila->viajes, 0, ',', '.') : 0 }}
                            </td>
                            <td class="text-center">
                                @if($valido($fila))
                                    <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($fila->mediana, $medianaGlobal) }}">
                                        {{ \App\Database\DBTiemposRuta::formatear_minutos($fila->mediana) }}
                                    </span>
                                @else
                                    {{ $compacto($fila->mediana ?? null) }}
                                @endif
                            </td>
                            <td class="text-center">{{ $compacto($fila->promedio ?? null) }}</td>
                            <td class="text-center">{{ $compacto($fila->p25 ?? null) }}</td>
                            <td class="text-center">{{ $compacto($fila->p75 ?? null) }}</td>
                            <td class="text-center">{{ $compacto($fila->minimo ?? null) }}</td>
                            <td class="text-center">{{ $compacto($fila->maximo ?? null) }}</td>
                            <td class="text-center">
                                {{ $valido($fila) ? \App\Database\DBTiemposRuta::desviacion($fila->mediana, $medianaGlobal) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================= MAPA DE CALOR DIA x HORA ================= --}}
        <div class="card mb-3">
            <div class="card-header py-2">
                <i class="bi bi-grid-3x3-gap"></i>
                Mapa de calor: día de la semana × {{ $esHora ? 'hora' : 'turno' }}
                <span class="text-muted small ms-2">verde = más rápido · rojo = más lento</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-dark small mb-0 text-center align-middle">
                    <thead class="table-primary">
                    <tr>
                        <th class="text-start">Día</th>
                        @foreach($ejeEtiquetas as $eje)
                            <th>{{ $esHora ? str_pad((string) $eje, 2, '0', STR_PAD_LEFT) : ($turnos[$eje]['nombre'] ?? $eje) }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($dias as $indice => $dia)
                        <tr>
                            <th class="text-start">{{ $dia }}</th>
                            @foreach($ejeEtiquetas as $eje)
                                @php
                                    $celda = $matrizDia[$indice][(string) $eje] ?? null;
                                    $suficiente = $celda && (int) $celda->viajes >= $minViajes;
                                @endphp
                                <td @if($suficiente)
                                        data-bs-toggle="tooltip"
                                        title="{{ $dia }} {{ $esHora ? str_pad((string) $eje, 2, '0', STR_PAD_LEFT) . ':00' : ($turnos[$eje]['rango'] ?? '') }} · {{ $celda->viajes }} viajes · mediana {{ \App\Database\DBTiemposRuta::formatear_minutos($celda->mediana) }}"
                                        style="background-color: {{ $horario::color_celda($celda->mediana, $referencia) }};"
                                    @endif>
                                    @if($suficiente)
                                        {{ $compacto($celda->mediana) }}
                                    @elseif($celda)
                                        <span class="text-muted" style="opacity: .55;">{{ $compacto($celda->mediana) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================= MAPA DE CALOR RUTA x HORA ================= --}}
        @if(count($datos->rutas_top) > 0)
            <div class="card mb-3">
                <div class="card-header py-2">
                    <i class="bi bi-signpost-split"></i>
                    Mapa de calor: {{ $datos->filtrando_rutas ? 'rutas seleccionadas' : 'rutas con más viajes' }} × hora
                    <span class="text-muted small ms-2">
                        {{ $datos->filtrando_rutas
                            ? count($datos->rutas_top) . ' rutas del filtro'
                            : 'top ' . count($datos->rutas_top) . ' rutas' }}
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-dark small mb-0 text-center align-middle">
                        <thead class="table-primary">
                        <tr>
                            <th class="text-start">Ruta</th>
                            <th>Viajes</th>
                            @foreach(range(0, 23) as $hora)
                                <th>{{ str_pad((string) $hora, 2, '0', STR_PAD_LEFT) }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($datos->rutas_top as $ruta)
                            <tr>
                                <th class="text-start">
                                    {{ $nombres_rutas[$ruta->ruta_id] ?? $ruta->ruta_id }}
                                    <div class="text-muted small">{{ $ruta->ruta_id }}</div>
                                </th>
                                <td class="fw-bold">{{ number_format($ruta->viajes, 0, ',', '.') }}</td>
                                @foreach(range(0, 23) as $hora)
                                    @php
                                        $celda = $matrizRuta[$ruta->ruta_id][$hora] ?? null;
                                        $suficiente = $celda && (int) $celda->viajes >= $minViajes;
                                    @endphp
                                    <td @if($suficiente)
                                            data-bs-toggle="tooltip"
                                            title="{{ $hora }}:00 · {{ $celda->viajes }} viajes · mediana {{ \App\Database\DBTiemposRuta::formatear_minutos($celda->mediana) }}"
                                            style="background-color: {{ $horario::color_celda($celda->mediana, $referencia) }};"
                                        @endif>
                                        @if($suficiente)
                                            {{ $compacto($celda->mediana) }}
                                        @elseif($celda)
                                            <span class="text-muted" style="opacity: .55;">{{ $compacto($celda->mediana) }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        @if(count($datos->rutas_top) === 0)
                            <tr>
                                <td colspan="26" class="text-center">
                                    No hay rutas con {{ $minViajes }} o más viajes para los filtros seleccionados.
                                </td>
                            </tr>
                        @endif
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    @endif

    {{-- ================= SCRIPTS DE LA VISTA ================= --}}
    {{-- Van dentro del contenido para no depender del @yield('scripts') del layout --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        // ---- si algo falla, se muestra en el propio filtro (util en navegadores con extensiones o cache viejo)
        window.addEventListener('error', function (evento) {
            const aviso = document.getElementById('ms_rutas_contador');
            if (aviso && !aviso.dataset.error) {
                aviso.dataset.error = '1';
                aviso.innerHTML = '<span class="text-danger">Error JS: ' + (evento.message || 'desconocido') + '</span>';
            }
        });

        // ---- tooltips: un fallo aqui no debe romper el resto del script
        try {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new bootstrap.Tooltip(el);
            });
        } catch (error) {
            console.warn('No se pudieron inicializar los tooltips', error);
        }

        // ---- selector multiple de rutas con busqueda por texto
        (function () {
            const contenedor = document.getElementById('ms_rutas');
            if (!contenedor) {
                return;
            }

            const lista = document.getElementById('ms_rutas_lista');
            const ocultos = document.getElementById('ms_rutas_inputs');
            const texto = document.getElementById('ms_rutas_texto');
            const buscar = document.getElementById('rutas_buscar');
            const vacio = document.getElementById('ms_rutas_vacio');
            const contador = document.getElementById('ms_rutas_contador');
            const casillas = Array.prototype.slice.call(lista.querySelectorAll('input[type="checkbox"]'));
            const total = casillas.length;

            // avisa al servidor de que las casillas las movio el usuario,
            // asi no se pisan con las rutas del conductor
            const tocadas = document.getElementById('rutas_tocadas');
            const marcarTocadas = function () {
                if (tocadas) {
                    tocadas.value = '1';
                }
            };

            // minusculas y sin acentos: asi "tingo maria" encuentra "Tingo Maria"
            const normalizar = function (valor) {
                return valor.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            };

            // los valores seleccionados viajan en inputs ocultos ruta_id[]
            const sincronizar = function () {
                const marcadas = casillas.filter(function (casilla) {
                    return casilla.checked;
                });

                ocultos.innerHTML = '';
                marcadas.forEach(function (casilla) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ruta_id[]';
                    input.value = casilla.value;
                    ocultos.appendChild(input);
                });

                if (marcadas.length === 0) {
                    texto.textContent = 'Todas (' + total + ')';
                } else if (marcadas.length === 1) {
                    texto.textContent = marcadas[0].dataset.nombre;
                } else {
                    texto.textContent = marcadas.length + ' rutas seleccionadas';
                }
            };

            const filtrar = function () {
                const consulta = normalizar(buscar.value.trim());
                let visibles = 0;

                lista.querySelectorAll('[data-buscar]').forEach(function (item) {
                    const coincide = consulta === '' || normalizar(item.dataset.buscar).indexOf(consulta) !== -1;
                    // se usa flex/none explicito porque .d-flex de Bootstrap lleva !important
                    item.style.display = coincide ? 'flex' : 'none';
                    if (coincide) {
                        visibles++;
                    }
                });

                vacio.style.display = visibles === 0 ? '' : 'none';
                contador.textContent = consulta === '' ? total + ' rutas' : visibles + ' de ' + total + ' rutas';
            };

            lista.addEventListener('change', function () {
                marcarTocadas();
                sincronizar();
            });
            buscar.addEventListener('input', filtrar);
            // 'search' se dispara al borrar con la X del navegador; 'keyup' cubre navegadores viejos
            buscar.addEventListener('search', filtrar);
            buscar.addEventListener('keyup', filtrar);
            buscar.addEventListener('keydown', function (evento) {
                // Enter en el buscador no debe enviar el formulario
                if (evento.key === 'Enter') {
                    evento.preventDefault();
                }
            });

            document.getElementById('ms_rutas_todas').addEventListener('click', function () {
                casillas.forEach(function (casilla) {
                    casilla.checked = true;
                });
                marcarTocadas();
                sincronizar();
            });

            document.getElementById('ms_rutas_limpiar').addEventListener('click', function () {
                casillas.forEach(function (casilla) {
                    casilla.checked = false;
                });
                marcarTocadas();
                sincronizar();
            });

            sincronizar();

            // si el navegador restauro un texto en el buscador, se aplica de una vez
            if (buscar.value !== '') {
                filtrar();
            } else {
                contador.textContent = total + ' rutas';
            }
        })();

        // ---- buscador del select de conductores + envio automatico al elegir
        (function () {
            const selector = document.getElementById('conductor');
            const buscar = document.getElementById('conductores_buscar');
            if (!selector || !buscar) {
                return;
            }

            const contador = document.getElementById('conductores_contador');

            const normalizar = function (valor) {
                return valor.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            };

            // copia de todas las opciones: el select se reconstruye en cada busqueda
            const opciones = Array.prototype.slice.call(selector.options).map(function (opcion) {
                return {
                    valor: opcion.value,
                    texto: opcion.textContent.trim(),
                    buscar: normalizar(opcion.dataset.buscar || opcion.textContent)
                };
            });
            const total = opciones.length;

            const filtrar = function () {
                const consulta = normalizar(buscar.value.trim());
                const elegido = selector.value;
                const fragmento = document.createDocumentFragment();
                let visibles = 0;

                opciones.forEach(function (opcion) {
                    // la opcion elegida se conserva aunque no coincida,
                    // si no el filtro se perderia al escribir
                    const coincide = consulta === '' || opcion.buscar.indexOf(consulta) !== -1;
                    if (!coincide && opcion.valor !== elegido) {
                        return;
                    }

                    const nodo = document.createElement('option');
                    nodo.value = opcion.valor;
                    nodo.textContent = opcion.texto;
                    nodo.dataset.buscar = opcion.buscar;
                    nodo.selected = opcion.valor === elegido;
                    fragmento.appendChild(nodo);
                    visibles++;
                });

                selector.innerHTML = '';
                selector.appendChild(fragmento);

                contador.textContent = consulta === ''
                    ? total + ' conductores'
                    : visibles + ' de ' + total + ' conductores';
            };

            buscar.addEventListener('input', filtrar);
            // 'search' se dispara al borrar con la X del navegador; 'keyup' cubre navegadores viejos
            buscar.addEventListener('search', filtrar);
            buscar.addEventListener('keyup', filtrar);
            buscar.addEventListener('keydown', function (evento) {
                // Enter en el buscador no debe enviar el formulario
                if (evento.key === 'Enter') {
                    evento.preventDefault();
                }
            });

            // al elegir el conductor se envian sus rutas
            selector.addEventListener('change', function () {
                // se limpia la marca para que el servidor vuelva a
                // seleccionar todas las rutas del conductor elegido
                const tocadas = document.getElementById('rutas_tocadas');
                if (tocadas) {
                    tocadas.value = '';
                }

                const formulario = selector.form;
                if (!formulario) {
                    return;
                }

                const boton = formulario.querySelector('button[type="submit"]');
                if (boton) {
                    boton.disabled = true;
                    boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Filtrando...';
                }

                formulario.submit();
            });

            // si el navegador restauro un texto en el buscador, se aplica de una vez
            if (buscar.value !== '') {
                filtrar();
            } else {
                contador.textContent = total + ' conductores';
            }
        })();

        // ---- graficos
        (function () {
            const minutos = function (valor) {
                if (valor === null || valor === undefined) {
                    return '—';
                }
                const h = Math.floor(valor / 60);
                const m = Math.round(valor % 60);
                return h > 0 ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm';
            };

            const dibujar = function (id, etiquetas, medianas, promedios, viajes) {
                const canvas = document.getElementById(id);
                if (!canvas) {
                    return;
                }

                if (typeof Chart === 'undefined') {
                    canvas.parentElement.innerHTML = '<div class="alert alert-warning mb-0">' +
                        'No se pudo cargar la librería de gráficos (Chart.js); los valores siguen en las tablas de abajo.' +
                        '</div>';
                    return;
                }

                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: etiquetas,
                        datasets: [
                            {
                                label: 'Mediana',
                                data: medianas,
                                backgroundColor: 'rgba(13, 110, 253, .65)',
                                borderColor: 'rgba(13, 110, 253, 1)',
                                borderWidth: 1,
                            },
                            {
                                label: 'Promedio',
                                data: promedios,
                                type: 'line',
                                borderColor: 'rgba(255, 193, 7, 1)',
                                backgroundColor: 'rgba(255, 193, 7, .2)',
                                borderWidth: 2,
                                pointRadius: 2,
                                tension: .3,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: function (v) { return minutos(v); } },
                                grid: { color: 'rgba(255,255,255,.08)' }
                            }
                        },
                        plugins: {
                            legend: { labels: { color: '#dee2e6' } },
                            tooltip: {
                                callbacks: {
                                    afterBody: function (items) {
                                        return 'Viajes: ' + viajes[items[0].dataIndex];
                                    },
                                    label: function (ctx) {
                                        return ctx.dataset.label + ': ' + minutos(ctx.parsed.y);
                                    }
                                }
                            }
                        }
                    }
                });
            };

            @if($resumen->viajes > 0)
            dibujar(
                'chart_horario',
                @json($chartLabels),
                @json($chartMedianas),
                @json($chartPromedios),
                @json($chartViajes)
            );

            @unless($esHora)
            dibujar(
                'chart_hora',
                @json($chartHoraLabels),
                @json($chartHoraMedianas),
                @json($chartHoraPromedios),
                @json($chartHoraViajes)
            );
            @endunless
            @endif
        })();
    </script>
@endsection
