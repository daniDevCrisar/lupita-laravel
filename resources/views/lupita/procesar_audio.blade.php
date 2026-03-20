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

        <div class="col-6">
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
                        <input type="hidden" id="lista_{{$loop->index}}_id" value="{{ $row->vapi_id }}">
                        <input type="hidden" id="lista_{{$loop->index}}_conductor" value="{{ $row->conductor }}">
                        <input type="hidden" id="lista_{{$loop->index}}_telefono" value="{{ $row->telefono }}">
                        <input type="hidden" id="lista_{{$loop->index}}_ref" value="{{ $row->ref }}">
                        <input type="hidden" id="lista_{{$loop->index}}_placa" value="{{ $row->placa }}">
                        <input type="hidden" id="lista_{{$loop->index}}_viaje" value="{{ $row->origen . ' - '. $row->destino }}">
                        <input type="hidden" id="lista_{{$loop->index}}_audio" value="{{ $row->audio_link }}">
                        <input type="hidden" id="lista_{{$loop->index}}_audio_duracion" value="{{ $llamadas::audio_duracion_format($row->audio_duracion) }}">
                        <input type="hidden" id="lista_{{$loop->index}}_analisis_transcripcion" value="{{ $row->analisis_transcripcion }}">
                        <input type="hidden" id="lista_{{$loop->index}}_analisis_audio" value="{{ $row->analisis_audio }}">
                        <input type="hidden" id="lista_{{$loop->index}}_razon_f"
                               value="{{ $llamadas::$razones_finalizacion[$row->razon_finalizacion_id]->codigo }}">
                        <input type="hidden" id="lista_{{$loop->index}}_razon_id"
                               value="{{ $row->razon_finalizacion_id }}">
                        <input type="hidden" id="lista_{{$loop->index}}_error_origen" value="{{ $row->error_origen }}">
                        <input type="hidden" id="lista_{{$loop->index}}_llamada_exitosa" value="{{ $row->llamada_exitosa }}">
                        @php $orden=$loop->index @endphp
                        @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                            @if($item[4])
                                <input type="hidden" name="lista_{{$orden. '_' . $key }}" value="{{ $row->$key }}">
                            @endif
                        @endforeach

                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }} small">
                            <td>
                                <span style="font-size: 0.8em;" id="lista_{{$loop->index}}_id_html">
                                <i class="bi bi-telephone-outbound {{ $row->entro_llamada ? 'text-success': '' }}"></i>
                                    {{ $row->vapi_id }}
                                @if ($row->exitosa_segun_ia)
                                    <i class="bi bi-robot text-success"></i><i class="bi bi-check-lg text-success"></i>
                                @endif
                                </span>

                            </td>
                            <td>{{ $llamadas::format_fecha($row->created_at) }}
                                <button class="btn btn-outline-success" onclick="selLlamada({{$loop->index}})">
                                    <i class="bi bi-play-fill me-1"></i> etit
                                </button>
                            </td>
                            <td id="lista_{{$loop->index}}_tipol_html"><i class="{{ $llamadas::tipos_l($row->llamada_tipo_id,'icon') }}"></i>
                                {{ $llamadas::tipos_l($row->llamada_tipo_id) }}</td>
                            <td id="lista_{{$loop->index}}_ref_html">
                                @if($row->ref)
                                    {{ $row->ref }}<br>
                                @endif
                                @if ($row->origen.$row->destino !='')
                                    <i class="bi bi-airplane"></i> {{ $row->origen }}-{{ $row->destino }} <br>
                                @endif
                                <i class="bi bi-card-text"></i> {{ $row->placa }} <br>
                            </td>
                            <td>
                                <span id="lista_{{$loop->index}}_telefono_html">
                                    <i class="bi bi-telephone"></i> {{ $row->telefono }}
                                </span><br>
                                <span id="lista_{{$loop->index}}_conductor_html">
                                <i class="bi bi-person"></i> {{$row->conductor }} (#{{ $row->conductor_id }})
                                </span><br>

                                @if( $row->trt)
                                    <i class="bi-building"></i> {{ $row->trt }} (#{{ $row->trt_id }})

                                @endif
                            </td>
                            <td>
                                @if ($row->entro_llamada)
                                    Duracion: {{$row->audio_duracion}} seg
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


        <div class="col-6">
            <div class="card mb-3 border-secondary col-12">
                <div class="card-header bg-primary">
                    <h5>
                        <i class="bi bi-play-circle me-2"></i>Llamada
                    </h5>

                    <span style="font-size: 0.8em;" id="card_id_html">
                    <i class="bi bi-telephone-outbound text-success"></i> 019CE03C-DFA1-7BBD-90DB-39A91224DDF3
                    <i class="bi bi-robot text-success"></i><i class="bi bi-check-lg text-success"></i>
                    </span>

                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small" id="card_conductor_html"><i class="bi bi-telephone"></i> 51936924257 <i class="bi bi-person"></i> JESUS NARRO</li>
                    <li class="list-group-item small"
                    id="card_ref_html">Ref: 967103
                        <i class="bi bi-airplane"></i> HUARAL-CHINCHA
                        <i class="bi bi-card-text"></i> T3P946
                    </li>
                    <li class="list-group-item small" id="card_tipol_html"><i class="bi-building"></i>Dentro de planta</li>
                </ul>
                <div class="card-body">
                    <audio id="mainAudio" controls class="w-100">
                        <source id="audioSource" src="" type="audio/mpeg">
                        Tu navegador no soporta audio.
                    </audio>
                    <div class="d-flex justify-content-center align-items-center gap-3" style="height:40px;">
                        <button class="btn btn-outline-info btn-circular">
                            <i class="bi bi-skip-backward-fill"></i>
                        </button>

                        <button class="btn btn-outline-success btn-circular">
                            <i class="bi bi-chat-fill"></i>
                        </button>

                        <button class="btn btn-outline-info btn-circular">
                            <i class="bi bi-skip-forward-fill"></i>
                        </button>

                        <button class="btn btn-outline-light btn-circular">
                            <i class="bi bi-floppy-fill"></i>
                        </button>

                        <button class="btn btn-outline-info btn-circular">
                            <i class="bi bi-gear-fill"></i>
                        </button>

                    </div>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small">Duracion: <span class="fw-bold text-info" id="card_audio_duracion">audio_duracion_format</span></li>
                    <li class="list-group-item text-info small">IA-FINALIZA-LLAMADA</li>
                    <li class="list-group-item small">
                        Analisis de transcripcion: <span class="text-danger">BUZON</span>
                    </li>
                </ul>

                <div class="card-body">
                    <div class="row">
                        <div class="input-group col-12">
                            <input class="form-control bg-secondary text-success" type="text" placeholder="Analisis de audio" value=""
                                   name="txt_audio" id="txt_audio" list="audio_list">
                            <datalist id="audio_list">
                                <option value="Manzana">
                                <option value="Banana">
                                <option value="Naranja">
                                <option value="Fresa">
                                <option value="Mango">
                            </datalist>
                            <button class="btn btn-primary" type="button" id="button-addon2"><i class="bi bi-floppy"></i></button>
                        </div>
                        <div class="btn-group col-12 pb-2" role="group">
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

                        <button id="btn3" type="button" class="btn btn-outline-success btn-sm">
                            dddd
                        </button>
                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==1)
                                    <button type="button" class="btn btn-outline-light btn-sm ">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==2)
                                    <button type="button" class="btn btn-outline-light btn-sm ">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>

                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==3)
                                    <button type="button" class="btn btn-outline-light btn-sm ">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <p id='audio_texto'>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-12">{{ $llamadas::$lista->links() }}</div>


    </div>

    <style>
        .bg-activo {
            color: white !important;
        }
        .btn-circular{
            width:40px;
            height:40px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:22px;
        }
    </style>
@endsection
@section('scripts')

    @livewireScripts
    <script>
        const btn = document.getElementById("btn3");

        btn.addEventListener("click", () => {

            btn.classList.toggle("bg-activo");
            btn.classList.toggle("bg-primary");
        });


        function selLlamada(orden){
            const card_id_html=document.getElementById('card_id_html');
            const card_conductor_html=document.getElementById('card_conductor_html');
            const card_ref_html=document.getElementById('card_ref_html');
            const card_tipol_html=document.getElementById('card_tipol_html');
            const card_audio_duracion=document.getElementById('card_audio_duracion');

            let conten='';

            card_id_html.innerHTML=document.getElementById('lista_' + orden+'_id_html').innerHTML;
            card_conductor_html.innerHTML=
                document.getElementById('lista_' + orden+'_telefono_html').innerHTML + document.getElementById('lista_' + orden+'_conductor_html').innerHTML;

            conten=document.getElementById('lista_' + orden+'_ref_html').innerHTML;
            conten=conten.replaceAll('<br>',' ');
            card_ref_html.innerHTML= 'Ref:'+ conten;
            card_tipol_html.innerHTML= document.getElementById('lista_' + orden+'_tipol_html').innerHTML;
            card_audio_duracion.innerHTML= document.getElementById('lista_' + orden+'_audio_duracion').value;

        }


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
