@extends('layouts.app')

@section('title', 'Predicciones guardadas')

@section('content')
    @php
        $g = $resumen ?? [];
        $t = $tablero ?? [];
        $tolerancia = $t['tolerancia'] ?? 180;
        $prod = $t['produccion'] ?? [];
        $motor = $t['motor'] ?? null;
        $moni = $t['monitorista'] ?? [];
        $cara = $t['cara_a_cara'] ?? [];

        $fmt = fn ($min) => $min === null ? '—' : (abs($min) >= 60
            ? intdiv(abs($min), 60) . ' h ' . str_pad((string) (abs($min) % 60), 2, '0', STR_PAD_LEFT)
            : $min . ' min');
        $corto = fn ($min) => $min === null ? '—' : (abs($min) >= 60 ? $fmt(abs($min)) : abs($min) . '′');
        $signo = fn ($min) => $min === null ? '—'
            : ($min > 0 ? '+' : ($min < 0 ? '−' : '')) . $corto(abs($min));
        $pct = fn ($valor) => $valor === null ? '—' : $valor . ' %';
        $colorError = fn ($min) => $min === null ? 'secondary'
            : (abs($min) <= 60 ? 'success' : (abs($min) <= 180 ? 'warning text-dark' : (abs($min) <= 720 ? 'info text-dark' : 'danger')));
        $errores = $prod['errores'] ?? [];
        $posicion = $prod['posicion'] ?? ['n' => 0];
    @endphp

    @if(session('estado'))
        <div class="alert alert-success py-2">{{ session('estado') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-speedometer2"></i> Predicciones guardadas</h1>
            <p class="text-muted small mb-1">
                Todo lo que se sabe del motor en un solo lugar, en <strong>tres varas</strong> que no se mezclan:
                <span class="badge bg-success">producción</span> lo que se guardó y ya presentó,
                <span class="badge bg-primary">motor</span> la corrida de aciertos sobre referencias reales, y
                <span class="badge bg-warning text-dark">monitorista</span> el <code>compromiso_carga</code>
                que se promete a mano contra lo que pasó de verdad.
            </p>
            <p class="text-muted small mb-0">
                <a href="{{ route('lupita.prediccion.aciertos') }}">&larr; Tabla de aciertos</a> ·
                <a href="{{ route('lupita.prediccion.parametros.ver') }}">Parámetros del motor</a> ·
                <a href="{{ route('lupita.prediccion.presenta') }}">Calculadora</a>
            </p>
        </div>
    </div>

    {{-- filtros --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.prediccion.guardadas') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ $filtros['desde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $filtros['hasta'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Origen</label>
                    <select name="origen" class="form-select">
                        <option value="">todos</option>
                        @foreach($origenes as $origen)
                            <option value="{{ $origen }}" @selected($filtros['origen'] === $origen)>{{ $origen }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Filas de la tabla</label>
                    <select name="limite" class="form-select">
                        @foreach($limites as $opcion)
                            <option value="{{ $opcion }}" @selected((int) $filtros['limite'] === $opcion)>{{ $opcion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary flex-fill"><i class="bi bi-arrow-repeat"></i> Ver</button>
                    <button class="btn btn-outline-secondary" name="refrescar" value="1"
                            title="vuelve a correr el backtest del motor (se guarda 10 min)">
                        <i class="bi bi-arrow-clockwise"></i> Medir motor
                    </button>
                </div>
            </form>

            {{-- borrar lo guardado del rango: el motor se recalibra y lo viejo
                 compara contra un modelo que ya no existe --}}
            <form method="POST" action="{{ route('lupita.prediccion.guardadas.borrar') }}"
                  class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top"
                  onsubmit="return confirm('¿Borrar las predicciones guardadas con corte entre {{ $filtros['desde'] }} y {{ $filtros['hasta'] }}? No se puede deshacer.');">
                @csrf
                <input type="hidden" name="fecha_inicio" value="{{ $filtros['desde'] }}">
                <input type="hidden" name="fecha_fin" value="{{ $filtros['hasta'] }}">
                <input type="hidden" name="origen" value="{{ $filtros['origen'] }}">
                <input type="hidden" name="limite" value="{{ $filtros['limite'] }}">
                <button class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i> Borrar las de este rango
                </button>
                <span class="small text-muted">
                    Borra de <code>prediccion_resultados</code> las predicciones cuyo <strong>corte</strong> cae en el
                    rango @if($filtros['origen'] !== ''), del origen <strong>{{ $filtros['origen'] }}</strong>@endif.
                    El motor se recalibra seguido: lo guardado con otro modelo ensucia el acierto.
                </span>
            </form>
        </div>
    </div>

    {{-- 1) PRODUCCIÓN: lo guardado que ya presentó --}}
    <div class="card mb-3 border-success">
        <div class="card-header bg-success text-white d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-database-check"></i> Producción — lo guardado que ya presentó</strong>
            <span class="small">
                {{ $g['guardadas'] ?? 0 }} filas · {{ $prod['refs'] ?? 0 }} refs ·
                <strong>n = {{ $g['n'] ?? 0 }}</strong> comparables
            </span>
        </div>
        <div class="card-body">
            @if(!($prod['muestra_suficiente'] ?? false))
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Muestra insuficiente:</strong> con {{ $g['n'] ?? 0 }} referencias comparables un
                    porcentaje es anécdota, no medición. Los números de abajo están bien calculados pero todavía no
                    sirven para decidir. Para llenarlos, andá a la
                    <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de aciertos</a>, subí las filas a
                    300-1000 y apretá <strong>"Guardar estas predicciones"</strong>; también se guardan solas cada
                    vez que alguien predice por el API.
                </div>
            @endif

            @if(($prod['sin_ref'] ?? 0) > 0)
                <div class="alert alert-secondary py-2 small mb-3">
                    {{ $prod['sin_ref'] }} filas apuntan a referencias que no existen en
                    <code>referencias</code> (pruebas, como <code>ZZTEST…</code>): no se pueden comparar y no
                    cuentan en el acierto.
                </div>
            @endif

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-2">
                    <div class="text-muted small">Éxito en la ventana</div>
                    <div class="fs-3 fw-bold text-success">{{ $pct($g['pct_ventana'] ?? null) }}</div>
                    <div class="small text-muted">{{ $g['dentro_ventana'] ?? 0 }} de {{ $g['n'] ?? 0 }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="text-muted small">Éxito a ±{{ $tolerancia }} min</div>
                    <div class="fs-3 fw-bold">{{ $pct($g['pct_tolerancia'] ?? null) }}</div>
                    <div class="small text-muted">{{ $g['dentro_tolerancia'] ?? 0 }} de {{ $g['n'] ?? 0 }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="text-muted small">MAE</div>
                    <div class="fs-3 fw-bold">{{ $corto($errores['mae'] ?? null) }}</div>
                    <div class="small text-muted">error medio absoluto</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="text-muted small">Sesgo</div>
                    <div class="fs-3 fw-bold">{{ $signo($errores['sesgo'] ?? null) }}</div>
                    <div class="small text-muted">
                        @if(($errores['sesgo'] ?? 0) > 0) se queda corto @elseif(($errores['sesgo'] ?? 0) < 0) se pasa @endif
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="text-muted small">Error mediano</div>
                    <div class="fs-3 fw-bold">{{ $signo($errores['error_mediano'] ?? null) }}</div>
                    <div class="small text-muted">p50 {{ $corto($errores['p50'] ?? null) }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="text-muted small">Cola (p90)</div>
                    <div class="fs-3 fw-bold">{{ $corto($errores['p90'] ?? null) }}</div>
                    <div class="small text-muted">peor {{ $corto($errores['tope'] ?? null) }}</div>
                </div>
            </div>

            <div class="small text-muted mb-1">
                Se adelantó <strong>{{ $errores['adelanto'] ?? 0 }}</strong> veces y se quedó corto
                <strong>{{ $errores['atraso'] ?? 0 }}</strong> veces (de {{ $errores['n'] ?? 0 }}).
                Error = predicho − real: <span class="text-danger">positivo</span> = el camión presentó después
                de lo que dijimos.
            </div>

            {{-- HORIZONTE: desde el último evento real de la placa antes del corte
                 (el ancla que usa el motor) hasta la presentación real --}}
            @if(($prod['horizonte_n'] ?? 0) > 0)
                <div class="small mb-1">
                    <span class="text-muted">Horizonte desde el ancla (último evento de la placa):</span>
                    <strong>{{ $fmt($prod['horizonte_mediano'] ?? null) }}</strong> de mediana
                    <span class="text-muted">(n = {{ $prod['horizonte_n'] }})</span>
                    · <span class="badge bg-success">{{ $prod['horizonte_corto'] }} dentro de 6 h</span>
                    · <span class="badge bg-danger">{{ $prod['horizonte_largo'] }} más allá de 24 h</span>
                    <span class="text-muted" style="font-size:.7rem">
                        — en el tramo corto el motor acierta; en el largo, no hay con qué.
                    </span>
                </div>
            @endif

            {{-- ¿de qué lado cayó la presentación real? --}}
            @if(($posicion['n'] ?? 0) > 0)
                @php
                    $total = max(1, $posicion['n']);
                    $ancho = fn ($valor) => round(100 * $valor / $total, 1);
                @endphp
                <div class="mt-3">
                    <div class="small fw-semibold mb-1">
                        Dónde cayó la presentación real ({{ $posicion['n'] }} con las cuatro fechas)
                    </div>
                    <div class="progress" style="height:24px">
                        <div class="progress-bar bg-info" style="width:{{ $ancho($posicion['antes_rapido']) }}%"
                             title="antes de la fecha rápida: {{ $posicion['antes_rapido'] }}">
                            @if($ancho($posicion['antes_rapido']) > 12){{ $posicion['antes_rapido'] }}@endif
                        </div>
                        <div class="progress-bar bg-success" style="width:{{ $ancho($posicion['primera_mitad']) }}%"
                             title="entre rápido y normal: {{ $posicion['primera_mitad'] }}">
                            @if($ancho($posicion['primera_mitad']) > 12){{ $posicion['primera_mitad'] }}@endif
                        </div>
                        <div class="progress-bar bg-warning text-dark" style="width:{{ $ancho($posicion['segunda_mitad']) }}%"
                             title="entre normal y tardado: {{ $posicion['segunda_mitad'] }}">
                            @if($ancho($posicion['segunda_mitad']) > 12){{ $posicion['segunda_mitad'] }}@endif
                        </div>
                        <div class="progress-bar bg-danger" style="width:{{ $ancho($posicion['despues_tardado']) }}%"
                             title="después del tardado: {{ $posicion['despues_tardado'] }}">
                            @if($ancho($posicion['despues_tardado']) > 12){{ $posicion['despues_tardado'] }}@endif
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small text-muted mt-1">
                        <span><span class="badge bg-info">&nbsp;</span> antes de rápido {{ $posicion['antes_rapido'] }}</span>
                        <span><span class="badge bg-success">&nbsp;</span> rápido→normal {{ $posicion['primera_mitad'] }}</span>
                        <span><span class="badge bg-warning">&nbsp;</span> normal→tardado {{ $posicion['segunda_mitad'] }}</span>
                        <span><span class="badge bg-danger">&nbsp;</span> después de tardado {{ $posicion['despues_tardado'] }}</span>
                    </div>
                    <div class="text-muted" style="font-size:.7rem">
                        Muchas barras celestes = la ventana quedó corrida hacia adelante y el motor se está
                        yendo para atrás a propósito.
                    </div>
                </div>
            @endif

            <div class="row g-3 mt-1">
                {{-- LAS CUATRO FECHAS: cuál conviene, con su escalera --}}
                @if(($t['fechas']['n'] ?? 0) > 0)
                    <div class="col-12">
                        <div class="small fw-semibold">Las cuatro fechas publicadas, contra la presentación real</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-1" style="font-size:.8rem">
                                <thead class="table-light">
                                <tr>
                                    <th>fecha</th>
                                    <th class="text-end">n</th>
                                    <th class="text-end">MAE</th>
                                    <th class="text-end">Error mediano</th>
                                    <th class="text-end">±30m</th>
                                    <th class="text-end">±1h</th>
                                    <th class="text-end">±2h</th>
                                    <th class="text-end">±3h</th>
                                    <th class="text-end">±6h</th>
                                    <th class="text-end">±12h</th>
                                    <th class="text-end" title="de las {{ $t['fechas']['n'] }} filas comparables, cuántas veces esta fecha queda más cerca que las otras tres">Más cerca</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($t['fechas']['filas'] as $fecha)
                                    <tr class="{{ $fecha['clave'] === 'probable' ? 'table-info' : '' }}">
                                        <td>
                                            <strong>{{ $fecha['etiqueta'] }}</strong>
                                            <div class="text-muted" style="font-size:.7rem">{{ $fecha['texto'] }}</div>
                                        </td>
                                        <td class="text-end">{{ (int) $fecha['n'] }}</td>
                                        <td class="text-end fw-bold">{{ $corto($fecha['mae']) }}</td>
                                        <td class="text-end">{{ $signo($fecha['error_mediano']) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_30'] ?? null) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_60'] ?? null) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_120'] ?? null) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_180'] ?? null) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_360'] ?? null) }}</td>
                                        <td class="text-end">{{ $pct($fecha['pct_720'] ?? null) }}</td>
                                        <td class="text-end">
                                            {{ $pct($fecha['pct_mas_cerca'] ?? null) }}
                                            <span class="text-muted" style="font-size:.7rem">({{ (int) $fecha['mas_cerca'] }})</span>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted" style="font-size:.7rem">
                            El error es <code>fecha − real</code>. La columna <strong>Más cerca</strong> cuenta cuántas
                            veces esa fecha quedó más cerca que las otras tres: ganar en promedio (MAE) y ganar en
                            cantidad de veces no es lo mismo — el tardado se lleva los casos de error enorme, la
                            probable los normales.
                            @if(($t['fechas']['filas'][3]['n'] ?? 0) === 0)
                                <br><strong>La cuarta fecha no tiene filas todavía:</strong> las predicciones guardadas
                                antes de aplicarla no la tienen. Se llena con las nuevas.
                            @endif
                        </div>
                    </div>
                @endif

                {{-- la escalera de tolerancias, igual que en la tabla de aciertos --}}
                <div class="col-12">
                    @include('lupita.partials.prediccion_tolerancias', [
                        'datos' => $errores,
                        'n' => $errores['n'] ?? 0,
                        'titulo' => 'Dentro de tolerancias:',
                    ])
                    <div class="text-muted mt-1" style="font-size:.7rem">
                        Porcentaje de presentaciones reales que cayeron dentro de cada tolerancia alrededor de la hora
                        guardada. Es la misma escalera que muestra la tabla de aciertos, para comparar las dos varas.
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="small fw-semibold">Por evidencia</div>
                    @include('lupita.partials.prediccion_desglose', [
                        'grupos' => $prod['por_evidencia'] ?? [],
                        'titulo' => 'evidencia',
                        'tolerancia' => $tolerancia,
                    ])
                </div>
                <div class="col-md-6">
                    <div class="small fw-semibold">Por horizonte (cuánto antes se dijo)</div>
                    @include('lupita.partials.prediccion_desglose', [
                        'grupos' => $prod['por_horizonte'] ?? [],
                        'titulo' => 'horizonte',
                        'tolerancia' => $tolerancia,
                    ])
                </div>
                <div class="col-md-6">
                    <div class="small fw-semibold">Por confianza declarada</div>
                    @include('lupita.partials.prediccion_desglose', [
                        'grupos' => $prod['por_confianza'] ?? [],
                        'titulo' => 'confianza',
                        'tolerancia' => $tolerancia,
                    ])
                </div>
                <div class="col-md-6">
                    <div class="small fw-semibold">Por origen</div>
                    @include('lupita.partials.prediccion_desglose', [
                        'grupos' => $prod['por_origen'] ?? [],
                        'titulo' => 'origen',
                        'tolerancia' => $tolerancia,
                    ])
                </div>
            </div>
        </div>
    </div>

    {{-- 2) MOTOR: la corrida de aciertos --}}
    <div class="card mb-3 border-primary">
        <div class="card-header bg-primary text-white d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-cpu"></i> Motor — acierto medido sobre referencias reales</strong>
            <span class="small">
                @if($motor !== null)
                    {{ $motor['n'] }} filas · corrida {{ $motor['desde'] }} → {{ $motor['hasta'] }} ·
                    cacheada 10 min
                @else
                    sin corrida
                @endif
            </span>
        </div>
        <div class="card-body">
            @if($motor === null)
                <div class="alert alert-warning py-2 small mb-0">
                    <strong>No se pudo medir el motor.</strong> {{ $t['error_motor'] ?? '' }}
                </div>
            @else
                @php $r = $motor['resumen']; @endphp
                <div class="row g-3 mb-2">
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">MAE</div>
                        <div class="fs-3 fw-bold">{{ $corto($r->mae_min ?? null) }}</div>
                        <div class="small text-muted">n = {{ (int) ($r->n ?? 0) }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Sesgo</div>
                        <div class="fs-3 fw-bold">{{ $signo($r->sesgo_min ?? null) }}</div>
                        <div class="small text-muted">mediana {{ $signo($r->mediana_error_min ?? null) }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Éxito en la ventana</div>
                        <div class="fs-3 fw-bold text-success">{{ $pct($r->pct_ventana ?? null) }}</div>
                        <div class="small text-muted">{{ (int) ($r->dentro_ventana ?? 0) }} de {{ (int) ($r->n ?? 0) }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Antelación</div>
                        <div class="fs-3 fw-bold">{{ $corto($motor['horizonte_min'] ?? null) }}</div>
                        <div class="small text-muted">antes del hecho, en mediana</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted small mb-1">Dentro de tolerancias</div>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="badge bg-success">±30m {{ $pct($r->pct_30 ?? null) }}</span>
                            <span class="badge bg-success">±1h {{ $pct($r->pct_60 ?? null) }}</span>
                            <span class="badge bg-info text-dark">±2h {{ $pct($r->pct_120 ?? null) }}</span>
                            <span class="badge bg-info text-dark">±3h {{ $pct($r->pct_180 ?? null) }}</span>
                            <span class="badge bg-warning text-dark">±6h {{ $pct($r->pct_360 ?? null) }}</span>
                            <span class="badge bg-warning text-dark">±12h {{ $pct($r->pct_720 ?? null) }}</span>
                        </div>
                        <div class="text-muted mt-1" style="font-size:.7rem">
                            {{ $motor['descartados'] }} pares descartados por fuera de dominio ·
                            {{ $motor['sin_ruta'] }} sin ruta
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small fw-semibold">Por evidencia</div>
                        @include('lupita.partials.prediccion_desglose', [
                            'grupos' => $motor['por_evidencia'] ?? [],
                            'titulo' => 'evidencia',
                            'tolerancia' => $tolerancia,
                        ])
                    </div>
                    <div class="col-md-4">
                        <div class="small fw-semibold">Por horizonte</div>
                        @include('lupita.partials.prediccion_desglose', [
                            'grupos' => $motor['por_horizonte'] ?? [],
                            'titulo' => 'horizonte',
                            'tolerancia' => $tolerancia,
                        ])
                    </div>
                    <div class="col-md-4">
                        <div class="small fw-semibold">Por confianza declarada</div>
                        @include('lupita.partials.prediccion_desglose', [
                            'grupos' => $motor['por_confianza'] ?? [],
                            'titulo' => 'confianza',
                            'tolerancia' => $tolerancia,
                        ])
                    </div>
                </div>
                <div class="text-muted mt-2" style="font-size:.7rem">
                    La confianza que publica el API debería bajar el error al subir de estrato. Si los estratos
                    altos se equivocan igual que los bajos, ese número no informa nada y hay que recalibrarlo.
                </div>
            @endif
        </div>
    </div>

    {{-- 3) MONITORISTA: compromiso_carga contra la realidad --}}
    <div class="card mb-3 border-warning">
        <div class="card-header bg-warning text-dark d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-person-check"></i> Monitorista — <code>compromiso_carga</code> contra la realidad</strong>
            <span class="small">
                {{ $moni['n'] ?? 0 }} con compromiso y presentación real ·
                cobertura {{ $pct($moni['cobertura'] ?? null) }}
                ({{ $moni['con_compromiso'] ?? 0 }} de {{ $moni['refs_rango'] ?? 0 }} refs del rango)
            </span>
        </div>
        <div class="card-body">
            @if(($moni['cobertura'] ?? 0) !== null && ($moni['cobertura'] ?? 0) < 80)
                <div class="alert alert-secondary py-2 small mb-3">
                    <strong>Cobertura parcial:</strong> sólo el {{ $moni['cobertura'] }} % de las referencias del
                    rango traen <code>compromiso_carga</code> cargado @if($moni['ultimo_compromiso'] ?? null),
                        el último el {{ \Carbon\Carbon::parse($moni['ultimo_compromiso'])->format('d/m/Y') }}@endif.
                    Los números de abajo describen esa parte, no toda la operación.
                </div>
            @endif

            @if(($moni['n'] ?? 0) === 0)
                <div class="text-muted small">
                    En el rango no hay referencias con <code>compromiso_carga</code> y presentación real.
                </div>
            @else
                @if(($moni['descartados'] ?? 0) > 0)
                    <div class="alert alert-secondary py-2 small mb-3">
                        {{ $moni['descartados'] }} compromisos se descartaron por alejarse más de
                        {{ intdiv(\App\Prediccion\Tablero::TOPE_COMPROMISO_MIN, 1440) }} días de la presentación
                        real (fechas mal cargadas): contarlos arruinaría el MAE.
                    </div>
                @endif
                <div class="row g-3 mb-2">
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Cumplió el compromiso</div>
                        <div class="fs-3 fw-bold text-success">{{ $pct($moni['pct_cumple'] ?? null) }}</div>
                        <div class="small text-muted">{{ $moni['cumple'] ?? 0 }} de {{ $moni['n'] ?? 0 }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Llegó tarde</div>
                        <div class="fs-3 fw-bold text-danger">{{ $moni['tarde'] ?? 0 }}</div>
                        <div class="small text-muted">presentó después de lo prometido</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">MAE del compromiso</div>
                        <div class="fs-3 fw-bold">{{ $corto($moni['mae'] ?? null) }}</div>
                        <div class="small text-muted">p90 {{ $corto($moni['p90'] ?? null) }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Error mediano</div>
                        <div class="fs-3 fw-bold">{{ $signo($moni['error_mediano'] ?? null) }}</div>
                        <div class="small text-muted">real − compromiso</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted small">Antelación con la que promete</div>
                        <div class="fs-3 fw-bold">{{ $corto($moni['antelacion_min'] ?? null) }}</div>
                        <div class="small text-muted">
                            desde el despacho hasta el compromiso; el motor predice
                            {{ $corto($motor['horizonte_min'] ?? null) }} antes
                        </div>
                    </div>
                </div>

                {{-- la misma escalera de tolerancias que producción, sobre el compromiso --}}
                <div class="mb-2">
                    @include('lupita.partials.prediccion_tolerancias', [
                        'datos' => $moni,
                        'n' => $moni['n'] ?? 0,
                        'titulo' => 'Dentro de tolerancias (real contra compromiso):',
                    ])
                </div>

                <div class="border-top pt-2">
                    <div class="small fw-semibold mb-1">
                        Cara a cara sobre las mismas referencias (n = {{ $cara['n'] ?? 0 }})
                    </div>
                    @if(($cara['n'] ?? 0) === 0)
                        <div class="text-muted small">
                            Todavía no hay referencias donde el motor haya predicho <em>y</em> exista compromiso
                            del monitorista. Se llena cuando el API prediga refs que tengan
                            <code>compromiso_carga</code>.
                        </div>
                    @else
                        @if(($cara['n'] ?? 0) < \App\Prediccion\Tablero::MUESTRA_MINIMA)
                            <div class="alert alert-warning py-2 small mb-2">
                                Con {{ $cara['n'] }} referencias en común no se puede concluir nada: el motor
                                predice sólo las refs que ya tienen viaje anterior, y el compromiso se dejó de
                                cargar en los últimos días del rango. Elegí un rango más viejo (donde el
                                compromiso esté completo) para que la comparación tenga muestra.
                            </div>
                        @endif
                        <div class="row g-3">
                            <div class="col-6 col-md-2">
                                <div class="text-muted small">MAE motor</div>
                                <div class="fs-4 fw-bold text-primary">{{ $corto($cara['mae_motor'] ?? null) }}</div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="text-muted small">MAE compromiso</div>
                                <div class="fs-4 fw-bold text-warning">{{ $corto($cara['mae_monitorista'] ?? null) }}</div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="text-muted small">Ganó el motor</div>
                                <div class="fs-4 fw-bold">{{ (int) ($cara['gana_motor'] ?? 0) }}</div>
                                <div class="small text-muted">{{ $pct($cara['pct_gana_motor'] ?? null) }}</div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="text-muted small">Ganó el compromiso</div>
                                <div class="fs-4 fw-bold">{{ (int) ($cara['gana_monitorista'] ?? 0) }}</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="text-muted small">Diferencia (compromiso − motor)</div>
                                <div class="fs-4 fw-bold {{ ($cara['diferencia'] ?? 0) > 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $signo($cara['diferencia'] ?? null) }}
                                </div>
                                <div class="small text-muted">
                                    {{ ($cara['diferencia'] ?? 0) > 0 ? 'el motor se acerca más' : 'el compromiso se acerca más' }}
                                    · {{ (int) ($cara['empate'] ?? 0) }} empates
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="text-muted mt-2" style="font-size:.7rem">
                        Ojo al leerlo: no compiten con la misma información. El monitorista compromete desde el
                        despacho y el motor predice desde el último hito real del camión, mucho más cerca del
                        hecho. Si el motor no le gana al compromiso con esa ventaja, el modelo todavía no aporta.
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- 4) parámetros con los que está corriendo --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white d-flex flex-wrap justify-content-between gap-2">
            <strong><i class="bi bi-sliders"></i> Parámetros con los que está corriendo</strong>
            <span class="small">
                <a class="link-light" href="{{ route('lupita.prediccion.parametros.ver') }}">
                    calibrar / cambiar &rarr;
                </a>
            </span>
        </div>
        <div class="card-body">
            <div class="row g-2">
                @foreach(($t['parametros'] ?? []) as $parametro)
                    <div class="col-md-4">
                        <div class="border rounded px-2 py-1 h-100">
                            <div class="d-flex justify-content-between gap-2">
                                <code class="small">{{ $parametro['clave'] }}</code>
                                <strong>{{ $parametro['valor'] === null ? '—' : $parametro['valor'] }}</strong>
                            </div>
                            <div class="text-muted" style="font-size:.7rem">{{ $parametro['texto'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 5) la tabla de filas --}}
    <div class="card mb-3">
        <div class="card-header">
            <strong>Filas guardadas del rango</strong>
            <span class="small text-muted">— se muestran hasta {{ (int) $filtros['limite'] }}</span>
        </div>
        <div class="card-body">
            @if(!empty($g['filas']))
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Ref</th>
                            <th>Placa</th>
                            <th>Corte</th>
                            <th class="text-end">Horizonte<br><span class="fw-normal small">desde el ancla</span></th>
                            <th class="text-success">Rápido</th>
                            <th class="text-primary">Predicho</th>
                            <th class="text-warning">Tardado</th>
                            <th class="text-success">Real<br><span class="fw-normal small">presenta_para_carga</span></th>
                            <th class="text-end">Error</th>
                            <th>Acierto</th>
                            <th>Origen</th>
                            <th>Evidencia</th>
                            <th class="text-end">Conf.</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($g['filas'] as $fila)
                            <tr>
                                <td><code>{{ $fila->ref }}</code></td>
                                <td class="small">{{ $fila->placa }}</td>
                                <td class="small text-nowrap">{{ $fila->corte->format('d/m/Y H:i') }}</td>
                                <td class="small text-end text-nowrap">
                                    @if(($fila->horizonte_min ?? null) === null)
                                        <span class="text-muted">—</span>
                                    @else
                                        @php $horas = $fila->horizonte_min / 60; @endphp
                                        <span class="badge bg-{{ $horas <= 6 ? 'success' : ($horas <= 24 ? 'info text-dark' : 'danger') }}"
                                              title="desde el último evento de la placa ({{ $fila->ancla_fecha?->format('d/m H:i') ?? '—' }}) hasta la presentación real">
                                            {{ $fmt($fila->horizonte_min) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="small text-success text-nowrap">
                                    {{ $fila->rapido ? $fila->rapido->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-primary fw-bold text-nowrap">
                                    {{ $fila->predicho ? $fila->predicho->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-warning text-nowrap">
                                    {{ $fila->tardado ? $fila->tardado->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-success fw-bold text-nowrap">
                                    {{ $fila->real ? $fila->real->format('d/m H:i') : '—' }}
                                </td>
                                <td class="small text-end text-nowrap">
                                    @if($fila->error_min === null)
                                        —
                                    @else
                                        <span class="badge bg-{{ $colorError($fila->error_min) }}">{{ $signo($fila->error_min) }}</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">
                                    @if($fila->en_ventana === true)
                                        <span class="badge bg-success">dentro</span>
                                    @elseif($fila->en_ventana === false)
                                        <span class="badge bg-danger">afuera</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $fila->origen }}</td>
                                <td class="small text-muted">{{ $fila->evidencia }}</td>
                                <td class="small text-end text-muted">{{ (int) $fila->confianza }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted mt-2">
                    El acierto de arriba se calcula sobre todas las guardadas del rango, no sobre estas filas.
                    Si una ref tiene varias predicciones, vale la <strong>última hecha antes de presentar</strong>:
                    es la que el despachador vio.
                </div>
            @else
                <div class="text-muted small">
                    Todavía no hay predicciones guardadas en el rango. Se llenan solas al usar
                    <a href="{{ route('lupita.prediccion.presenta') }}">la calculadora</a> o el API, o apretando
                    <strong>"Guardar estas predicciones"</strong> en la
                    <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de aciertos</a>.
                </div>
            @endif
        </div>
    </div>
@endsection
