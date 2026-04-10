@extends('layouts.app')

@section('title', 'Inicio')

@section('heads')
    @livewireStyles
@endsection

@section('content')
    @include('lupita.resources.input_etiquetas_css')
    <livewire:mensajes-llamada />

    <div class="row">
        <div class="col-12">
            <h1>Etiquetar Llamadas</h1>
        </div>
    </div>

    <div class="row">
        @include('lupita.resources.filtros_busqueda')

        <div class="col-12">{{ $llamadas::$lista->links() }}</div>

        <div class="col-12  col-lg-6">
            <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>#</th>
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
                        @php
                            //listar llamadas para ayudar al etiquetado
                            $fila_inicial= ($llamadas::$lista->perPage() * ($llamadas::$lista->currentPage()-1) ) ;
                        @endphp

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

                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }} small" id="lista_{{$loop->index}}_tr">
                            <td id="lista_{{$loop->index}}_orden">{{ $fila_inicial+$loop->index+1 }}</td>
                            <td>
                                <span id="lista_{{$loop->index}}_id_html">
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
                                <span id="lista_{{ $loop->index }}_icon_exitosa_html">
                                <i class="{{ $llamadas::icon_exito($row) }} fs-3"></i>
                                </span>
                            </td>
                        </tr>
                    @endforeach


                    </tbody>
                </table>

            </div>
        </div>

{{-- --------------------------ETIQUETADO------------------------ --}}

        <div class="col-12 col-lg-6">
            {{--    ALERTAS    --}}
            <div class="col-12" id="div_alertas">
                <div class="alert alert-secondary border border-success text-white d-none" id='alerta_exito'>
                    <p id="alerta_exito_txt"></p>
                    <i class="bi bi-check-circle"></i>
                    Guardado con exito!
                </div>
                <div class="alert alert-danger text-white d-none" id='alerta_error'>
                    <i class="bi bi-x-circle"></i>
                    Error al guardar.
                </div>
            </div>
            {{--  icon_exito  --}}
            @foreach($llamadas::$iconos_exito as $key => $item)
                <span id="icon_exitosa_{{ $key }}" class="d-none"><i class="{{ $item }} fs-3"></i></span>
            @endforeach
            {{-- --------------------------ETIQUETADO------------------------ --}}
            <div class="card mb-3 border-secondary col-12">
                @csrf

                <div class="card-header bg-primary d-flex justify-content-between align-items-center">
                    <div>
                        <h5>
                            <i class="bi bi-play-circle me-2"></i>Llamada
                        </h5>
                        <span style="font-size: 0.8em;" id="card_id_html">Selecciona...
                        </span>
                    </div>
                    <div>
                        <h5 id="card_numero"></h5>
                    </div>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small" id="card_conductor_html">
                    </li>
                    <li class="list-group-item small" id="card_ref_html">
                    </li>
                    <li class="list-group-item small" id="card_tipol_html"></li>
                </ul>
                <div class="card-body">
                    <audio id="mainAudio" controls class="w-100">
                        <source id="audioSource" src="" type="audio/mpeg">
                        Tu navegador no soporta audio.
                    </audio>
                    <div class="d-flex justify-content-center align-items-center gap-3" style="height:40px;">
                        <button class="btn btn-outline-info btn-circular"
                        onclick="cambiarFila(-1)">
                            <i class="bi bi-skip-backward-fill"></i>
                        </button>

                        <button class="btn btn-outline-success btn-circular"
                        onclick="abrirMensajes()">
                            <i class="bi bi-chat-fill"></i>
                        </button>

                        <button class="btn btn-outline-info btn-circular"
                        onclick="cambiarFila(1)">
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
                    <li class="list-group-item small">Duracion: <span class="fw-bold text-info" id="card_audio_duracion"></span></li>
                    <li class="list-group-item text-info small" id="card_razon_f"></li>
                    <li class="list-group-item small">
                        Analisis de transcripcion: <span class="text-danger" id="card_analisis_t"></span>
                    </li>
                </ul>

                <div class="card-body">
                    <div class="row">
                        <div class="input-group col-12">
                            <input class="form-control bg-secondary text-success" type="text" placeholder="Analisis de audio" value=""
                                   name="txt_audio" id="txt_audio" list="audio_list" onchange="modifico=true">
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
                            <input type="radio" class="btn-check" name="e_exitosa" id="e_rd_ex_0" value="exito"
                            onchange="modifico=true">
                            <label class="btn btn-outline-primary" for="e_rd_ex_0">
                                <i class="bi bi-check-lg text-success"></i></label>
                            @foreach($llamadas::$error_origen as $item)
                                <input type="radio"
                                       class="btn-check"
                                       name="e_exitosa"
                                       id="e_rd_ex_{{ $loop->index + 1 }}"
                                       value="{{ $item->id }}" onchange="modifico=true">
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

        .tr-primary {
            background-color: var(--bs-primary) !important;
        }
        .tr-primary > td {
            background-color: var(--bs-primary) !important;
        }
    </style>
@endsection
@section('scripts')
    @livewireScripts
    @include('lupita.resources.input_etiquetas_js')
    <script>
        let orden_lista,vapi_id='',modifico=false ;

        let total_filas={{$llamadas::$lista->count()}}; //filas en esta pagina
        let total={{$llamadas::$lista->total()}}; //filas en el query
        let total_por_pagina={{$llamadas::$lista->perPage()}};
        let pagina_actual={{$llamadas::$lista->currentPage()}};
        let paginas_total={{$llamadas::$lista->lastPage()}}; //total de paginas


        const card_id_html=document.getElementById('card_id_html');
        const card_conductor_html=document.getElementById('card_conductor_html');
        const txt_audio=document.getElementById('txt_audio');

        function etiquetaClick(id){
            if (!vapi_id) return false;
            const btn = document.getElementById(id);
            btn.classList.toggle("bg-activo");
            btn.classList.toggle("bg-primary");
            modifico=true;
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

        let anterior_fila=-1;
        function selLlamada(orden){
            const error_origen = document.getElementById('lista_' + orden+'_error_origen').value;
            const llamada_exitosa = document.getElementById('lista_' + orden+'_llamada_exitosa').value;

            const card_ref_html=document.getElementById('card_ref_html');
            const card_tipol_html=document.getElementById('card_tipol_html');
            const card_audio_duracion=document.getElementById('card_audio_duracion');
            const card_razon_f=document.getElementById('card_razon_f');
            const card_analisis_t=document.getElementById('card_analisis_t');
            const card_numero=document.getElementById('card_numero');

            //lenar los datos
            let conten='';
            card_id_html.innerHTML=document.getElementById('lista_' + orden+'_id_html').innerHTML;
            card_numero.innerHTML='#'+document.getElementById('lista_' + orden+'_orden').innerHTML;
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
            modifico=false;

            document.getElementById('lista_' + orden + '_tr').classList.add('tr-primary');
            if (anterior_fila>=0){
                document.getElementById('lista_' + anterior_fila + '_tr').classList.remove('tr-primary')
            }
            anterior_fila=orden;
        }

        function guardar_etiqueta(){
            if(vapi_id==='' || !modifico) return false;
            let guardo=false;

            const formData = new FormData();
            const guardando = document.getElementById('overlayGuardando');
            let json_result;
            const alerta_exito = document.getElementById('alerta_exito');
            const alerta_error = document.getElementById('alerta_error');

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
            }).then(res => res.json()) // paso 1 JSON
            .then(data => {
                    json_result = data; //  paso 2 JSON
            }).catch(err => {
                mostrarAlertas(alerta_error,alerta_exito,guardando);
                return false;
            }).finally(() => {
                mostrarAlertas(alerta_exito,alerta_error,guardando);
                if (json_result.accion==='guardar'){
                    document.getElementById('alerta_exito_txt').innerHTML=
                        card_id_html.innerHTML + '<br>' +
                        card_conductor_html.innerHTML;
                    guardo=true;
                    console.log(json_result);
                    modifico=false;
                    actualizarFila()
                    if (esperar_guardado){
                        esperar_guardado=false;
                        cambiarFila(ultima_direccion);
                    }
                }
                else mostrarAlertas(alerta_error,alerta_exito,guardando);
                return guardo;
            });
        }

        let ultima_direccion= 0;//guardar ultima direccion para llamarla en el fetch
        let esperar_guardado=false;
        function cambiarFila(direccion){
            if (vapi_id==='' || esperar_guardado) return false;
            //guardar si hay error no cambiar
            if (modifico){
                guardar_etiqueta()
                esperar_guardado=true
                ultima_direccion=direccion;
                return false;
            }
            //------------------
            let suma= orden_lista+direccion;
            if (suma <0 || suma > (total_filas-1) ) return false;

            suma=0;
            let count=0,ultimo=false;
            let e_buzon, e_contesta,prenombre;
            do{
                count+=direccion;
                suma= orden_lista+count;

                if(suma<0 || suma > (total_filas-1) ) {
                    ultimo=true
                    suma-=direccion;
                    break;
                }

                prenombre='lista_'+suma+'_';
                e_buzon= document.getElementById(prenombre+ 'e_buzon_de_voz').value;
                e_contesta = document.getElementById(prenombre+'contesta').value;

            } while ( Number (e_buzon) || !Number(e_contesta)  );


            if (ultimo) alert('Sin mas llamadas ir a la siguiente pagina')
            else
                selLlamada(suma);
            ultima_direccion=0;
        }

        function actualizarFila(){
            document.querySelectorAll('button[id^="e_"]').forEach(el => {
                let valor=0;
                if (el.classList.contains("bg-activo")) valor=1
                //pasar nuevos valores de etiqueta a la fila
                document.getElementById('lista_'+orden_lista+'_'+ el.id).value= valor
            });
            document.getElementById('lista_' + orden_lista+'_analisis_a').innerHTML=txt_audio.value;

            const error_origen =document.querySelector('input[name="e_exitosa"]:checked').value;

            if (error_origen==='exito')
                document.getElementById('lista_' + orden_lista+'_llamada_exitosa').value=1;
            else{
                document.getElementById('lista_' + orden_lista +'_llamada_exitosa').value=0;
                document.getElementById('lista_' + orden_lista +'_error_origen').value=error_origen;
            }

            document.getElementById('lista_' + orden_lista +'_icon_exitosa_html').innerHTML=
                document.getElementById('icon_exitosa_'+ error_origen).innerHTML
        }

        function mostrarAlertas(mostrar,ocultar,guardando){
            guardando.classList.add('d-none');
            ocultar.classList.add('d-none');
            mostrar.classList.remove('d-none');
            setTimeout(() => {
                mostrar.classList.add('d-none');
            }, 10000);
        }


        function abrirMensajes(){
            if(vapi_id==='') return false;
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
