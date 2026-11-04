@php
    $estados = $enPlanta['estados'] ?? [];
    $colores = [
        'esperando_carga'    => 'secondary',
        'cargando'           => 'success',
        'esperando_descarga' => 'warning',
        'descargando'        => 'danger',
    ];
    $corto = fn ($etiqueta) => explode(' (', $etiqueta)[0];
    $depNombre = fn ($cod) => $depas[$cod]['nombre'] ?? ($cod !== '' ? $cod : 'S/D');

    // departamentos presentes en cualquier estado, ordenados por total de camiones
    $deps = [];
    foreach ($estados as $e) {
        foreach (array_keys($e['por_dep']) as $cod) {
            $deps[$cod] = true;
        }
    }
    $totalDep = fn ($cod) => array_sum(array_map(fn ($e) => $e['por_dep'][$cod] ?? 0, $estados));
    $deps = array_keys($deps);
    usort($deps, fn ($a, $b) => $totalDep($b) <=> $totalDep($a));

    $hayPlantas = collect($estados)->sum(fn ($e) => count($e['plantas'])) > 0;
@endphp

<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>
            <i class="bi bi-box-seam"></i> Camiones en planta
            <small class="text-white-50">· placas distintas</small>
        </span>
        <small class="text-white-50">
            Ventana: {{ $enPlanta['horas'] ? $enPlanta['horas'] . ' h' : 'todo el histórico' }}
            · corte {{ \Illuminate\Support\Carbon::parse($enPlanta['referencia'])->format('d/m/Y H:i') }}
        </small>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach($estados as $cod => $e)
                <span class="badge bg-{{ $colores[$cod] }} fs-6">
                    {{ $e['etiqueta'] }}: {{ $e['total_camiones'] }}
                </span>
            @endforeach
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <h6 class="text-white-50 text-uppercase small mb-2">Por departamento</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Departamento</th>
                                @foreach($estados as $e)
                                    <th class="text-end">{{ $corto($e['etiqueta']) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deps as $cod)
                                <tr>
                                    <td>{{ $depNombre($cod) }}</td>
                                    @foreach($estados as $e)
                                        <td class="text-end">{{ $e['por_dep'][$cod] ?? 0 }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($estados) + 1 }}" class="text-white-50">
                                        Sin camiones en el rango o ventana elegida.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-lg-7">
                <h6 class="text-white-50 text-uppercase small mb-2">Por planta</h6>
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="sticky-top bg-body">
                            <tr>
                                <th>Planta</th>
                                <th>Departamento</th>
                                <th>Estado</th>
                                <th class="text-end">Camiones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($estados as $cod => $e)
                                @foreach($e['plantas'] as $p)
                                    <tr>
                                        <td>{{ $p['planta'] }}</td>
                                        <td>{{ $depNombre($p['dep']) }}</td>
                                        <td><span class="badge bg-{{ $colores[$cod] }}">{{ $corto($e['etiqueta']) }}</span></td>
                                        <td class="text-end">{{ $p['camiones'] }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @unless($hayPlantas)
                                <tr>
                                    <td colspan="4" class="text-white-50">
                                        Sin camiones en el rango o ventana elegida.
                                    </td>
                                </tr>
                            @endunless
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <small class="text-white-50 d-block mt-3">
            Un estado se abre con el primer evento del tramo y se cierra con el siguiente: esperando carga
            (presenta → inicio de carga), cargando (inicio de carga → inicio de ruta), esperando descarga
            (llegada a destino → inicio de descargue) y descargando (inicio de descargue → fin de descargue).
            Los viajes sin evento de cierre quedan abiertos; la ventana acota su antigüedad.
            Los filtros de etapa o "solo completas" excluyen estos viajes abiertos.
        </small>
    </div>
</div>
