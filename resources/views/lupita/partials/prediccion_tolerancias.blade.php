{{--
    Escalera de tolerancias del tablero: ±30m · ±1h · ±2h · ±3h · ±6h · ±12h.

    La misma que muestra la tabla de aciertos, para que las tres varas se puedan
    comparar de un vistazo. Recibe:
      $datos   array con pct_30, pct_60, pct_120, pct_180, pct_360, pct_720
      $n       cuántas mediciones hay (para decir "sin muestra")
      $titulo  texto a la izquierda (opcional)
--}}
@php
    $escalera = [
        30 => 'bg-success',
        60 => 'bg-success',
        120 => 'bg-info text-dark',
        180 => 'bg-info text-dark',
        360 => 'bg-warning text-dark',
        720 => 'bg-warning text-dark',
    ];
    $etiquetas = [30 => '±30 min', 60 => '±1 h', 120 => '±2 h', 180 => '±3 h', 360 => '±6 h', 720 => '±12 h'];
    $sinMuestra = (int) ($n ?? 0) === 0;
@endphp

<div class="d-flex flex-wrap align-items-center gap-1">
    @if($titulo ?? null)
        <span class="text-muted small me-1">{{ $titulo }}</span>
    @endif

    @if($sinMuestra)
        <span class="text-muted small">sin mediciones todavía</span>
    @else
        @foreach($escalera as $minutos => $clase)
            <span class="badge {{ $clase }}">
                {{ $etiquetas[$minutos] }}
                {{ ($datos['pct_' . $minutos] ?? null) === null ? '—' : $datos['pct_' . $minutos] . ' %' }}
            </span>
        @endforeach
    @endif
</div>
