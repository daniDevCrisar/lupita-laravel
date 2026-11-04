@extends('layouts.app')

@section('title', 'Comportamiento · ' . $datos->ficha->nombre)

@section('content')

    @php
        $f = $datos->ficha;
        $fila = $f->fila;
        $global = $datos->global;
        $confianza = $datos->confianza ?? [];
        $chofer = $datos->chofer ?? null;
        $esPlaca = $grupo === 'placa';

        $params_volver = request()->query();
        unset($params_volver['clave']);
        $params_volver['grupo'] = $grupo;

        // mapa de calor: maximo del cruce dia x hora para escalar los colores
        $max_mapa = 0;
        foreach ($f->mapa ?? [] as $horas_dia) {
            if ($horas_dia) {
                $max_mapa = max($max_mapa, max($horas_dia));
            }
        }

        $horas_chart = [];
        $horas_presenta = [];
        $horas_llegada = [];
        $labels_horas = [];
        for ($h = 0; $h < 24; $h++) {
            $horas_chart[] = (int) ($f->horas[$h] ?? 0);
            $horas_presenta[] = (int) ($f->horas_presenta[$h] ?? 0);
            $horas_llegada[] = (int) ($f->horas_llegada[$h] ?? 0);
            $labels_horas[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h';
        }
    @endphp

    <div class="row mb-2">
        <div class="col-12 d-flex flex-wrap align-items-center gap-2">
            <div>
                <h1 class="mb-0">
                    <i class="bi {{ $esPlaca ? 'bi-truck' : 'bi-person-badge' }}"></i>
                    {{ $f->nombre }}
                </h1>
                <div class="text-muted small">
                    {{ $esPlaca ? 'Comportamiento de la placa' : 'Comportamiento del conductor' }}
                    entre {{ request('fecha_inicio') }} y {{ request('fecha_fin') }}
                    · tope por etapa: {{ $topes[$datos->tope] ?? 'Sin tope' }}
                </div>
            </div>
            <a href="{{ route('lupita.comportamiento', $params_volver) }}" class="btn btn-outline-secondary ms-auto">
                <i class="bi bi-arrow-left"></i> Volver al ranking
            </a>
        </div>
    </div>

    {{-- ================= VEREDICTO DE LA PROXIMA CARGA ================= --}}
    @php $prox = $f->proxima ?? null; @endphp
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card border-{{ \App\Database\DBComportamiento::color_confiabilidad($prox->confiabilidad ?? null) }}">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-4">
                    <div>
                        <div class="text-muted small">
                            Veredicto de la próxima carga
                            @if($prox)
                                <span>· consultado hoy {{ strtolower($prox->dia_hoy) }} a las {{ $prox->consultado }}</span>
                            @endif
                        </div>
                        @if($prox)
                            <div class="fs-3 fw-bold text-nowrap">
                                <i class="bi bi-clock"></i>
                                Vuelve a cargar {{ $prox->relativo }} a las
                                {{ \App\Database\DBComportamiento::etiqueta_hora_min($prox->hora, $prox->minuto ?? 0) }}
                            </div>
                        @else
                            <div class="fs-5 fw-bold text-muted">Sin presentas registradas</div>
                        @endif
                    </div>
                    @if($prox)
                        <div>
                            <div class="text-muted small">
                                {{ count($prox->opciones ?? []) }} {{ count($prox->opciones ?? []) === 1 ? 'opción' : 'opciones' }}
                                {{ $prox->salto === 0 ? 'para hoy' : 'para ' . strtolower($prox->dia_nombre) }}
                                con el minuto promedio de cada hora
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-1">
                                @foreach($prox->opciones ?? [] as $i => $opcion)
                                    <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($opcion['pct'] ?? null) }}"
                                          title="{{ $opcion['n'] }} {{ $opcion['n'] === 1 ? 'viaje' : 'viajes' }} en esa hora">
                                        {{ $i + 1 }}. {{ \App\Database\DBComportamiento::etiqueta_hora_min($opcion['hora'], $opcion['minuto'] ?? 0) }}
                                        {{ $opcion['pct'] ?? 0 }}%
                                    </span>
                                @endforeach
                            </div>
                            <div class="text-muted small mt-1">
                                La primera se lleva {{ $prox->n }} {{ $prox->n === 1 ? 'viaje' : 'viajes' }} de las
                                presentas del {{ strtolower($prox->dia_nombre) }}
                            </div>
                        </div>
                        <div class="small ms-auto text-end">
                            <div class="text-muted">Confiabilidad ajustada por choferes</div>
                            <div class="badge bg-{{ \App\Database\DBComportamiento::color_confiabilidad($prox->confiabilidad ?? null) }}">
                                {{ $prox->confiabilidad ?? '—' }}%
                            </div>
                            <div class="text-muted" style="font-size: .7rem">
                                regularidad × choferes efectivos
                            </div>
                        </div>
                    @else
                        <div class="small text-muted ms-auto" style="max-width: 420px">
                            No hay presentas para carga registradas en el rango, así que no se puede estimar a qué hora
                            vuelve a cargar.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ================= TARJETAS DEL GRUPO ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Viajes</div>
                    <div class="fs-4 fw-bold">{{ number_format($fila->viajes, 0, ',', '.') }}</div>
                    <div class="small text-muted">
                        {{ $fila->dias }} días · {{ $fila->viajes_semana ?? '—' }} por semana
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Ruta habitual</div>
                    <div class="fw-bold text-truncate" title="{{ $f->rutas[0]['nombre'] ?? '—' }}">
                        {{ $f->rutas[0]['nombre'] ?? '—' }}
                    </div>
                    <div class="small text-muted">
                        {{ !empty($f->rutas) ? round($f->rutas[0]['n'] / max(1, $fila->viajes) * 100, 1) : '—' }}%
                        de sus viajes · {{ $fila->rutas }} rutas
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-success h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Rutina horaria (fuera de planta)</div>
                    @foreach([['Sale de planta', $fila->hora_habitual ?? null, $fila->regularidad ?? null, []],
                              ['Presenta en carga', $fila->hora_presenta ?? null, $fila->regularidad_presenta ?? null, $fila->presenta_top ?? []],
                              ['Llega a destino', $fila->hora_llegada ?? null, $fila->regularidad_llegada ?? null, []]] as [$etiqueta, $hora, $regularidad, $top])
                        <div class="d-flex justify-content-between align-items-center small">
                            <span class="text-muted">{{ $etiqueta }}</span>
                            <span class="text-nowrap">
                                <span class="fw-bold">{{ \App\Database\DBComportamiento::franja_habitual($hora) }}</span>
                                <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($regularidad) }}">
                                    {{ $regularidad ?? '—' }}%
                                </span>
                            </span>
                        </div>
                        @if(count($top) > 0)
                            <div class="text-muted mb-1" style="font-size: .7rem"
                                 title="Otras horas de presenta en carga, fuera de la franja habitual">
                                <i class="bi bi-clock-history"></i>
                                otras horas: {{ \App\Database\DBComportamiento::texto_probables($top, 0, 3) }}
                            </div>
                        @endif
                    @endforeach
                    <div class="d-flex justify-content-between align-items-center small mt-1 pt-1 border-top"
                         title="Probabilidad de acertar la hora de presenta: la regularidad de la franja dividida entre los {{ $esPlaca ? 'choferes' : 'placas' }} efectivos que manejan el grupo">
                        <span class="text-muted">Confiabilidad de presenta</span>
                        <span class="text-nowrap">
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_confiabilidad($fila->confiabilidad ?? null) }}">
                                {{ $fila->confiabilidad ?? '—' }}%
                            </span>
                            <span class="text-muted">
                                {{ $fila->actores_efectivos ?? '—' }} {{ $esPlaca ? 'choferes' : 'placas' }} efectivos de {{ $fila->actores ?? '—' }}
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-warning h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Puntualidad (mediana)</div>
                    <div class="fs-4 fw-bold">
                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_desvio($fila->desvio_mediana) }}">
                            {{ \App\Database\DBComportamiento::texto_desvio($fila->desvio_mediana) }}
                        </span>
                    </div>
                    <div class="small text-muted">
                        {{ $fila->pct_puntual ?? '—' }}% ±30 min · {{ $fila->pct_tarde ?? '—' }}% tarde
                        ({{ $fila->desvio_n }} pares)
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Ciclo promedio</div>
                    <div class="fs-4 fw-bold">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo) }}</div>
                    <div class="small text-muted">
                        rango {{ \App\Database\DBTiemposRuta::formatear_minutos($global->ciclo) }}
                        <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($fila->ciclo, $global->ciclo) }}">
                            {{ \App\Database\DBTiemposRuta::desviacion($fila->ciclo, $global->ciclo) }}
                        </span>
                    </div>
                    <div class="small text-muted">
                        real por viaje {{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo_real ?? null) }}
                        · ruta {{ \App\Database\DBTiemposRuta::formatear_minutos($fila->e4 ?? null) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card border-danger h-100">
                <div class="card-body py-2">
                    <div class="text-muted small">Registro</div>
                    <div class="fs-4 fw-bold">
                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_cierre($fila->pct_cerrados) }}">
                            {{ $fila->pct_cerrados ?? '—' }}% cerrado
                        </span>
                    </div>
                    <div class="small text-muted">
                        {{ $fila->pct_sin_conductor ?? '—' }}% sin conductor ·
                        {{ $fila->pct_sin_presenta ?? '—' }}% sin presenta
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= CHOFER HABITUAL (mas de la mitad de los viajes) ================= --}}
    @if($esPlaca && $chofer && ($chofer->resumen ?? null))
        @php $cr = $chofer->resumen; @endphp
        <div class="card mb-3 border-info">
            <div class="card-header py-1">
                <i class="bi bi-person-badge"></i> Chofer habitual de la placa
                <span class="text-muted small">
                    ({{ $chofer->nombre }} concentra {{ $chofer->pct }}% de los viajes: {{ $chofer->n }} de {{ $fila->viajes }})
                </span>
            </div>
            <div class="card-body p-0">
                <details>
                    <summary class="px-3 py-2 small d-flex flex-wrap justify-content-between align-items-center gap-2"
                             style="cursor: pointer">
                        <span>
                            <i class="bi bi-chevron-right"></i>
                            Su rutina: presenta en carga
                            <strong>{{ \App\Database\DBComportamiento::franja_habitual($cr->hora_presenta) }}</strong>
                            <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($cr->regularidad_presenta) }}">
                                {{ $cr->regularidad_presenta ?? '—' }}% regular
                            </span>
                            <span class="text-muted">en {{ $cr->viajes }} viajes</span>
                        </span>
                        <span class="text-info">Ver sus promedios</span>
                    </summary>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark table-bordered mb-0 small">
                            <thead class="table-primary">
                            <tr>
                                <th>Promedios del chofer</th>
                                <th class="text-center">{{ $chofer->nombre }}</th>
                                <th class="text-center">{{ $f->nombre }} (placa)</th>
                                <th class="text-center">Rango</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>Viajes medidos</td>
                                <td class="text-center fw-bold">{{ $cr->viajes }}</td>
                                <td class="text-center">{{ $fila->viajes }}</td>
                                <td class="text-center text-muted">{{ number_format($global->viajes, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Presenta en carga</td>
                                @foreach([[ $cr->hora_presenta ?? null, $cr->regularidad_presenta ?? null ],
                                          [ $fila->hora_presenta ?? null, $fila->regularidad_presenta ?? null ],
                                          [ $confianza['hora'] ?? null, $confianza['regularidad'] ?? null ]] as [$hora, $regularidad])
                                    <td class="text-center">
                                        <div class="fw-bold">{{ \App\Database\DBComportamiento::franja_habitual($hora) }}</div>
                                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_regularidad($regularidad) }}">
                                            {{ $regularidad ?? '—' }}%
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                            <tr>
                                <td>Otras horas de presenta</td>
                                <td class="text-center">{{ \App\Database\DBComportamiento::texto_probables($cr->presenta_top ?? [], 0, 3) }}</td>
                                <td class="text-center">{{ \App\Database\DBComportamiento::texto_probables($fila->presenta_top ?? [], 0, 3) }}</td>
                                <td class="text-center text-muted">—</td>
                            </tr>
                            @foreach($etapas as $numero => $nombre)
                                @php $campo = 'e' . $numero; @endphp
                                <tr>
                                    <td>E{{ $numero }} · {{ $nombre }}</td>
                                    <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($cr->$campo ?? null) }}</td>
                                    <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->$campo ?? null) }}</td>
                                    <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->$campo ?? null) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td>Ciclo completo (E2 - E6)</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($cr->ciclo ?? null) }}</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo ?? null) }}</td>
                                <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->ciclo ?? null) }}</td>
                            </tr>
                            <tr>
                                <td>Ciclo real por viaje</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($cr->ciclo_real ?? null) }}</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo_real ?? null) }}</td>
                                <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->ciclo_real ?? null) }}</td>
                            </tr>
                            <tr>
                                <td>Ruta por viaje (E4)</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($cr->e4 ?? null) }}</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->e4 ?? null) }}</td>
                                <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->e4 ?? null) }}</td>
                            </tr>
                            <tr>
                                <td>Puntualidad (mediana)</td>
                                @foreach([[ $cr->desvio_mediana ?? null, $cr->pct_puntual ?? null ],
                                          [ $fila->desvio_mediana ?? null, $fila->pct_puntual ?? null ],
                                          [ $global->desvio_mediana ?? null, $global->pct_puntual ?? null ]] as [$desvio, $puntual])
                                    <td class="text-center">
                                        <span class="badge bg-{{ \App\Database\DBComportamiento::color_desvio($desvio) }}">
                                            {{ \App\Database\DBComportamiento::texto_desvio($desvio) }}
                                        </span>
                                        <div class="text-muted" style="font-size: .7rem">{{ $puntual ?? '—' }}% ±30 min</div>
                                    </td>
                                @endforeach
                            </tr>
                            <tr>
                                <td>Viajes cerrados</td>
                                <td class="text-center">{{ $cr->pct_cerrados ?? '—' }}%</td>
                                <td class="text-center">{{ $fila->pct_cerrados ?? '—' }}%</td>
                                <td class="text-center text-muted">{{ $global->pct_cerrados ?? '—' }}%</td>
                            </tr>
                            <tr>
                                <td>Ritmo</td>
                                <td class="text-center">{{ $cr->viajes_semana ?? '—' }} por semana</td>
                                <td class="text-center">{{ $fila->viajes_semana ?? '—' }} por semana</td>
                                <td class="text-center text-muted">{{ $global->viajes_semana ?? '—' }} por semana</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-1 text-muted" style="font-size: .72rem">
                        Sus promedios se miden solo sobre los viajes de esta placa que manejo
                        ({{ $chofer->n }} de {{ $fila->viajes }}): sirven como referencia, no reemplazan al grupo.
                        La franja del chofer puede ser mas cerrada que la de la placa: no mezcla otras rutinas.
                    </div>
                </details>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">

        {{-- ================= COMPARATIVA POR ETAPA ================= --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-1">
                    <i class="bi bi-stopwatch"></i> Tiempos en planta y tránsito
                    <span class="text-muted small">(minutos promedio contra el rango)</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-dark table-bordered mb-0 small">
                        <thead class="table-primary">
                        <tr>
                            <th>Etapa</th>
                            <th class="text-center">Grupo</th>
                            <th class="text-center">Rango</th>
                            <th class="text-center">Desvío</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($etapas as $numero => $nombre)
                            @php
                                $campo = 'e' . $numero;
                                $valor = $fila->$campo;
                                $ref = $global->$campo;
                            @endphp
                            <tr>
                                <td>E{{ $numero }} · {{ $nombre }}</td>
                                <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($valor) }}</td>
                                <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($ref) }}</td>
                                <td class="text-center">
                                    <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($valor, $ref) }}">
                                        {{ \App\Database\DBTiemposRuta::desviacion($valor, $ref) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="fw-bold">
                            <td>Ciclo completo (E2 - E6)</td>
                            <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo) }}</td>
                            <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->ciclo) }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($fila->ciclo, $global->ciclo) }}">
                                    {{ \App\Database\DBTiemposRuta::desviacion($fila->ciclo, $global->ciclo) }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td>Ciclo real por viaje <span class="text-muted small">· {{ $fila->ciclo_real_n ?? 0 }} viajes</span></td>
                            <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->ciclo_real ?? null) }}</td>
                            <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->ciclo_real ?? null) }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($fila->ciclo_real ?? null, $global->ciclo_real ?? null) }}">
                                    {{ \App\Database\DBTiemposRuta::desviacion($fila->ciclo_real ?? null, $global->ciclo_real ?? null) }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td>Ruta por viaje (E4)</td>
                            <td class="text-center">{{ \App\Database\DBTiemposRuta::formatear_minutos($fila->e4 ?? null) }}</td>
                            <td class="text-center text-muted">{{ \App\Database\DBTiemposRuta::formatear_minutos($global->e4 ?? null) }}</td>
                            <td class="text-center">
                                <span class="badge bg-{{ \App\Database\DBTiemposRuta::color_por_tiempo($fila->e4 ?? null, $global->e4 ?? null) }}">
                                    {{ \App\Database\DBTiemposRuta::desviacion($fila->e4 ?? null, $global->e4 ?? null) }}
                                </span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="px-2 py-1 text-muted" style="font-size: .72rem">
                        E2 espera en carga · E3 carga · E4 tránsito · E5 espera en descarga · E6 descargue.
                        El ciclo completo suma promedios; el ciclo real y la ruta se promedian viaje a viaje.
                    </div>
                </div>
            </div>
        </div>

        {{-- ================= RUTAS Y PARES ================= --}}
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header py-1">
                    <i class="bi bi-signpost-split"></i> Rutas que mas usa
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-dark mb-0 small">
                        <tbody>
                        @forelse($f->rutas as $ruta)
                            @php $pctRuta = round($ruta['n'] / max(1, $fila->viajes) * 100, 1); @endphp
                            <tr>
                                <td class="text-truncate" style="max-width: 320px" title="{{ $ruta['nombre'] }}">
                                    {{ $ruta['nombre'] }}
                                </td>
                                <td class="text-center" style="width: 60px">{{ $ruta['n'] }}</td>
                                <td style="width: 40%">
                                    <div class="progress" style="height: 14px">
                                        <div class="progress-bar bg-info" style="width: {{ $pctRuta }}%">
                                            {{ $pctRuta }}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-muted">Sin rutas registradas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-1">
                    <i class="bi {{ $esPlaca ? 'bi-person-badge' : 'bi-truck' }}"></i>
                    {{ $esPlaca ? 'Conductores que la manejan' : 'Placas que maneja' }}
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-dark mb-0 small">
                        <tbody>
                        @forelse($f->pares as $par)
                            @php $pctPar = round($par['n'] / max(1, $fila->viajes) * 100, 1); @endphp
                            <tr>
                                <td class="text-truncate" style="max-width: 320px">{{ $par['nombre'] }}</td>
                                <td class="text-center" style="width: 60px">{{ $par['n'] }}</td>
                                <td class="text-center" style="width: 70px">
                                    <span class="badge bg-{{ $pctPar >= 80 ? 'success' : ($pctPar >= 50 ? 'info' : 'warning') }}">
                                        {{ $pctPar }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-muted">Sin datos de {{ $esPlaca ? 'conductores' : 'placas' }}.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= MAPA DE CALOR HORA x DIA ================= --}}
    <div class="card mb-3">
        <div class="card-header py-1">
            <i class="bi bi-grid-3x3-gap"></i> Cuando se presenta en carga: viajes por día y hora de presenta para carga
            <span class="text-muted small">(el máximo del grupo es {{ $max_mapa }} viajes en una hora)</span>
        </div>
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-sm table-dark table-bordered mb-0 small text-center">
                    <thead class="table-primary">
                    <tr>
                        <th style="min-width: 90px">Día</th>
                        @for($h = 0; $h < 24; $h++)
                            <th title="{{ \App\Database\DBComportamiento::etiqueta_hora($h) }}"
                                style="min-width: 26px">{{ $h }}</th>
                        @endfor
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($dias as $indice => $dia)
                        <tr>
                            <td class="text-start">{{ $dia }}</td>
                            @for($h = 0; $h < 24; $h++)
                                @php
                                    $viajesCelda = (int) ($f->mapa[$indice][$h] ?? 0);
                                    $fondo = \App\Database\DBComportamiento::color_celda($viajesCelda, $max_mapa);
                                @endphp
                                <td style="background-color: {{ $fondo }}"
                                    title="{{ $dia }} {{ \App\Database\DBComportamiento::etiqueta_hora($h) }}: {{ $viajesCelda }} presentas en carga">
                                    {{ $viajesCelda ?: '' }}
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-muted mt-1" style="font-size: .72rem">
                Solo cuentan los viajes con presenta para carga registrado y dentro del tope por etapa.
            </div>
        </div>
    </div>

    {{-- ================= GRAFICO DE HORAS ================= --}}
    <div class="card mb-3">
        <div class="card-header py-1">
            <i class="bi bi-bar-chart-line"></i> Distribución por hora: salida, presenta y llegada
            <span class="text-muted small">(viajes por hora del día en cada marca)</span>
        </div>
        <div class="card-body">
            <div style="height: 240px">
                <canvas id="chart_horas_comportamiento"></canvas>
            </div>
        </div>
    </div>

    {{-- ================= ULTIMOS VIAJES ================= --}}
    <div class="card mb-3">
        <div class="card-header py-1">
            <i class="bi bi-list-ul"></i> Últimos viajes del grupo
            <span class="text-muted small">({{ count($f->viajes) }} de {{ $fila->viajes }})</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                <table class="table table-sm table-dark table-hover mb-0 small">
                    <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <th>Ref</th>
                        <th>Ruta</th>
                        @if(!$esPlaca)
                            <th>Placa</th>
                        @else
                            <th>Conductor</th>
                        @endif
                        <th title="Momento en que el chofer se presento en la planta de carga">Presenta en carga</th>
                        <th>Fin descargue</th>
                        @foreach($etapas as $numero => $nombre)
                            <th class="text-center" title="{{ $nombre }}">E{{ $numero }}</th>
                        @endforeach
                        <th class="text-center">Desvío</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($f->viajes as $viaje)
                        <tr>
                            <td>{{ $viaje->ref }}</td>
                            <td class="text-truncate" style="max-width: 260px" title="{{ $viaje->ruta_nombre }}">
                                {{ $viaje->ruta_nombre }}
                            </td>
                            <td>{{ $esPlaca ? ($viaje->conductor ?: '—') : $viaje->placa }}</td>
                            <td>{{ $viaje->marca_presenta ?? '—' }}</td>
                            <td>
                                {{ $viaje->ultima_marca ?? '—' }}
                                @if(!(int) $viaje->cerrado)
                                    <span class="badge bg-warning text-dark">abierto</span>
                                @endif
                            </td>
                            @foreach($etapas as $numero => $nombre)
                                @php $campo = 'etapa_' . $numero; @endphp
                                <td class="text-center">
                                    {{ \App\Database\DBTiemposRuta::formatear_minutos($viaje->$campo) }}
                                </td>
                            @endforeach
                            <td class="text-center">
                                @if($viaje->desvio === null)
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="badge bg-{{ \App\Database\DBComportamiento::color_desvio($viaje->desvio) }}">
                                        {{ \App\Database\DBComportamiento::texto_desvio($viaje->desvio) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="text-center">Sin viajes para los filtros.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        // el grafico va dentro del contenido para no depender de la seccion de scripts del layout
        (function () {
            const canvas = document.getElementById('chart_horas_comportamiento');
            if (!canvas || typeof Chart === 'undefined') {
                return;
            }

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: @json($labels_horas),
                    datasets: [{
                        label: 'Sale de planta',
                        data: @json($horas_chart),
                        backgroundColor: 'rgba(13, 110, 253, .65)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1
                    }, {
                        label: 'Presenta en carga',
                        data: @json($horas_presenta),
                        backgroundColor: 'rgba(25, 135, 84, .65)',
                        borderColor: 'rgba(25, 135, 84, 1)',
                        borderWidth: 1
                    }, {
                        label: 'Llega a destino',
                        data: @json($horas_llegada),
                        backgroundColor: 'rgba(255, 193, 7, .65)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: { color: '#dee2e6', boxWidth: 12 }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { color: '#dee2e6' } }
                    }
                }
            });
        })();
    </script>

@endsection
