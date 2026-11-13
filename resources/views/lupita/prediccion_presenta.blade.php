@extends('layouts.app')

@section('title', 'Predecir presenta para carga')

@section('content')
    @php
        $p = $catalogos->parametros;
        $ahoraInput = $ahora !== '' ? str_replace(' ', 'T', substr($ahora, 0, 16)) : '';
        $tipoUbicacion = $opciones['ubicacion_tipo'] ?? 'auto';
        $ubicacionId = $opciones['ubicacion_id'] ?? '';
        $anclaSel = $opciones['ancla_ref'] ?? '';
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-clock-history"></i> Predecir presenta para carga</h1>
            <p class="text-muted small mb-1">
                Se ancla en el <strong>último hito conocido</strong> del camión (el viaje anterior, cerrado o en curso) y
                se le suma lo que falta: las etapas que le quedan del viaje actual con los promedios de su ruta, el
                <strong>hueco real</strong> medido entre su último <em>fin de descargue</em> y la presentación siguiente
                de esa misma placa, corregido por la hora del día, y el <strong>descanso o almuerzo</strong> inferido de
                sus propias franjas sin actividad. La espera en la planta de carga se suma aparte para estimar el
                <em>inicio de carga</em>.
            </p>
            <p class="text-muted small mb-0">
                Todo sale de las referencias y de los promedios compilados de las APIs de tiempos; no hay GPS, así que
                la ubicación se deduce del último hito o la declaras en el formulario. Cada predicción trae su desglose
                en minutos, sus supuestos y su nivel de evidencia.
                <a href="{{ route('lupita.prediccion.aciertos') }}">Ver la tabla de aciertos &rarr;</a>
            </p>
        </div>
    </div>

    @if(session('estado'))
        <div class="alert alert-info py-2">{{ session('estado') }}</div>
    @endif

    @if(($errors ?? null)?->any())
        <div class="alert alert-danger py-2">
            <strong>Revisá los datos:</strong>
            <ul class="mb-0 small">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header bg-primary text-white"><strong>Paso 1 — Qué referencia</strong></div>
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.prediccion.presenta') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Referencia a predecir</label>
                    <input type="text" name="ref" class="form-control" value="{{ $ref }}"
                           placeholder="ej. 1180021" maxlength="10" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label">o placa (para ver sus viajes)</label>
                    <input type="text" name="placa" class="form-control" value="{{ $placa }}"
                           placeholder="ej. BEQ926" maxlength="20" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Corte "ahora" (opcional)</label>
                    <input type="datetime-local" name="ahora" class="form-control" value="{{ $ahoraInput }}">
                    <div class="text-muted" style="font-size:.7rem">vacío = ahora mismo; sirve para simular</div>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Cargar</button>
                </div>
            </form>
        </div>
    </div>

    @if($placa !== '' || $candidatos !== [])
        <form method="GET" action="{{ route('lupita.prediccion.presenta') }}">
            <input type="hidden" name="ref" value="{{ $ref }}">
            <input type="hidden" name="placa" value="{{ $placa }}">
            <input type="hidden" name="ahora" value="{{ $ahora }}">

            <div class="card mb-3">
                <div class="card-header bg-dark text-white">
                    <strong>Paso 2 — Viaje ancla</strong>
                    <span class="small">— el último viaje del camión antes de presentar; puede estar en curso</span>
                </div>
                @if($candidatos === [])
                    <div class="card-body text-muted">
                        <p class="mb-1">
                            La placa <code>{{ $placa }}</code> no tiene viajes con hitos registrados.
                        </p>
                        <p class="mb-0 small">
                            Igual se puede predecir: en el <strong>paso 3</strong> declarás en qué local o ubigeo está
                            el camión y el motor cuenta desde ahí el traslado hasta la planta de carga más el tiempo
                            muerto. La evidencia de esa predicción es <code>cadena</code> (no hay hueco medido).
                        </p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                            <tr>
                                <th style="width:40px"></th>
                                <th>Ref</th>
                                <th>Ruta</th>
                                <th>Último hito</th>
                                <th>Fecha</th>
                                <th class="text-end">Hace</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>
                                    <input type="radio" name="ancla" value="" class="form-check-input"
                                           id="ancla_auto" @checked($anclaSel === '')>
                                </td>
                                <td colspan="5" class="text-muted">
                                    <label for="ancla_auto" class="mb-0">Automático (el más reciente)</label>
                                </td>
                            </tr>
                            @foreach($candidatos as $i => $viaje)
                                <tr class="{{ $anclaSel === $viaje->ref ? 'table-warning' : '' }}">
                                    <td>
                                        <input type="radio" name="ancla" value="{{ $viaje->ref }}"
                                               id="ancla_{{ $i }}" class="form-check-input"
                                               @checked($anclaSel === $viaje->ref)>
                                    </td>
                                    <td><label for="ancla_{{ $i }}" class="mb-0"><code>{{ $viaje->ref }}</code></label></td>
                                    <td class="small">{{ $viaje->ruta ?: '—' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $viaje->cerrado ? 'success' : 'info' }}">
                                            {{ $viaje->hito ?? 'sin hito' }}
                                        </span>
                                    </td>
                                    <td class="small">{{ $viaje->fecha ? $viaje->fecha->format('d/m H:i') : '—' }}</td>
                                    <td class="small text-end">
                                        {{ $viaje->edad_min === null ? '—' : intdiv($viaje->edad_min, 60) . ' h' }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <strong>Paso 3 — Dónde está el camión</strong>
                    <span class="small">— por defecto se deduce del último hito</span>
                </div>
                <div class="card-body row g-2">
                    <div class="col-md-3">
                        <label class="form-label">Tipo</label>
                        <select name="ubicacion_tipo" id="ubicacion_tipo" class="form-select">
                            <option value="auto" @selected($tipoUbicacion === 'auto')>Automático (del último hito)</option>
                            <option value="local" @selected($tipoUbicacion === 'local')>En un local / planta</option>
                            <option value="ubigeo" @selected($tipoUbicacion === 'ubigeo')>En un ubigeo</option>
                            <option value="indeterminado" @selected($tipoUbicacion === 'indeterminado')>No se sabe</option>
                        </select>
                        <div class="text-muted" style="font-size:.7rem">
                            sin viaje en curso, elegí local o ubigeo
                        </div>
                    </div>
                    <div class="col-md-5" id="bloque_local">
                        <label class="form-label">Local / planta</label>
                        <select name="ubicacion_id" id="select_local" class="form-select">
                            <option value="">— elegir —</option>
                            @foreach($catalogos->locales as $local)
                                <option value="{{ $local->id }}" @selected($ubicacionId === $local->id)>
                                    {{ $local->nombre }}{{ $local->distrito_ubigeo ? ' (' . $local->distrito_ubigeo . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5" id="bloque_ubigeo">
                        <label class="form-label">Ubigeo del distrito (6 dígitos)</label>
                        <input type="text" name="ubicacion_id" id="input_ubigeo" class="form-control"
                               value="{{ $ubicacionId }}" maxlength="6" placeholder="ej. 140101"
                               inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="col-md-4 small text-muted align-self-end" id="ayuda_ubicacion"></div>
                </div>
            </div>

            <button class="btn btn-success mb-3"><i class="bi bi-calculator"></i> Calcular predicción</button>
        </form>
    @endif

    @if($resultado)
        @include('lupita.partials.prediccion_resultado', ['resultado' => $resultado])
    @endif

    @include('lupita.partials.prediccion_parametros')
@endsection

@push('scripts')
    <script>
        (function () {
            const tipo = document.getElementById('ubicacion_tipo');
            const bloqueLocal = document.getElementById('bloque_local');
            const bloqueUbigeo = document.getElementById('bloque_ubigeo');
            const select = document.getElementById('select_local');
            const input = document.getElementById('input_ubigeo');
            const ayuda = document.getElementById('ayuda_ubicacion');

            if (!tipo || !bloqueLocal) return;

            function aplicar() {
                const valor = tipo.value;
                const esLocal = valor === 'local';
                const esUbigeo = valor === 'ubigeo';

                bloqueLocal.style.display = esLocal ? '' : 'none';
                bloqueUbigeo.style.display = esUbigeo ? '' : 'none';

                // sólo se envía el control visible: el otro quedaría pisando el valor
                if (select) select.disabled = !esLocal;
                if (input) input.disabled = !esUbigeo;

                ayuda.textContent = valor === 'auto'
                    ? 'Se deduce la planta de descarga del último hito del camión.'
                    : (valor === 'indeterminado'
                        ? 'Sin ubicación: la predicción se apoya sólo en los huecos medidos y la evidencia baja.'
                        : 'El traslado se busca en las rutas medidas entre esa posición y la planta de carga.');
            }

            tipo.addEventListener('change', aplicar);
            aplicar();
        })();
    </script>
@endpush
