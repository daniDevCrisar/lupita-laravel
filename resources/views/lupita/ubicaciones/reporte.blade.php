@extends('layouts.app')

@section('title', 'Reporte de reproceso')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Reporte de reproceso</h1>
                <span class="text-white-50 small">
                    resultado de llamar <code class="text-warning">sp_actualizar_ruta_id</code> ref por ref
                </span>
                <a href="{{ route('lupita.ubicaciones.titulos') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-arrow-left"></i> Volver a Títulos sin ruta
                </a>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-4 text-light small">
                        <span><b class="text-warning">{{ $reporte['total'] }}</b> refs elegidas</span>
                        <span><b class="text-success">{{ $reporte['ok'] }}</b> resueltas</span>
                        <span><b class="text-danger">{{ $reporte['error'] }}</b> con error</span>
                        <span><b class="text-secondary">{{ count($reporte['omitidas']) }}</b> omitidas (ya tenían ruta)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(count($reporte['omitidas']) > 0)
        <div class="alert alert-secondary mt-3 mb-0">
            <b>Omitidas:</b> ya tenían <code>ruta_id</code>, así que no se volvió a llamar al procedimiento
            (cada llamada suma en <code>rutas.veces_usada</code> aunque el resultado sea el mismo).
            <span class="d-block small mt-1">{{ implode(', ', $reporte['omitidas']) }}</span>
        </div>
    @endif

    <div class="row mt-3">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="thead-lupita">
                    <tr>
                        <th style="width: 110px;">Ref</th>
                        <th style="width: 100px;">Resultado</th>
                        <th>Ruta</th>
                        <th>Mensaje del procedimiento</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($reporte['filas'] as $fila)
                        <tr>
                            <td><code class="text-light">{{ $fila['ref'] }}</code></td>
                            <td>
                                @if($fila['ok'])
                                    <span class="badge bg-success">ok</span>
                                @else
                                    <span class="badge bg-danger">error</span>
                                @endif
                            </td>
                            <td>
                                @if($fila['ruta_id'])
                                    <code class="text-success">{{ $fila['ruta_id'] }}</code>
                                @else
                                    <span class="text-white-50">—</span>
                                @endif
                            </td>
                            <td class="small text-white-50">{{ $fila['mensaje'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-white-50 text-center py-4">
                                No se procesó ninguna ref.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
