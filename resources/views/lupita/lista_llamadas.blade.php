@extends('layouts.app')

@section('title', 'Inicio')

@section('heads')
    @livewireStyles
@endsection

@section('content')
    @include('lupita.resources.input_etiquetas_css')
    <livewire:mensajes-llamada/>


    <div class="row">
        <div class="col-12">
            <h1>Lista de Llamadas</h1>
        </div>
    </div>

    <div class="row">
        @include('lupita.resources.filtros_busqueda')


        <div class="col-12">{{ $llamadas::$lista->links() }}</div>
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-body">
                    <p id='audio_texto'>
                    </p>
                    <audio id="mainAudio" controls class="w-100">
                        <source id="audioSource" src="" type="audio/mpeg">
                        Tu navegador no soporta audio.
                    </audio>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>Id</th>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Ref</th>
                        <th>Datos</th>
                        <th>Llamada</th>
                        <th>Etiquetas</th>
                        <th>Finalizacion</th>
                        <th>Exitosa</th>
                    </tr>
                    </thead>
                    <tbody>


                    @foreach($llamadas::$lista as $row)
                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                            <td>
                                <i class="bi bi-telephone-outbound {{ $row->entro_llamada ? 'text-success': '' }}"></i>
                                <span class='small'>{{ $row->vapi_id }}</span>
                                @if ($row->exitosa_segun_ia)
                                    <i class="bi bi-robot text-success"></i><i class="bi bi-check-lg text-success"></i>
                                @endif

                            </td>
                            <td>{{ $llamadas::format_fecha($row->created_at) }}</td>
                            <td><i class="{{ $llamadas::tipos_l($row->llamada_tipo_id,'icon') }}"></i>
                                {{ $llamadas::tipos_l($row->llamada_tipo_id) }}</td>
                            <td>
                                @if($row->ref)
                                    <button type="button" class="btn btn-outline-info">{{ $row->ref }}</button><br>
                                @endif
                                @if ($row->origen.$row->destino !='')
                                    <i class="bi bi-airplane"></i> {{ $row->origen }}-{{ $row->destino }} <br>
                                @endif
                                <i class="bi bi-card-text"></i> {{ $row->placa }} <br>
                            </td>
                            <td>
                                <i class="bi bi-telephone"></i> {{ $row->telefono }} <br>

                                <button class="btn btn-outline-light mb-1">
                                    <i class="bi bi-person"></i> {{$row->conductor }} (#{{ $row->conductor_id }})
                                </button>
                                <br>
                                @if( $row->trt)

                                    <button class="btn btn-outline-light">
                                        <i class="bi bi-shop"></i> {{ $row->trt }} (#{{ $row->trt_id }})
                                    </button>
                                @endif
                            </td>
                            <td>
                                @if ($row->entro_llamada)
                                    <button class="btn btn-outline-success"
                                            onclick="playAudio('{{ $row->audio_link }}','{{ $row->telefono }}','{{ $row->conductor }}' )">
                                        <i class="bi bi-play-fill me-1"></i> Reproducir
                                    </button>
                                    <button class="btn btn-outline-info"
                                            onclick="Livewire.dispatch('abrirMensaje',{
                                telefono: '{{ $row->telefono }}',
                                nombre: '{{ $row->conductor }}',
                                vapi_id : '{{ $row->vapi_id }}'
                                })">
                                        <i class="bi bi-chat-dots-fill me-1"></i> Mensajes
                                    </button><br>
                                @endif

                                <span class="text-danger">{{ $row->analisis_transcripcion }}</span> <br>
                                <span class="text-success">{{ $row->analisis_audio }}</span>
                            </td>
                            <td>
                                {!! $llamadas::etiquetas_icon_bi($row) !!}

                            </td>
                            <td>
                                {{ $llamadas::razon_f($row->razon_finalizacion_id) }}
                            </td>
                            <td>
                                <i class="{{ $llamadas::icon_exito($row) }} fs-3"></i>
                            </td>
                        </tr>
                    @endforeach


                    </tbody>
                </table>

            </div>
        </div>
        <div class="col-12">{{ $llamadas::$lista->links() }}</div>


    </div>
@endsection
@section('scripts')

    @include('lupita.resources.input_etiquetas_js')
    @livewireScripts
    <script>
        function playAudio(url, tlf, nombres) {
            const audio = document.getElementById('mainAudio');
            const audio_texto = document.getElementById('audio_texto');
            if (!audio.paused) {
                audio.pause();
            }
            audio.src = url.toLowerCase();
            audio.play().catch(() => {
            });
            audio_texto.innerHTML = `
        <i class="bi bi-telephone"></i> ${tlf} <i class="bi bi-person"></i> ${nombres}
        `;
        }
    </script>
@endsection
