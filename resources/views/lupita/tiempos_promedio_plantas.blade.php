@extends('layouts.app')

@section('title', 'Promedios de espera por planta (fuera y dentro)')

@section('content')

    @php
        $resumen = $datos->resumen;
        $estado = $datos->estado;
        $espera_maxima = \App\Database\DBTiemposPromedio::ESPERA_MAXIMO;
        $fmt = fn ($minutos) => \App\Database\DBTiemposRuta::formatear_minutos($minutos);
        $puede_compilar = auth()->user()?->can('compilar promedios') ?? false;

        $color_descartado = function ($pct) {
            if ($pct === null) return 'secondary';
            if ($pct <= 18.5) return 'success';
            if ($pct <= 30) return 'warning';
            return 'danger';
        };

        $etiqueta_modo = [
            'recomendado' => 'el recomendado de cada planta (≤18 % descartado)',
            'global' => 'global para todas',
            'propio' => 'el tope de cada planta',
        ][$datos->modo] ?? 'el recomendado de cada planta';
    @endphp

    <style>
        /* el hover de darkly casi no se nota en tablas largas */
        #tabla_plantas > tbody > tr > td {
            transition: background-color .12s linear;
        }

        #tabla_plantas > tbody > tr:hover > td {
            background-color: rgba(var(--bs-info-rgb), .12);
        }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1">Promedios de espera por planta</h1>
            <p class="text-muted small mb-2">
                Cada viaje toca <strong>dos plantas</strong>: la de carga (origen de la ruta) y la de descarga
                (destino). Esta tabla las <strong>fusiona en una sola fila por planta</strong>, así que sus tiempos
                suman lo que espera cuando es origen y cuando es destino.
            </p>
            <p class="text-muted small mb-2">
                <strong>Fuera de planta</strong> = presenta para carga &rarr; inicio de carga (<em>E2</em>, cuando es
                origen) y llegada a destino &rarr; inicio de descargue (<em>E5</em>, cuando es destino).
                <strong>Dentro de planta</strong> = inicio de carga &rarr; inicio de ruta (<em>E3</em>) y inicio de
                descargue &rarr; fin de descargue (<em>E6</em>). Las columnas fusionadas ponderan por viajes.
            </p>
            <p class="text-muted small mb-0">
                El tope se aplica <strong>por planta y por lado</strong>: un viaje puede quedar fuera del tope en la
                planta de carga y no en la de descarga. El tope <strong>recomendado</strong> se calibra por planta para
                descartar como máximo <strong>18 %</strong> de sus lados: arranca en el p82 y sube el percentil solo si
                fuera + dentro descartan más. También se puede cambiar a un tope global o a uno por planta.
            </p>
        </div>
        <div class="text-end small">
            <span class="badge bg-{{ $estado->tabla ? 'success' : 'warning' }} text-dark">
                {{ $estado->tabla ? 'Tabla de plantas lista' : 'Falta la tabla (aplicar migración)' }}
            </span>
            @if($estado->plantas > 0)
                <div class="text-muted mt-1">
                    {{ $estado->plantas }} planta(s) compiladas · {{ number_format($estado->viajes) }} lado(s)<br>
                    Última compilación: {{ $estado->ultima }}
                </div>
            @endif
            <div class="mt-1">
                <a href="{{ route('lupita.tiempos.promedio.ver') }}" class="text-info">
                    Ver promedios de ruta <i class="bi bi-arrow-right"></i>
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

    @include('lupita._tiempos_promedio_tabs', ['tab' => 'plantas'])

    {{-- filtros: previsualizan la espera con los topes elegidos --}}
    <form method="GET" id="filtros_plantas" class="mt-3">
        <fieldset class="border p-3 rounded">
            <legend class="float-none w-auto px-2 fs-6">Tope de espera y filtros</legend>

            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="modo">Tope a aplicar</label>
                    <select name="modo" id="modo" class="form-control">
                        @foreach($datos->modos as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected($datos->modo === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        <strong>Recomendado</strong>: el percentil de cada planta que deja ≤18 % de sus lados fuera.
                        <strong>Global</strong>: el mismo tope para todas. <strong>El de cada planta</strong>: el
                        escrito en la fila o el guardado al compilar.
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="tope_fuera">Tope fuera (min)</label>
                    <input type="number" id="tope_fuera" name="tope_fuera" min="1" max="{{ $espera_maxima }}"
                           step="10" value="{{ $datos->tope_fuera }}" class="form-control" placeholder="rec.">
                    <div class="form-text">Vacío = el recomendado.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="tope_dentro">Tope dentro (min)</label>
                    <input type="number" id="tope_dentro" name="tope_dentro" min="1" max="{{ $espera_maxima }}"
                           step="10" value="{{ $datos->tope_dentro }}" class="form-control" placeholder="rec.">
                    <div class="form-text">Vacío = el recomendado.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="planta_id">Planta</label>
                    <select name="planta_id" id="planta_id" class="form-control">
                        <option value="">Todas</option>
                        @foreach($datos->plantas as $id => $planta)
                            <option value="{{ $id }}" @selected((string) $datos->planta_id === (string) $id)>
                                {{ $planta->nombre }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Filtra la vista; para compilar una sola usa el botón de su fila.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="departamento">Departamento de la planta</label>
                    <select name="departamento" id="departamento" class="form-control">
                        <option value="">Todos</option>
                        @foreach($datos->departamentos as $codigo => $nombre)
                            <option value="{{ $codigo }}" @selected((string) $datos->departamento === (string) $codigo)>
                                {{ $nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="min_viajes">Mínimo de lados</label>
                    <input type="number" name="min_viajes" id="min_viajes" min="0" step="10"
                           value="{{ $datos->min_viajes }}" class="form-control">
                    <div class="form-text">0 = todas.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="orden">Orden</label>
                    <select name="orden" id="orden" class="form-control">
                        @foreach($datos->ordenes as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected($datos->orden === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="ocultar_compiladas"
                               id="ocultar_compiladas" value="1" @checked($datos->ocultar_compiladas)>
                        <label class="form-check-label" for="ocultar_compiladas">Ocultar compiladas</label>
                    </div>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Calcular
                    </button>
                    <a href="{{ route('lupita.tiempos.promedio.plantas') }}" class="btn btn-secondary">Limpiar</a>
                </div>

                <div class="col-12">
                    <span class="text-muted small me-2">Atajos de espera:</span>
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach([60 => '1 h', 120 => '2 h', 240 => '4 h', 360 => '6 h', 720 => '12 h', 1440 => '24 h'] as $min => $etiqueta)
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="poner_espera({{ $min }})">{{ $etiqueta }}</button>
                        @endforeach
                        <button type="button" class="btn btn-outline-warning" onclick="poner_espera('')">Sin tope</button>
                    </div>
                </div>
            </div>
        </fieldset>
    </form>

    {{-- resumen: cada planta pesa igual, como en el resumen por ruta --}}
    <div class="row g-3 mt-1">
        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Plantas con datos</div>
                    <div class="fs-4 fw-bold">{{ number_format($resumen->plantas) }}</div>
                    <div class="small text-muted">{{ number_format($resumen->lados) }} lado(s) en total</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Lados medibles</div>
                    <div class="fs-4 fw-bold">{{ number_format($resumen->viajes) }}</div>
                    <div class="small text-muted">carga y descarga por separado</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Descartados con el tope</div>
                    <div class="fs-4 fw-bold text-{{ $color_descartado($resumen->pct_descartado) }}">
                        {{ number_format($resumen->descartados) }}
                        @if($resumen->pct_descartado !== null)
                            <span class="fs-6">({{ $resumen->pct_descartado }} %)</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        fuera {{ number_format($resumen->descartados_fuera) }} ·
                        dentro {{ number_format($resumen->descartados_dentro) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Espera fuera (prom.)</div>
                    <div class="fs-4 fw-bold">{{ $fmt($resumen->prom_fuera_min) }}</div>
                    <div class="small text-muted">E2 en carga · E5 en descarga</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Espera dentro (prom.)</div>
                    <div class="fs-4 fw-bold">{{ $fmt($resumen->prom_dentro_min) }}</div>
                    <div class="small text-muted">E3 en carga · E6 en descarga</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Tiempo en planta (prom.)</div>
                    <div class="fs-4 fw-bold">{{ $fmt($resumen->prom_espera_min) }}</div>
                    <div class="small text-muted">fuera + dentro</div>
                </div>
            </div>
        </div>
    </div>

    @can('compilar promedios')
    <form method="POST" action="{{ route('lupita.tiempos.promedio.plantas.compilar') }}"
          id="compilar_plantas_todas" class="mt-3">
        @csrf
        <input type="hidden" name="alcance" value="todas">
        <input type="hidden" name="modo" value="{{ $datos->modo }}">
        <input type="hidden" name="tope_fuera" value="{{ $datos->tope_fuera }}">
        <input type="hidden" name="tope_dentro" value="{{ $datos->tope_dentro }}">
        <input type="hidden" name="min_viajes" value="{{ $datos->min_viajes }}">
        <input type="hidden" name="orden" value="{{ $datos->orden }}">
        <input type="hidden" name="departamento" value="{{ $datos->departamento }}">
    </form>

    <form method="POST" action="{{ route('lupita.tiempos.promedio.plantas.eliminar') }}"
          id="eliminar_plantas" class="mt-3">
        @csrf
        <input type="hidden" name="modo" value="{{ $datos->modo }}">
        <input type="hidden" name="tope_fuera" value="{{ $datos->tope_fuera }}">
        <input type="hidden" name="tope_dentro" value="{{ $datos->tope_dentro }}">
        <input type="hidden" name="min_viajes" value="{{ $datos->min_viajes }}">
        <input type="hidden" name="orden" value="{{ $datos->orden }}">
        <input type="hidden" name="departamento" value="{{ $datos->departamento }}">
        <input type="hidden" name="planta_id" value="{{ $datos->planta_id }}">
        @if($datos->ocultar_compiladas)
            <input type="hidden" name="ocultar_compiladas" value="1">
        @endif
    </form>
    @endcan

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-3">
        <div>
            <span class="text-muted small">
                {{ count($datos->filas) }} planta(s) con datos · tope {{ $etiqueta_modo }}
                @if($datos->ocultas > 0 || $datos->ocultas_min > 0)
                    · {{ $datos->ocultas }} oculta(s) por compiladas, {{ $datos->ocultas_min }} por mínimo de lados
                @endif
            </span>
        </div>
        @can('compilar promedios')
            <div class="d-flex flex-wrap align-items-end gap-2">
                <button type="button" class="btn btn-success" onclick="compilar_plantas('todas')">
                    <i class="bi bi-database-add"></i> Compilar todas las plantas
                </button>
                <button type="button" class="btn btn-outline-success" id="btn_seleccion"
                        onclick="compilar_plantas('seleccion')" disabled
                        title="Compila cada planta marcada con el tope escrito en su fila">
                    <i class="bi bi-check2-square"></i>
                    Compilar las <span id="seleccion_n">0</span> marcadas
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn_eliminar"
                        onclick="eliminar_marcadas()" disabled
                        title="Borra el promedio guardado de las plantas marcadas">
                    <i class="bi bi-trash"></i>
                    Eliminar promedio de las <span id="seleccion_borrar_n">0</span> marcadas
                </button>
                <button type="button" class="btn btn-outline-primary" id="btn_recalcular"
                        onclick="recalcular_plantas()"
                        title="Vuelve a medir la tabla con el tope escrito en cada fila, sin guardar nada">
                    <i class="bi bi-arrow-repeat"></i> Recalcular
                </button>
                <span class="small text-warning" id="topes_pendientes" hidden></span>
            </div>
        @endcan
    </div>

    <div class="table-responsive mt-2">
        <table class="table table-sm table-hover align-middle" id="tabla_plantas">
            <thead>
            <tr>
                @if($puede_compilar)
                    <th class="text-center" style="width: 34px">
                        <input type="checkbox" class="form-check-input" id="marcar_todas"
                               title="Marcar o desmarcar las plantas visibles">
                    </th>
                @endif
                <th>Planta</th>
                <th>Dpto.</th>
                <th class="text-end" title="Rutas que la usan como planta de carga / como planta de descarga">
                    Rutas
                </th>
                <th class="text-end" title="Veces que un viaje tocó esta planta dentro de los topes: carga + descarga">
                    Lados
                </th>
                <th class="text-end" title="Lados que se pierden con el tope de esta planta">Descartados</th>
                <th class="text-end">%</th>
                <th class="text-end" title="Espera fuera de planta: E2 si es origen, E5 si es destino">
                    Fuera
                </th>
                <th class="text-end" title="Espera dentro de planta: E3 si es origen, E6 si es destino">
                    Dentro
                </th>
                <th class="text-end" title="Fuera + dentro">Total</th>
                <th class="text-end" title="Escribe el tope de esta planta. Vacío = el de arriba (o el recomendado en ese modo)">
                    Tope por planta
                </th>
                <th>Compilado</th>
            </tr>
            </thead>
            <tbody>
            @forelse($datos->filas as $fila)
                <tr>
                    @if($puede_compilar)
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input" form="compilar_plantas_todas"
                                   name="plantas[]" value="{{ $fila->planta_id }}" data-check-planta
                                   title="Incluir esta planta al compilar o eliminar las marcadas">
                        </td>
                    @endif
                    <td>
                        <div class="text-nowrap">{{ $fila->nombre }}</div>
                        <div class="text-muted" style="font-size: .72rem">
                            {{ $fila->planta_id }}
                            @if($fila->palabra_clave)
                                · {{ $fila->palabra_clave }}
                            @endif
                        </div>
                    </td>
                    <td class="small text-muted text-nowrap">{{ $fila->departamento ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        {{ number_format($fila->rutas_carga) }} / {{ number_format($fila->rutas_descarga) }}
                        <div class="text-muted" style="font-size: .72rem">carga / descarga</div>
                    </td>
                    <td class="text-end text-nowrap">
                        {{ number_format($fila->viajes) }}
                        <div class="text-muted" style="font-size: .72rem">
                            {{ number_format($fila->viajes_carga) }} carga ·
                            {{ number_format($fila->viajes_descarga) }} descarga
                        </div>
                    </td>
                    <td class="text-end text-nowrap">
                        {{ number_format($fila->descartados) }}
                        @if($fila->descartados > 0)
                            <div class="text-muted" style="font-size: .72rem">
                                fuera {{ number_format($fila->descartados_fuera) }} ·
                                dentro {{ number_format($fila->descartados_dentro) }}
                            </div>
                        @endif
                    </td>
                    <td class="text-end">
                        <span class="badge bg-{{ $color_descartado($fila->pct_descartado) }}">
                            {{ $fila->pct_descartado === null ? '—' : $fila->pct_descartado . ' %' }}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        {{ $fmt($fila->prom_fuera_min) }}
                        <div class="text-muted" style="font-size: .72rem">
                            carga {{ $fmt($fila->prom_fuera_carga_min) }} ·
                            descarga {{ $fmt($fila->prom_fuera_descarga_min) }}
                        </div>
                    </td>
                    <td class="text-end text-nowrap">
                        {{ $fmt($fila->prom_dentro_min) }}
                        <div class="text-muted" style="font-size: .72rem">
                            carga {{ $fmt($fila->prom_dentro_carga_min) }} ·
                            descarga {{ $fmt($fila->prom_dentro_descarga_min) }}
                        </div>
                    </td>
                    <td class="text-end text-nowrap fw-bold">{{ $fmt($fila->prom_espera_min) }}</td>
                    <td class="text-end">
                        @can('compilar promedios')
                            <form method="POST" action="{{ route('lupita.tiempos.promedio.plantas.compilar') }}"
                                  class="d-flex flex-column gap-1 align-items-end"
                                  onsubmit="return confirm('¿Compilar solo esta planta con el tope escrito?');">
                                @csrf
                                <input type="hidden" name="alcance" value="planta">
                                <input type="hidden" name="planta_id" value="{{ $fila->planta_id }}">
                                <input type="hidden" name="modo" value="{{ $datos->modo }}">
                                <input type="hidden" name="tope_fuera" value="{{ $datos->tope_fuera }}">
                                <input type="hidden" name="tope_dentro" value="{{ $datos->tope_dentro }}">
                                <div class="d-flex gap-1">
                                    <input type="number" name="topes_fuera[{{ $fila->planta_id }}]" min="1" step="1"
                                           value="{{ $fila->tope_fuera_min }}" class="form-control form-control-sm"
                                           style="width: 84px" placeholder="Fuera"
                                           title="Tope de espera FUERA de planta (E2/E5). Vacío = el de arriba"
                                           data-espera-planta data-campo="fuera" data-planta="{{ $fila->planta_id }}">
                                    <input type="number" name="topes_dentro[{{ $fila->planta_id }}]" min="1" step="1"
                                           value="{{ $fila->tope_dentro_min }}" class="form-control form-control-sm"
                                           style="width: 84px" placeholder="Dentro"
                                           title="Tope de espera DENTRO de planta (E3/E6). Vacío = el de arriba"
                                           data-espera-planta data-campo="dentro" data-planta="{{ $fila->planta_id }}">
                                    <button type="submit" class="btn btn-sm btn-outline-success"
                                            title="Compilar solo esta planta">
                                        <i class="bi bi-check2"></i>
                                    </button>
                                </div>
                            </form>
                        @else
                            <span class="small text-muted">
                                fuera {{ $fila->tope_fuera_min ?? '—' }} · dentro {{ $fila->tope_dentro_min ?? '—' }}
                            </span>
                        @endcan
                        <div class="mt-1" style="font-size: .7rem">
                            @if($fila->p82_fuera_min === null && $fila->p82_dentro_min === null)
                                <span class="text-muted">recomendado: —</span>
                            @else
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-info text-decoration-none"
                                        style="font-size: .7rem"
                                        data-p82-fuera="{{ $fila->p82_fuera_min }}"
                                        data-p82-dentro="{{ $fila->p82_dentro_min }}"
                                        title="Poner el tope recomendado de esta planta (deja fuera ≤18 % de sus lados)">
                                    p{{ $fila->percentil_rec ?? 82 }}: fuera {{ $fmt($fila->p82_fuera_min) }} ·
                                    dentro {{ $fmt($fila->p82_dentro_min) }}
                                </button>
                            @endif
                        </div>
                    </td>
                    <td class="text-nowrap">
                        @if($fila->guardado)
                            <div class="text-muted" style="font-size: .72rem">
                                fuera {{ $fila->guardado->tope_fuera_min ?? '—' }} ·
                                dentro {{ $fila->guardado->tope_dentro_min ?? '—' }}
                                @if(!empty($fila->guardado->percentil))
                                    · p{{ $fila->guardado->percentil }}
                                @endif
                            </div>
                            <span class="badge bg-success text-dark" title="Promedio guardado">
                                {{ $fmt($fila->guardado->prom_espera_min) }}
                            </span>
                            <div class="text-muted" style="font-size: .72rem">{{ $fila->guardado->actualizado_en }}</div>
                        @else
                            <span class="badge bg-secondary">sin compilar</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $puede_compilar ? 12 : 11 }}" class="text-center text-muted py-4">
                        @if($datos->ocultar_compiladas && $datos->ocultas > 0)
                            Todas las plantas con datos ya están compiladas: apaga
                            <strong>Ocultar compiladas</strong> para verlas con sus topes.
                        @elseif($datos->ocultas_min > 0)
                            Ninguna planta llega a {{ number_format($datos->min_viajes) }} lados medibles: baja el
                            mínimo o pon 0 para verlas todas.
                        @else
                            No hay viajes con planta asignada en la data.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-muted small mt-2">
        Un <strong>lado</strong> es una planta tocada por un viaje: la carga y la descarga cuentan por separado, así que
        un viaje descartado en las dos plantas suma dos. El tope recomendado se recalcula en cada carga y se guarda con
        el percentil que quedó calibrado. Las plantas sin lados dentro del tope
        <strong>no se crean ni se modifican</strong>, igual que las rutas. Los checks sirven para
        <strong>compilar solo las marcadas</strong> (con el tope escrito en su fila) o para
        <strong>eliminar su promedio guardado</strong>; <strong>Recalcular</strong> solo vuelve a medir la tabla con el
        tope escrito en cada fila y no guarda nada.
    </p>

    <script>
        // atajos de los topes globales de la pantalla
        function poner_espera(minutos) {
            ['tope_fuera', 'tope_dentro'].forEach(function (id) {
                const input = document.getElementById(id);
                if (input) {
                    input.value = minutos;
                }
            });
            document.getElementById('modo').value = minutos === '' ? 'recomendado' : 'global';
        }

        // el tope recomendado de la fila se pone en sus dos inputs con un clic
        document.querySelectorAll('[data-p82-fuera]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                const fila = boton.closest('tr');
                fila.querySelectorAll('[data-espera-planta]').forEach(function (input) {
                    const valor = input.dataset.campo === 'fuera'
                        ? boton.dataset.p82Fuera
                        : boton.dataset.p82Dentro;

                    if (valor) {
                        input.value = valor;
                        input.dataset.modificado = '1';
                        input.classList.add('border-warning');
                    }
                });
                contar_topes_pendientes();
            });
        });

        // topes escritos que todavia no se han aplicado con Recalcular
        function contar_topes_pendientes() {
            const aviso = document.getElementById('topes_pendientes');
            if (!aviso) {
                return;
            }
            const n = document.querySelectorAll('[data-espera-planta][data-modificado]').length;
            aviso.textContent = n + ' tope(s) modificado(s): pulsa Recalcular para aplicarlos';
            aviso.hidden = n === 0;
        }

        document.querySelectorAll('[data-espera-planta]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.dataset.modificado = '1';
                input.classList.add('border-warning');
                contar_topes_pendientes();
            });
        });

        // ---- checks de la tabla: se enganchan al form de compilacion con el
        //      atributo form, porque cada fila tiene su propio formulario
        const checks = Array.from(document.querySelectorAll('[data-check-planta]'));
        const maestro = document.getElementById('marcar_todas');
        const boton_seleccion = document.getElementById('btn_seleccion');
        const boton_eliminar = document.getElementById('btn_eliminar');
        const contador = document.getElementById('seleccion_n');
        const contador_borrar = document.getElementById('seleccion_borrar_n');

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

            if (contador) {
                contador.textContent = marcadas;
            }
            if (contador_borrar) {
                contador_borrar.textContent = marcadas;
            }
            if (boton_seleccion) {
                boton_seleccion.disabled = marcadas === 0;
            }
            if (boton_eliminar) {
                boton_eliminar.disabled = marcadas === 0;
            }

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

        // un tope escrito en una fila viaja en el POST como topes_fuera[planta];
        // si el hidden ya existe se reutiliza para no mandar el mismo campo dos veces
        function poner_tope_envio(form, base, planta, minutos, temporal) {
            const campo = base + '[' + planta + ']';
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

        // los topes escritos en las filas viajan con el lote de plantas: el
        // formulario del lote solo lleva el modo y los topes globales
        function compilar_plantas(alcance) {
            const filtros = document.getElementById('filtros_plantas');
            const form = document.getElementById('compilar_plantas_todas');

            if (!form) {
                return;
            }

            const marcadas = checks.filter(function (check) {
                return check.checked;
            });

            if (alcance === 'seleccion' && marcadas.length === 0) {
                alert('Marca al menos una planta en la tabla.');
                return;
            }

            // la seleccion manda solo los topes de sus filas; el lote, todos
            const filas = alcance === 'seleccion'
                ? marcadas.map(function (check) {
                    return check.closest('tr');
                })
                : Array.from(document.querySelectorAll('[data-espera-planta]'))
                    .map(function (input) {
                        return input.closest('tr');
                    });

            // los topes temporales de un envio anterior no deben viajar
            form.querySelectorAll('[data-tope-temporal]').forEach(function (input) {
                input.remove();
            });

            filas.forEach(function (fila) {
                if (!fila) {
                    return;
                }
                fila.querySelectorAll('[data-espera-planta]').forEach(function (input) {
                    if (input.value === '') {
                        return;
                    }
                    poner_tope_envio(
                        form,
                        input.dataset.campo === 'fuera' ? 'topes_fuera' : 'topes_dentro',
                        input.dataset.planta,
                        input.value.trim(),
                        true
                    );
                });
            });

            form.elements['alcance'].value = alcance;
            form.elements['modo'].value = alcance === 'seleccion' ? 'propio' : filtros.elements['modo'].value;
            ['tope_fuera', 'tope_dentro'].forEach(function (nombre) {
                const destino = form.elements[nombre];
                if (destino) {
                    destino.value = filtros.elements[nombre] ? filtros.elements[nombre].value : '';
                }
            });

            const modo = filtros.elements['modo'].value;
            let etiqueta = modo === 'recomendado'
                ? 'el tope recomendado de cada planta (≤18 % descartado)'
                : (modo === 'global' ? 'el tope global de la pantalla' : 'el tope escrito o guardado de cada planta');

            if (alcance === 'seleccion') {
                etiqueta = 'el tope escrito en la fila de las ' + marcadas.length + ' planta(s) marcadas';
            }

            if (!confirm('¿Compilar las plantas con ' + etiqueta + '? Los promedios de ruta no se tocan.')) {
                return;
            }

            form.submit();
        }

        // vuelve a medir la tabla con el tope escrito en cada fila, sin guardar
        function recalcular_plantas() {
            const filtros = document.getElementById('filtros_plantas');
            const params = new URLSearchParams(new FormData(filtros));

            params.set('modo', 'propio');

            // los topes tocados en la tabla mandan sobre lo que venia en la URL.
            // Se cortan en 120: mas entradas harian la URL mas larga de lo que
            // acepta el servidor (8 KB); el resto usa su tope guardado.
            let topes = 0;
            document.querySelectorAll('[data-espera-planta][data-modificado]').forEach(function (input) {
                if (topes++ >= 120) {
                    return;
                }
                const base = input.dataset.campo === 'fuera' ? 'topes_fuera' : 'topes_dentro';
                params.append(base + '[' + input.dataset.planta + ']', input.value);
            });

            window.location = window.location.pathname + '?' + params.toString();
        }

        // borra el promedio guardado de las plantas marcadas
        function eliminar_marcadas() {
            const form = document.getElementById('eliminar_plantas');

            if (!form) {
                return;
            }

            const marcadas = checks.filter(function (check) {
                return check.checked;
            });

            if (marcadas.length === 0) {
                alert('Marca al menos una planta en la tabla.');
                return;
            }

            form.querySelectorAll('[data-planta-eliminar]').forEach(function (input) {
                input.remove();
            });

            let con_promedio = 0;
            marcadas.forEach(function (check) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'plantas[]';
                input.value = check.value;
                input.dataset.plantaEliminar = '1';
                form.appendChild(input);

                const fila = check.closest('tr');
                if (fila && fila.querySelector('.badge.bg-success')) {
                    con_promedio++;
                }
            });

            const aviso = con_promedio === 0
                ? 'Ninguna de las ' + marcadas.length + ' planta(s) marcadas tiene promedio guardado. ¿Continuar igual?'
                : '¿Eliminar el promedio guardado de ' + marcadas.length + ' planta(s)? (' + con_promedio + ' tienen promedio)';

            if (!confirm(aviso + ' Los promedios de ruta no se tocan.')) {
                return;
            }

            form.submit();
        }
    </script>

@endsection
