{{-- Rankings de conductores y transportistas en tablas email-safe.
     Recibe $mejores, $peores, $trts y $llamadas (helpers). --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:separate;border-spacing:8px;margin:0 0 8px 0;table-layout:fixed;font-family:'Segoe UI',Arial,sans-serif;">
    <tr>
        {{-- mejores --}}
        <td width="50%" valign="top" style="background:#ffffff;border-top:5px solid #2e7d32;border-radius:12px;padding:16px;">
            <p style="margin:0 0 10px 0;font-size:16px;font-weight:bold;color:#1e2a3a;">🏆 Mejores conductores</p>
            <table width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
                <thead>
                <tr style="background:#2c3e50;color:#ffffff;">
                    <th align="left" style="padding:8px;font-weight:500;">Conductor</th>
                    <th align="center" style="padding:8px;font-weight:500;">Total</th>
                    <th align="center" style="padding:8px;font-weight:500;">Éxito</th>
                    <th align="center" style="padding:8px;font-weight:500;">%</th>
                </tr>
                </thead>
                <tbody>
                @forelse($mejores as $c)
                    <tr style="border-bottom:1px solid #e9ecef;{!! $loop->odd ? '' : 'background:#f8f9fa;' !!}">
                        <td style="padding:8px;">
                            {{ $loop->iteration }}. {{ $c->conductor }}
                            <br><span style="font-size:11px;color:#6c757d;">{{ $c->trt }}</span>
                        </td>
                        <td align="center" style="padding:8px;">{{ $c->total }}</td>
                        <td align="center" style="padding:8px;color:#2e7d32;font-weight:bold;">{{ $c->exitosas }}</td>
                        <td align="center" style="padding:8px;">
                            <span style="display:inline-block;background:#2e7d32;color:#fff;border-radius:12px;padding:1px 8px;font-size:11px;">{{ $c->tasa_exito }}%</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:10px;color:#6c757d;">Sin datos suficientes.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>

        {{-- peores --}}
        <td width="50%" valign="top" style="background:#ffffff;border-top:5px solid #c62828;border-radius:12px;padding:16px;">
            <p style="margin:0 0 10px 0;font-size:16px;font-weight:bold;color:#1e2a3a;">⚠️ Peores conductores</p>
            <table width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
                <thead>
                <tr style="background:#2c3e50;color:#ffffff;">
                    <th align="left" style="padding:8px;font-weight:500;">Conductor</th>
                    <th align="center" style="padding:8px;font-weight:500;">Total</th>
                    <th align="center" style="padding:8px;font-weight:500;">Fallidas</th>
                    <th align="center" style="padding:8px;font-weight:500;">%</th>
                </tr>
                </thead>
                <tbody>
                @forelse($peores as $c)
                    <tr style="border-bottom:1px solid #e9ecef;{!! $loop->odd ? '' : 'background:#f8f9fa;' !!}">
                        <td style="padding:8px;">
                            {{ $loop->iteration }}. {{ $c->conductor }}
                            <br><span style="font-size:11px;color:#6c757d;">{{ $c->trt }}</span>
                        </td>
                        <td align="center" style="padding:8px;">{{ $c->total }}</td>
                        <td align="center" style="padding:8px;color:#c62828;font-weight:bold;">{{ $c->fallidas }}</td>
                        <td align="center" style="padding:8px;">
                            <span style="display:inline-block;background:#c62828;color:#fff;border-radius:12px;padding:1px 8px;font-size:11px;">{{ $c->tasa_exito }}%</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:10px;color:#6c757d;">Sin datos suficientes.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
    </tr>
</table>

{{-- transportistas: volumen, tasa y costo por confirmación --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-radius:12px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 12px 0;font-size:16px;font-weight:bold;color:#1e2a3a;">🚚 Ranking por transportista</p>
        <table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
            <thead>
            <tr style="background:#2c3e50;color:#ffffff;">
                <th align="left" style="padding:10px;font-weight:500;">Transportista</th>
                <th align="center" style="padding:10px;font-weight:500;">Llamadas</th>
                <th align="center" style="padding:10px;font-weight:500;">Exitosas</th>
                <th align="center" style="padding:10px;font-weight:500;">Conduct.</th>
                <th align="center" style="padding:10px;font-weight:500;">% Éxito</th>
                <th align="right" style="padding:10px;font-weight:500;">Costo</th>
                <th align="right" style="padding:10px;font-weight:500;">$/Confirm.</th>
            </tr>
            </thead>
            <tbody>
            @foreach($trts as $trt)
                @php
                    $hex = $trt->tasa_exito >= 70 ? '#2e7d32' : ($trt->tasa_exito >= 45 ? '#ff8f00' : '#c62828');
                @endphp
                <tr style="border-bottom:1px solid #e9ecef;{!! $loop->odd ? 'background:#f8f9fa;' : '' !!}">
                    <td style="padding:10px;"><b>{{ $trt->trt }}</b></td>
                    <td align="center" style="padding:10px;">{{ $trt->total }}</td>
                    <td align="center" style="padding:10px;color:#2e7d32;">{{ $trt->exitosas }}</td>
                    <td align="center" style="padding:10px;">{{ $trt->conductores }}</td>
                    <td align="center" style="padding:10px;">
                        <span style="display:inline-block;background:{{ $hex }};color:#fff;border-radius:12px;padding:2px 9px;font-size:12px;">{{ $trt->tasa_exito }}%</span>
                    </td>
                    <td align="right" style="padding:10px;color:#6c757d;">${{ number_format((float) $trt->costo, 2) }}</td>
                    <td align="right" style="padding:10px;color:#b26a00;">
                        {{ $trt->exitosas ? '$' . number_format((float) $trt->costo_confirmacion, 3) : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </td></tr>
</table>
