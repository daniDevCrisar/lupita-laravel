@extends('layouts.app')

@section('title', 'Conductores Duplicados')

@section('content')

@php
$total_conductores = count($conductores);
$total_trts = count($trts);
@endphp

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
            <a href="{{ url('/importar/excel/'.$lote_id . '/procesar') }}" class="btn btn-success btn-sm">
                Procesar <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </div>

    @if($cabecera)
    <div class="row mb-3">
        <div class="col-12">
            <div class="card bg-secondary text-white border-0">
                <div class="card-body">
                    <div class="col-12">

                        <h5 class="card-title mb-3">Información del Lote</h5>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <i class="bi bi-file-earmark-text"></i>
                            <strong>Archivo:</strong><br>
                            {{ $cabecera->nombre }}
                        </div>
                        <div class="col-md-3">
                            <i class="bi bi-chat"></i>
                            <strong>Comentario:</strong><br>
                            {{ $cabecera->comentario }}
                        </div>
                        <div class="col-md-3">
                            <strong>Procesado:</strong><br>
                            @if($cabecera->procesado)
                                <span class="text-success">SI</span>
                            @else
                                <span class="text-danger">NO</span>
                            @endif

                        </div>

                        <div class="col-md-3">
                            <i class="bi bi-calendar-date"></i>
                            <strong>Fecha de Creacion</strong><br>
                            {{ $cabecera->created_at ?: '—' }}
                        </div>

                        <div class="col-md-3">
                            <i class="bi bi-telephone-fill"></i>
                            <strong>Llamadas {{ $llamadas['total'] }} :</strong><br>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            {{ $llamadas['exitosas'] }}
                            <i class="bi bi-x-circle-fill text-danger"></i>
                            {{ $llamadas['fallidas'] }}
                        </div>

                        <div class="col-md-3">
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
        <div class="col-12 table-responsive" style="max-height: 800px; overflow-y: auto;">
            <table class="table table-bordered table-hover table-sm table-dark">
                <thead class="table-primary">
                    <tr>
                        <th>vapi_id</th>
                        <th>type</th>
                        <th>created_at</th>
                        <th>created_at_excel</th>
                        <th>llamada_tipo</th>
                        <th>ref</th>
                        <th>origen</th>
                        <th>destino</th>
                        <th>telefono</th>
                        <th>conductor</th>
                        <th>placa</th>
                        <th>fecha_prometida</th>
                        <th>mensajes_conten</th>
                        <th>audio</th>
                        <th>exitosa_segun_ia</th>
                        <th>entro_llamada</th>
                        <th>razon_finalizacion</th>
                        <th>razon_finalizacion_espanol</th>
                        <th>transportista</th>
                        <th>analisis_transcripcion</th>
                        <th>analisis_audio</th>
                        <th>conductor_confirma</th>
                        <th>buzon_de_voz</th>
                        <th>conductor_contesta_pero_no_habla</th>
                        <th>conductor_no_escucha</th>
                        <th>conductor_da_motivos</th>
                        <th>conductor_mala_senal</th>
                        <th>confusion_en_llamada</th>
                        <th>contesta_otra_persona</th>
                        <th>numero_equivocado</th>
                        <th>conversacion_fluida</th>
                        <th>llamada_interesante</th>
                        <th>ia_se_confunde</th>
                        <th>ia_no_escucha</th>
                        <th>ia_cambio_de_datos</th>
                        <th>ia_error_interpretacion</th>
                        <th>ia_dice_variable</th>
                        <th>ia_mala_pronunciacion</th>
                        <th>conductor_cuelga</th>
                        <th>conductor_no_contesta</th>
                        <th>conductor_conducta_inapropiada</th>
                        <th>error_tecnico_llamada</th>
                        <th>error_audio</th>
                        <th>error_origen</th>
                        <th>llamada_exitosa</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($llamadas['detalle'] as $row)
                <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                    <td>{{ $row->vapi_id }}</td>
                    <td>{{ $row->type }}</td>
                    <td>{{ $row->created_at }}</td>
                    <td>{{ $row->created_at_excel }}</td>
                    <td>{{ $row->llamada_tipo }}</td>
                    <td>{{ $row->ref }}</td>
                    <td>{{ $row->origen }}</td>
                    <td>{{ $row->destino }}</td>
                    <td>{{ $row->telefono }}</td>
                    <td>{{ $row->conductor }}</td>
                    <td>{{ $row->placa }}</td>
                    <td>{{ $row->fecha_prometida }}</td>
                    <td>{{ substr($row->mensajes_conten,0,35) }}....</td>
                    <td>{{ $row->audio }}</td>
                    <td>{{ $row->exitosa_segun_ia }}</td>
                    <td>{{ $row->entro_llamada }}</td>
                    <td>{{ $row->razon_finalizacion }}</td>
                    <td>{{ $row->razon_finalizacion_espanol }}</td>
                    <td>{{ $row->transportista }}</td>
                    <td>{{ $row->analisis_transcripcion }}</td>
                    <td>{{ $row->analisis_audio }}</td>
                    <td>{{ $row->conductor_confirma }}</td>
                    <td>{{ $row->buzon_de_voz }}</td>
                    <td>{{ $row->conductor_contesta_pero_no_habla }}</td>
                    <td>{{ $row->conductor_no_escucha }}</td>
                    <td>{{ $row->conductor_da_motivos }}</td>
                    <td>{{ $row->conductor_mala_senal }}</td>
                    <td>{{ $row->confusion_en_llamada }}</td>
                    <td>{{ $row->contesta_otra_persona }}</td>
                    <td>{{ $row->numero_equivocado }}</td>
                    <td>{{ $row->conversacion_fluida }}</td>
                    <td>{{ $row->llamada_interesante }}</td>
                    <td>{{ $row->ia_se_confunde }}</td>
                    <td>{{ $row->ia_no_escucha }}</td>
                    <td>{{ $row->ia_cambio_de_datos }}</td>
                    <td>{{ $row->ia_error_interpretacion }}</td>
                    <td>{{ $row->ia_dice_variable }}</td>
                    <td>{{ $row->ia_mala_pronunciacion }}</td>
                    <td>{{ $row->conductor_cuelga }}</td>
                    <td>{{ $row->conductor_no_contesta }}</td>
                    <td>{{ $row->conductor_conducta_inapropiada }}</td>
                    <td>{{ $row->error_tecnico_llamada }}</td>
                    <td>{{ $row->error_audio }}</td>
                    <td>{{ $row->error_origen }}</td>
                    <td>{{ $row->llamada_exitosa }}</td>
                </tr>
                @endforeach
                </tbody>

            </table>

        </div>


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
                            <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
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
