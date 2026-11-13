@extends('layouts.app')

@section('title', 'Uso del API de Geoapify')

@section('content')
    @php
        $hoy = $uso->hasta->isToday() ? 'hoy' : $uso->hasta->format('d/m/Y');
        $n = fn ($valor) => number_format((int) $valor);
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-cloud-check"></i> Uso del API de Geoapify</h1>
            <p class="text-muted small mb-1">
                Geoapify se llama <strong>sólo cuando no hay ningún promedio compilado</strong> de la flota para el
                traslado (ni ruta medida entre locales, ni par de ubigeos, ni la ruta de la propia referencia). Acá
                está el contador real: <strong>cada petición HTTP suma uno</strong>, también la que falla o la que se
                reintenta, porque es lo que el proveedor descuenta del plan.
            </p>
            <p class="text-muted small mb-0">
                <a href="{{ route('lupita.prediccion.presenta') }}">&larr; Calculadora</a>
                · <a href="{{ route('lupita.prediccion.documentacion') }}">Documentación del API &rarr;</a>
            </p>
        </div>
    </div>

    @if(!$uso->tabla)
        <div class="alert alert-warning">
            <strong>La tabla del contador no existe.</strong>
            La predicción funciona igual, pero no se puede contar el uso. Aplicá el DDL
            (migración <code>2026_11_13_160000_create_geoapify_uso_table.php</code> o el bloque
            <code>geoapify_uso</code> de <code>sql/prediccion.sql</code>):
            <pre class="small mb-0 mt-2">CREATE TABLE `geoapify_uso` (
    `fecha` DATE NOT NULL,
    `tipo` VARCHAR(20) NOT NULL,
    `llamadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `exitosas` INT UNSIGNED NOT NULL DEFAULT 0,
    `fallidas` INT UNSIGNED NOT NULL DEFAULT 0,
    `actualizado_en` DATETIME NULL,
    PRIMARY KEY (`fecha`, `tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
        </div>
    @endif

    @if(!$contando)
        <div class="alert alert-warning py-2">
            <strong>El contador está apagado</strong> (<code>GEOAPIFY_CONTAR=false</code>): las llamadas se hacen
            pero no se registran.
        </div>
    @endif

    @if(!$disponible)
        <div class="alert alert-secondary py-2">
            Ahora mismo <strong>no hay key de Geoapify</strong> (<code>GEOAPIFY_API_KEY</code>): no se está llamando al
            API, así que el contador no debería moverse.
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100 border-primary">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">{{ ucfirst($hoy) }}</div>
                    <div class="fs-3 fw-bold text-primary">{{ $n($uso->hoy) }}</div>
                    <div class="text-muted" style="font-size:.7rem">
                        {{ $n($uso->hoy_geocode) }} geocodificación · {{ $n($uso->hoy_routing) }} rutas
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">Ayer</div>
                    <div class="fs-3 fw-bold">{{ $n($uso->ayer) }}</div>
                    <div class="text-muted" style="font-size:.7rem">llamadas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">Últimos 7 días</div>
                    <div class="fs-3 fw-bold">{{ $n($uso->ultimos_7) }}</div>
                    <div class="text-muted" style="font-size:.7rem">llamadas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">Últimos 30 días</div>
                    <div class="fs-3 fw-bold">{{ $n($uso->ultimos_30) }}</div>
                    <div class="text-muted" style="font-size:.7rem">
                        {{ $uso->dias_con_uso }} {{ $uso->dias_con_uso === 1 ? 'día con uso' : 'días con uso' }}
                        @if($uso->promedio_dia_uso !== null)
                            · {{ $uso->promedio_dia_uso }}/día
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">Histórico</div>
                    <div class="fs-3 fw-bold">{{ $n($uso->total) }}</div>
                    <div class="text-muted" style="font-size:.7rem">
                        {{ $n($uso->total_geocode) }} geocodificación · {{ $n($uso->total_routing) }} rutas
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted small text-uppercase">Fallidas</div>
                    <div class="fs-3 fw-bold {{ $uso->total_fallidas > 0 ? 'text-danger' : '' }}">{{ $n($uso->total_fallidas) }}</div>
                    <div class="text-muted" style="font-size:.7rem">
                        @if($uso->ultima)
                            última: {{ $uso->ultima->format('d/m/Y H:i') }}
                        @else
                            sin llamadas registradas
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-light">
            <strong>Día por día</strong>
            <span class="text-muted small">— últimos 30 días, {{ $uso->desde->format('d/m/Y') }} a {{ $uso->hasta->format('d/m/Y') }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Día</th>
                    <th class="text-end">Geocodificación</th>
                    <th class="text-end">Rutas</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Fallidas</th>
                </tr>
                </thead>
                <tbody>
                @foreach($uso->dias as $dia)
                    <tr @class(['fw-bold' => $dia->fecha->isSameDay($uso->hasta)])>
                        <td>
                            {{ $dia->fecha->format('d/m/Y') }}
                            @if($dia->fecha->isSameDay($uso->hasta))
                                <span class="badge bg-primary">{{ $uso->hasta->isToday() ? 'hoy' : 'corte' }}</span>
                            @endif
                        </td>
                        <td class="text-end {{ $dia->geocode === 0 ? 'text-muted' : '' }}">{{ $n($dia->geocode) }}</td>
                        <td class="text-end {{ $dia->routing === 0 ? 'text-muted' : '' }}">{{ $n($dia->routing) }}</td>
                        <td class="text-end {{ $dia->total === 0 ? 'text-muted' : '' }}">{{ $n($dia->total) }}</td>
                        <td class="text-end {{ $dia->fallidas === 0 ? 'text-muted' : 'text-danger' }}">{{ $n($dia->fallidas) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                <tr>
                    <th>30 días</th>
                    <th class="text-end">{{ $n(array_sum(array_map(fn ($dia) => $dia->geocode, $uso->dias))) }}</th>
                    <th class="text-end">{{ $n(array_sum(array_map(fn ($dia) => $dia->routing, $uso->dias))) }}</th>
                    <th class="text-end">{{ $n($uso->ultimos_30) }}</th>
                    <th class="text-end">{{ $n(array_sum(array_map(fn ($dia) => $dia->fallidas, $uso->dias))) }}</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-light"><strong>Cómo se cuenta</strong></div>
        <div class="card-body small">
            <ul class="mb-0">
                <li><strong>geocode</strong>: ubicar las coordenadas del distrito (tabla <code>geo_coordenadas</code>).
                    Se pide una sola vez por ubigeo: si ya está guardado, no se llama al API. Si el primer texto del
                    distrito no encuentra nada, se reintenta con el nombre solo — eso son <strong>2 llamadas</strong>.</li>
                <li><strong>routing</strong>: tiempo de manejo entre dos ubigeos. Una llamada por par
                    (origen → destino) que no tenga ningún promedio medido.</li>
                <li>El contador se guarda por día y por endpoint en <code>geoapify_uso</code>. La pantalla de la
                    calculadora y el JSON del API muestran <code>geoapify.llamadas</code> (lo que gastó ESA
                    predicción) y <code>geoapify.uso_hoy</code> (el acumulado del día).</li>
                <li>La tabla de aciertos corre con Geoapify <strong>apagado</strong>: recalcular cientos de filas no
                    debe gastar API, así que no suma acá.</li>
            </ul>
        </div>
    </div>
@endsection
