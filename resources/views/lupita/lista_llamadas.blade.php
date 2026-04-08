@extends('layouts.app')

@section('title', 'Inicio')

@section('heads')
    @livewireStyles
@endsection

@section('content')

<livewire:mensajes-llamada />

<style>
    /* CSS compacto */

    .acordeon-mini .accordion-button{padding:6px 10px!important;font-size:1rem!important;}
    .acordeon-mini .accordion-body{padding:8px 10px!important;}
    .acordeon-mini .accordion-item{margin-bottom:4px;border-radius:5px;}
    .acordeon-mini .accordion-button::after{background-size:.75rem;width:.75rem;height:.75rem;}

    /* Grid de botones */
    .grid-botones{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;}

    /* Ocultar checkbox original */
    .btn-checkbox{display:none;}

    /* Estilo de botón usando clases de Bootstrap */
    .btn-opcion{
        display:block;
        text-align:center;
        padding:6px 8px;
        font-size:.7rem;
        font-weight:500;
        cursor:pointer;
        transition:all 0.15s;
    }

    /* Checkbox chequeado -> botón primary activo */
    .btn-checkbox:checked + .btn-opcion{
        background-color:var(--bs-primary);
        border-color:var(--bs-primary);
        color:white;
    }

    .btn-mini{padding:3px 8px;font-size:.65rem;border-radius:3px;}
</style>



<div class="row">
    <div class="col-12">
        <h1>Lista de Llamadas</h1>
    </div>
</div>

<div class="row">
    <form method="GET">
        <fieldset class="border p-3 rounded mb-3">
            <legend class="float-none w-auto px-2 fs-6">
                Filtros de búsqueda
            </legend>

            <div class="row g-3">
                <div class="col-12">
                    <button
                        formmethod="GET"
                        formaction="{{ url('/lupita/reporte') }}"
                        formtarget="_blank"
                        class="btn btn-warning">
                        Reporte Top Todo
                    </button>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="llamada_tipo_id">Tipo de llamada</label>
                    <select name="llamada_tipo_id" id="llamada_tipo_id"
                            class="form-control">
                        <option value="" @selected((string) request('llamada_tipo_id')==='') >Todos</option>
                        @foreach($llamadas::$tipos_llamada as $item)
                            <option value="{{$item->id}}"
                                @selected(request('llamada_tipo_id') === (string) $item->id)>{{$item->nombre}}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                    <input type="date" id="fecha_inicio"
                           name="fecha_inicio"
                           value="{{ request('fecha_inicio') }}"
                           class="form-control">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="fecha_fin">Fecha fin</label>
                    <input type="date" id="fecha_fin"
                           name="fecha_fin"
                           value="{{ request('fecha_fin') }}"
                           class="form-control">
                </div>

                <div class="col-md-4">
                    <label for="conductor" class="form-label">
                        Conductor
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input type="text"
                               id="conductor"
                               name="conductor"
                               value="{{ request('conductor') }}"
                               class="form-control"
                               placeholder="Conductor, id...">
                    </div>
                </div>

                <div class="col-md-4">
                    <label for="trt" class="form-label">
                        Transportista
                    </label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-building"></i>
                        </span>
                        <input type="text"
                               id="trt"
                               name="trt"
                               value="{{ request('trt') }}"
                               class="form-control"
                               placeholder="Transportista, id...">
                    </div>
                </div>

                <div class="col-md-4">
                    <label for="trt" class="form-label">
                        Evaluacion
                    </label>
                    <div class="input-group">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="exitosa" id="rd_ex_1" value=""
                                @checked((string)request('exitosa') === '')>
                            <label class="btn btn-outline-primary" for="rd_ex_1">Todo</label>
                            <input type="radio" class="btn-check" name="exitosa" id="rd_ex_2" value="exito"
                                @checked(request('exitosa') === 'exito')>
                            <label class="btn btn-outline-primary" for="rd_ex_2">
                                <i class="bi bi-check-lg text-success"></i></label>
                            @foreach($llamadas::$error_origen as $item)
                                <input type="radio"
                                       class="btn-check"
                                       name="exitosa"
                                       id="rd_ex_{{ $loop->index + 3 }}"
                                       value="{{ $item->id }}"
                                        @checked(request('exitosa') === (string) $item->id)>
                                <label class="btn btn-outline-primary"
                                       for="rd_ex_{{ $loop->index +3}}">
                                    <i class="{{ $llamadas::icon_exito($item->id, true) }}"></i>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Acordeón mini -->
                <div class="col-md-4">
                    <label for="trt" class="form-label">
                        Etiquetas:
                    </label>
                    <div class="acordeon-mini">
                        <div class="accordion" id="acordeon_etiquetas">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMini">
                                        <i class="bi bi-tags"></i> <span id="acordeon_head"></span>
                                    </button>

                                </h2>
                                <div id="collapseMini" class="accordion-collapse collapse " data-bs-parent="#acordeonMini">
                                    <div class="accordion-body">
                                        <div class="btn-group btn-group-sm mb-2" role="group">
                                            <input type="radio" class="btn-check" name="e_operador" id="opTodas" value="" @checked(!request('e_operador'))>
                                            <label class="btn btn-outline-primary" for="opTodas"><i class="bi bi-check-all"></i> Todas</label>

                                            <input type="radio" class="btn-check" name="e_operador" id="opAlMenosUna" value="1" @checked(request('e_operador'))>
                                            <label class="btn btn-outline-primary" for="opAlMenosUna"><i class="bi bi-check"></i> Al menos una</label>
                                        </div>
                                        <!-- Grid de botones con colores -->
                                        <div class="grid-botones">
                                            <!-- Frontend - Primary (azul) -->
                                            @php
                                            $etiquetas=request('etiquetas',[]);
                                            @endphp
                                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                                @if($item[4]!=0)
                                                    <div>
                                                        <input type="checkbox" class="btn-checkbox btn-frontend" name="etiquetas[]" value="{{$key}}" id="{{$key}}"
                                                        @checked(in_array($key, $etiquetas)) >
                                                        <label for="{{$key}}" class="btn-opcion btn btn-outline-light w-100"><i class="{{$item[0]}}"></i> {{$item[1]}}</label>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>



                <div class="col-md-4 d-flex align-items-end">
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
                    <i class="bi bi-telephone-outbound {{ $row->entro_llamada ? 'text-success': '' }}"></i> <span class='small'>{{ $row->vapi_id }}</span>
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
                        </button> <br>
                        @if( $row->trt)

                            <button class="btn btn-outline-light">
                                <i class="bi bi-shop"></i> {{ $row->trt }} (#{{ $row->trt_id }})
                            </button>
                        @endif
                    </td>
                    <td>
                        @if ($row->entro_llamada)
                            <button class="btn btn-outline-success" onclick="playAudio('{{ $row->audio_link }}','{{ $row->telefono }}','{{ $row->conductor }}' )">
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

@livewireScripts
<script>
    function playAudio(url,tlf,nombres) {
        const audio = document.getElementById('mainAudio');
        const audio_texto= document.getElementById('audio_texto');
        if (!audio.paused) {
            audio.pause();
        }
        audio.src = url.toLowerCase();
        audio.play().catch(() => {});
        audio_texto.innerHTML = `
        <i class="bi bi-telephone"></i> ${tlf} <i class="bi bi-person"></i> ${nombres}
        `;
    }

    function actualizarHeader() {
        let seleccionadas = [];
        document.querySelectorAll('input[name="etiquetas[]"]:checked').forEach(cb => {
            let label = document.querySelector(`label[for="${cb.id}"]`);
            if(label) seleccionadas.push(label.innerText);
        });
        let operador= document.querySelector(('input[name="e_operador"]:checked'))
        let header = document.getElementById('acordeon_head');


        if(seleccionadas.length)
            header.innerHTML = ` ${seleccionadas.length} seleccionada${seleccionadas.length !== 1 ? 's' : ''}`;
        else
            header.innerHTML = 'sin seleccion';
    }

    // Eventos
    document.querySelectorAll('input[name="etiquetas[]"]').forEach(cb => {
        cb.addEventListener('change', actualizarHeader);
    });
    document.querySelectorAll('input[name="e_operador"]').forEach(rd => {
        rd.addEventListener('change', actualizarHeader);
    });

    // Ejecutar al inicio
    actualizarHeader();
</script>
@endsection
