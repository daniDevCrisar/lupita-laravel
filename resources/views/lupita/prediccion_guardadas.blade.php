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
                {{-- "Ver" no tiene que perder las fechas tildadas en la tarjeta de producción --}}
                <input type="hidden" name="comparar" value="1">
                @foreach(($t['fechas']['seleccion'] ?? []) as $claveFecha)
                    <input type="hidden" name="fechas[]" value="{{ $claveFecha }}">
                @endforeach
                @if(($t['fechas']['promediado'] ?? false) || ($t['fechas']['promediar_pedido'] ?? false))
                    <input type="hidden" name="promediar" value="1">
                @endif
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
                    <label class="form-label" for="limite">Límite de refs</label>
                    <select name="limite" id="limite" class="form-select">
                        @foreach($limites as $opcion)
                            <option value="{{ $opcion }}" @selected((int) $filtros['limite'] === $opcion)>
                                {{ (int) $opcion === 0 ? 'todas' : number_format((int) $opcion, 0, ',', '.') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    {{-- tope de horizonte: el motor acierta con horizontes cortos y no
                         con los de días; esto deja fuera las filas que lo pasan --}}
                    <label class="form-label" for="horizonte">Horizonte</label>
                    <select name="horizonte" id="horizonte" class="form-select">
                        @foreach($horizontes ?? [0] as $opcion)
                            <option value="{{ $opcion }}" @selected((int) ($filtros['horizonte'] ?? 0) === (int) $opcion)>
                                {{ (int) $opcion === 0 ? 'sin tope' : 'hasta ' . $opcion . ' h' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    {{-- local donde el camión se presenta a cargar (planta de carga) --}}
                    @include('lupita.tools.selector', [
                        'nombre' => 'local',
                        'id' => 'local',
                        'etiqueta' => 'Local donde presenta a cargar',
                        'tamano' => 'sm',
                        'opciones' => $plantas ?? [],
                        'todas' => 'Todos los locales',
                        'valor' => (string) ($filtros['local'] ?? ''),
                        'ayuda' => 'Planta de carga de la ruta: es el local donde el camión se presenta.',
                    ])
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Ver</button>
                    <button class="btn btn-outline-secondary" name="refrescar" value="1"
                            title="vuelve a correr el backtest del motor (se guarda 10 min)">
                        <i class="bi bi-arrow-clockwise"></i> Medir motor
                    </button>
                    <a href="{{ route('lupita.prediccion.guardadas') }}" class="btn btn-outline-dark">
                        <i class="bi bi-x-circle"></i> Limpiar filtros
                    </a>
                    @if(($filtros['local'] ?? '') !== '')
                        <span class="align-self-center small text-muted">
                            filtrando por <strong>{{ $filtros['local'] }}</strong>
                        </span>
                    @endif
                    @if((int) ($filtros['horizonte'] ?? 0) > 0)
                        <span class="align-self-center small text-muted">
                            · horizonte <strong>≤ {{ (int) $filtros['horizonte'] }} h</strong>
                            ({{ (int) ($prod['horizonte_dentro'] ?? 0) }} filas dentro,
                            {{ (int) ($prod['horizonte_fuera'] ?? 0) }} fuera @if((int) ($prod['horizonte_sin'] ?? 0) > 0),
                            {{ (int) $prod['horizonte_sin'] }} sin ancla @endif
                        </span>
                    @endif
                </div>
            </form>

            {{-- borrar lo guardado del rango: el motor se recalibra y lo viejo
                 compara contra un modelo que ya no existe --}}
            <form method="POST" action="{{ route('lupita.prediccion.guardadas.borrar') }}"
                  class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top"
                  onsubmit="return confirm('¿Borrar las predicciones guardadas con corte entre {{ $filtros['desde'] }} y {{ $filtros['hasta'] }}@if(($filtros['local'] ?? '') !== ''), del local {{ $filtros['local'] }}@endif? No se puede deshacer.');">
                @csrf
                <input type="hidden" name="fecha_inicio" value="{{ $filtros['desde'] }}">
                <input type="hidden" name="fecha_fin" value="{{ $filtros['hasta'] }}">
                <input type="hidden" name="origen" value="{{ $filtros['origen'] }}">
                <input type="hidden" name="local" value="{{ $filtros['local'] ?? '' }}">
                <input type="hidden" name="limite" value="{{ $filtros['limite'] }}">
                <button class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i> Borrar las de este rango
                </button>
                <span class="small text-muted">
                    Borra de <code>prediccion_resultados</code> las predicciones cuyo <strong>corte</strong> cae en el
                    rango @if($filtros['origen'] !== ''), del origen <strong>{{ $filtros['origen'] }}</strong>@endif @if(($filtros['local'] ?? '') !== ''), del local <strong>{{ $filtros['local'] }}</strong>@endif.
                    El motor se recalibra seguido: lo guardado con otro modelo ensucia el acierto.
                    @if((int) ($filtros['horizonte'] ?? 0) > 0)
                        <strong>Ojo:</strong> el horizonte es una medida de cada fila, no un filtro de la
                        base: se borra todo el rango (no sólo las de ≤ {{ (int) $filtros['horizonte'] }} h).
                    @endif
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
                @php
                    $cuatro = $t['fechas'] ?? [];
                    $opcionesFechas = $cuatro['opciones'] ?? [];
                @endphp
                @if($opcionesFechas !== [])
                    <div class="col-12">
                        <div class="small fw-semibold">Las fechas publicadas, contra la presentación real</div>

                        {{-- elegir qué fechas entran: la comparación es cara a cara --}}
                        <form method="GET" action="{{ route('lupita.prediccion.guardadas') }}"
                              class="d-flex flex-wrap align-items-center gap-2 mt-1 mb-1">
                            <input type="hidden" name="comparar" value="1">
                            <input type="hidden" name="fecha_inicio" value="{{ $filtros['desde'] }}">
                            <input type="hidden" name="fecha_fin" value="{{ $filtros['hasta'] }}">
                            <input type="hidden" name="origen" value="{{ $filtros['origen'] }}">
                            <input type="hidden" name="limite" value="{{ $filtros['limite'] }}">
                            <input type="hidden" name="local" value="{{ $filtros['local'] ?? '' }}">
                            <input type="hidden" name="horizonte" value="{{ (int) ($filtros['horizonte'] ?? 0) }}">
                            <span class="small text-muted">comparar:</span>
                            @foreach($opcionesFechas as $opcion)
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="checkbox" name="fechas[]"
                                           value="{{ $opcion['clave'] }}" id="fecha-{{ $opcion['clave'] }}"
                                           @checked($opcion['activa'])>
                                    <label class="form-check-label small" for="fecha-{{ $opcion['clave'] }}"
                                           title="{{ $opcion['texto'] }}">
                                        {{ $opcion['etiqueta'] }}
                                        @if((int) $opcion['faltan'] > 0)
                                            <span class="badge bg-secondary"
                                                  title="filas del rango que NO tienen esta fecha: quedan fuera de la comparación">
                                                {{ (int) $opcion['faltan'] }} sin dato
                                            </span>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                            {{-- una fila más: el promedio de las tildadas, con sus propios KPIs --}}
                            <div class="form-check form-check-inline m-0 ps-2 border-start">
                                <input class="form-check-input" type="checkbox" name="promediar" value="1"
                                       id="promediar" @checked(($cuatro['promediado'] ?? false) || ($cuatro['promediar_pedido'] ?? false))>
                                <label class="form-check-label small" for="promediar"
                                       title="promedia las horas tildadas de cada fila y calcula los KPIs de ese promedio">
                                    promediar las seleccionadas
                                    <span class="text-muted">(calcula sus KPIs)</span>
                                </label>
                            </div>
                            <button class="btn btn-sm btn-primary py-0"
                                    title="vuelve a calcular la tabla con las fechas tildadas">
                                <i class="bi bi-calculator"></i> Calcular
                            </button>
                        </form>

                        @if(($cuatro['aviso'] ?? null) !== null)
                            <div class="alert alert-warning py-1 px-2 small mb-2">{{ $cuatro['aviso'] }}</div>
                        @endif

                        @if(($cuatro['n'] ?? 0) > 0)
                            <div class="text-muted mb-1" style="font-size:.7rem">
                                Se comparan <strong>{{ (int) $cuatro['n'] }}</strong> filas que tienen
                                @if((int) ($cuatro['comparadas'] ?? 0) === 1)
                                    la fecha elegida
                                @else
                                    las {{ (int) ($cuatro['comparadas'] ?? 0) }} fechas elegidas
                                @endif
                                @if($cuatro['promediado'] ?? false)
                                    <strong>+ su promedio</strong>
                                @elseif($cuatro['promediar_pedido'] ?? false)
                                    <span class="text-warning">(con una sola fecha el promedio es esa fecha:
                                        tildá dos o más para que se agregue la fila)</span>
                                @endif
                                @if((int) ($cuatro['incompletas'] ?? 0) > 0)
                                    · {{ (int) $cuatro['incompletas'] }} quedan fuera porque les falta alguna
                                    (destildá la que falte para comparar con más filas)
                                @endif
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-1" style="font-size:.8rem">
                                    <thead class="table-light">
                                    <tr>
                                        <th>fecha</th>
                                        <th class="text-end" title="el mismo n para todas: son las filas que tienen las fechas elegidas">n</th>
                                        <th class="text-end">MAE</th>
                                        <th class="text-end">Error mediano</th>
                                        <th class="text-end">±30m</th>
                                        <th class="text-end">±1h</th>
                                        <th class="text-end">±2h</th>
                                        <th class="text-end">±3h</th>
                                        <th class="text-end">±6h</th>
                                        <th class="text-end">±12h</th>
                                        <th class="text-end" title="de las {{ (int) $cuatro['n'] }} filas comparadas, cuántas veces esta fecha queda más cerca que las otras elegidas">Más cerca</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($cuatro['filas'] as $fecha)
                                        <tr class="{{ $fecha['clave'] === 'promedio' ? 'table-secondary' : ($fecha['clave'] === 'probable' ? 'table-info' : '') }}">
                                            <td>
                                                <strong>{{ $fecha['etiqueta'] }}</strong>
                                                <div class="text-muted" style="font-size:.7rem">{{ $fecha['texto'] }}</div>
                                                @if(($fecha['veredicto'] ?? null) !== null)
                                                    <div class="mt-1" style="font-size:.65rem">
                                                        @foreach([
                                                            'más cerca' => $fecha['veredicto']['mas_cerca'],
                                                            '±' . $fecha['veredicto']['tolerancia_min'] . ' min' => $fecha['veredicto']['tolerancia'],
                                                        ] as $etiquetaVeredicto => $delta)
                                                            @php $colorVeredicto = $delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'secondary'); @endphp
                                                            <span class="badge bg-{{ $colorVeredicto }}"
                                                                  title="contra la mejor de las fechas elegidas en esa columna">
                                                                {{ $etiquetaVeredicto }} {{ $delta > 0 ? '+' : '' }}{{ $delta }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
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
                                veces esa fecha quedó más cerca que las otras elegidas: ganar en promedio (MAE) y ganar en
                                cantidad de veces no es lo mismo — el tardado se lleva los casos de error enorme, la
                                probable los normales.
                                @if($cuatro['promediado'] ?? false)
                                    <br><strong>Promedio</strong> es la hora promedio de las fechas tildadas en cada fila
                                    (promediar las horas es lo mismo que promediar sus errores) y compite en la misma
                                    carrera: si le gana a las sueltas, promediar sirve; si no, no.
                                @endif
                                <br>La comparación es <strong>cara a cara</strong>: sólo entran las filas que tienen
                                <strong>todas</strong> las fechas tildadas. Si no, la fecha que está en más filas gana
                                por tener más casos fáciles, no por ser mejor. Las guardadas viejas no tienen la cuarta
                                ni el consenso: destildalas para comparar las demás con el historial completo.
                            </div>

                            {{-- ============ QUIÉN GANA SEGÚN LA TOLERANCIA ============ --}}
                            @php $kpi = $cuatro['kpi'] ?? []; @endphp
                            @if($kpi !== [])
                                <div class="small fw-semibold mt-3">Quién queda más cerca, según el KPI</div>
                                <div class="text-muted mb-1" style="font-size:.7rem">
                                    De las filas en que <strong>alguna</strong> fecha entra en esa tolerancia, qué
                                    porcentaje se la lleva cada una. Es la pregunta de la promesa ("±30 min"), no la
                                    del promedio de errores.
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-1" style="font-size:.8rem">
                                        <thead class="table-light">
                                        <tr>
                                            <th>KPI</th>
                                            @foreach($kpi[0]['fechas'] ?? [] as $columna)
                                                <th class="text-end">{{ $columna['etiqueta'] }}</th>
                                            @endforeach
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($kpi as $fila)
                                            @php
                                                $mejor = 0;
                                                foreach ($fila['fechas'] as $columna) {
                                                    $mejor = max($mejor, (int) ($columna['pct'] ?? 0));
                                                }
                                            @endphp
                                            <tr>
                                                <td>
                                                    <strong>{{ $fila['etiqueta'] }}</strong>
                                                    <span class="text-muted" style="font-size:.7rem">
                                                        ({{ (int) $fila['total'] }} de {{ (int) $cuatro['n'] }} filas)
                                                    </span>
                                                </td>
                                                @foreach($fila['fechas'] as $columna)
                                                    @php $esMejor = ($columna['pct'] ?? null) !== null && (int) $columna['pct'] === $mejor && $mejor > 0; @endphp
                                                    <td class="text-end {{ $esMejor ? 'fw-bold text-success' : '' }}">
                                                        @if(($columna['pct'] ?? null) === null)
                                                            <span class="text-muted">—</span>
                                                        @else
                                                            {{ (int) $columna['pct'] }} %
                                                            <span class="text-muted" style="font-size:.7rem">({{ (int) $columna['n'] }})</span>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-muted" style="font-size:.7rem">
                                    Cada fila suma 100 %. El total entre paréntesis es cuántas filas comparadas
                                    entraron en esa tolerancia (las demás no las acertó nadie): si el total se
                                    desploma al achicar el KPI, el motor no llega a esa promesa con ninguna fecha.
                                </div>
                            @endif
                        @else
                            <div class="alert alert-warning py-2 small mb-0">
                                <strong>Con esa selección no hay filas completas</strong> en el rango
                                ({{ (int) ($cuatro['total'] ?? 0) }} filas guardadas, ninguna con
                                @if((int) ($cuatro['comparadas'] ?? 0) === 1)
                                    esa fecha
                                @else
                                    esas {{ (int) ($cuatro['comparadas'] ?? 0) }} fechas
                                @endif
                                a la vez). Fijate en los <em>sin dato</em> de cada check para ver cuál sobra.
                            </div>
                        @endif
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
                {{-- las cinco fechas DENTRO de cada segmento ------------------
                     Estos son los cuatro cuadros: evidencia, horizonte, confianza
                     y origen, cada uno con las cinco fechas lado a lado. Antes
                     medían sólo la normal, así que no se veía que en `cadena`,
                     con confianza baja o pasando 24 h la mejor fecha es otra. --}}
                <div class="col-12">
                    <div class="small fw-semibold mb-1">
                        Las cinco fechas dentro de cada segmento
                        <span class="text-muted fw-normal">
                            (cara a cara: sólo filas con todas las fechas elegidas;
                            el MAE más bajo de cada tramo va en verde)
                        </span>
                    </div>
                    @include('lupita.partials.prediccion_fechas_por', [
                        'desgloses' => $prod['fechas_por'] ?? [],
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
                    @if((int) ($motor['fuera_tope'] ?? 0) > 0)
                        <span class="badge bg-warning text-dark"
                              title="con el tope de horizonte, estas filas no entran en las métricas del motor">
                            {{ (int) $motor['dentro'] }} dentro del horizonte ·
                            {{ (int) $motor['fuera_tope'] }} fuera
                        </span>
                    @endif
                    @if((int) ($filtros['limite'] ?? 0) === 0 || (int) $filtros['limite'] > (int) ($motor['limite'] ?? 0))
                        <span class="badge bg-warning text-dark"
                              title="la corrida no puede pasar de este tope: más filas agotan la memoria del host">
                            el motor se mide con {{ number_format((int) ($motor['limite'] ?? 0), 0, ',', '.') }}
                            (tope de memoria)
                        </span>
                    @endif
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
        <div class="card-header d-flex flex-wrap justify-content-between gap-2">
            <span>
                <strong>Filas guardadas del rango</strong>
                <span class="small text-muted">
                    — {{ (int) ($g['guardadas'] ?? 0) }} cargadas con el límite
                    <strong>{{ (int) $filtros['limite'] === 0 ? 'todas' : number_format((int) $filtros['limite'], 0, ',', '.') }}</strong>;
                    se dibujan las primeras {{ (int) \App\Http\Controllers\PrediccionController::FILAS_TABLA_GUARDADAS }}
                </span>
            </span>
            @if((int) ($g['guardadas'] ?? 0) > (int) \App\Http\Controllers\PrediccionController::FILAS_TABLA_GUARDADAS)
                <span class="badge bg-secondary align-self-center">
                    +{{ (int) $g['guardadas'] - (int) \App\Http\Controllers\PrediccionController::FILAS_TABLA_GUARDADAS }}
                    en las métricas
                </span>
            @endif
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

    {{-- comportamiento del combo con búsqueda (tools) --}}
    <script src="{{ asset('js/lupita-selectores.js') }}"></script>
@endsection
