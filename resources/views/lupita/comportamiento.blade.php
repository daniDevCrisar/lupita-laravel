@extends('layouts.app')

@section('title', 'Comportamiento por placa y conductor')

@section('content')

    @php
        $r = $datos->resumen;
        $confianza = $datos->confianza ?? [];
        $esPlaca = $grupo === 'placa';
        // el cambio de pestana conserva los filtros del formulario
        $url_grupo = fn($g) => route('lupita.comportamiento', array_merge(request()->query(), ['grupo' => $g]));
        $url_ficha = fn($g, $c) => route('lupita.comportamiento.ficha', array_merge(
            request()->query(),
            ['grupo' => $g, 'clave' => $c]
        ));
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1>Comportamiento por placa y conductor</h1>
            <p class="text-muted small mb-1">
                Los habitos salen de las mismas marcas del ciclo logistico que usa el modulo de tiempos:
                <em>presenta para carga</em> &rarr; <em>inicio de carga</em> &rarr; <em>fin de carga</em> &rarr;
                <em>inicio de ruta</em> &rarr; <em>llegada a destino</em> &rarr; <em>inicio de descargue</em> &rarr;
                <em>fin de descargue</em>.
            </p>
            <p class="text-muted small mb-1">
                <strong>Horas fuera de planta</strong>: de cada marca se muestra la hora mas repetida (franja de &plusmn;1 h)
                y la <strong>regularidad</strong>, el porcentaje de viajes que caen dentro de ella.
                <em>Sale de planta</em> usa <em>inicio de ruta</em> (cuando deja la planta de carga),
                <em>presenta en carga</em> usa <em>presenta para carga</em> y <em>llega a destino</em> usa
                <em>llegada a destino</em>, que cierra el tramo en calle.
                <strong>Ruta habitual</strong>: la ruta origen - destino mas repetida y su participacion.
            </p>
            <p class="text-muted small mb-0">
                <strong>Ciclo</strong> es la suma de los promedios E2 - E6. <strong>Ciclo real/viaje</strong> mide el viaje completo
                (<em>presenta para carga</em> → <em>fin de descargue</em>) promedio por viaje, y <strong>Ruta/viaje</strong>
                el tramo <em>inicio de ruta</em> → <em>llegada a destino</em> (la etapa E4).
                Los viajes con mas de 7 dias entre esas marcas se descartan por error de registro.
            </p>
            <p class="text-muted small mb-0">
                <strong>Puntualidad</strong>: mediana de la diferencia entre <em>presenta para carga</em> y el
                <em>compromiso de carga</em>; un valor negativo significa que llego antes del compromiso.
                Los pares con mas de 24 h de diferencia se descartan por error de registro, y el
                <strong>tope por etapa</strong> descarta el viaje completo cuando alguna etapa cae fuera de 0 a ese maximo.
            </p>
            <p class="text-muted small mb-0">
                <strong>Confiabilidad de presenta</strong>: probabilidad de acertar la hora de <em>presenta para carga</em>.
                La regularidad de la franja se ajusta por cuantos choferes efectivos manejan el grupo: una placa que
                rotan varios choferes tiene varias rutinas horarias, asi que su acierto se reparte y baja;
                una placa con un solo chofer conserva su regularidad casi completa.
            </p>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <input type="hidden" name="grupo" value="{{ $grupo }}">

            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">Filtros del reporte</legend>

                <div class="row g-3">

                    <div class="col-md-2">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio"
                               value="{{ request('fecha_inicio') }}" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin"
                               value="{{ request('fecha_fin') }}" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="placa">Placa</label>
                        <input type="text" id="placa" name="placa" value="{{ request('placa') }}"
                               class="form-control" placeholder="Todas">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="conductor">Conductor</label>
                        <input type="text" id="conductor" name="conductor" value="{{ request('conductor') }}"
                               class="form-control" placeholder="Nombre o id">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="trt">Transportista</label>
                        <input type="text" id="trt" name="trt" value="{{ request('trt') }}"
                               class="form-control" placeholder="Nombre o id">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="ruta_id">Ruta</label>
                        <select name="ruta_id" id="ruta_id" class="form-select">
                            <option value="">Todas</option>
                            @foreach($rutas as $ruta)
                                <option value="{{ $ruta->id }}" @selected((string) request('ruta_id') === (string) $ruta->id)>
                                    {{ $nombres_rutas[$ruta->id] ?? $ruta->id }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="tope">Tope por etapa</label>
                        <select name="tope" id="tope" class="form-select">
                            @foreach($topes as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected((string) request('tope', $datos->tope) === (string) $valor)>
                                    {{ $etiqueta }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="min_viajes">Mínimo de viajes</label>
                        <input type="number" id="min_viajes" name="min_viajes" min="1" step="1"
                               value="{{ request('min_viajes', 1) }}" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="limite">Máximo de filas</label>
                        <input type="number" id="limite" name="limite" min="1" max="500"
                               value="{{ request('limite', 100) }}" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="ordenar_por">Ordenar por</label>
                        <div class="row g-2">
                            <div class="col">
                                <select name="ordenar_por" id="ordenar_por" class="form-select">
                                    <option value="viajes" @selected(request('ordenar_por', 'viajes') === 'viajes')>Cantidad de viajes</option>
                                    <option value="dias" @selected(request('ordenar_por') === 'dias')>Días operados</option>
                                    <option value="ruta_pct" @selected(request('ordenar_por') === 'ruta_pct')>Ruta habitual (%)</option>
                                    <option value="regularidad" @selected(request('ordenar_por') === 'regularidad')>Regularidad horaria</option>
                                    <option value="hora" @selected(request('ordenar_por') === 'hora')>Hora de salida de planta</option>
                                    <option value="hora_presenta" @selected(request('ordenar_por') === 'hora_presenta')>Hora de presenta en carga</option>
                                    <option value="hora_llegada" @selected(request('ordenar_por') === 'hora_llegada')>Hora de llegada a destino</option>
                                    <option value="regularidad_presenta" @selected(request('ordenar_por') === 'regularidad_presenta')>Regularidad de presenta</option>
                                    <option value="regularidad_llegada" @selected(request('ordenar_por') === 'regularidad_llegada')>Regularidad de llegada</option>
                                    <option value="confiabilidad" @selected(request('ordenar_por') === 'confiabilidad')>Confiabilidad de presenta</option>
                                    <option value="desvio" @selected(request('ordenar_por') === 'desvio')>Puntualidad (desvío)</option>
                                    <option value="ciclo" @selected(request('ordenar_por') === 'ciclo')>Ciclo completo</option>
                                    <option value="ciclo_real" @selected(request('ordenar_por') === 'ciclo_real')>Ciclo real por viaje</option>
                                    <option value="ruta_prom" @selected(request('ordenar_por') === 'ruta_prom')>Ruta por viaje</option>
                                    <option value="cerrados" @selected(request('ordenar_por') === 'cerrados')>Viajes cerrados</option>
                                    <option value="rutas" @selected(request('ordenar_por') === 'rutas')>Rutas distintas</option>
                                    <option value="par_pct" @selected(request('ordenar_por') === 'par_pct')>Par habitual (%)</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="orden" id="orden_desc" value="desc"
                                        @checked(request('orden', 'desc') !== 'asc')>
                                    <label class="btn btn-outline-primary" for="orden_desc">Desc</label>

                                    <input type="radio" class="btn-check" name="orden" id="orden_asc" value="asc"
                                        @checked(request('orden') === 'asc')>
                                    <label class="btn btn-outline-primary" for="orden_asc">Asc</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> Generar
                        </button>
                        <a href="{{ route('lupita.comportamiento', ['grupo' => $grupo]) }}" class="btn btn-secondary">
                            Limpiar
                        </a>
                    </div>

                </div>
            </fieldset>
        </form>
    </div>

    {{-- ================= TOTALES DEL RANGO ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes del rango</div>
                    <div class="fs-4 fw-bold">{{ number_format($r->viajes, 0, ',', '.') }}</div>
                    <div class="small text-muted">
                        {{ $r->placas }} placas · {{ $r->conductores }} conductores
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Rutas distintas</div>
                    <div class="fs-4 fw-bold">{{ $r->rutas }}</div>
                    <div class="small text-muted">{{ $r->dias }} días con salidas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-success h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes cerrados</div>
                    <div class="fs-4 fw-bold">{{ $r->pct_cerrados ?? '—' }}%</div>
                    <div class="small text-muted">
                        {{ number_format($r->cerrados, 0, ',', '.') }} con fin de descargue
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-warning h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Puntualidad (mediana)</div>
                    <div class="fs-4 fw-bold">
                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_desvio($r->desvio_mediana) }}">
                            {{ \App\Database\DBComportamiento::texto_desvio($r->desvio_mediana) }}
                        </span>
                    </div>
                    <div class="small text-muted">
                        {{ $r->pct_puntual ?? '—' }}% en ventana · {{ $r->pct_tarde ?? '—' }}% tarde
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Ciclo promedio (E2 - E6)</div>
                    <div class="fs-4 fw-bold">{{ \App\Database\DBTiemposRuta::formatear_minutos($r->ciclo) }}</div>
                    <div class="small text-muted">
                        tránsito {{ \App\Database\DBTiemposRuta::formatear_minutos($r->e4) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-danger h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Sin conductor</div>
                    <div class="fs-4 fw-bold">{{ $r->pct_sin_conductor ?? '—' }}%</div>
                    <div class="small text-muted">
                        {{ number_format($r->sin_conductor, 0, ',', '.') }} viajes sin chofer en la referencia ni en llamadas
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card border-{{ \App\Database\DBComportamiento::color_confiabilidad($confianza['confiabilidad'] ?? null) }} h-100">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
                    <div>
                        <div class="text-muted small">Confiabilidad de presenta en carga</div>
                        <div class="fs-4 fw-bold">{{ $confianza['confiabilidad'] ?? '—' }}%</div>
                    </div>
                    <div class="small">
                        <div>
                            Franja mas probable en el rango:
                            <strong>{{ $confianza['franja'] ?? '—' }}</strong>
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($confianza['regularidad'] ?? null) }}">
                                {{ $confianza['regularidad'] ?? '—' }}% regular
                            </span>
                        </div>
                        <div class="text-muted">
                            {{ number_format($confianza['pares'] ?? 0, 0, ',', '.') }} pares placa - chofer ·
                            {{ number_format($confianza['placas'] ?? 0, 0, ',', '.') }} placas ·
                            {{ $confianza['efectivos'] ?? '—' }} choferes efectivos por placa
                        </div>
                    </div>
                    <div class="text-muted small ms-auto" style="max-width: 420px">
                        Promedio ponderado por viajes: al acierto de la franja de cada placa se le descuenta la rotacion de
                        choferes (1 / suma de participaciones al cuadrado). Si la placa rota entre varios choferes, el horario
                        de cada uno pesa menos y baja la confiabilidad.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= PESTANAS ================= --}}
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $esPlaca ? 'active' : '' }}" href="{{ $url_grupo('placa') }}">
                <i class="bi bi-truck"></i> Por placa
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $esPlaca ? '' : 'active' }}" href="{{ $url_grupo('conductor') }}">
                <i class="bi bi-person-badge"></i> Por conductor
            </a>
        </li>
    </ul>

    <div class="table-responsive" style="max-height: 720px; overflow-y: auto;">
        <table class="table table-bordered table-hover table-sm table-dark small mb-0">
            <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
            <tr>
                <th>{{ $esPlaca ? 'Placa' : 'Conductor' }}</th>
                <th class="text-center" title="Transportista con mas viajes del grupo">TRT habitual</th>
                <th class="text-center">Viajes</th>
                <th class="text-center" title="Días distintos con inicio de ruta">Días</th>
                <th class="text-center" title="Viajes por semana entre la primera y la última salida">/Semana</th>
                <th class="text-center" title="Rutas origen - destino distintas">Rutas</th>
                <th title="Ruta mas repetida y su participación en los viajes">Ruta habitual</th>
                <th class="text-center" title="Hora de inicio de ruta mas repetida: cuando deja la planta de carga">
                    Sale de planta
                </th>
                <th class="text-center" title="Hora de presenta para carga mas repetida">
                    Presenta en carga
                </th>
                <th class="text-center" title="Hora de llegada a destino mas repetida: cierra el tramo fuera de planta">
                    Llega a destino
                </th>
                <th class="text-center" title="Probabilidad de acertar la hora de presenta para carga: regularidad de la franja dividida entre los choferes efectivos que manejan el grupo">
                    Confiab. presenta
                </th>
                <th class="text-center" title="Tres horas probables de la proxima carga, con el minuto promedio real. Se usa el dia de la semana de hoy y solo cuentan las horas que todavia no pasaron">
                    Prox. carga
                </th>
                <th class="text-center" title="Mediana de presenta para carga - compromiso de carga. Negativo = llegó antes">
                    Puntualidad
                </th>
                @foreach($etapas as $numero => $nombre)
                    <th class="text-center" title="{{ $nombre }} (E{{ $numero }})">E{{ $numero }}</th>
                @endforeach
                <th class="text-center" title="Suma de los promedios E2 a E6; vacío si alguna etapa no tiene datos">
                    Ciclo
                </th>
                <th class="text-center" title="Duración media del viaje completo (presenta para carga → fin de descargue), promediada viaje a viaje">
                    Ciclo real/viaje
                </th>
                <th class="text-center" title="Duración media del tramo inicio de ruta → llegada a destino (es la etapa E4), promediada por viaje">
                    Ruta/viaje
                </th>
                @if($esPlaca)
                    <th class="text-center" title="Conductores distintos que manejaron la placa">Choferes</th>
                    <th title="Conductor con mas viajes en la placa y su participación">Chofer habitual</th>
                @else
                    <th class="text-center" title="Placas distintas que manejó el conductor">Placas</th>
                    <th title="Placa con mas viajes del conductor y su participación">Placa habitual</th>
                @endif
                <th class="text-center" title="Porcentaje de viajes con fin de descargue registrado">Cerrados</th>
                <th class="text-center">Ficha</th>
            </tr>
            </thead>
            <tbody>
            @forelse($datos->ranking as $fila)
                <tr>
                    <td>
                        <a href="{{ $url_ficha($grupo, $fila->clave) }}" class="text-decoration-none fw-bold">
                            {{ $fila->nombre }}
                        </a>
                        @if(!$esPlaca && (int) $fila->clave === 0)
                            <div class="text-muted" style="font-size: .7rem">conductor ausente en la referencia</div>
                        @endif
                    </td>
                    <td class="text-center" style="max-width: 190px">
                        <span class="d-inline-block text-truncate" style="max-width: 180px"
                              title="{{ $fila->trt }}">{{ $fila->trt ?: '—' }}</span>
                    </td>
                    <td class="text-center fw-bold">{{ number_format($fila->viajes, 0, ',', '.') }}</td>
                    <td class="text-center">{{ $fila->dias }}</td>
                    <td class="text-center">{{ $fila->viajes_semana ?? '—' }}</td>
                    <td class="text-center">{{ $fila->rutas }}</td>
                    <td style="max-width: 230px">
                        @if($fila->ruta_habitual)
                            <span class="d-inline-block text-truncate" style="max-width: 220px"
                                  title="{{ $fila->ruta_habitual }}">{{ $fila->ruta_habitual }}</span>
                            <div class="text-muted" style="font-size: .7rem">{{ $fila->ruta_pct }}% de sus viajes</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    @foreach([[ $fila->hora_habitual ?? null, $fila->regularidad ?? null, [] ],
                              [ $fila->hora_presenta ?? null, $fila->regularidad_presenta ?? null, $fila->presenta_top ?? [] ],
                              [ $fila->hora_llegada ?? null, $fila->regularidad_llegada ?? null, [] ]] as [$hora, $regularidad, $top])
                        <td class="text-center">
                            @if($hora === null)
                                <span class="text-muted">—</span>
                            @else
                                <div class="fw-bold">{{ \App\Database\DBComportamiento::franja_habitual($hora) }}</div>
                                <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($regularidad) }}">
                                    {{ $regularidad }}% regular
                                </span>
                                @if(count($top) > 0)
                                    <div class="text-muted" style="font-size: .7rem"
                                         title="Otras horas de presenta en carga, fuera de la franja habitual">
                                        luego {{ \App\Database\DBComportamiento::texto_probables($top, 0, 2) }}
                                    </div>
                                @endif
                            @endif
                        </td>
                    @endforeach
                    <td class="text-center" title="Regularidad {{ $fila->regularidad_presenta ?? '—' }}% ajustada por cuantos {{ $esPlaca ? 'choferes' : 'placas' }} efectivos manejan el grupo: {{ $fila->actores_efectivos ?? '—' }} de {{ $fila->actores ?? '—' }} (cuenta aparte los viajes sin chofer)">
                        @if(($fila->confiabilidad ?? null) === null)
                            <span class="text-muted">—</span>
                        @else
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_confiabilidad($fila->confiabilidad) }}">
                                {{ $fila->confiabilidad }}%
                            </span>
                            <div class="text-muted" style="font-size: .7rem">
                                {{ $fila->actores_efectivos ?? '—' }} ef. de {{ $fila->actores ?? '—' }}
                            </div>
                        @endif
                    </td>
                    <td class="text-center" style="min-width: 110px"
                        @if(($fila->proxima->hora ?? null) !== null)
                            title="Opciones del {{ strtolower($fila->proxima->dia_nombre) }}: {{ \App\Database\DBComportamiento::texto_opciones($fila->proxima->opciones ?? []) }} ({{ $fila->proxima->n }} {{ $fila->proxima->n === 1 ? 'viaje' : 'viajes' }} en la primera) · confiabilidad ajustada {{ $fila->proxima->confiabilidad ?? '—' }}%"
                        @endif>
                        @if(($fila->proxima->hora ?? null) === null)
                            <span class="text-muted">—</span>
                        @else
                            <div class="fw-bold text-nowrap">
                                {{ ucfirst($fila->proxima->relativo) }} {{ \App\Database\DBComportamiento::etiqueta_hora_min($fila->proxima->hora, $fila->proxima->minuto ?? 0) }}
                            </div>
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($fila->proxima->pct) }}">
                                {{ $fila->proxima->pct }}%
                            </span>
                            @if(count($fila->proxima->opciones ?? []) > 1)
                                <div class="text-muted" style="font-size: .7rem">
                                    luego {{ \App\Database\DBComportamiento::texto_opciones($fila->proxima->opciones ?? [], 1, 2) }}
                                </div>
                            @endif
                        @endif
                    </td>
                    <td class="text-center">
                        @if($fila->desvio_mediana === null)
                            <span class="text-muted">—</span>
                        @else
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_desvio($fila->desvio_mediana) }}">
                                {{ \App\Database\DBComportamiento::texto_desvio($fila->desvio_mediana) }}
                            </span>
                            <div class="text-muted" style="font-size: .7rem">
                                {{ $fila->pct_puntual ?? '—' }}% ±30 min · {{ $fila->pct_tarde ?? '—' }}% tarde
                            </div>
                        @endif
                    </td>
                    @foreach($etapas as $numero => $nombre)
                        @php $campo = 'e' . $numero; @endphp
                        <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->$campo) }}</td>
                    @endforeach
                    <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo ?? null) }}</td>
                    <td class="text-center" title="Promedio sobre {{ $fila->ciclo_real_n ?? 0 }} viajes con presenta y fin de descargue">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo_real ?? null) }}</td>
                    <td class="text-center" title="Duración media del tramo inicio de ruta → llegada a destino, sobre los viajes con llegada registrada">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->e4 ?? null) }}</td>
                    @if($esPlaca)
                        <td class="text-center">{{ $fila->conductores }}</td>
                        <td style="max-width: 200px">
                            @if($fila->par_habitual)
                                <span class="d-inline-block text-truncate" style="max-width: 190px"
                                      title="{{ $fila->par_habitual }}">{{ $fila->par_habitual }}</span>
                                <div class="text-muted" style="font-size: .7rem">{{ $fila->par_pct }}%</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @else
                        <td class="text-center">{{ $fila->placas }}</td>
                        <td style="max-width: 200px">
                            @if($fila->par_habitual)
                                <span class="d-inline-block text-truncate" style="max-width: 190px"
                                      title="{{ $fila->par_habitual }}">{{ $fila->par_habitual }}</span>
                                <div class="text-muted" style="font-size: .7rem">{{ $fila->par_pct }}%</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @endif
                    <td class="text-center">
                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_cierre($fila->pct_cerrados) }}">
                            {{ $fila->pct_cerrados ?? '—' }}%
                        </span>
                    </td>
                    <td class="text-center">
                        <a href="{{ $url_ficha($grupo, $fila->clave) }}" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-box-arrow-in-up-right"></i> Ver
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="25" class="text-center">
                        No hay {{ $esPlaca ? 'placas' : 'conductores' }} con viajes para los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

@endsection
