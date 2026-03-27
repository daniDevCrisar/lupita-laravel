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
                        <input type="hidden" id="lista_{{$loop->index}}_contesta" value="{{ $row->entro_llamada }}">
                        <input type="hidden" id="lista_{{$loop->index}}_conductor" value="{{ $row->conductor }}">
                        <input type="hidden" id="lista_{{$loop->index}}_telefono" value="{{ $row->telefono }}">
                        <input type="hidden" id="lista_{{$loop->index}}_ref" value="{{ $row->ref }}">
                        <input type="hidden" id="lista_{{$loop->index}}_placa" value="{{ $row->placa }}">
                        <input type="hidden" id="lista_{{$loop->index}}_viaje" value="{{ $row->origen . ' - '. $row->destino }}">
                        <input type="hidden" id="lista_{{$loop->index}}_audio" value="{{ $row->audio_link }}">
                        <input type="hidden" id="lista_{{$loop->index}}_audio_duracion" value="{{ $llamadas::audio_duracion_format($row->audio_duracion) }}">
                        <input type="hidden" id="lista_{{$loop->index}}_razon_f"
                               value="{{ $llamadas::$razones_finalizacion[$row->razon_finalizacion_id]->codigo }}">
                        <input type="hidden" id="lista_{{$loop->index}}_razon_id"
                               value="{{ $row->razon_finalizacion_id }}">
                        <input type="hidden" id="lista_{{$loop->index}}_error_origen" value="{{ $row->error_origen }}">
                        <input type="hidden" id="lista_{{$loop->index}}_llamada_exitosa" value="{{ $row->llamada_exitosa }}">
                        <input type="hidden" id="lista_{{$loop->index}}_error_origen" value="{{ $row->error_origen }}">
                        @php $orden=$loop->index @endphp
                        @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                            @if($item[4])
                                <input type="hidden" id="lista_{{$orden. '_e_' . $key }}" value="{{ $row->$key }}">
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
                                    <button class="btn btn-outline-success"
                                    onclick="selLlamada({{$loop->index}})">
                                        <i class="bi bi-play-fill me-1"></i> Etiquetar
                                    </button>
                                @endif

                                <span class="text-danger" id="lista_{{$loop->index}}_analisis_t">{{ $row->analisis_transcripcion }}</span> <br>
                                <span class="text-success" id="lista_{{$loop->index}}_analisis_a">{{ $row->analisis_audio }}</span>
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

{{-- --------------------------ETIQUETADO------------------------ --}}

        <div class="col-6">
            {{--    ALERTAS    --}}
            <div class="col-12" id="div_alertas">
                <div class="alert alert-secondary border border-success text-white d-none" id='alerta_exito'>
                    <p id="alerta_exito_txt"></p>
                    <i class="bi bi-check-circle"></i>
                    Guardado con exito!
                </div>
                <div class="alert alert-danger text-white d-none" id='alerta_error'>
                    <i class="bi bi-x-circle"></i>
                    Error al procesar el archivo JSON.
                </div>
            </div>
            {{-- --------------------------ETIQUETADO------------------------ --}}
            <div class="card mb-3 border-secondary col-12">
                @csrf

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

                        <button class="btn btn-outline-success btn-circular"
                        onclick="abrirMensajes()">
                            <i class="bi bi-chat-fill"></i>
                        </button>

                        <button class="btn btn-outline-info btn-circular">
                            <i class="bi bi-skip-forward-fill"></i>
                        </button>

                        {{--   GUARDAR    --}}
                        <button class="btn btn-outline-light btn-circular" id="btn_guardar" onclick="guardar_etiqueta()">
                            <i class="bi bi-floppy-fill"></i>
                        </button>

                        <button class="btn btn-outline-info btn-circular">
                            <i class="bi bi-gear-fill"></i>
                        </button>

                    </div>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small">Duracion: <span class="fw-bold text-info" id="card_audio_duracion">audio_duracion_format</span></li>
                    <li class="list-group-item text-info small" id="card_razon_f">IA-FINALIZA-LLAMADA</li>
                    <li class="list-group-item small">
                        Analisis de transcripcion: <span class="text-danger" id="card_analisis_t">BUZON</span>
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
                            <input type="radio" class="btn-check" name="e_exitosa" id="e_rd_ex_0" value="exito">
                            <label class="btn btn-outline-primary" for="e_rd_ex_0">
                                <i class="bi bi-check-lg text-success"></i></label>
                            @foreach($llamadas::$error_origen as $item)
                                <input type="radio"
                                       class="btn-check"
                                       name="e_exitosa"
                                       id="e_rd_ex_{{ $loop->index + 1 }}"
                                       value="{{ $item->id }}">
                                <label class="btn btn-outline-primary"
                                       for="e_rd_ex_{{ $loop->index +1}}">
                                    <i class="{{ $llamadas::icon_exito($item->id, true) }}"></i>
                                </label>
                            @endforeach
                        </div>

                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==1)
                                    <button type="button" class="btn btn-outline-light btn-sm "
                                    id="e_{{$key}}" onclick="etiquetaClick('e_{{ $key }}')">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==2)
                                    <button type="button" class="btn btn-outline-light btn-sm "
                                    id="e_{{$key}}" onclick="etiquetaClick('e_{{ $key }}')">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>

                        <div class="btn-group-vertical col-4">
                            @foreach($llamadas::$etiquetas_icon_bi as $key => $item)
                                @if($item[4]==3)
                                    <button type="button" class="btn btn-outline-light btn-sm "
                                    id="e_{{$key}}" onclick="etiquetaClick('e_{{ $key }}')">
                                        <i class="{{$item[0]}}"></i> {{$item[1]}}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-12">{{ $llamadas::$lista->links() }}</div>


    </div>

    <div id="overlayGuardando" class="position-fixed top-0 start-0 w-100 h-100 d-none"
         style="background: rgba(0,0,0,0.5); z-index:9999;">

        <div class="d-flex justify-content-center align-items-center h-100 flex-column">
            <div class="spinner-border text-light"></div>
            <div class="text-white mt-2">Guardando...</div>
        </div>
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
        let orden_lista,vapi_id;

        const card_id_html=document.getElementById('card_id_html');
        const card_conductor_html=document.getElementById('card_conductor_html');

        function etiquetaClick(id){
            const btn = document.getElementById(id);
            btn.classList.toggle("bg-activo");
            btn.classList.toggle("bg-primary");
        }

        function checkedRadio_exito(exito,error_origen){
            const radio =document.querySelectorAll('input[name="e_exitosa"]');
            radio.forEach(r => {
                r.checked = false;
            });
            if (exito==='1') document.querySelector(`input[name="e_exitosa"][value="exito"]`).checked=true;
            else {
                radio.forEach(r => {
                    r.checked = r.value === error_origen;
                });
            }
        }

        function disabledRadio_exito(exito,error_origen){
            const radio =document.querySelectorAll('input[name="e_exitosa"]');
            const r_exito = document.querySelector(`input[name="e_exitosa"][value="exito"]`);
            const r_ia = document.querySelector(`input[name="e_exitosa"][value="1"]`);
            const r_conductor = document.querySelector(`input[name="e_exitosa"][value="0"]`);

            radio.forEach(r => {
                r.disabled = true;
                r.checked = false;
            });
            if (error_origen !== '0' && error_origen !== '1'){
                console.log(error_origen);
                radio.forEach(r => {
                    r.disabled = !(r.value === error_origen);
                    r.checked = r.value === error_origen;
                });
            } else {
                console.log('dadad')
                r_exito.disabled=false;
                r_ia.disabled=false;
                r_conductor.disabled=false;
                if (exito==='1'){
                    r_exito.checked = true;
                }else {
                    if (error_origen==='0')
                        r_conductor.checked=true
                    else
                        r_ia.checked=true
                }
            }
        }

        function colorearBoton(id,valor){
            const btn = document.getElementById(id);
            const sel = document.getElementById(valor);
            if (sel.value==='1'){
                btn.classList.add("bg-activo");
                btn.classList.add("bg-primary");
            }
            else {
                btn.classList.remove("bg-activo");
                btn.classList.remove("bg-primary");
            }
        }

        function selLlamada(orden){
            const card_ref_html=document.getElementById('card_ref_html');
            const card_tipol_html=document.getElementById('card_tipol_html');
            const card_audio_duracion=document.getElementById('card_audio_duracion');
            const card_razon_f=document.getElementById('card_razon_f');
            const card_analisis_t=document.getElementById('card_analisis_t');
            const txt_audio=document.getElementById('txt_audio');

            const error_origen = document.getElementById('lista_' + orden+'_error_origen').value;
            const llamada_exitosa = document.getElementById('lista_' + orden+'_llamada_exitosa').value;

            let conten='';
            card_id_html.innerHTML=document.getElementById('lista_' + orden+'_id_html').innerHTML;
            card_conductor_html.innerHTML= document.getElementById('lista_' + orden+'_telefono_html').innerHTML + document.getElementById('lista_' + orden+'_conductor_html').innerHTML;

            conten=document.getElementById('lista_' + orden+'_ref_html').innerHTML;
            conten=conten.replaceAll('<br>',' ');
            card_ref_html.innerHTML= 'Ref:'+ conten;
            card_tipol_html.innerHTML= document.getElementById('lista_' + orden+'_tipol_html').innerHTML;
            card_audio_duracion.innerHTML= document.getElementById('lista_' + orden+'_audio_duracion').value;
            card_razon_f.innerHTML= document.getElementById('lista_' + orden+'_razon_f').value;
            card_analisis_t.innerHTML= document.getElementById('lista_' + orden+'_analisis_t').innerHTML;
            txt_audio.value= document.getElementById('lista_' + orden+'_analisis_a').innerHTML;

            //colorear las etiquetas(button)
            document.querySelectorAll('button[id^="e_"]').forEach(el => {
                colorearBoton(el.id,'lista_' + orden+'_' + el.id);
            });
            //disabledRadio_exito(llamada_exitosa,error_origen);
            checkedRadio_exito(llamada_exitosa,error_origen);

            orden_lista=orden;
            vapi_id= document.getElementById('lista_' + orden+'_id').value.trim();
            playAudio()
        }

        function guardar_etiqueta(){
            const formData = new FormData();
            const guardando = document.getElementById('overlayGuardando');
            let json_result;
            let alerta_exito = document.getElementById('alerta_exito');
            let alerta_error = document.getElementById('alerta_error');

            formData.append('exito',
                document.querySelector('input[name="e_exitosa"]:checked')?.value);
            document.querySelectorAll('button[id^="e_"]').forEach(el => {
                let valor=0;
                if (el.classList.contains("bg-activo")) valor=1
                // eliminar el e_ en el id del botoon psaa dejar el nombre de etiqueta
                formData.append(el.id.substring(2),valor)
            });
            formData.append('vapi_id',vapi_id);
            formData.append('analisis_audio',document.getElementById('txt_audio').value);

            guardando.classList.remove('d-none');
            fetch('{{ route('lupita.audio.guardar') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    //'Accept': 'application/json'
                },
                body: formData
            }).then(res => res.json()) // 🔥 conviertes a JSON
            .then(data => {
                    json_result = data; // ✅ ahora sí es el JSON real
            }).catch(err => {
                guardando.classList.add('d-none');
                alerta_exito.classList.add('d-none');
                alerta_error.classList.remove('d-none');
                setTimeout(() => {
                    alerta_error.classList.add('d-none');
                }, 10000);
            }).finally(() => {
                guardando.classList.add('d-none');
                alerta_exito.classList.remove('d-none');
                alerta_error.classList.add('d-none');
                document.getElementById('alerta_exito_txt').innerHTML=
                    card_id_html.innerHTML + '<br>' +
                    card_conductor_html.innerHTML;

                setTimeout(() => {
                    alerta_exito.classList.add('d-none');
                }, 10000);
                console.log(json_result)
            });
        }

        function abrirMensajes(){
            let parametros={
                telefono: document.getElementById('lista_' + orden_lista+'_telefono').value,
                nombre: document.getElementById('lista_' + orden_lista +'_conductor').value,
                'vapi_id' : vapi_id
            }

            Livewire.dispatch('abrirMensaje',parametros);
        }


        function playAudio() {
            let url=document.getElementById('lista_' + orden_lista+'_audio').value;
            if (!url) return false;
            const audio = document.getElementById('mainAudio');
            if (!audio.paused) {
                audio.pause();
            }
            audio.src = url.toLowerCase();
            audio.play().catch(() => {});
        }
    </script>
@endsection
