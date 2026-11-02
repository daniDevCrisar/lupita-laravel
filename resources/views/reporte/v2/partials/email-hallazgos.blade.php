{{-- Tres hallazgos del periodo en tarjetas email-safe (tablas + estilos inline).
     Recibe $t (kpis), $ventana (mejor franja de 3h) y $llamadas (helpers). --}}
@php
    $hallazgos = [
        [
            'titulo' => '💸 Costo por confirmación',
            'valor' => '$' . number_format((float) $t->costo_confirmacion, 3),
            'detalle' => 'Cada llamada exitosa del periodo costó eso. Costo total $' . number_format((float) $t->costo, 2)
                . '; de las exitosas $' . number_format((float) $t->costo_exitosa, 2) . '.',
            'fondo' => '#fff8e1',
            'borde' => '#ff8f00',
            'color' => '#b26a00',
        ],
        [
            'titulo' => '⏰ Hora dorada',
            'valor' => $ventana->rango ? $ventana->tasa . '%' : 'Sin datos',
            'detalle' => $ventana->rango
                ? 'Entre ' . $ventana->rango . ' se logró la mejor tasa ('
                    . $ventana->exitosas . ' de ' . $ventana->total . ' llamadas). Promedio general: ' . $t->tasa_exito . '%.'
                : 'No hay volumen horario suficiente para detectar la mejor franja.',
            'fondo' => '#e6f7e6',
            'borde' => '#2e7d32',
            'color' => '#2e7d32',
        ],
        [
            'titulo' => '📵 Fuga en buzón',
            'valor' => $t->buzon_tiempo_pct . '% del tiempo',
            'detalle' => $t->buzon_de_voz . ' llamadas cayeron en buzón: '
                . ($llamadas::audio_duracion_format($t->buzon_duracion) ?: '0 seg')
                . ' y $' . number_format((float) $t->buzon_costo, 2) . ' sin confirmación.',
            'fondo' => '#ffebee',
            'borde' => '#c62828',
            'color' => '#c62828',
        ],
    ];
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:separate;border-spacing:8px;margin:0 0 8px 0;table-layout:fixed;font-family:'Segoe UI',Arial,sans-serif;">
    <tr>
        @foreach($hallazgos as $h)
            <td width="33.33%" valign="top"
                style="background:{{ $h['fondo'] }};border-left:5px solid {{ $h['borde'] }};border-radius:12px;padding:14px;">
                <p style="margin:0 0 4px 0;font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">{{ $h['titulo'] }}</p>
                <p style="margin:0;font-size:24px;font-weight:bold;color:{{ $h['color'] }};">{{ $h['valor'] }}</p>
                <p style="margin:8px 0 0 0;font-size:12px;color:#5f6368;line-height:1.5;">{{ $h['detalle'] }}</p>
            </td>
        @endforeach
    </tr>
</table>
