@extends('layouts.app')

@section('title', 'Tiempos promedio por ruta y conductor')

@section('content')

    <div class="row">
        <div class="col-12">
            <h1>Tiempos promedio por ruta y conductor</h1>
            <p class="text-muted small mb-2">
                Minutos calculados con los hitos de la referencia. El <strong>ciclo completo</strong> es la
                <strong>suma de los promedios de las etapas E2 a E6</strong>
                (<em>presenta para carga</em> a <em>fin de descargue</em>), por eso la columna cuadra
                siempre con lo que se ve en esas columnas; el número pequeño bajo el ciclo indica cuántas
                de las 5 etapas tienen datos. La <em>etapa 1</em> (compromiso de carga - presenta) no entra
                en el ciclo, y la <strong>ruta</strong> es solo el recorrido de origen a destino:
                <em>inicio de ruta</em> - <em>QR llegada a destino</em> (etapa 4).
            </p>
            <p class="text-muted small mb-2">
                Los <strong>mín/máx</strong> del ciclo sí salen solo de viajes que registran toda la cadena
                de hitos en orden: un hueco entre los hitos extremos inflaría el dato con días enteros.
            </p>
            <p class="text-muted small mb-2">
                El <strong>tope por etapa</strong> descarta todo el viaje cuando alguna etapa registrada
                (E2 a E6) cae fuera de 0 a ese máximo, para dejar fuera los hitos errados.
            </p>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">
                    Filtros de búsqueda
                </legend>

                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio"
                               name="fecha_inicio"
                               value="{{ request('fecha_inicio') }}"
                               class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin"
                               name="fecha_fin"
                               value="{{ request('fecha_fin') }}"
                               class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="ruta_id">Ruta</label>
                        <select name="ruta_id" id="ruta_id" class="form-control">
                            <option value="">Todas</option>
                            @foreach($rutas as $ruta)
                                <option value="{{ $ruta->id }}" @selected(request('ruta_id') === $ruta->id)>
                                    {{ $tiempos::nombre_ruta($ruta) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="min_viajes">Mínimo de viajes</label>
                        <select name="min_viajes" id="min_viajes" class="form-control">
                            @foreach([1, 3, 5, 10] as $opcion)
                                <option value="{{ $opcion }}"
                                    @selected((string) request('min_viajes', 1) === (string) $opcion)>
                                    {{ $opcion }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="max_etapa">Tope por etapa (min)</label>
                        <input type="number" id="max_etapa" name="max_etapa" min="1" step="1"
                               value="{{ request('max_etapa') }}" placeholder="Sin tope"
                               class="form-control">
                        <div class="form-text">
                            Descarta el viaje si alguna etapa registrada supera el tope o es negativa.
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="conductor" class="form-label">Conductor</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text"
                                   id="conductor"
                                   name="conductor"
                                   value="{{ request('conductor') }}"
                                   class="form-control"
                                   placeholder="Conductor, id...">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="trt" class="form-label">Transportista</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <input type="text"
                                   id="trt"
                                   name="trt"
                                   value="{{ request('trt') }}"
                                   class="form-control"
                                   placeholder="Transportista, id...">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="ordenar_por" class="form-label">
                            <i class="bi bi-arrow-down-up"></i> Ordenar por
                        </label>
                        <div class="row">
                            <div class="col">
                                <select name="ordenar_por" id="ordenar_por" class="form-control">
                                    <option value="ruta" @selected(request('ordenar_por', 'ruta') === 'ruta')>
                                        Tiempo de ruta</option>
                                    <option value="ciclo" @selected(request('ordenar_por') === 'ciclo')>
                                        Ciclo completo</option>
                                    <option value="viajes" @selected(request('ordenar_por') === 'viajes')>
                                        Cantidad de viajes</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="orden" id="rd_ord_1" value="1"
                                        @checked(request('orden') !== '0')>
                                    <label class="btn btn-outline-primary" for="rd_ord_1">Asc</label>

                                    <input type="radio" class="btn-check" name="orden" id="rd_ord_2" value="0"
                                        @checked(request('orden') === '0')>
                                    <label class="btn btn-outline-primary" for="rd_ord_2">Desc</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="limit">Máximo de filas</label>
                        <input type="number" id="limit" name="limit" min="1" max="1000"
                               value="{{ request('limit', 100) }}"
                               class="form-control">
                    </div>

                    <div class="col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> Filtrar
                        </button>

                        <a href="{{ url()->current() }}" class="btn btn-secondary me-2">
                            Limpiar
                        </a>

                        @php
                            $params_horario = array_filter([
                                'fecha_inicio' => request('fecha_inicio'),
                                'fecha_fin' => request('fecha_fin'),
                            ]);
                            if (request('ruta_id')) {
                                $params_horario['ruta_id'] = [(string) request('ruta_id')];
                            }
                            if (is_numeric(request('conductor'))) {
                                $params_horario['conductor'] = (int) request('conductor');
                            }
                        @endphp

                        <a href="{{ route('lupita.tiempos.horario', $params_horario) }}" class="btn btn-outline-info">
                            <i class="bi bi-clock-history"></i> Ver por horario
                        </a>
                    </div>

                </div>
            </fieldset>
        </form>
    </div>

    @php
        $resumen = $datos->resumen;
        $promedios_ruta = [];
        foreach ($datos->por_ruta as $item) {
            $promedios_ruta[$item->ruta_id] = $item->ruta_promedio;
        }
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-md">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes</div>
                    <div class="fs-4 fw-bold">{{ $resumen->viajes }}</div>
                    @if (!is_null($datos->descartados))
                        <div class="small text-muted">
                            {{ $datos->descartados }} descartados por tope
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Rutas</div>
                    <div class="fs-4 fw-bold">{{ $resumen->rutas }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Conductores</div>
                    <div class="fs-4 fw-bold">{{ $resumen->conductores }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-success h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Ruta prom. (origen - destino)</div>
                    <div class="fs-4 fw-bold">
                        {{ $tiempos::formatear_minutos($resumen->ruta_promedio) }}
                    </div>
                    <div class="small text-muted">
                        mín {{ $tiempos::formatear_minutos($resumen->ruta_min) }} ·
                        máx {{ $tiempos::formatear_minutos($resumen->ruta_max) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-warning h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Ciclo completo prom. (suma de E2 - E6)</div>
                    <div class="fs-4 fw-bold">
                        {{ $tiempos::formatear_minutos($resumen->ciclo_promedio) }}
                    </div>
                    <div class="small text-muted" data-bs-toggle="tooltip"
                         title="Mín y máx observados en los {{ $resumen->ciclo_viajes }} de {{ $resumen->viajes }} viajes que tienen toda la cadena de hitos en orden.">
                        mín {{ $tiempos::formatear_minutos($resumen->ciclo_min) }} ·
                        máx {{ $tiempos::formatear_minutos($resumen->ciclo_max) }}
                    </div>
                    <div class="small text-muted">
                        {{ $resumen->ciclo_etapas }} de {{ count($etapas) - 1 }} etapas con datos
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="tabs_tiempos" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab_rutas_btn" data-bs-toggle="tab"
                    data-bs-target="#tab_rutas" type="button" role="tab">
                <i class="bi bi-signpost-split"></i> Por ruta
                <span class="badge bg-secondary">{{ count($datos->por_ruta) }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab_conductores_btn" data-bs-toggle="tab"
                    data-bs-target="#tab_conductores" type="button" role="tab">
                <i class="bi bi-person-badge"></i> Por conductor
                <span class="badge bg-secondary">{{ count($datos->por_conductor) }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab_matriz_btn" data-bs-toggle="tab"
                    data-bs-target="#tab_matriz" type="button" role="tab">
                <i class="bi bi-grid-3x3-gap"></i> Ruta x conductor
                <span class="badge bg-secondary">{{ count($datos->matriz) }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="tabs_tiempos_content">

        {{-- ================= POR RUTA ================= --}}
        <div class="tab-pane fade show active" id="tab_rutas" role="tabpanel">
            <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark small mb-0">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>Ruta</th>
                        <th class="text-center">Viajes</th>
                        <th class="text-center">Conductores</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Solo el recorrido origen - destino: inicio_ruta - qr_llegada_destino">
                            Ruta<br>prom.</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más rápida (origen - destino)">Mín</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más lenta (origen - destino)">Máx</th>
                        @foreach($etapas as $numero => $nombre)
                            <th class="text-center" data-bs-toggle="tooltip" title="{{ $nombre }}">
                                E{{ $numero }}
                            </th>
                        @endforeach
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Suma de los promedios de las etapas E2 a E6: coincide con sumar esas columnas. El índice indica cuántas etapas tienen datos.">
                            Ciclo<br>completo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($datos->por_ruta as $row)
                        <tr>
                            <td>
                                {{ $tiempos::nombre_ruta($row) }}
                                <div class="text-muted small">{{ $row->ruta_id }}</div>
                            </td>
                            <td class="text-center fw-bold">{{ $row->viajes }}</td>
                            <td class="text-center">{{ $row->conductores }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ $tiempos::color_por_tiempo($row->ruta_promedio, $resumen->ruta_promedio) }}">
                                    {{ $tiempos::formatear_minutos($row->ruta_promedio) }}
                                </span>
                            </td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_min) }}</td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_max) }}</td>
                            @foreach($etapas as $numero => $nombre)
                                @php $campo = 'etapa_' . $numero . '_promedio'; @endphp
                                <td class="text-center">
                                    {{ $tiempos::formatear_minutos($row->$campo ?? null) }}
                                </td>
                            @endforeach
                            <td class="text-center">
                                {{ $tiempos::formatear_minutos($row->ciclo_promedio) }}
                                @if ($row->ciclo_etapas < count($etapas) - 1)
                                    <div class="text-muted" style="font-size: .7rem">
                                        {{ $row->ciclo_etapas }}/{{ count($etapas) - 1 }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 7 + count($etapas) }}" class="text-center">
                                No hay rutas con tiempos registrados para los filtros seleccionados.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================= POR CONDUCTOR ================= --}}
        <div class="tab-pane fade" id="tab_conductores" role="tabpanel">
            <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark small mb-0">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>Conductor</th>
                        <th>TRT</th>
                        <th class="text-center">Rutas</th>
                        <th class="text-center">Viajes</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Solo el recorrido origen - destino: inicio_ruta - qr_llegada_destino">
                            Ruta<br>prom.</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más rápida (origen - destino)">Mín</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más lenta (origen - destino)">Máx</th>
                        @foreach($etapas as $numero => $nombre)
                            <th class="text-center" data-bs-toggle="tooltip" title="{{ $nombre }}">
                                E{{ $numero }}
                            </th>
                        @endforeach
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Suma de los promedios de las etapas E2 a E6: coincide con sumar esas columnas. El índice indica cuántas etapas tienen datos.">
                            Ciclo<br>completo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($datos->por_conductor as $row)
                        <tr>
                            <td>
                                <a href="{{ route('lupita.conductores', [
                                    'fecha_inicio' => request('fecha_inicio'),
                                    'fecha_fin' => request('fecha_fin'),
                                    'conductor' => $row->conductor_id,
                                ]) }}" target="_blank">{{ $row->conductor }}</a>
                                <div class="text-muted small">{{ $row->conductor_id }}</div>
                            </td>
                            <td>{{ $row->trt ?? 'SIN TRT' }}</td>
                            <td class="text-center">{{ $row->rutas }}</td>
                            <td class="text-center fw-bold">{{ $row->viajes }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ $tiempos::color_por_tiempo($row->ruta_promedio, $resumen->ruta_promedio) }}">
                                    {{ $tiempos::formatear_minutos($row->ruta_promedio) }}
                                </span>
                            </td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_min) }}</td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_max) }}</td>
                            @foreach($etapas as $numero => $nombre)
                                @php $campo = 'etapa_' . $numero . '_promedio'; @endphp
                                <td class="text-center">
                                    {{ $tiempos::formatear_minutos($row->$campo ?? null) }}
                                </td>
                            @endforeach
                            <td class="text-center">
                                {{ $tiempos::formatear_minutos($row->ciclo_promedio) }}
                                @if ($row->ciclo_etapas < count($etapas) - 1)
                                    <div class="text-muted" style="font-size: .7rem">
                                        {{ $row->ciclo_etapas }}/{{ count($etapas) - 1 }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 8 + count($etapas) }}" class="text-center">
                                No hay conductores con tiempos registrados para los filtros seleccionados.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================= RUTA x CONDUCTOR ================= --}}
        <div class="tab-pane fade" id="tab_matriz" role="tabpanel">
            <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark small mb-0">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>Ruta</th>
                        <th>Conductor</th>
                        <th class="text-center">Viajes</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Solo el recorrido origen - destino: inicio_ruta - qr_llegada_destino">
                            Ruta<br>prom.</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Diferencia contra el promedio de la ruta">± Ruta</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más rápida (origen - destino)">Mín</th>
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Ruta más lenta (origen - destino)">Máx</th>
                        @foreach($etapas as $numero => $nombre)
                            <th class="text-center" data-bs-toggle="tooltip" title="{{ $nombre }}">
                                E{{ $numero }}
                            </th>
                        @endforeach
                        <th class="text-center" data-bs-toggle="tooltip"
                            title="Suma de los promedios de las etapas E2 a E6: coincide con sumar esas columnas. El índice indica cuántas etapas tienen datos.">
                            Ciclo<br>completo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($datos->matriz as $row)
                        @php $promedio_ruta = $promedios_ruta[$row->ruta_id] ?? null; @endphp
                        <tr>
                            <td>
                                {{ $tiempos::nombre_ruta($row) }}
                                <div class="text-muted small">{{ $row->ruta_id }}</div>
                            </td>
                            <td>{{ $row->conductor }}</td>
                            <td class="text-center fw-bold">{{ $row->viajes }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ $tiempos::color_por_tiempo($row->ruta_promedio, $promedio_ruta) }}">
                                    {{ $tiempos::formatear_minutos($row->ruta_promedio) }}
                                </span>
                            </td>
                            <td class="text-center">
                                {{ $tiempos::desviacion($row->ruta_promedio, $promedio_ruta) }}
                            </td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_min) }}</td>
                            <td class="text-center">{{ $tiempos::formatear_minutos($row->ruta_max) }}</td>
                            @foreach($etapas as $numero => $nombre)
                                @php $campo = 'etapa_' . $numero . '_promedio'; @endphp
                                <td class="text-center">
                                    {{ $tiempos::formatear_minutos($row->$campo ?? null) }}
                                </td>
                            @endforeach
                            <td class="text-center">
                                {{ $tiempos::formatear_minutos($row->ciclo_promedio) }}
                                @if ($row->ciclo_etapas < count($etapas) - 1)
                                    <div class="text-muted" style="font-size: .7rem">
                                        {{ $row->ciclo_etapas }}/{{ count($etapas) - 1 }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 8 + count($etapas) }}" class="text-center">
                                No hay viajes con tiempos registrados para los filtros seleccionados.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

@endsection

@section('scripts')
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el)
        })
    </script>
@endsection
