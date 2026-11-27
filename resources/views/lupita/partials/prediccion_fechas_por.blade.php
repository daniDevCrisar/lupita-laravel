{{--
    Las fechas publicadas dentro de cada segmento (evidencia, horizonte,
    confianza y origen).

    Antes cada desglose medía sólo la fecha normal: no se veía lo que importa, que
    en `cadena` o pasando 24 h la mejor fecha es otra. Cada celda trae el MAE de
    esa fecha dentro del tramo y, debajo, el acierto en la tolerancia; el MAE más
    bajo de la fila queda marcado y la última columna dice quién gana la carrera
    de "más cerca" en ese tramo.

    Las columnas salen de `Tablero::catalogo_fechas()`, así que agregar una fecha
    (la 1.47 sumó `perfecta`) no necesita tocar esta vista. OJO: la comparación es
    cara a cara, así que una fila guardada antes de la 1.47 —sin la sexta— no
    entra en el cuadro.

    Con `promediar` no cambia nada: acá se comparan las fechas publicadas, no el
    promedio.

    Recibe:
      $desgloses  array de salidas de App\Prediccion\Tablero::fechas_por()
--}}
@php
    $compacto = function ($min) {
        if ($min === null) {
            return '—';
        }

        return abs($min) >= 60
            ? intdiv(abs($min), 60) . 'h' . str_pad((string) (abs($min) % 60), 2, '0', STR_PAD_LEFT)
            : abs($min) . '′';
    };
@endphp

<div class="row g-3">
    @foreach($desgloses as $desglose)
        @if(($desglose['filas'] ?? []) !== [])
            <div class="col-12">
                <div class="card">
                    <div class="card-header py-1">
                        <span class="fw-semibold">{{ $desglose['titulo'] }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.76rem">
                            <thead class="table-light">
                            <tr>
                                <th>{{ $desglose['titulo'] }}</th>
                                <th class="text-end">n</th>
                                @foreach($desglose['fechas'] as $fecha)
                                    <th class="text-end">{{ $fecha['etiqueta'] }}</th>
                                @endforeach
                                <th class="text-end" title="quién gana más veces el «más cerca» dentro del tramo">
                                    Más cerca
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($desglose['filas'] as $fila)
                                <tr>
                                    <td><code>{{ $fila['clave'] }}</code></td>
                                    <td class="text-end text-muted">{{ (int) $fila['n'] }}</td>
                                    @foreach($fila['celdas'] as $indice => $celda)
                                        <td class="text-end {{ $fila['mejor'] !== null && (int) $fila['mejor'] === (int) $indice ? 'fw-bold text-success' : '' }}">
                                            {{ $compacto($celda['mae']) }}
                                            @if($celda['pct'] !== null)
                                                <div class="text-muted" style="font-size:.68rem">
                                                    {{ $celda['pct'] }} % ±{{ $desglose['tolerancia'] }}′
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-end">
                                        @if($fila['gana'] === null)
                                            —
                                        @else
                                            <span class="badge bg-secondary">{{ $fila['gana'] }}</span>
                                            <span class="text-muted">{{ $fila['gana_pct'] }} %</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(($desglose['nota'] ?? null) !== null)
                        <div class="card-footer py-1 small text-muted">{{ $desglose['nota'] }}</div>
                    @endif
                </div>
            </div>
        @endif
    @endforeach
</div>
