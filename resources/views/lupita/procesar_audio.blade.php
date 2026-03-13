@extends('layouts.app')

@section('title', 'Inicio')

@section('heads')
    @livewireStyles
@endsection

@section('content')

    <livewire:mensajes-llamada />

    <div class="row">
        <div class="col-12">
            <h1>Etiquetar Llamadas</h1>
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

        <div class="col-7">
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
                        <th>Exitosa</th>
                    </tr>
                    </thead>
                    <tbody>


                    @foreach($llamadas::$lista as $row)
                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }} small">
                            <td>

                                <i class="bi bi-telephone-outbound {{ $row->entro_llamada ? 'text-success': '' }}"></i> <span style="font-size: 0.8em;">{{ $row->vapi_id }}</span>
                                @if ($row->exitosa_segun_ia)
                                    <i class="bi bi-robot text-success"></i><i class="bi bi-check-lg text-success"></i>
                                @endif

                            </td>
                            <td>{{ $llamadas::format_fecha($row->created_at) }}</td>
                            <td><i class="{{ $llamadas::tipos_l($row->llamada_tipo_id,'icon') }}"></i>
                                {{ $llamadas::tipos_l($row->llamada_tipo_id) }}</td>
                            <td>
                                @if($row->ref)
                                    {{ $row->ref }}<br>
                                @endif
                                @if ($row->origen.$row->destino !='')
                                    <i class="bi bi-airplane"></i> {{ $row->origen }}-{{ $row->destino }} <br>
                                @endif
                                <i class="bi bi-card-text"></i> {{ $row->placa }} <br>
                            </td>
                            <td>
                                <i class="bi bi-telephone"></i> {{ $row->telefono }} <br>
                                <i class="bi bi-person"></i> {{$row->conductor }} (#{{ $row->conductor_id }})
                                <br>
                                @if( $row->trt)
                                    <i class="bi-building"></i> {{ $row->trt }} (#{{ $row->trt_id }})

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
                                <i class="{{ $llamadas::icon_exito($row) }} fs-3"></i>
                            </td>
                        </tr>
                    @endforeach


                    </tbody>
                </table>

            </div>
        </div>
        <div class="col-5">
            <div class="card mb-3 border-secondary col-12">
                <div class="card-header bg-primary">
                    <h4>
                        <i class="bi bi-play-circle me-2"></i>Llamada
                    </h4>

                    <span style="font-size: 0.8em;">
                    <i class="bi bi-telephone-outbound text-success"></i> 019CE03C-DFA1-7BBD-90DB-39A91224DDF3
                    <i class="bi bi-robot text-success"></i><i class="bi bi-check-lg text-success"></i>
                    </span>

                </div>
                <div class="card-body">

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item"><i class="bi bi-telephone"></i> 51936924257 <i class="bi bi-person"></i> JESUS NARRO</li>
                        <li class="list-group-item small">Ref: 967103 <br>
                            <i class="bi bi-airplane"></i> HUARAL-CHINCHA <br>
                            <i class="bi bi-card-text"></i> T3P946 <br>
                        </li>
                        <li class="list-group-item small"><i class="bi-building"></i>Dentro de planta</li>
                        <li class="list-group-item text-info">IA-FINALIZA-LLAMADA</li>

                    </ul>
                    <audio id="mainAudio" controls class="w-100">
                        <source id="audioSource" src="" type="audio/mpeg">
                        Tu navegador no soporta audio.
                    </audio>

                        <span class="input-group-text small">
                            Analisis Transcripcion
                        </span>
                        <input type="text" class="text-danger"
                               id="trt"
                               name="trt"
                               value="BUZON"
                               class="form-control"
                               placeholder="Transportista, id...">



                    <p id='audio_texto'>
                    </p>
                </div>
            </div>

            <style>
                .btn-circular{
                    width:60px;
                    height:60px;
                    border-radius:50%;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    font-size:24px;
                }
            </style>

            <div class="col-12 d-flex justify-content-center align-items-center gap-3" style="height:80px;">
                <button class="btn btn-outline-info btn-circular">
                    <i class="bi bi-skip-backward-fill"></i>
                </button>

                <button class="btn btn-outline-success btn-circular">
                    <i class="bi bi-play-fill"></i>
                </button>

                <button class="btn btn-outline-info btn-circular">
                    <i class="bi bi-skip-forward-fill"></i>
                </button>
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
    </script>
@endsection
