@extends('layouts.app')

@section('title', 'Compilar promedios de ruta (inicio - llegada)')

@section('content')

    @php
        $p = $datos->perdida;
        $estado = $datos->estado;
        $tope_minimo = \App\Database\DBTiemposPromedio::TOPE_MINIMO;
        $fmt = fn ($minutos) => \App\Database\DBTiemposRuta::formatear_minutos($minutos);

        $color_perdido = function ($pct) {
            if ($pct === null) return 'secondary';
            if ($pct <= 2) return 'success';
            if ($pct <= 10) return 'warning';
            return 'danger';
        };

        $etiqueta_modo = [
            'global' => 'global',
            'ruta' => 'p82 de cada ruta',
            'propio' => 'el tope guardado de cada ruta',
        ][$datos->modo] ?? 'global';
    @endphp

    <style>
        /* tabla de perdidas: el hover de darkly casi no se nota, y la fila marcada
           para compilar se pinta en verde. El fondo va en el <td>: Bootstrap pinta
           las celdas, no el <tr> */
        #tabla_perdida > tbody > tr > td {
            transition: background-color .12s linear;
        }

        #tabla_perdida > tbody > tr:hover > td {
            background-color: rgba(var(--bs-info-rgb), .12);
        }

        #tabla_perdida > tbody > tr.marcada > td {
            background-color: rgba(var(--bs-success-rgb), .18);
        }

        #tabla_perdida > tbody > tr.marcada > td:first-child {
            box-shadow: inset 3px 0 0 var(--bs-success);
        }

        #tabla_perdida > tbody > tr.marcada:hover > td {
            background-color: rgba(var(--bs-success-rgb), .28);
        }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1">Compilar promedios de ruta</h1>
            <p class="text-muted small mb-2">
                Etapa de ruta: <strong>inicio de ruta &rarr; llegada a destino</strong> (etapa 4), calculada sobre
                <strong>toda la data</strong>, sin filtrar por estados de planta ni por fechas. Aquí se elige un
                <strong>tope en minutos</strong>: los viajes que lo superan se descartan y abajo se ve exactamente
                cuántos se pierden. Con el tope en <strong>0</strong> no se manda ningún corte global y lo que aplica
                es el combo <strong>Tope a aplicar</strong>.
            </p>
            <p class="text-muted small mb-2">
                También se compila el <strong>ciclo completo</strong> (presenta para carga &rarr; fin de descargue: E2 a E6):
                es la suma de los promedios de las cinco etapas y solo se guarda si todas aportan datos.
            </p>
            <p class="text-muted small mb-2">
                El <strong>promedio sin tope</strong> es el de todos los viajes medibles; el
                <strong>promedio con tope</strong> es el que se guarda. Las rutas sin viajes dentro del tope
                <strong>no se crean ni se modifican</strong>: la compilación se puede hacer por partes y con
                distintos topes, y lo ya compilado se respeta.
            </p>
            <p class="text-muted small mb-0">
                El <strong>promedio fusionado</strong> (ida &harr; vuelta) no se recalcula sobre los viajes: es el
                promedio de los dos promedios ya guardados del mismo par de ubicaciones.
            </p>
        </div>
        <div class="text-end small">
            <span class="badge bg-{{ $estado->tablas ? 'success' : 'warning' }} text-{{ $estado->tablas ? 'dark' : 'dark' }}">
                {{ $estado->tablas ? 'Tablas listas' : 'Faltan las tablas (aplicar migración)' }}
            </span>
            @if($estado->rutas > 0)
                <div class="text-muted mt-1">
                    {{ $estado->rutas }} ruta(s) compiladas · {{ number_format($estado->celdas) }} celda(s) día/hora<br>
                    Última compilación: {{ $estado->ultima }}
                </div>
            @endif
            <div class="mt-1">
                <a href="{{ route('lupita.tiempos.promedio.ver') }}" class="text-info">
                    Ver promedios compilados <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success mt-3 mb-0">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">{{ session('error') }}</div>
    @endif

    @include('lupita._tiempos_promedio_tabs', ['tab' => 'rutas'])

    {{-- filtros: previsualizan la perdida con el tope elegido --}}
    <form method="GET" id="filtros" class="mt-3">
        <fieldset class="border p-3 rounded">
            <legend class="float-none w-auto px-2 fs-6">Tope y alcance</legend>

            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="tope">Tope (minutos)</label>
                    <input type="number" id="tope" name="tope" min="0" max="100000"
                           step="1" value="{{ $datos->tope }}" class="form-control">
                    <div class="form-text">
                        {{-- el JS reescribe la nota entera: con 0 el texto no es una equivalencia en horas --}}
                        <span id="tope_nota">
                            @if($datos->tope > 0)
                                Equivale a {{ round($datos->tope / 60, 1) }} h: un tope menor descarta más viajes.
                            @else
                                0 = sin tope global: no se corta nada y manda el combo de al lado.
                            @endif
                        </span>
                        @if($datos->modo === 'propio')
                            Ahora manda el tope de cada ruta (el escrito en la tabla o el guardado); este valor solo se
                            usa en las rutas que no tienen ninguno.
                        @endif
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="modo">Tope a aplicar</label>
                    <select name="modo" id="modo" class="form-control">
                        @foreach($datos->modos as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected($datos->modo === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        <strong>Global</strong>: el mismo tope para todas (ej. 4320 = 72 h); con el tope en 0 no se
                        descarta ningún viaje.
                        <strong>p82 por ruta</strong>: a cada ruta se le aplica su propio percentil 82, con piso de
                        4 h: deja fuera, como máximo, el 18 % de sus viajes; una ruta con viajes cortos no recibe un tope de días.
                        <strong>El de cada ruta</strong>: el tope escrito en cada fila de la tabla o, si no tiene, el que
                        se fijó al compilarla ({{ $estado->rutas }} guardada(s)); las demás usan el global.
                        Al guardar con el tope en 0 y modo global se aplica el p82 de cada ruta, para no promediar
                        sin ningún corte.
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="ruta_id">Ruta</label>
                    <select name="ruta_id" id="ruta_id" class="form-control">
                        <option value="">Todas</option>
                        @foreach($datos->rutas as $id => $ruta)
                            <option value="{{ $id }}" @selected((string) $datos->ruta_id === (string) $id)>
                                {{ $ruta->nombre }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Filtra la vista; para compilar un tramo usa el botón de abajo.</div>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Calcular pérdida
                    </button>
                    <a href="{{ route('lupita.tiempos.promedio') }}" class="btn btn-secondary">Limpiar</a>
                </div>

                {{-- departamentos: salen del ubigeo de cada ruta (2 primeros digitos) --}}
                @foreach([
                    'dep_origen' => ['Departamento de origen', $datos->dep_origen, 'Todos los orígenes'],
                    'dep_destino' => ['Departamento de destino', $datos->dep_destino, 'Todos los destinos'],
                ] as $campo => $definicion)
                    <div class="col-md-3">
                        <label class="form-label" for="{{ $campo }}_buscar">{{ $definicion[0] }}</label>
                        <div class="dropdown" data-combo="{{ $campo }}">
                            <button type="button"
                                    class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                <span class="text-truncate" data-combo-texto>
                                    {{ $definicion[1] === null ? $definicion[2] : ($datos->departamentos[$definicion[1]] ?? $definicion[1]) }}
                                </span>
                                <i class="bi bi-chevron-down ms-2"></i>
                            </button>
                            <div class="dropdown-menu w-100 p-2">
                                <input type="search" id="{{ $campo }}_buscar" class="form-control form-control-sm mb-2"
                                       data-combo-buscar placeholder="Buscar departamento..." autocomplete="off">
                                <div data-combo-lista style="max-height: 240px; overflow-y: auto">
                                    <button type="button" class="dropdown-item rounded" data-valor=""
                                            data-buscar="{{ mb_strtolower($definicion[2]) }}">
                                        {{ $definicion[2] }}
                                    </button>
                                    @foreach($datos->departamentos as $codigo => $nombre)
                                        <button type="button" class="dropdown-item rounded text-wrap"
                                                data-valor="{{ $codigo }}"
                                                data-buscar="{{ mb_strtolower($nombre . ' ' . $codigo) }}">
                                            {{ $nombre }}
                                        </button>
                                    @endforeach
                                </div>
                                <div class="text-muted small text-center py-2" data-combo-vacio hidden>
                                    Sin coincidencias
                                </div>
                            </div>
                            <input type="hidden" name="{{ $campo }}" value="{{ $definicion[1] }}">
                        </div>
                        <div class="form-text">
                            {{ $campo === 'dep_origen' ? 'Ubigeo de origen de la ruta' : 'Ubigeo de destino de la ruta' }}
                        </div>
                    </div>
                @endforeach

                <div class="col-md-6">
                    <label class="form-label d-block">Atajos de tope</label>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" onclick="poner_tope(0)"
                                title="Tope 0: no se corta nada, manda el combo de tope a aplicar">
                            Sin tope
                        </button>
                        @foreach([720 => '12 h', 1440 => '24 h', 2880 => '48 h', 4320 => '72 h', 5760 => '96 h', 8640 => '6 días'] as $min => $etiqueta)
                            <button type="button" class="btn btn-outline-secondary" onclick="poner_tope({{ $min }})">
                                {{ $etiqueta }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- las rutas con poca muestra se pueden sacar de la tabla y del resumen --}}
                <div class="col-md-3">
                    <label class="form-label" for="min_viajes">Mínimo de viajes</label>
                    <input type="number" id="min_viajes" name="min_viajes" min="0" step="1"
                           value="{{ $datos->min_viajes }}" class="form-control"
                           onchange="document.getElementById('filtros').submit()">
                    <div class="form-text">
                        Oculta las rutas con menos viajes medibles y acota los lotes de compilación.
                        Deja 0 para verlas todas.
                    </div>
                </div>

                {{-- las rutas compiladas se pueden sacar de la tabla y del resumen --}}
                <div class="col-md-3">
                    <label class="form-label d-block">Rutas compiladas</label>
                    <div class="form-check form-switch">
                        <input type="hidden" name="ocultar_compiladas" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="ocultar_compiladas"
                               name="ocultar_compiladas" value="1"
                               @checked($datos->ocultar_compiladas)
                               onchange="document.getElementById('filtros').submit()">
                        <label class="form-check-label" for="ocultar_compiladas">
                            Ocultar las rutas que ya tienen promedio compilado
                        </label>
                    </div>
                    <div class="form-text">
                        La tabla y el resumen de pérdida pasan a medir solo lo pendiente.
                        @if($estado->rutas > 0)
                            Hay {{ number_format($estado->rutas) }} ruta(s) compiladas.
                        @endif
                    </div>
                </div>

                {{-- topes escritos en la tabla: viajan con el GET para no perderse al ordenar --}}
                @foreach($datos->topes as $ruta_tope => $minutos)
                    <input type="hidden" name="topes[{{ $ruta_tope }}]" value="{{ $minutos }}">
                @endforeach
            </div>
        </fieldset>
    </form>

    {{-- resumen de lo que se pierde --}}
    <div class="row g-3 mt-1">
        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes medibles (sin tope)</div>
                    <div class="fs-4 fw-bold">{{ number_format($p->viajes_total) }}</div>
                    <div class="small text-muted">
                        {{ $p->rutas }} ruta(s) {{ $datos->ocultar_compiladas ? 'sin compilar' : 'con datos' }}
                        @if($p->sin_promedio > 0)
                            · {{ $p->sin_promedio }} sin viaje dentro del tope
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">
                        {{ $datos->modo === 'propio' ? 'Se descartan con el tope de cada ruta' : 'Se descartan con este tope' }}
                    </div>
                    <div class="fs-4 fw-bold text-{{ $color_perdido($p->pct_perdido) }}">
                        {{ number_format($p->descartados) }}
                        @if($p->pct_perdido !== null)
                            <span class="fs-6">({{ $p->pct_perdido }} %)</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        Quedan {{ number_format($p->viajes) }} viaje(s) ·
                        {{ $p->rutas_afectadas }} ruta(s) afectada(s)
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small" title="Presenta para carga → fin de descargue: suma de los promedios E2..E6">
                        Ciclo promedio (E2 &rarr; E6)
                    </div>
                    <div class="fs-4 fw-bold">{{ $fmt($p->prom_ciclo_min) }}</div>
                    <div class="small text-muted">
                        Rápido: {{ $fmt($p->prom_ciclo_rapido_min) }}
                        · Tardado: {{ $fmt($p->prom_ciclo_sin_tope_min) }}
                        <br>Espera fuera: {{ $fmt($p->prom_fuera_min) }}
                        · dentro: {{ $fmt($p->prom_dentro_min) }}
                        @if($p->con_ciclo > 0)
                            <br>{{ $p->con_ciclo }} ruta(s) con ciclo completo
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Promedio por ruta</div>
                    <div class="fs-4 fw-bold">
                        {{ $fmt($p->prom_min) }}
                    </div>
                    <div class="small text-muted">
                        Sin tope: {{ $fmt($p->prom_sin_tope_min) }}
                        @if($p->prom_min !== null && $p->prom_sin_tope_min)
                            ({{ round((($p->prom_min - $p->prom_sin_tope_min) / $p->prom_sin_tope_min) * 100, 1) }} %)
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Rango de los viajes</div>
                    <div class="fs-5 fw-bold">
                        {{ $fmt($p->min_min) }}
                        <span class="text-muted">&rarr;</span>
                        {{ $fmt($p->max_min) }}
                    </div>
                    <div class="small text-muted">
                        El máximo es el viaje más largo registrado en toda la data
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- compilacion: mismo tope y modo que la vista --}}
    <form method="POST" action="{{ route('lupita.tiempos.promedio.compilar') }}" id="compilar" class="mt-3">
        @csrf
        <input type="hidden" name="tope" value="{{ $datos->tope }}">
        <input type="hidden" name="modo" value="{{ $datos->modo }}">
        <input type="hidden" name="ruta_id" value="{{ $datos->ruta_id }}">
        <input type="hidden" name="dep_origen" value="{{ $datos->dep_origen }}">
        <input type="hidden" name="dep_destino" value="{{ $datos->dep_destino }}">
        <input type="hidden" name="orden" value="{{ $datos->orden }}">
        <input type="hidden" name="alcance" value="todas">
        <input type="hidden" name="ocultar_compiladas" value="{{ $datos->ocultar_compiladas ? 1 : 0 }}">
        <input type="hidden" name="min_viajes" value="{{ $datos->min_viajes }}">

        {{-- topes escritos en la tabla: viajan con el POST para "El tope de cada ruta" --}}
        @foreach($datos->topes as $ruta_tope => $minutos)
            <input type="hidden" name="topes[{{ $ruta_tope }}]" value="{{ $minutos }}">
        @endforeach

        <fieldset class="border p-3 rounded">
            <legend class="float-none w-auto px-2 fs-6">Compilar y guardar</legend>

            @can('compilar promedios')

            <p class="small text-muted mb-2">
                @if($datos->tope > 0)
                    Se guarda con tope <strong>{{ $datos->tope }} min</strong> ({{ $etiqueta_modo }}).
                @elseif($datos->modo === 'global')
                    Se guarda <strong>sin tope global</strong>: el modo global no tiene ningún corte que aplicar,
                    así que cada ruta queda con su <strong>p82</strong> (máximo 18 % descartado).
                @else
                    Se guarda <strong>sin tope global</strong> ({{ $etiqueta_modo }}): manda el tope a aplicar.
                @endif
                El botón usa el tope que está
                escrito arriba, aunque todavía no hayas recalculado: la pérdida real se recalcula al guardar.
                @if($datos->modo === 'propio')
                    Con <strong>El tope de cada ruta</strong> cada una se recompila con el tope que ya tenía guardado.
                @endif
            </p>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success" onclick="compilar('todas')">
                    <i class="bi bi-database-add"></i>
                    @if($datos->ocultar_compiladas)
                        Compilar todas las pendientes
                    @elseif($datos->dep_origen || $datos->dep_destino || $datos->min_viajes > 0)
                        Compilar todas las del filtro
                    @else
                        Compilar todas las rutas
                    @endif
                </button>

                <button type="button" class="btn btn-outline-success" id="btn_seleccion"
                        onclick="compilar('seleccion')" disabled
                        title="Compila cada ruta marcada con el tope escrito en su fila">
                    <i class="bi bi-check2-square"></i>
                    Compilar las <span id="seleccion_n">0</span> marcadas
                </button>

                <button type="button" class="btn btn-outline-success"
                        onclick="compilar('ruta')" @disabled(!$datos->ruta_id)>
                    <i class="bi bi-signpost-split"></i>
                    Compilar solo {{ $datos->ruta_id ? \App\Database\DBTiemposPromedio::nombre($datos->ruta_id) : 'la ruta elegida' }}
                </button>

                <button type="button" class="btn btn-outline-success" onclick="compilar('faltantes')">
                    <i class="bi bi-plus-circle"></i> Compilar solo las que faltan
                </button>
            </div>

            <div class="form-text mt-2">
                <strong>Solo las que faltan</strong> no toca las rutas que ya tienen promedio guardado: sirve para
                compilar por partes. <strong>Las marcadas</strong> usa los checks de la tabla (máximo
                {{ $datos->limite }} rutas visibles) y compila cada una con el tope escrito en su fila, sin importar
                el tope global elegido. Los lotes "todas" y "las que faltan" sí se limitan al filtro de
                departamento y usan el tope escrito en cada fila solo si el combo dice <strong>El tope de cada ruta</strong>.
                Ninguna opción borra promedios de rutas sin datos.
                @if($datos->min_viajes > 0)
                    Los lotes "todas" y "las que faltan" omiten las rutas con menos de
                    {{ number_format($datos->min_viajes) }} viajes medibles; <strong>las marcadas</strong> se
                    compilan igual porque van elegidas a mano.
                @endif
                @if($datos->ocultar_compiladas)
                    Con <strong>Ocultar compiladas</strong> activo, "todas" compila solo las pendientes: no se
                    reescribe ninguna ruta que ya tenga promedio.
                @endif
            </div>
            @else
            <p class="small text-warning mb-0">
                <i class="bi bi-lock"></i> No tienes el permiso <code>compilar promedios</code>: solo puedes
                consultar los promedios, no guardarlos.
            </p>
            @endcan
        </fieldset>
    </form>

    {{-- detalle por ruta --}}
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-4">
        <h2 class="fs-5 mb-0">Lo que se pierde por ruta</h2>
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div class="text-muted small text-end">
                {{ count($datos->filas) }} ruta(s){{ $datos->ocultar_compiladas ? ' sin compilar' : '' }}
                @if($datos->ocultar_compiladas && $datos->ocultas > 0)
                    <br>{{ $datos->ocultas }} oculta(s) por estar compiladas
                @endif
                @if($datos->ocultas_min > 0)
                    <br>{{ $datos->ocultas_min }} oculta(s) con menos de {{ number_format($datos->min_viajes) }} viajes
                @endif
                @if($datos->dep_origen)
                    <br>Origen: {{ $datos->departamentos[$datos->dep_origen] ?? $datos->dep_origen }}
                @endif
                @if($datos->dep_destino)
                    <br>Destino: {{ $datos->departamentos[$datos->dep_destino] ?? $datos->dep_destino }}
                @endif
            </div>
            <div>
                <label class="form-label mb-1 small text-muted" for="orden">Ordenar por</label>
                <select name="orden" id="orden" form="filtros" class="form-select form-select-sm"
                        onchange="document.getElementById('filtros').submit()">
                    @foreach($datos->ordenes as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected($datos->orden === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" class="btn btn-outline-primary" id="btn_recalcular"
                    onclick="recalcular_propio()"
                    title="Recalcula el resumen y la tabla con el tope escrito en cada fila y con el que se fijó al compilarla">
                <i class="bi bi-arrow-repeat"></i> Recalcular
            </button>
            <span class="small text-warning" id="topes_pendientes" hidden></span>
        </div>
    </div>

    <div class="table-responsive mt-2" style="max-height: 65vh; overflow: auto">
        <table class="table table-sm table-hover align-middle mb-0" id="tabla_perdida">
            <thead class="table-dark" style="position: sticky; top: 0; z-index: 1">
            <tr>
                <th class="text-center" style="width: 34px">
                    <input type="checkbox" class="form-check-input" id="marcar_todas"
                           title="Marcar o desmarcar las rutas visibles">
                </th>
                <th>Ruta</th>
                <th class="text-end">Viajes</th>
                <th class="text-end">Descartados</th>
                <th class="text-end">% perdido</th>
                <th class="text-end" title="Promedio de los viajes que quedan dentro del tope">
                    Prom. con tope
                </th>
                <th class="text-end" title="Promedio de todos los viajes medibles, sin descartar nada">
                    Prom. sin tope
                </th>
                <th class="text-end" title="Ciclo E2 → E6 con tres escenarios de espera: rápido (p50), promedio (p82, pierde ≤18 % de viajes) y tardado (toda la espera real). Debajo, la espera media fuera y dentro">
                    Ciclo prom.
                </th>
                <th class="text-end" title="Escribe el tope de recorrido de esta ruta y compílala sola. Haz clic en el p82 (deja fuera ≤18 % de los viajes) para ponerlo en el input">
                    Tope por ruta
                </th>
                <th class="text-end">Mediana</th>
                <th class="text-end" title="Promedio de los dos promedios ya guardados del mismo par: ida y vuelta">
                    Fusión ida/vuelta
                </th>
                <th>Compilado</th>
            </tr>
            </thead>
            <tbody>
            @forelse($datos->filas as $indice => $fila)
                @if($indice >= $datos->limite)
                    @break
                @endif
                <tr>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input" form="compilar" name="rutas[]"
                               value="{{ $fila->ruta_id }}" data-check-ruta
                               title="Incluir esta ruta al compilar las marcadas">
                    </td>
                    <td>
                        <div class="text-nowrap">{{ $fila->nombre }}</div>
                        <div class="text-muted" style="font-size: .72rem">
                            {{ $fila->ruta_id }}
                            @if($fila->dep_origen || $fila->dep_destino)
                                · {{ $fila->dep_origen ?? '—' }} &rarr; {{ $fila->dep_destino ?? '—' }}
                            @endif
                        </div>
                    </td>
                    <td class="text-end">{{ number_format($fila->viajes_total) }}</td>
                    <td class="text-end text-nowrap">
                        {{ number_format($fila->descartados) }}
                    </td>
                    <td class="text-end">
                        <span class="badge bg-{{ $color_perdido($fila->pct_perdido) }}">
                            {{ $fila->pct_perdido === null ? '—' : $fila->pct_perdido . ' %' }}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        {{ $fmt($fila->prom_min) }}
                        <div class="{{ $fila->viajes < 5 ? 'text-warning' : 'text-muted' }}" style="font-size: .72rem">
                            {{ $fila->viajes }} {{ $fila->viajes === 1 ? 'viaje' : 'viajes' }}
                        </div>
                    </td>
                    <td class="text-end text-nowrap text-muted">
                        {{ $fmt($fila->prom_sin_tope_min) }}
                    </td>
                    <td class="text-end text-nowrap">
                        {{ $fmt($fila->prom_ciclo_min) }}
                        @if($fila->prom_ciclo_rapido_min !== null)
                            <div class="text-muted" style="font-size: .72rem"
                                 title="Ciclo con el tope rápido (p50 de espera)">
                                rápido {{ $fmt($fila->prom_ciclo_rapido_min) }}
                            </div>
                        @endif
                        @if($fila->prom_ciclo_sin_tope_min !== null)
                            <div class="text-muted" style="font-size: .72rem"
                                 title="Ciclo con toda la espera real, sin tope">
                                tardado {{ $fmt($fila->prom_ciclo_sin_tope_min) }}
                                @if($fila->ciclo_viajes > 0)
                                    · {{ $fila->ciclo_viajes }} viaje(s)
                                @endif
                            </div>
                        @endif
                        @if($fila->prom_fuera_min !== null || $fila->prom_dentro_min !== null)
                            <div class="text-warning" style="font-size: .72rem"
                                 title="Espera media fuera (E2 + E5) y dentro (E3 + E6) con el tope del 18 %">
                                fuera {{ $fmt($fila->prom_fuera_min) }}
                                · dentro {{ $fmt($fila->prom_dentro_min) }}
                            </div>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('compilar promedios')
                        <form method="POST" action="{{ route('lupita.tiempos.promedio.ruta') }}"
                              class="d-flex flex-column gap-1 align-items-end"
                              onsubmit="return confirm('¿Compilar solo esta ruta con el tope escrito?');">
                            @csrf
                            <input type="hidden" name="ruta_id" value="{{ $fila->ruta_id }}">
                            @if($datos->ocultar_compiladas)
                                <input type="hidden" name="ocultar_compiladas" value="1">
                            @endif
                            <input type="hidden" name="min_viajes" value="{{ $datos->min_viajes }}">
                            <div class="d-flex gap-1">
                                <input type="number" name="tope" min="{{ $tope_minimo }}" step="1"
                                       value="{{ $fila->tope_min }}" class="form-control form-control-sm"
                                       style="width: 82px" title="Tope de recorrido en minutos"
                                       data-tope-fila data-ruta="{{ $fila->ruta_id }}">
                                <button type="submit" class="btn btn-sm btn-outline-success"
                                        title="Compilar esta ruta con este tope">
                                    <i class="bi bi-check2"></i>
                                </button>
                            </div>
                        </form>
                        @else
                        <span class="small text-muted">
                            tope {{ $fila->tope_min === null ? 'sin tope' : $fila->tope_min . ' min' }}
                        </span>
                        @endcan
                        <div class="mt-1" style="font-size: .7rem">
                            @if($fila->p82_min === null)
                                <span class="text-muted">p82: —</span>
                            @else
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-info text-decoration-none"
                                        style="font-size: .7rem"
                                        data-tope-sugerido="{{ $fila->p82_min }}"
                                        title="Poner el p82 ({{ $fmt($fila->p82_min) }}) como tope de esta ruta: deja fuera ≤18 % de sus viajes">
                                    p82: {{ $fmt($fila->p82_min) }}
                                </button>
                            @endif
                        </div>
                    </td>
                    <td class="text-end text-nowrap text-muted">
                        {{ $fmt($fila->guardado->mediana_min ?? null) }}
                    </td>
                    <td class="text-end text-nowrap">
                        @if(($fila->guardado->prom_fusion_min ?? null) !== null)
                            {{ $fmt($fila->guardado->prom_fusion_min) }}
                            <div class="text-muted" style="font-size: .72rem">
                                {{ $fila->guardado->viajes_fusion }} viaje(s)
                            </div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        @if($fila->guardado ?? null)
                            @php $tope_guardado = (int) $fila->guardado->tope_min; @endphp
                            <span class="badge bg-{{ $tope_guardado > 0 ? 'success' : 'secondary' }} text-dark"
                                  title="{{ $tope_guardado > 0 ? 'Promedio guardado con ese tope' : 'Promedio guardado sin tope: entraron todos los viajes medibles' }}">
                                {{ $tope_guardado > 0 ? $tope_guardado . ' min' : 'sin tope' }}
                            </span>
                            <div class="text-muted" style="font-size: .72rem">{{ $fila->guardado->actualizado_en }}</div>
                        @else
                            <span class="badge bg-secondary">sin promedio</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center text-muted py-4">
                        @if($datos->ocultar_compiladas && $datos->ocultas > 0)
                            Todas las rutas con datos ya están compiladas: apaga
                            <strong>Ocultar compiladas</strong> para verlas con sus topes.
                        @elseif($datos->ocultas_min > 0)
                            Ninguna ruta llega a {{ number_format($datos->min_viajes) }} viajes medibles: baja el
                            mínimo o pon 0 para verlas todas.
                        @else
                            No hay viajes medibles con estos filtros.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if(count($datos->filas) > $datos->limite)
        <div class="text-muted small mt-2">
            Se muestran {{ $datos->limite }} de {{ count($datos->filas) }} rutas: el resumen y la compilación usan
            todas.
        </div>
    @endif

    @if($estado->rutas > 0)
        <p class="text-muted small mt-3 mb-0">
            Guardado actual: {{ $estado->rutas }} ruta(s), {{ number_format($estado->viajes) }} viaje(s) usados,
            {{ number_format($estado->descartados) }} descartados, {{ number_format($estado->celdas) }} celda(s) de
            día/hora y {{ $estado->fusionadas }} ruta(s) con promedio fusionado.
            @if($estado->con_ciclo > 0)
                <br>{{ number_format($estado->con_ciclo) }} ruta(s) con ciclo promedio guardado.
            @endif
        </p>
    @endif

    <script>
        // la nota del tope: equivalencia en horas si hay corte, y el sentido
        // del 0 (sin tope global, manda el combo) cuando no lo hay
        function pintar_tope(minutos) {
            const nota = document.getElementById('tope_nota');
            if (!nota) {
                return;
            }
            nota.textContent = minutos > 0
                ? 'Equivale a ' + (minutos / 60).toFixed(1) + ' h: un tope menor descarta más viajes.'
                : '0 = sin tope global: no se corta nada y manda el combo de al lado.';
        }

        function poner_tope(minutos) {
            const input = document.getElementById('tope');
            input.value = minutos;
            pintar_tope(minutos);
        }

        // recalcula el resumen y la tabla con el tope escrito o guardado de cada ruta
        function recalcular_propio() {
            const form = document.getElementById('filtros');
            const params = new URLSearchParams();

            new FormData(form).forEach(function (valor, campo) {
                params.append(campo, valor);
            });

            params.set('modo', 'propio');

            // los topes tocados en la tabla mandan sobre lo que venia en la URL.
            // Se cortan en 120: mas entradas harian la URL mas larga de lo que
            // acepta el servidor (8 KB); el resto usa su tope guardado o el global.
            let topes = 0;
            document.querySelectorAll('[data-tope-fila][data-modificado]').forEach(function (input) {
                if (topes++ >= 120) {
                    return;
                }
                params.append('topes[' + input.dataset.ruta + ']', input.value);
            });

            window.location = window.location.pathname + '?' + params.toString();
        }

        // el p82 de una fila se pone en el tope de esa ruta con un clic
        document.querySelectorAll('[data-tope-sugerido]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                const input = boton.closest('td').querySelector('[data-tope-fila]');
                if (!input) {
                    return;
                }
                input.value = boton.dataset.topeSugerido;
                input.dataset.modificado = '1';
                input.classList.add('border-warning');
                input.focus();
                contar_topes_pendientes();
            });
        });

        // topes escritos que todavia no se han guardado con el boton de la fila
        function contar_topes_pendientes() {
            const aviso = document.getElementById('topes_pendientes');
            if (!aviso) {
                return;
            }
            const n = document.querySelectorAll('[data-tope-fila][data-modificado]').length;
            aviso.textContent = n + ' tope(s) modificado(s): pulsa Recalcular para aplicarlos';
            aviso.hidden = n === 0;
        }

        document.querySelectorAll('[data-tope-fila]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.dataset.modificado = '1';
                input.classList.add('border-warning');
                contar_topes_pendientes();
            });
        });

        // ---- combos de departamento: la lista se filtra con el input de busqueda
        document.querySelectorAll('[data-combo]').forEach(function (combo) {
            const boton = combo.querySelector('[data-bs-toggle="dropdown"]');
            const texto = combo.querySelector('[data-combo-texto]');
            const buscar = combo.querySelector('[data-combo-buscar]');
            const lista = combo.querySelector('[data-combo-lista]');
            const vacio = combo.querySelector('[data-combo-vacio]');
            const valor = combo.querySelector('input[type="hidden"]');
            const items = Array.from(lista.querySelectorAll('[data-valor]'));

            function filtrar(termino) {
                const t = termino.trim().toLowerCase();
                let visibles = 0;

                items.forEach(function (item) {
                    const coincide = t === '' || (item.dataset.buscar || '').indexOf(t) !== -1;
                    item.hidden = !coincide;
                    if (coincide) {
                        visibles++;
                    }
                });

                vacio.hidden = visibles > 0;
            }

            function marcar() {
                items.forEach(function (item) {
                    item.classList.toggle('active', item.dataset.valor === valor.value);
                });
            }

            items.forEach(function (item) {
                item.addEventListener('click', function () {
                    valor.value = item.dataset.valor;
                    texto.textContent = item.textContent.trim();
                    marcar();
                    buscar.value = '';
                    filtrar('');

                    const instancia = bootstrap.Dropdown.getInstance(boton);
                    if (instancia) {
                        instancia.hide();
                    }
                });
            });

            buscar.addEventListener('input', function () {
                filtrar(this.value);
            });

            buscar.addEventListener('keydown', function (evento) {
                if (evento.key !== 'Enter') {
                    return;
                }

                evento.preventDefault();
                const primero = items.find(function (item) {
                    return !item.hidden;
                });

                if (primero) {
                    primero.click();
                }
            });

            combo.addEventListener('shown.bs.dropdown', function () {
                buscar.focus();
            });

            marcar();
        });

        // ---- checks de la tabla: se enganchan al form de compilacion con el
        //      atributo form, porque cada fila tiene su propio formulario
        const checks = Array.from(document.querySelectorAll('[data-check-ruta]'));
        const maestro = document.getElementById('marcar_todas');
        const boton_seleccion = document.getElementById('btn_seleccion');
        const contador = document.getElementById('seleccion_n');

        function contar_marcadas() {
            // la fila marcada se resalta para no perderla de vista al compilar
            checks.forEach(function (check) {
                const fila = check.closest('tr');
                if (fila) {
                    fila.classList.toggle('marcada', check.checked);
                }
            });

            const marcadas = checks.filter(function (check) {
                return check.checked;
            }).length;

            contador.textContent = marcadas;
            boton_seleccion.disabled = marcadas === 0;

            if (maestro) {
                maestro.checked = marcadas > 0 && marcadas === checks.length;
                maestro.indeterminate = marcadas > 0 && marcadas < checks.length;
            }
        }

        checks.forEach(function (check) {
            check.addEventListener('change', contar_marcadas);
        });

        if (maestro) {
            maestro.addEventListener('change', function () {
                checks.forEach(function (check) {
                    check.checked = maestro.checked;
                });
                contar_marcadas();
            });
        }

        contar_marcadas();

        // un tope escrito en una fila viaja en el POST como topes[ruta]; si el
        // hidden ya existe se reutiliza para no mandar el mismo campo dos veces
        function poner_tope_envio(form, base, ruta_id, minutos, temporal) {
            const campo = base + '[' + ruta_id + ']';
            let input = form.elements[campo];

            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = campo;
                if (temporal) {
                    input.dataset.topeTemporal = '1';
                }
                form.appendChild(input);
            }

            input.value = minutos;
        }

        function compilar(alcance) {
            const filtros = document.getElementById('filtros');
            const form = document.getElementById('compilar');
            const ruta = filtros.elements['ruta_id'].value;
            const marcadas = checks.filter(function (check) {
                return check.checked;
            });

            if (alcance === 'ruta' && !ruta) {
                alert('Elige una ruta en el selector para compilar solo ese tramo.');
                return;
            }

            if (alcance === 'seleccion' && marcadas.length === 0) {
                alert('Marca al menos una ruta en la tabla.');
                return;
            }

            // topes escritos en la fila de cada ruta marcada
            const topes_filas = marcadas.map(function (check) {
                const fila = check.closest('tr');
                return {
                    ruta: check.value,
                    input: fila.querySelector('[data-tope-fila]'),
                };
            });

            // el tope de la fila lo valida el propio input (cualquier minuto
            // entero: 425 es valido, no se exige multiplo de 10)
            for (const fila of topes_filas) {
                if (!fila.input) {
                    continue;
                }
                if (fila.input.value.trim() === '') {
                    alert('Escribe el tope en minutos de ' + fila.ruta + ' antes de compilar la selección.');
                    fila.input.focus();
                    return;
                }
                if (!fila.input.reportValidity()) {
                    return;
                }
            }

            let etiqueta = alcance === 'ruta'
                ? 'solo la ruta elegida'
                : (alcance === 'faltantes' ? 'solo las rutas sin promedio' : 'todas las rutas');

            if (alcance === 'seleccion') {
                etiqueta = 'las ' + marcadas.length + ' ruta(s) marcadas';
            }

            // la seleccion usa el tope de cada fila; los lotes, el tope de arriba
            const tope_lote = parseInt(filtros.elements['tope'].value, 10) || 0;
            let resumen_tope;

            if (alcance === 'seleccion') {
                resumen_tope = 'el tope escrito en la fila de cada marcada';
            } else if (tope_lote > 0) {
                resumen_tope = 'un tope de ' + tope_lote + ' min';
            } else if (filtros.elements['modo'].value === 'global') {
                // sin corte global el guardado cae al p82 de cada ruta
                resumen_tope = 'sin tope global, así que se guardará el p82 de cada ruta';
            } else {
                const combo = filtros.elements['modo'].selectedOptions[0].textContent.trim();
                resumen_tope = 'sin tope global: manda «' + combo + '»';
            }

            if (!confirm('Se guardarán los promedios con ' + resumen_tope + ' para ' + etiqueta + '. ¿Continuar?')) {
                return;
            }

            form.elements['tope'].value = filtros.elements['tope'].value;
            form.elements['modo'].value = alcance === 'seleccion' ? 'propio' : filtros.elements['modo'].value;
            form.elements['ruta_id'].value = ruta;
            form.elements['alcance'].value = alcance;
            form.elements['min_viajes'].value = filtros.elements['min_viajes'].value;

            // los topes temporales de una seleccion anterior no deben viajar
            form.querySelectorAll('[data-tope-temporal]').forEach(function (input) {
                input.remove();
            });

            if (alcance === 'seleccion') {
                topes_filas.forEach(function (fila) {
                    if (fila.input) {
                        poner_tope_envio(form, 'topes', fila.ruta, fila.input.value, true);
                    }
                });
            } else {
                // lotes: mandan los topes escritos que aun no se recalcularon
                document.querySelectorAll('[data-tope-fila][data-modificado]').forEach(function (input) {
                    poner_tope_envio(form, 'topes', input.dataset.ruta, input.value.trim(), false);
                });
            }

            form.submit();
        }

        document.getElementById('tope').addEventListener('input', function () {
            pintar_tope(parseInt(this.value, 10) || 0);
        });
    </script>
@endsection
