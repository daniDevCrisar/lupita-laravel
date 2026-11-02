{{-- Mapa de calor dia x hora. Recibe $heatmap (matriz + maximo) y nada mas.
     Compartido por el dashboard y el reporte web. --}}
@php
    $dias_nombre = [1 => 'Domingo', 2 => 'Lunes', 3 => 'Martes', 4 => 'Miércoles', 5 => 'Jueves', 6 => 'Viernes', 7 => 'Sábado'];
    $heat = $heatmap['matriz'];
    $heat_max = max(1, $heatmap['maximo']);
@endphp

<div class="card bg-dark border-secondary mb-3">
    <div class="card-body">
        <h6 class="fw-bold text-light mb-1"><i class="bi bi-fire text-danger me-2"></i>Mapa de calor · día x hora</h6>
        <p class="text-white-50 small mb-3">Intensidad = cantidad de llamadas. El punto más oscuro marca el bloque de mayor demanda.</p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 text-center">
                <thead class="thead-lupita">
                <tr>
                    <th class="text-start" style="min-width:70px;">Día</th>
                    @for($h = 0; $h < 24; $h++)
                        <th class="small">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</th>
                    @endfor
                </tr>
                </thead>
                <tbody>
                @foreach([2, 3, 4, 5, 6, 7, 1] as $dow)
                    <tr>
                        <td class="text-start text-light small fw-bold">{{ $dias_nombre[$dow] }}</td>
                        @for($h = 0; $h < 24; $h++)
                            @php
                                $celda = $heat[$dow][$h] ?? null;
                                $valor = $celda['total'] ?? 0;
                                $alfa = $valor ? max(0.18, round($valor / $heat_max, 2)) : 0.04;
                            @endphp
                            <td style="background:rgba(13,110,253,{{ $alfa }});color:{{ $alfa >= 0.45 ? '#fff' : '#dee2e6' }};font-size:.75rem;"
                                title="{{ $valor }} llamadas">
                                {{ $valor ?: '' }}
                            </td>
                        @endfor
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
