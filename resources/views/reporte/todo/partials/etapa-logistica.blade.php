{{-- Resumen de éxito por etapa logística. Layout en TABLAS con estilos inline (compatible correo).
     Recibe $etapas (1..6) y $dias_total. --}}
@php
    $colores = [
        'success' => '#2e7d32', 'danger' => '#c62828', 'warning' => '#ff8f00',
        'primary' => '#0d6efd', 'info' => '#0c7591', 'secondary' => '#6c757d', 'dark' => '#2c3e50',
    ];
@endphp
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-radius:12px;padding:20px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 14px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">📋 Resumen de éxito por etapa logística</p>
        <table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;">
            <thead>
            <tr style="background:#2c3e50;color:#ffffff;">
                <th align="left" style="padding:10px;font-weight:500;">#</th>
                <th align="left" style="padding:10px;font-weight:500;">Etapa</th>
                <th align="left" style="padding:10px;font-weight:500;">Llamadas</th>
                <th align="left" style="padding:10px;font-weight:500;">Exitosas</th>
                <th align="left" style="padding:10px;font-weight:500;">Días ({{ $dias_total }})</th>
                <th align="left" style="padding:10px;font-weight:500;">Tasa de éxito</th>
            </tr>
            </thead>
            <tbody>
            @for ($i = 1; $i < 7; $i++)
                @php $etapa = $etapas[$i] ?? null; @endphp
                @if($etapa)
                    @php
                        $hex = $colores[$llamadas::$tipos_llamada[$etapa->tipo]->color ?? 'secondary'] ?? '#6c757d';
                    @endphp
                    <tr style="border-bottom:1px solid #e9ecef;{!! ($i % 2 == 1) ? 'background:#f8f9fa;' : '' !!}">
                        <td style="padding:10px;">
                            <span style="display:inline-block;background:{{ $hex }};color:#fff;font-weight:bold;border-radius:6px;padding:2px 8px;">{{$i}}</span>
                        </td>
                        <td style="padding:10px;">{{$llamadas::$tipos_llamada[(int) $etapa->tipo]->emoji ?? ''}} {{$llamadas::$tipos_llamada[(int) $etapa->tipo]->nombre ?? ''}}</td>
                        <td style="padding:10px;">{{$etapa->total}}</td>
                        <td style="padding:10px;"><b style="color:#2e7d32;">{{$etapa->exitosas}}</b></td>
                        <td style="padding:10px;">
                            <span style="display:inline-block;background:{{ $hex }};color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;">{{$etapa->dias}}</span>
                        </td>
                        <td style="padding:10px;">
                            @php $resto = max(0, 100 - (int) $etapa->porcentaje); @endphp
                            <table width="120" cellpadding="0" cellspacing="0" border="0" style="background:#e9ecef;border-radius:4px;height:8px;display:inline-table;vertical-align:middle;table-layout:fixed;">
                                <tr>
                                    <td width="{{ $etapa->porcentaje }}%" style="background:{{ $hex }};border-radius:4px 0 0 4px;font-size:1px;line-height:1px;">&nbsp;</td>
                                    <td width="{{ $resto }}%" style="background:#e9ecef;border-radius:0 4px 4px 0;font-size:1px;line-height:1px;">&nbsp;</td>
                                </tr>
                            </table>
                            &nbsp;{{$etapa->porcentaje}}%
                        </td>
                    </tr>
                @endif
            @endfor
            </tbody>
        </table>
    </td></tr>
</table>
