@extends('layouts.app')

@section('title', 'Compilar promedios por día y hora')

@section('content')

    @php
        $tope_minimo = \App\Database\DBTiemposPromedio::TOPE_MINIMO;
        $fmt = fn ($minutos) => \App\Database\DBTiemposRuta::formatear_minutos($minutos);
        $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

        $resumen = $datos->horario->resumen;
        $celdas = $datos->horario->filas;
        $estado = $datos->estado;

        $color_perdido = function ($pct) {
            if ($pct === null) return 'secondary';
            if ($pct <= 2) return 'success';
            if ($pct <= 10) return 'warning';
            return 'danger';
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1">Compilar promedios por día y hora</h1>
            <p class="text-muted small mb-2">
                Matriz <strong>ruta &times; día de la semana &times; hora de inicio de ruta</strong> de la etapa
                <em>inicio de ruta &rarr; llegada a destino</em>, sobre <strong>toda la data</strong>. Cada celda es
                una hora concreta de un día concreto, así que el promedio responde a "un lunes a las 08" y no al
                promedio general de la ruta.
            </p>
            <p class="text-muted small mb-2">
                El tope se aplica <strong>viaje por viaje antes de promediar la celda</strong>: los viajes que lo
                superan se descartan y la columna <strong>% perdido</strong> dice cuánto se cae en esa hora. Una celda
                que se queda sin viajes no se guarda.
            </p>
            <p class="text-muted small mb-0">
                Esta compilación escribe <strong>solo</strong> las celdas de día/hora: no toca los promedios de ruta
                de <a href="{{ route('lupita.tiempos.promedio') }}">Compilar promedios</a>, así que puedes recompilar
                el horario con otro tope sin perder nada.
            </p>
        </div>
        <div class="text-end small">
            <span class="badge bg-{{ $estado->tablas ? 'success' : 'warning' }} text-dark">
                {{ $estado->tablas ? 'Tablas listas' : 'Faltan las tablas (aplicar migración)' }}
            </span>
            @if($estado->celdas > 0)
                <div class="text-muted mt-1">
                    {{ number_format($estado->celdas) }} celda(s) de {{ $estado->rutas }} ruta(s)<br>
                    {{ number_format($estado->con_1) }} con 1 viaje ·
                    {{ number_format($estado->con_5) }} con 5+<br>
                    {{ $estado->topes }} tope(s) distinto(s)
                </div>
            @endif
            <div class="mt-1">
                <a href="{{ route('lupita.tiempos.promedio.horario.ver') }}" class="text-info">
                    Ver horarios compilados <i class="bi bi-arrow-right"></i>
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

    <form method="GET" id="filtros" class="mt-3">
        <fieldset class="border p-3 rounded">
            <legend class="float-none w-auto px-2 fs-6">Tope y alcance</legend>

            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="tope">Tope (minutos)</label>
                    <input type="number" id="tope" name="tope" min="{{ $tope_minimo }}"
                           step="10" value="{{ $datos->tope }}" class="form-control" required>
                    <div class="form-text">
                        <span id="tope_horas">{{ round($datos->tope / 60, 1) }}</span> h
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label d-block">Tope a aplicar</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="modo" id="modo_global" value="global"
                            @checked($datos->modo === 'global')>
                        <label class="btn btn-outline-primary" for="modo_global">Global</label>

                        <input type="radio" class="btn-check" name="modo" id="modo_ruta" value="ruta"
                            @checked($datos->modo === 'ruta')>
                        <label class="btn btn-outline-primary" for="modo_ruta">p97 por ruta</label>
                    </div>
                    <div class="form-text">El p97 se calcula por ruta con piso de 4 h.</div>
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
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="min_viajes">Mínimo de viajes</label>
                    <select name="min_viajes" id="min_viajes" class="form-control">
                        @foreach([1, 2, 3, 5, 10] as $opcion)
                            <option value="{{ $opcion }}" @selected($datos->min_viajes === $opcion)>
                                {{ $opcion }}{{ $opcion === 1 ? ' (todas)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Filtra la tabla, no la compilación.</div>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Calcular
                    </button>
                    <a href="{{ route('lupita.tiempos.promedio.horario') }}" class="btn btn-secondary">Limpiar</a>
                </div>

                <div class="col-12">
                    <span class="text-muted small me-2">Atajos:</span>
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach([720 => '12 h', 1440 => '24 h', 2880 => '48 h', 4320 => '72 h', 5760 => '96 h'] as $min => $etiqueta)
                            <button type="button" class="btn btn-outline-secondary" onclick="poner_tope({{ $min }})">
                                {{ $etiqueta }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </fieldset>
    </form>

    <div class="row g-3 mt-1">
        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Celdas día/hora con datos</div>
                    <div class="fs-4 fw-bold">{{ number_format($resumen->celdas) }}</div>
                    <div class="small text-muted">
                        {{ number_format($resumen->viajes) }} viaje(s) ·
                        {{ number_format($resumen->mostradas) }} en la tabla
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Se descartan con este tope</div>
                    <div class="fs-4 fw-bold text-{{ $color_perdido($resumen->pct_perdido) }}">
                        {{ number_format($resumen->descartados) }}
                        @if($resumen->pct_perdido !== null)
                            <span class="fs-6">({{ $resumen->pct_perdido }} %)</span>
                        @endif
                    </div>
                    <div class="small text-muted">Quedan {{ number_format($resumen->dentro) }} viaje(s)</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Muestra de las celdas</div>
                    <div class="fs-5 fw-bold">
                        <span class="badge bg-secondary">{{ number_format($resumen->con_1) }} con 1</span>
                        <span class="badge bg-info text-dark">{{ number_format($resumen->con_2) }} con 2</span>
                        <span class="badge bg-success text-dark">{{ number_format($resumen->con_3) }} con 3+</span>
                    </div>
                    <div class="small text-muted">
                        {{ number_format($resumen->con_5) }} celda(s) llegan a 5 viajes
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Promedio de las celdas</div>
                    <div class="fs-4 fw-bold">{{ $fmt($resumen->prom_min) }}</div>
                    <div class="small text-muted">Cada celda pesa igual, no cada viaje</div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('lupita.tiempos.promedio.horario.compilar') }}" id="compilar" class="mt-3">
        @csrf
        <input type="hidden" name="tope" value="{{ $datos->tope }}">
        <input type="hidden" name="modo" value="{{ $datos->modo }}">
        <input type="hidden" name="ruta_id" value="{{ $datos->ruta_id }}">
        <input type="hidden" name="alcance" value="todas">
        <input type="hidden" name="min_viajes" value="{{ $datos->min_viajes }}">

        <fieldset class="border p-3 rounded">
            <legend class="float-none w-auto px-2 fs-6">Compilar y guardar celdas</legend>

            <p class="small text-muted mb-2">
                Se guardan las celdas con tope <strong>{{ $datos->tope }} min</strong>
                ({{ $datos->modo === 'ruta' ? 'p97 de cada ruta' : 'global' }}). Solo se reescriben las celdas de las
                rutas que se compilan; el resto de la matriz y los promedios de ruta quedan intactos.
            </p>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success" onclick="compilar('todas')">
                    <i class="bi bi-database-add"></i> Compilar todas las rutas
                </button>

                <button type="button" class="btn btn-outline-success"
                        onclick="compilar('ruta')" @disabled(!$datos->ruta_id)>
                    <i class="bi bi-signpost-split"></i>
                    Compilar solo {{ $datos->ruta_id ? \App\Database\DBTiemposPromedio::nombre($datos->ruta_id) : 'la ruta elegida' }}
                </button>
            </div>
        </fieldset>
    </form>

    <div class="d-flex justify-content-between align-items-end mt-4">
        <h2 class="fs-5 mb-0">Lo que se pierde por día y hora</h2>
        <div class="text-muted small">
            {{ number_format($resumen->mostradas) }} celda(s) · ordenadas por viajes
        </div>
    </div>

    <div class="table-responsive mt-2" style="max-height: 65vh; overflow: auto">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-dark" style="position: sticky; top: 0; z-index: 1">
            <tr>
                <th>Ruta</th>
                <th>Día</th>
                <th class="text-end">Hora</th>
                <th class="text-end">Viajes</th>
                <th class="text-end">Quedan</th>
                <th class="text-end">% perdido</th>
                <th class="text-end" title="Promedio de los viajes de esa hora que pasan el tope">
                    Prom. con tope
                </th>
                <th class="text-end" title="Promedio de todos los viajes de esa hora, sin tope">
                    Prom. sin tope
                </th>
                <th class="text-end">Tope</th>
            </tr>
            </thead>
            <tbody>
            @forelse($celdas as $indice => $celda)
                @if($indice >= $datos->limite)
                    @break
                @endif
                <tr>
                    <td>
                        <div class="text-nowrap">{{ $celda->nombre }}</div>
                        <div class="text-muted" style="font-size: .72rem">{{ $celda->ruta_id }}</div>
                    </td>
                    <td class="text-nowrap">{{ $dias[$celda->dia] ?? $celda->dia }}</td>
                    <td class="text-end text-nowrap">{{ sprintf('%02d:00', $celda->hora) }}</td>
                    <td class="text-end">{{ number_format($celda->total) }}</td>
                    <td class="text-end">
                        <span class="{{ $celda->dentro === 1 ? 'text-warning' : '' }}">
                            {{ number_format($celda->dentro) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <span class="badge bg-{{ $color_perdido($celda->pct_perdido) }}">
                            {{ $celda->pct_perdido === null ? '—' : $celda->pct_perdido . ' %' }}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">{{ $fmt($celda->prom_min) }}</td>
                    <td class="text-end text-nowrap text-muted">{{ $fmt($celda->prom_sin_tope) }}</td>
                    <td class="text-end text-nowrap text-muted">{{ $fmt($celda->tope_min) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        No hay viajes medibles con estos filtros.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($resumen->mostradas > $datos->limite)
        <div class="text-muted small mt-2">
            Se muestran {{ number_format($datos->limite) }} de {{ number_format($resumen->mostradas) }} celdas: el
            resumen y la compilación usan todas.
        </div>
    @endif

    <script>
        function poner_tope(minutos) {
            document.getElementById('tope').value = minutos;
            document.getElementById('tope_horas').textContent = (minutos / 60).toFixed(1);
        }

        function compilar(alcance) {
            const filtros = document.getElementById('filtros');
            const form = document.getElementById('compilar');
            const ruta = filtros.elements['ruta_id'].value;

            if (alcance === 'ruta' && !ruta) {
                alert('Elige una ruta en el selector para compilar solo su horario.');
                return;
            }

            const etiqueta = alcance === 'ruta' ? 'solo la ruta elegida' : 'todas las rutas';

            if (!confirm('Se guardarán las celdas día/hora con tope ' + filtros.elements['tope'].value + ' min para ' + etiqueta + '. ¿Continuar?')) {
                return;
            }

            form.elements['tope'].value = filtros.elements['tope'].value;
            form.elements['modo'].value = filtros.elements['modo'].value;
            form.elements['ruta_id'].value = ruta;
            form.elements['alcance'].value = alcance;
            form.elements['min_viajes'].value = filtros.elements['min_viajes'].value;
            form.submit();
        }

        document.getElementById('tope').addEventListener('input', function () {
            document.getElementById('tope_horas').textContent = (this.value / 60).toFixed(1);
        });
    </script>
@endsection
