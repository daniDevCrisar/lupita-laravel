{{-- Serie del periodo en formato email-safe: barras verticales + tabla diaria.
     Recibe $por_dia, $periodo y $llamadas. Si el rango supera 31 dias la tabla
     se agrupa por semana para que el correo no crezca sin control. --}}
@php
    $filas = $por_dia;
    $agrupado_semana = false;

    if (count($filas) > 31) {
        $agrupado_semana = true;
        $semanas = [];
        foreach ($filas as $f) {
            $clave = $f->fecha_carbon->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
            if (!isset($semanas[$clave])) {
                $semanas[$clave] = (object) [
                    'fecha' => $clave,
                    'fecha_carbon' => \Carbon\Carbon::parse($clave),
                    'total' => 0,
                    'exitosas' => 0,
                    'fallidas' => 0,
                    'costo' => 0,
                    'duracion' => 0,
                ];
            }
            $semanas[$clave]->total += $f->total;
            $semanas[$clave]->exitosas += $f->exitosas;
            $semanas[$clave]->fallidas += $f->fallidas;
            $semanas[$clave]->costo += $f->costo;
            $semanas[$clave]->duracion += $f->duracion;
        }
        foreach ($semanas as $s) {
            $s->tasa_exito = $s->total ? round($s->exitosas / $s->total * 100, 1) : 0;
            $s->costo_confirmacion = $s->exitosas ? round($s->costo / $s->exitosas, 3) : 0;
        }
        $filas = array_values($semanas);
    }

    $max_total = 1;
    foreach ($filas as $f) if ($f->total > $max_total) $max_total = $f->total;
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-radius:12px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 4px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">📈 Volumen del periodo</p>
        <p style="margin:0 0 16px 0;font-size:13px;color:#6c757d;">
            {{ $agrupado_semana ? 'Agrupado por semana' : 'Día por día' }} ·
            máximo {{ number_format($max_total) }} llamadas.
        </p>

        @if(!$agrupado_semana)
            {{-- barras verticales: una celda por dia, alineada al fondo --}}
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tr>
                    @foreach($filas as $f)
                        @php $alto = $max_total ? max(2, round($f->total / $max_total * 120)) : 2; @endphp
                        <td valign="bottom" align="center" style="padding:0 1px;">
                            <div style="height:{{ $alto }}px;background:{{ $f->total ? '#0d6efd' : '#e9ecef' }};border-radius:3px 3px 0 0;font-size:1px;line-height:1px;">&nbsp;</div>
                        </td>
                    @endforeach
                </tr>
                <tr>
                    @foreach($filas as $f)
                        <td align="center" style="font-size:9px;color:#adb5bd;padding-top:4px;">
                            {{ $f->fecha_carbon->format('d') }}
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif

        <table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-top:12px;">
            <thead>
            <tr style="background:#2c3e50;color:#ffffff;">
                <th align="left" style="padding:10px;font-weight:500;">{{ $agrupado_semana ? 'Semana' : 'Fecha' }}</th>
                <th align="center" style="padding:10px;font-weight:500;">Llamadas</th>
                <th align="center" style="padding:10px;font-weight:500;">Exitosas</th>
                <th align="center" style="padding:10px;font-weight:500;">Fallidas</th>
                <th align="left" style="padding:10px;font-weight:500;">% Éxito</th>
                <th align="right" style="padding:10px;font-weight:500;">$/Confirm.</th>
            </tr>
            </thead>
            <tbody>
            @foreach($filas as $f)
                @php
                    $hex = $f->tasa_exito >= 70 ? '#2e7d32' : ($f->tasa_exito >= 45 ? '#ff8f00' : '#c62828');
                    $label = $agrupado_semana
                        ? 'Semana del ' . $f->fecha_carbon->format('d/m')
                        : $llamadas->get_fecha_day_name($f->fecha_carbon->toDateString()) . ' ' . $f->fecha_carbon->format('d/m');
                @endphp
                <tr style="border-bottom:1px solid #e9ecef;{!! $loop->odd ? 'background:#f8f9fa;' : '' !!}">
                    <td style="padding:10px;">{{ $label }}</td>
                    <td align="center" style="padding:10px;">{{ $f->total }}</td>
                    <td align="center" style="padding:10px;color:#2e7d32;">{{ $f->exitosas }}</td>
                    <td align="center" style="padding:10px;color:#c62828;">{{ $f->fallidas }}</td>
                    <td style="padding:10px;">
                        <table width="100" cellpadding="0" cellspacing="0" border="0" style="background:#e9ecef;border-radius:4px;height:8px;table-layout:fixed;">
                            <tr>
                                <td width="{{ (int) $f->tasa_exito }}%" style="background:{{ $hex }};border-radius:4px 0 0 4px;font-size:1px;line-height:1px;">&nbsp;</td>
                                <td width="{{ max(0, 100 - (int) $f->tasa_exito) }}%" style="font-size:1px;line-height:1px;">&nbsp;</td>
                            </tr>
                        </table>
                        <span style="font-size:11px;color:#5f6368;">{{ $f->tasa_exito }}%</span>
                    </td>
                    <td align="right" style="padding:10px;color:#b26a00;">
                        {{ $f->exitosas ? '$' . number_format((float) $f->costo_confirmacion, 3) : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </td></tr>
</table>
