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
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#collapseMini">
                                    <i class="bi bi-tags"></i> <span id="acordeon_head"></span>
                                </button>

                            </h2>
                            <div id="collapseMini" class="accordion-collapse collapse "
                                 data-bs-parent="#acordeonMini">
                                <div class="accordion-body">
                                    <div class="btn-group btn-group-sm mb-2" role="group">
                                        <input type="radio" class="btn-check" name="e_operador" id="opTodas"
                                               value="" @checked(!request('e_operador'))>
                                        <label class="btn btn-outline-primary" for="opTodas"><i
                                                class="bi bi-check-all"></i> Todas</label>

                                        <input type="radio" class="btn-check" name="e_operador"
                                               id="opAlMenosUna" value="1" @checked(request('e_operador'))>
                                        <label class="btn btn-outline-primary" for="opAlMenosUna"><i
                                                class="bi bi-check"></i> Al menos una</label>
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
                                                    <input type="checkbox" class="btn-checkbox btn-frontend"
                                                           name="etiquetas[]" value="{{$key}}" id="{{$key}}"
                                                        @checked(in_array($key, $etiquetas)) >
                                                    <label for="{{$key}}"
                                                           class="btn-opcion btn btn-outline-light w-100"><i
                                                            class="{{$item[0]}}"></i> {{$item[1]}}</label>
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
