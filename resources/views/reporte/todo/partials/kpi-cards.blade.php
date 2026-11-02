{{-- 8 tarjetas KPI + barra de duracion. Layout en TABLAS con estilos inline (compatible correo).
     Recibe $t (objecto de etiqueta_totales()). --}}
@php
    $c_total    = (int) ($t->llamadas ?? 0);
    $c_exitosas = (int) ($t->llamada_exitosa ?? 0);
    $c_fallidas = $c_total - $c_exitosas;
    $exitosas_100  = $c_total ? round($c_exitosas / $c_total * 100) : 0;
    $fallidas_100  = $c_total ? round($c_fallidas / $c_total * 100) : 0;
    $promedio_exitosas = ($t->audio_duracion_exitosas && $c_exitosas)
        ? round($t->audio_duracion_exitosas / $c_exitosas) : 0;
    $promedio_fallidas = ($t->audio_duracion_fallidas_sin_buzon && $t->contestadas_fallidas)
        ? round($t->audio_duracion_fallidas_sin_buzon / $t->contestadas_fallidas) : 0;

    // label, valor, icono(emoji), fondo, borde, color del numero, sub-texto
    $cards = [
        ['TOTAL LLAMADAS', $c_total, '📞', '#ffffff', '#0d6efd', '#1e2a3a', '$' . round($t->costo ?? 0, 2)],
        ['EXITOSAS', $c_exitosas, '✅', '#e6f7e6', '#2e7d32', '#2e7d32', $exitosas_100 . '% del total ($' . round($t->costo_exitosa ?? 0, 2) . ')'],
        ['FALLIDAS', $c_fallidas, '❌', '#ffebee', '#c62828', '#c62828', $fallidas_100 . '% del total ($' . round($t->costo_fallida ?? 0, 2) . ')'],
        ['CONDUCTORES ÚNICOS', $t->conductores, '👥', '#fff8e1', '#ff8f00', '#b26a00', $t->trts . ' transportistas'],
        ['LLAMADAS CONTESTADAS', $t->contestadas, '📲', '#e6f7e6', '#2e7d32', '#2e7d32', $t->buzon_de_voz . ' buzón de voz'],
        ['CONDUCTORES CONFIRMADOS', $t->conductores_exitosos, '✔️', '#e6f7e6', '#2e7d32', '#2e7d32', $promedio_exitosas . 's promedio de llamada'],
        ['LLAMADAS NO CONTESTADAS', $t->razon_5_ocupado + $t->razon_3_no_contesta, '📵', '#ffebee', '#c62828', '#c62828', '&nbsp;'],
        ['CONTESTADAS SIN CONFIRMACIÓN', $t->contestadas_fallidas, '⚠️', '#ffebee', '#c62828', '#c62828', $promedio_fallidas . 's promedio de llamada'],
    ];
    $total_cards = count($cards);
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:separate;border-spacing:8px;margin:0 0 16px 0;table-layout:fixed;">
    @foreach($cards as $i => $c)
        @if($i % 4 == 0)<tr>@endif
        <td width="25%" valign="top" style="background:{{ $c[3] }};border-left:5px solid {{ $c[4] }};border-radius:12px;padding:16px;font-family:'Segoe UI',Arial,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td valign="top">
                        <p style="margin:0 0 6px 0;color:#6c757d;text-transform:uppercase;font-size:11px;letter-spacing:.5px;">{{ $c[0] }}</p>
                        <p style="margin:0;font-size:28px;font-weight:bold;color:{{ $c[5] }};">{{ $c[1] }}</p>
                    </td>
                    <td width="40" align="right" valign="top" style="font-size:24px;line-height:1;">{{ $c[2] }}</td>
                </tr>
            </table>
            <p style="margin:10px 0 0 0;color:#6c757d;font-size:12px;">{!! $c[6] !!}</p>
        </td>
        @if($i % 4 == 3)</tr>@endif
    @endforeach
    @if($total_cards % 4 != 0)
        @for($k = $total_cards % 4; $k < 4; $k++)<td width="25%"></td>@endfor
        </tr>
    @endif
</table>

{{-- barra de duracion exitosas vs fallidas (tabla de 2 celdas de color) --}}
@if($t->audio_duracion_total)
    @php
        $duracion_exitosas = $t->audio_duracion_exitosas
            ? round(($t->audio_duracion_exitosas / $t->audio_duracion_total) * 100) : 0;
        $duracion_fallidas = round(($t->audio_duracion_fallidas / $t->audio_duracion_total) * 100) ?: 0;
        $duracion_resto = max(0, 100 - $duracion_exitosas - $duracion_fallidas);
    @endphp
    <table width="100%" cellpadding="0" cellspacing="0" border="0"
           style="margin:0 0 16px 0;height:26px;background:#e9ecef;border-radius:6px;border-collapse:separate;">
        <tr>
            <td width="{{ $duracion_exitosas }}%" align="center"
                style="background:#2e7d32;color:#fff;font-size:12px;font-weight:bold;border-radius:6px 0 0 6px;">
                {{ $llamadas::audio_duracion_format($t->audio_duracion_exitosas) }}
            </td>
            <td width="{{ $duracion_fallidas }}%" align="center"
                style="background:#c62828;color:#fff;font-size:12px;font-weight:bold;">
                {{ $llamadas::audio_duracion_format($t->audio_duracion_fallidas) }}
            </td>
            @if($duracion_resto > 0)<td width="{{ $duracion_resto }}%" style="background:#e9ecef;border-radius:0 6px 6px 0;">&nbsp;</td>@endif
        </tr>
    </table>
@endif
