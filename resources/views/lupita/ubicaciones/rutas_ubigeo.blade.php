@extends('layouts.app')

@section('title', 'Rutas con ubigeo')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Rutas con ubigeo</h1>
                <span class="text-white-50 small">el SP no encontró un local y usó el distrito</span>
                <a href="{{ route('lupita.ubicaciones.titulos') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-question-circle"></i> Títulos sin ruta
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Cuando el procedimiento no encuentra un local, guarda el <b>ubigeo del distrito</b> como
                origen o destino de la ruta (por ejemplo <code>RUT_CARAL-150132</code>). Si después das de
                alta ese local, las refs de esa ruta <b>siguen apuntando al ubigeo</b> hasta que las
                reprocesás: acá ves cuántas ya mejorarían.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3 mb-0">{{ session('exito') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">
            <b><i class="bi bi-exclamation-octagon"></i> {{ session('error') }}</b>
        </div>
    @endif

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-4 text-light small">
                        <span><b class="text-warning">{{ count($rutas) }}</b> rutas con ubigeo</span>
                        <span><b class="text-warning">{{ number_format($refs_totales, 0, ',', '.') }}</b> refs en esas rutas</span>
                        <span><b class="text-success">{{ number_format($refs_mejorables, 0, ',', '.') }}</b> refs que ya resolverían a un local</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($refs_mejorables > 0)
        <div class="row mt-3">
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <b><i class="bi bi-arrow-repeat"></i> {{ number_format($refs_mejorables, 0, ',', '.') }} refs se pueden mejorar.</b>
                    Al reprocesarlas, el SP va a encontrar el local en vez del distrito y la ruta pasa a
                    <code>RUT_LOCAL-LOCAL</code>. Las rutas viejas con ubigeo quedan sin refs.
                    <form method="POST" action="{{ route('lupita.ubicaciones.reprocesar') }}" class="mt-2"
                          onsubmit="this.querySelectorAll('button').forEach(function (b) { b.disabled = true; });">
                        @csrf
                        <input type="hidden" name="desde" value="ubigeo">
                        <button class="btn btn-warning btn-sm" type="submit" @if(!$sp_disponible) disabled @endif>
                            <i class="bi bi-signpost-split"></i>
                            Reprocesar las {{ number_format($refs_mejorables, 0, ',', '.') }} refs mejorables
                        </button>
                        @if(!$sp_disponible)
                            <span class="small text-white-50 ms-2">
                                (el procedimiento no está instalado)
                            </span>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-secondary mt-3">
            No hay rutas con ubigeo que hoy resuelvan a un local. Al dar de alta locales nuevos van a
            aparecer acá.
        </div>
    @endif

    <div class="row mt-3">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover align-middle">
                    <thead class="thead-lupita">
                    <tr>
                        <th>Ruta</th>
                        <th>Origen</th>
                        <th>Destino</th>
                        <th style="width: 85px;">Refs</th>
                        <th style="width: 120px;">Resolverían</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rutas as $ruta)
                        <tr>
                            <td><code class="text-light">{{ $ruta['id'] }}</code></td>
                            @foreach(['origen', 'destino'] as $lado)
                                <td class="small">
                                    @if($ruta[$lado . '_ubigeo'])
                                        <span class="badge bg-warning text-dark">ubigeo {{ $ruta[$lado . '_id'] }}</span>
                                        <span class="d-block text-white-50">
                                            {{ $distritos[$ruta[$lado . '_id']] ?? 'distrito desconocido' }}
                                        </span>
                                    @else
                                        <code class="text-info">{{ $ruta[$lado . '_id'] }}</code>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-light small">{{ $ruta['refs'] }}</td>
                            <td class="small">
                                @if($ruta['resolverian'] > 0)
                                    <span class="badge bg-success">{{ $ruta['resolverian'] }}</span>
                                @else
                                    <span class="text-white-50">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-white-50 text-center py-4">
                                No hay rutas con ubigeo en el id.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
