{{--
    Tabla de desglose del tablero de predicciones guardadas.

    Recibe:
      $grupos      salida de App\Prediccion\Tablero::agrupar() (o por_evidencia y compañía)
      $titulo      nombre de la primera columna
      $tolerancia  minutos de la tolerancia aceptada (para el encabezado)
      $campo       clave del grupo (por defecto 'clave')
--}}
@php
    $fmt = fn ($min) => $min === null ? '—' : (abs($min) >= 60
        ? intdiv(abs($min), 60) . ' h ' . str_pad((string) (abs($min) % 60), 2, '0', STR_PAD_LEFT)
        : $min . ' min');
    $corto = fn ($min) => $min === null ? '—' : (abs($min) >= 60 ? $fmt($min) : abs($min) . '′');
    $signo = fn ($min) => $min === null ? '—'
        : ($min > 0 ? '+' : ($min < 0 ? '−' : '')) . $corto(abs($min));
    $campo = $campo ?? 'clave';
@endphp

<div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.78rem">
        <thead class="table-light">
        <tr>
            <th>{{ $titulo ?? 'Grupo' }}</th>
            <th class="text-end">n</th>
            <th class="text-end">MAE</th>
            <th class="text-end">Error mediano</th>
            <th class="text-end">p90</th>
            <th class="text-end" title="aciertos dentro de ±{{ $tolerancia ?? 180 }} min">
                ±{{ $tolerancia ?? 180 }}′
            </th>
            <th class="text-end" title="aciertos dentro de la ventana rápido–tardado">Ventana</th>
        </tr>
        </thead>
        <tbody>
        @forelse($grupos as $grupo)
            <tr>
                <td><code>{{ $grupo[$campo] ?? '—' }}</code></td>
                <td class="text-end">{{ (int) ($grupo['n'] ?? 0) }}</td>
                <td class="text-end">{{ $corto($grupo['mae'] ?? null) }}</td>
                <td class="text-end">{{ $signo($grupo['error_mediano'] ?? null) }}</td>
                <td class="text-end">{{ $corto($grupo['p90'] ?? null) }}</td>
                <td class="text-end">{{ $grupo['pct_tolerancia'] === null ? '—' : $grupo['pct_tolerancia'] . ' %' }}</td>
                <td class="text-end">{{ $grupo['pct_ventana'] === null ? '—' : $grupo['pct_ventana'] . ' %' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-muted">sin datos comparables en el rango</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
