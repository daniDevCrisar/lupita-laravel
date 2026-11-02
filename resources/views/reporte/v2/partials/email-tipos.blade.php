{{-- Detalle por tipo de llamada en una sola tabla (mismos KPIs del resumen).
     Recibe $tipos (fijos + con datos), $t (resumen) y $dias. --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-radius:12px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 4px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">📑 Detalle por tipo de llamada</p>
        <p style="margin:0 0 14px 0;font-size:13px;color:#6c757d;">
            {{ $dias }} día(s) analizados. El costo por confirmación permite comparar la eficiencia de cada etapa.
        </p>

        <table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
            <thead>
            <tr style="background:#2c3e50;color:#ffffff;">
                <th align="left" style="padding:10px;font-weight:500;">Ámbito</th>
                <th align="center" style="padding:10px;font-weight:500;">Total</th>
                <th align="center" style="padding:10px;font-weight:500;">Exitosas</th>
                <th align="center" style="padding:10px;font-weight:500;">Fallidas</th>
                <th align="center" style="padding:10px;font-weight:500;">% Éxito</th>
                <th align="center" style="padding:10px;font-weight:500;">Conduct.</th>
                <th align="right" style="padding:10px;font-weight:500;">Costo</th>
                <th align="right" style="padding:10px;font-weight:500;">$/Confirm.</th>
            </tr>
            </thead>
            <tbody>
            <tr style="background:#e7f1ff;">
                <td style="padding:10px;"><b>RESUMEN · todas las llamadas</b></td>
                <td align="center" style="padding:10px;">{{ $t->total }}</td>
                <td align="center" style="padding:10px;color:#2e7d32;"><b>{{ $t->exitosas }}</b></td>
                <td align="center" style="padding:10px;color:#c62828;">{{ $t->fallidas }}</td>
                <td align="center" style="padding:10px;"><b>{{ $t->tasa_exito }}%</b></td>
                <td align="center" style="padding:10px;">{{ $t->conductores }}</td>
                <td align="right" style="padding:10px;">${{ number_format((float) $t->costo, 2) }}</td>
                <td align="right" style="padding:10px;color:#b26a00;"><b>${{ number_format((float) $t->costo_confirmacion, 3) }}</b></td>
            </tr>
            @foreach($tipos as $tipo)
                @php
                    $fondo_fila = $loop->odd ? '#ffffff' : '#f8f9fa';
                    $color_pct = $tipo->tasa_exito >= 70 ? '#2e7d32' : ($tipo->tasa_exito >= 45 ? '#b26a00' : '#c62828');
                @endphp
                <tr style="border-bottom:1px solid #e9ecef;background:{{ $fondo_fila }};">
                    <td style="padding:10px;">
                        <span style="display:inline-block;background:{{ $tipo->hex }};color:#fff;border-radius:6px;padding:1px 7px;font-size:11px;font-weight:bold;">#{{ $tipo->id }}</span>
                        {{ $tipo->emoji }} {{ $tipo->nombre }}
                    </td>
                    <td align="center" style="padding:10px;">{{ $tipo->total }}</td>
                    <td align="center" style="padding:10px;color:#2e7d32;">{{ $tipo->exitosas }}</td>
                    <td align="center" style="padding:10px;color:#c62828;">{{ $tipo->fallidas }}</td>
                    <td align="center" style="padding:10px;">
                        @if($tipo->total)
                            <span style="display:inline-block;background:{{ $color_pct }};color:#fff;border-radius:12px;padding:2px 9px;font-size:12px;">{{ $tipo->tasa_exito }}%</span>
                        @else
                            <span style="color:#adb5bd;">—</span>
                        @endif
                    </td>
                    <td align="center" style="padding:10px;">{{ $tipo->conductores }}</td>
                    <td align="right" style="padding:10px;color:#6c757d;">${{ number_format((float) $tipo->costo, 2) }}</td>
                    <td align="right" style="padding:10px;color:#b26a00;">
                        {{ $tipo->exitosas ? '$' . number_format((float) $tipo->costo_confirmacion, 3) : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </td></tr>
</table>
