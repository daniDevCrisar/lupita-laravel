<!-- resources/views/livewire/log-conductores/padre.blade.php -->

<div>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1>Lista de Conductores</h1>
            </div>
        </div>

        <div class="row">
            {{-- Filtros de búsqueda (GET) --}}
            <form method="GET" action="{{ route('lupita.conductores.log') }}">
                <fieldset class="border p-3 rounded mb-3">
                    <legend class="float-none w-auto px-2 fs-6">
                        Filtros de búsqueda
                    </legend>

                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label" for="llamada_tipo_id">Tipo de llamada</label>
                            <select name="llamada_tipo_id" id="llamada_tipo_id" class="form-control">
                                <option value="">Todos</option>
                                @foreach($llamadas::$tipos_llamada as $item)
                                    <option value="{{ $item->id }}" @selected(request('llamada_tipo_id') === $item->id)>
                                        {{ $item->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" value="{{ request('fecha_inicio') }}" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="fecha_fin">Fecha fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin" value="{{ request('fecha_fin') }}" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label for="conductor" class="form-label">Conductor</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" id="conductor" name="conductor" value="{{ request('conductor') }}" class="form-control" placeholder="Conductor, id...">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="trt" class="form-label">Transportista Id</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-building"></i></span>
                                <input type="text" id="trt" name="trt" value="{{ request('trt') }}" class="form-control" placeholder="id...">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="ordenar_por" class="form-label"><i class="bi bi-arrow-down-up"></i> Ordenar por</label>
                            <div class="row">
                                <div class="col">
                                    <select name="ordenar_por" id="ordenar_por" class="form-control">
                                        <option value="">Mejores</option>
                                        <option value="llamadas" @selected(request('ordenar_por') == 'llamadas')>Llamadas</option>
                                        <option value="exitosas" @selected(request('ordenar_por') == 'exitosas')>Exitosas</option>
                                        <option value="fallidas" @selected(request('ordenar_por') == 'fallidas')>Fallidas</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <div class="btn-group" role="group">
                                        <input type="radio" class="btn-check" name="orden" id="rd_ord_1" value="1" @checked(request('orden') == '1')>
                                        <label class="btn btn-outline-primary" for="rd_ord_1">Asc</label>

                                        <input type="radio" class="btn-check" name="orden" id="rd_ord_2" value="" @checked(request('orden') == '')>
                                        <label class="btn btn-outline-primary" for="rd_ord_2">Desc</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                            <a href="{{ route('lupita.conductores.log') }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                            </a>
                        </div>

                    </div>
                </fieldset>
            </form>

{{--    NUEVO LOG       --}}

            <div class="row">
                {{-- Botón para abrir el modal --}}
                <div class="col-12 mb-3">
                    <button class="btn btn-success" wire:click="nuevoLog">
                        <i class="bi bi-plus-circle me-1"></i> Nuevo Log
                    </button>
                </div>

                {{-- TABLA DE CONDUCTORES --}}
                <div class="col-12">
                    @livewire('log-conductores.tabla-conductores', [
                        'fecha_inicio' => request('fecha_inicio', ''),
                        'fecha_fin' => request('fecha_fin', ''),
                        'llamada_tipo_id' => request('llamada_tipo_id', ''),
                        'conductor' => request('conductor', ''),
                        'trt' => request('trt', ''),
                        'ordenar_por' => request('ordenar_por', ''),
                        'orden' => request('orden', ''),
                        'reporte' => request('reporte', false),
                    ])
                </div>


                {{-- Modal Nuevo --}}
                @livewire('log-conductores.nuevo')
            </div>

        </div>
    </div>
</div>
