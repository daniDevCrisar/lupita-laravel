@extends('layouts.app')

@section('title', 'Inicio')

@section('heads')
@endsection

@section('content')

    <div class="row">
        <div class="col-12">
            <h1>Lista de Transportistas</h1>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">
                    Filtros de búsqueda
                </legend>

                <div class="row g-3">

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
                        <label for="ordenar_por" class="form-label">
                            <i class="bi bi-arrow-down-up"></i> Ordenar por
                        </label>
                        <div class="row">
                            <div class="col">

                                <div class="input-group">
                                    <select name="ordenar_por" id="ordenar_por"
                                            class="form-control">
                                        <option value="" @selected((string) request('ordenar_por')==='') >
                                            Mejores</option>
                                        <option value="llamadas" @selected((string) request('ordenar_por')==='llamadas') >
                                            Llamadas</option>
                                        <option value="exitosas" @selected((string) request('ordenar_por')==='exitosas') >
                                            Exitosas</option>
                                        <option value="fallidas" @selected((string) request('ordenar_por')==='fallidas') >
                                            Fallidas</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="orden" id="rd_ord_1" value="1"
                                        @checked((string)request('orden') === '1')>
                                    <label class="btn btn-outline-primary" for="rd_ord_1">Asc</label>

                                    <input type="radio" class="btn-check" name="orden" id="rd_ord_2" value=""
                                        @checked(request('orden') == '')>
                                    <label class="btn btn-outline-primary" for="rd_ord_2">Desc</label>
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

        <div class="col-12">{{ $trts->links() }}</div>
        <div class="col-12">
            <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                <table class="table table-bordered table-hover table-sm table-dark">
                    <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                    <tr>
                        <th>Id</th>
                        <th>Transportista</th>
                        <th data-bs-toggle="tooltip" title="Conductores con al menos 1 llamada sin errores"><i class="bi bi-people"></i></th>
                        <th data-bs-toggle="tooltip" title="Conductores con 0 llamadas exitosas / Con una 1 o mas fallas">Fallidos</th>
                        <th>% Problematico</th>
                        <th>Llamadas<br>sin errores</th>
                        <th>Exitosas</th>
                        <th>Fallidas</th>
                        <th>Tasa de Exito</th>
                        <th @if(request('reporte'))  style="display:none;" @endif>Etiquetas Positivas</th>
                        <th  @if(request('reporte'))  style="display:none;" @endif">Etiquetas Negativas</th>
                        <th @if(request('reporte'))  style="display:none;" @endif>Errores</th>
                        <th>Puntaje</th>
                        <th>Tiempo en <br>llamada</th>
                    </tr>
                    </thead>
                    <tbody>


                    @foreach($trts as $row)
                        <tr class="{{ $loop->odd ? 'table-secondary' : '' }} ">
                            <td class="bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}">{{ $row->trt_id  }}</td>
                            <td ><a href="{{ route('lupita.conductores', [
                                'fecha_inicio'     => request('fecha_inicio'),
                                'fecha_fin'        => request('fecha_fin'),
                                'llamada_tipo_id'  => request('llamada_tipo_id'),
                                'conductor'        => '',
                                'trt'              => $row->trt_id,
                            ]) }}" target="_blank">{{ $row->trt }}</a></td>

                            @php
                            $problematicos= round((($row->conductores  - $row->conductores_con_exito)/$row->conductores)*100,1)
                            @endphp

                            <td class="fw-bold">{{ $row->conductores  }}</td>
                            <td><span class='text-danger fw-bold'>
                                    {{ $row->conductores  - $row->conductores_con_exito }} </span>/ {{ $row->conductores_con_fallo }}</td>
                            <td><span class="badge bg-{{ $llamadas::color_porcentaje(100-$problematicos) }}">{{  $problematicos }}%</span></td>

                            <td><span class="badge bg-primary">{{ $row->total-$row->total_error }}</span></td>
                            <td> <span class="badge bg-success">{{ $row->exitosas }}</span> </td>
                            <td> <span class="badge bg-danger">{{ $row->fallidas-$row->total_error }}</span> </td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}" role="progressbar" style="width: {{$row->tasa_exito }}%;" aria-valuenow="{{$row->tasa_exito }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>

                                <small class="d-block text-center text-{{ $llamadas::color_porcentaje($row->tasa_exito) }}">{{$row->tasa_exito }}%</small>
                            </td>
                            <td @if(request('reporte'))  style="display:none;" @endif>{!! $llamadas::etiquetas_icon_bi($row,'',1,true) !!}</td>
                            <td @if(request('reporte'))  style="display:none;" @endif>{!! $llamadas::etiquetas_icon_bi($row,'',0,true,$row->fallidas-$row->total_error) !!}</td>
                            <td class="text-danger" @if(request('reporte'))  style="display:none;" @endif>
                                @if($row->error_desconocido)
                                    <i class="{{ $llamadas::icon_exito(-1,true) }}"></i>
                                    Desconocido({{ $row->error_desconocido }}) <br>
                                @endif

                                @if($row->error_ia)
                                    <i class="{{ $llamadas::icon_exito(1,true) }}"></i>
                                    IA:({{ $row->error_ia }}) <br>
                                @endif

                                @if($row->error_red)
                                    <i class="{{ $llamadas::icon_exito(2,true) }}"></i>
                                    Red:({{ $row->error_red }}) <br>
                                @endif
                                @if($row->error_sistema)
                                    <i class="{{ $llamadas::icon_exito(3,true) }}"></i>
                                    Sistema:({{ $row->error_sistema }})
                                @endif
                            </td>
                            @php $puntaje=$llamadas::puntaje_conductor($row); @endphp
                            <td class="text-{{ $puntaje > 0 ? 'success' : 'danger' }} fw-bold">
                                {{ $puntaje }}
                            </td>
                            <td class="small">{{$llamadas::audio_duracion_format($row->audio_duracion)}}</td>
                        </tr>
                    @endforeach


                    </tbody>
                </table>

            </div>
        </div>
        <div class="col-12">{{ $trts->links() }}</div>


    </div>
@endsection
@section('scripts')

    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el)
        })
    </script>
@endsection
