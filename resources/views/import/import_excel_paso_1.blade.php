@extends('layouts.app')

@section('title', 'Conductores Duplicados')

@section('content')

@php
$total_conductores = count($conductores);
$total_trts = count($trts);
@endphp


<div class="container mt-4">

    <div class="row mb-3">
        <!-- IZQUIERDA -->
        <div class="col text-start">
            <a href="{{ url('/import/eliminar/'.$lote_id) }}" class="btn btn-danger btn-sm me-2">
                <i class="bi bi-trash"></i> Eliminar
            </a>

            <a href="{{ url('/import') }}" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-repeat"></i> Cargar otro archivo
            </a>
        </div>

        <!-- DERECHA -->
        <div class="col text-end">
            <a href="{{ url('/import/paso-2/'.$lote_id) }}" class="btn btn-success btn-sm">
                Siguiente <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </div>

    @if($cabecera)
    <div class="row mb-3">
        <div class="col-12">
            <div class="card bg-secondary text-white border-0">
                <div class="card-body">
                    <h5 class="card-title mb-3">Información del Lote</h5>

                    <div class="row">
                        <div class="col-3">
                            <i class="bi bi-file-earmark-text"></i>
                            <strong>Archivo:</strong><br>
                            {{ $cabecera->nombre }}
                        </div>

                        <div class="col-3">
                            <i class="bi bi-calendar-date"></i>
                            <strong>Fecha de Creacion</strong><br>
                            {{ $cabecera->created_at ?: '—' }}
                        </div>

                        <div class="col-3">
                            <i class="bi bi-telephone-fill"></i>
                            <strong>Llamadas {{ $llamadas['total'] }} :</strong><br>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            {{ $llamadas['exitosas'] }}
                            <i class="bi bi-x-circle-fill text-danger"></i>
                            {{ $llamadas['fallidas'] }}
                        </div>

                        <div class="col-3">
                            <i class="bi bi-person-circle"></i>
                            <strong>Usuario ID:</strong><br>
                            {{  $cabecera->user_nombres  }}
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
    @endif
    
    <div class="row">
        <div class="col-6">
            <div class="card bg-primary text-white  border border-white">
                <div class="card-body text-center">
                    <i class="bi bi-person-badge-fill fs-1"></i>
                    <h6 class="card-title">Conductores Únicos</h6>
                    <h2>{{$total_conductores }}</h2>
                </div>
            </div>
        </div>

        <div class="col-6" >
            <div class="card bg-warning text-white  border border-white">
                <div class="card-body text-center">
                    <i class="bi bi-truck-front-fill fs-1"></i>
                    <h6 class="card-title">Transportistas Únicos</h6>
                    <h2>{{ $total_trts}}</h2>
                </div>
            </div>
        </div>

        <div class='col-12 py-3'>
            <div class="card bg-dark text-light border-0 p-3">
                <div class="d-flex align-items-center justify-content-between">

                    <div class="d-flex align-items-center">
                        <i class="bi bi-robot fs-2 me-3"></i>
                        <div>
                            <h5 class="mb-1">Prompt IA</h5>
                            <small class="d-block p-2">
                            <i class="bi bi-telephone-fill"></i>
                            Total Llamadas: {{ $llamadas['total'] }} <br>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            Total Llamadas Exitosas: {{ $llamadas ['exitosas']}} <br>
                            <i class="bi bi-person-badge-fill"></i>
                            Total Conductores: {{ $total_conductores }} <br>
                            <i class="bi bi-truck-front-fill"></i>
                            Total Transportistas: {{ $total_trts }} <br>
                            </small>
                        </div>
                    </div>

                    <button onclick="copiarPrompt()" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-clipboard"></i> Copiar
                    </button>

                </div>
            </div>
        </div>




    </div>




    <div class="row">
        <div class="col-6">
            <div class="card">
                <div class="card-body">
                    <table class="table table-bordered table-hover table-sm table-dark">
                        <thead class="table-primary">
                            <tr>
                                <th>#</th>
                                <th>Conductor</th>
                                <th>Teléfono</th>
                            </tr>
                        </thead>
                        <tbody>

                        @forelse($conductores as $i => $row)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $row->telefono }}</td>
                                <td>{{ $row->conductor }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">
                                    No se encontraron conductores
                                </td>
                            </tr>
                        @endforelse

                        </tbody>
                    </table>

                </div>
            </div>

        </div>
        <div class="col-6">
            <div class="card">
                <div class="card-body">
                    <table class="table table-bordered table-hover table-sm table-dark">
                        <thead class="table-primary">
                            <tr>
                                <th>#</th>
                                <th>Transportista</th>
                            </tr>
                        </thead>
                        <tbody>

                        @forelse($trts as $i => $row)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $row->transportista }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">
                                    No se encontraron transportistas
                                </td>
                            </tr>
                        @endforelse

                        </tbody>
                    </table>

                </div>
            </div>

        </div>

    </div>




</div>

@endsection

@section('scripts')
<script>

    const prompt = `
    Llamadas Totales: ${ {{ $llamadas['total'] }} }
    Llamadas Exitosas: ${ {{ $llamadas['exitosas'] }} }
    Conductores Únicos: ${ {{ $total_conductores }} }
    Transportistas Únicos: ${ {{ $total_trts }} }
    `;

    function copiarPrompt() {
        copiarTexto(prompt);
    }

</script>
@endsection