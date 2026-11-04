@php
    // Mapa por volumen de viajes (origen/destino por departamento), reutilizando
    // el mismo calculo de lupita/referencias.
    $maxDep = 1;
    foreach ($mapa['origen'] as $v) { $maxDep = max($maxDep, (int) $v); }
    foreach ($mapa['destino'] as $v) { $maxDep = max($maxDep, (int) $v); }

    // Radio con escala LOGARITMICA y ahora BURBUJAS PEQUENAS (8 a 26 px). Suele
    // haber un departamento dominante (p. ej. Lima) que, con escala lineal, aplasta
    // al resto contra el minimo; el log reparte el rango para que se noten las
    // diferencias. La cantidad exacta no depende del tamano: sale en la etiqueta,
    // en el tooltip y en el grafico de barras de abajo.
    $denom = log($maxDep + 1);
    $tamano = function ($veces) use ($denom) {
        $veces = (int) $veces;
        if ($veces <= 0 || $denom <= 0) return 0;
        return (int) round(8 + (log($veces + 1) / $denom) * 18);
    };

    // Filas para el grafico de volumen por departamento (origen + destino), ordenadas
    // por el total y limitadas a 15. El ancho es LINEAL (propio de un barras), relativo
    // al mayor total, y cada barra va partida en su tramo de origen y de destino.
    $filas = [];
    $codigos = array_unique(array_merge(array_keys($mapa['origen']), array_keys($mapa['destino'])));
    foreach ($codigos as $cod) {
        $o = (int) ($mapa['origen'][$cod] ?? 0);
        $d = (int) ($mapa['destino'][$cod] ?? 0);
        if ($o + $d <= 0) continue;
        $filas[] = ['nombre' => $depas[$cod]['nombre'] ?? $cod, 'o' => $o, 'd' => $d, 't' => $o + $d];
    }
    usort($filas, fn($a, $b) => $b['t'] <=> $a['t']);
    $filas = array_slice($filas, 0, 15);
    $maxTot = 1;
    foreach ($filas as $f) { $maxTot = max($maxTot, $f['t']); }

    $texto_class = $texto_class ?? 'text-muted';
    $ancho = $ancho ?? '520px';
@endphp

<style>
    /* Tooltip CSS instantaneo: el title nativo del navegador tarda ~1 s y resulta
       poco fiable; este aparece de inmediato al pasar el cursor sobre la burbuja. */
    .mv-burbuja { cursor: pointer; }
    .mv-burbuja::after {
        content: attr(data-tip);
        position: absolute;
        left: 50%;
        bottom: 100%;
        transform: translate(-50%, -6px);
        background: #212529;
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        padding: 3px 7px;
        border-radius: 6px;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        z-index: 30;
        box-shadow: 0 2px 6px rgba(0,0,0,.35);
    }
    .mv-burbuja:hover::after { opacity: 1; }
</style>

<div class="row g-3 align-items-center">
    <div class="col-12 col-lg-6">
        <div class="position-relative" style="max-width:{{ $ancho }};margin:0 auto;">
    <img src="{{ asset('images/peru.png') }}" alt="Mapa Peru" class="w-100 rounded">

    @foreach($depas as $cod => $dep)
        @php
            $o = $mapa['origen'][$cod] ?? 0;
            $d = $mapa['destino'][$cod] ?? 0;
            $so = $tamano($o);
            $sd = $tamano($d);
            // bajada de la etiqueta: media del punto mas grande + margen
            $bajada = (int) round(max($so, $sd) / 2) + 3;
        @endphp

        @if($o > 0)
            <span class="mv-burbuja" data-tip="{{ $dep['nombre'] }} - Origen: {{ $o }}"
                  title="{{ $dep['nombre'] }} &#183; Origen: {{ $o }}"
                  style="position:absolute;left:{{ $dep['x'] }}%;top:{{ $dep['y'] }}%;
                         width:{{ $so }}px;height:{{ $so }}px;border-radius:50%;
                         background:rgba(40,167,69,.8);border:1px solid #fff;
                         transform:translate(-50%,-50%);"></span>
        @endif
        @if($d > 0)
            <span class="mv-burbuja" data-tip="{{ $dep['nombre'] }} - Destino: {{ $d }}"
                  title="{{ $dep['nombre'] }} &#183; Destino: {{ $d }}"
                  style="position:absolute;left:calc({{ $dep['x'] }}% + {{ round($sd/2) }}px);
                         top:{{ $dep['y'] }}%;width:{{ $sd }}px;height:{{ $sd }}px;border-radius:50%;
                         background:rgba(23,162,184,.8);border:1px solid #fff;
                         transform:translate(-50%,-50%);"></span>
        @endif
        @if($o > 0 || $d > 0)
            <span style="position:absolute;left:{{ $dep['x'] }}%;top:calc({{ $dep['y'] }}% + {{ $bajada }}px);
                         transform:translate(-50%,0);font-size:9px;line-height:1.1;color:#fff;font-weight:600;
                         text-align:center;
                         text-shadow:0 1px 2px #000,0 0 2px #000;white-space:nowrap;pointer-events:none;">
                {{ $dep['nombre'] }}
                @if($o > 0)<span style="color:#7CFC97">O:{{ $o }}</span>@endif
                @if($d > 0)<span style="color:#7BE4F5">D:{{ $d }}</span>@endif
            </span>
        @endif
    @endforeach
</div>

<div class="text-center mt-2 small {{ $texto_class }}">
    <span class="badge" style="background:rgba(40,167,69,.8)">Origen (O)</span>
    <span class="badge" style="background:rgba(23,162,184,.8)">Destino (D)</span>
    &middot; el tamano del punto es el volumen de viajes cerrados &middot; pasa el cursor para ver la cantidad exacta
</div>
    </div>{{-- /col mapa --}}

    <div class="col-12 col-lg-6">
{{-- Grafico de volumen por departamento (barras horizontales, origen + destino) --}}
@if(!empty($filas))
    <div class="mt-4">
        <div class="fw-bold mb-2 small"><i class="bi bi-bar-chart-horizontal me-1"></i>Volumen por departamento (viajes)</div>
        @foreach($filas as $f)
            @php
                $wO = round($f['o'] / $maxTot * 100, 1);
                $wD = round($f['d'] / $maxTot * 100, 1);
            @endphp
            <div class="d-flex align-items-center mb-1" style="gap:8px;">
                <div class="{{ $texto_class }} text-end" style="flex:0 0 92px;width:92px;font-size:12px;">{{ $f['nombre'] }}</div>
                <div class="flex-grow-1 d-flex" style="height:16px;border-radius:4px;background:rgba(128,128,128,.18);overflow:hidden;">
                    @if($f['o'] > 0)<div style="width:{{ $wO }}%;background:rgba(40,167,69,.85);"></div>@endif
                    @if($f['d'] > 0)<div style="width:{{ $wD }}%;background:rgba(23,162,184,.85);"></div>@endif
                </div>
                <div class="{{ $texto_class }} text-end" style="flex:0 0 104px;width:104px;font-size:12px;white-space:nowrap;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:rgba(40,167,69,.9);"></span> {{ $f['o'] }}
                    <span class="ms-2" style="display:inline-block;width:8px;height:8px;border-radius:2px;background:rgba(23,162,184,.9);"></span> {{ $f['d'] }}
                </div>
            </div>
        @endforeach
    </div>
@endif

@if(!empty($con_animacion) && ($layout ?? null) !== 'layouts.export')
    {{-- Animacion por dia (pequena), debajo del grafico en la columna derecha.
         Solo en pantalla: en la descarga .html no se pinta ningun fragmento. --}}
    <div class="mt-3">
        <div class="fw-bold mb-2 small"><i class="bi bi-film me-1"></i>Animación por día</div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <label class="small mb-0 {{ $texto_class }}" for="calendario_dia">Día</label>
            <input type="date" id="calendario_dia" class="form-control form-control-sm" style="max-width:170px;"
                   min="{{ $fecha_desde ?? '' }}" max="{{ $fecha_hasta ?? '' }}" value="{{ $fecha_desde ?? '' }}">
            <a id="link_mapa_anim" href="#" target="_blank" class="btn btn-sm btn-outline-info text-nowrap">
                <i class="bi bi-box-arrow-up-right"></i> Abrir en pestaña nueva
            </a>
        </div>
        <div class="small {{ $texto_class }} mt-2"><i class="bi bi-info-circle me-1"></i> Solo referencias con etapa fin de descargue.</div>
        <script>
            (function () {
                // calendario -> url de la animacion para el dia elegido (se abre en pestana nueva)
                const inp = document.getElementById('calendario_dia');
                const link = document.getElementById('link_mapa_anim');
                if (!inp || !link) return;
                const base = @json(route('lupita.referencias.mapa'));
                const url = (dia) => base + '?' + new URLSearchParams({
                    fecha_inicio: dia, fecha_fin: dia, etapa: 'fin_descargue', q: '', ruta: ''
                }).toString();
                link.href = url(inp.value);
                inp.addEventListener('change', () => { link.href = url(inp.value); });
            })();
        </script>
    </div>
@endif
    </div>{{-- /col grafico --}}
</div>{{-- /row --}}
