<!DOCTYPE html>
<html lang="es">
<head>
    @php
        $rango_texto = $llamadas->format_fecha($periodo->desde, 'd/m/Y') . ' al ' . $llamadas->format_fecha($periodo->hasta, 'd/m/Y');
        $titulo = $periodo->dias === 1 ? 'Resultados del Día · VAPI' : 'Resultados del Rango · VAPI';
        $titulo_cabecera = $periodo->dias === 1 ? '📞 Resultados del Día' : '📆 Resultados del Rango';
        $comparativa = [
            ['Llamadas', number_format($kpis->total), $variacion->total],
            ['Exitosas', number_format($kpis->exitosas), $variacion->exitosas],
            ['% Éxito', $kpis->tasa_exito . '%', $variacion->tasa_exito],
            ['Costo total', '$' . number_format((float) $kpis->costo, 2), $variacion->costo],
            ['$/Confirmación', '$' . number_format((float) $kpis->costo_confirmacion, 3), $variacion->costo_confirmacion],
        ];
    @endphp
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titulo }} - {{ $rango_texto }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;">
{{-- Reporte email-safe: tablas + estilos inline + emojis, sin clases ni scripts. --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:860px;width:100%;font-family:'Segoe UI',Arial,sans-serif;color:#1e2a3a;">

            <!-- ============ ENCABEZADO ============ -->
            <tr><td style="padding:0 0 18px 0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td valign="top">
                            <h1 style="margin:0 0 6px 0;font-size:30px;font-weight:bold;color:#1e2a3a;">
                                {{ $titulo_cabecera }}
                            </h1>
                            <span style="display:inline-block;background:#2c3e50;color:#fff;border-radius:6px;padding:6px 14px;font-size:14px;">
                                📅 {{ $rango_texto }} · {{ $periodo->dias }} día(s)
                            </span>
                            @if($filtros->llamada_tipo_id !== null)
                                <span style="display:inline-block;background:#0d6efd;color:#fff;border-radius:6px;padding:6px 12px;font-size:13px;">
                                    Tipo #{{ $filtros->llamada_tipo_id }}
                                </span>
                            @endif
                            @if($filtros->trt_id !== null)
                                <span style="display:inline-block;background:#0c7591;color:#fff;border-radius:6px;padding:6px 12px;font-size:13px;">
                                    TRT #{{ $filtros->trt_id }}
                                </span>
                            @endif
                        </td>
                        <td valign="top" align="right">
                            <a href="{{ route('lupita.reportes.dashboard', ['fecha_inicio' => $periodo->desde, 'fecha_fin' => $periodo->hasta]) }}"
                               style="display:inline-block;background:#0d6efd;color:#ffffff;padding:8px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:bold;">
                                📊 Abrir dashboard
                            </a>
                        </td>
                    </tr>
                </table>
            </td></tr>

            @if($kpis->total == 0)
                <tr><td style="padding:20px;background:#ffebee;border-left:6px solid #c62828;border-radius:12px;">
                    <p style="margin:0;font-size:16px;color:#c62828;font-weight:bold;">
                        ⚠️ No hay llamadas registradas en este rango con los filtros aplicados.
                    </p>
                </td></tr>
            @else

                <!-- ============ COMPARATIVA CON EL PERIODO ANTERIOR ============ -->
                <tr><td style="padding:0 0 16px 0;">
                    <p style="margin:0 0 6px 0;font-size:13px;color:#6c757d;">
                        Comparado con el periodo inmediatamente anterior
                        ({{ $llamadas->format_fecha($periodo_anterior->desde, 'd/m') }} al {{ $llamadas->format_fecha($periodo_anterior->hasta, 'd/m') }}).
                    </p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                           style="border-collapse:separate;border-spacing:6px;table-layout:fixed;">
                        <tr>
                            @foreach($comparativa as $c)
                                @php
                                    $color = $c[2] === null ? '#6c757d' : ($c[2] > 0 ? '#2e7d32' : ($c[2] < 0 ? '#c62828' : '#6c757d'));
                                    $flecha = $c[2] === null ? '•' : ($c[2] > 0 ? '▲' : ($c[2] < 0 ? '▼' : '='));
                                @endphp
                                <td width="20%" valign="top" style="background:#ffffff;border-radius:12px;padding:12px;">
                                    <p style="margin:0 0 2px 0;font-size:10px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">{{ $c[0] }}</p>
                                    <p style="margin:0;font-size:20px;font-weight:bold;color:#1e2a3a;">{{ $c[1] }}</p>
                                    <p style="margin:4px 0 0 0;font-size:12px;color:{{ $color }};">
                                        {{ $flecha }} {{ $c[2] === null ? 's/base' : ($c[2] > 0 ? '+' : '') . $c[2] . '%' }}
                                    </p>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </td></tr>

                <!-- ============ RESUMEN ============ -->
                <tr><td style="padding:0 0 4px 0;">
                    <h2 style="margin:0;font-size:22px;font-weight:bold;color:#1e2a3a;">📊 Resumen del periodo</h2>
                    <p style="margin:4px 0 12px 0;color:#6c757d;font-size:14px;">
                        Totales consolidados{{ $filtros->llamada_tipo_id !== null || $filtros->trt_id !== null ? ' con los filtros aplicados' : '' }}.
                    </p>
                </td></tr>
                <tr><td style="padding:0 0 8px 0;">
                    @include('reporte.todo.partials.kpi-cards', ['t' => $kpis])
                </td></tr>

                <!-- ============ HALLAZGOS ============ -->
                <tr><td style="padding:0 0 12px 0;">
                    <h2 style="margin:0 0 8px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">🔎 Hallazgos del periodo</h2>
                    @include('reporte.v2.partials.email-hallazgos', ['t' => $kpis, 'ventana' => $ventana, 'llamadas' => $llamadas])
                </td></tr>

                <!-- ============ SERIE POR DÍA ============ -->
                <tr><td>
                    @include('reporte.v2.partials.email-serie', ['por_dia' => $por_dia, 'periodo' => $periodo, 'llamadas' => $llamadas])
                </td></tr>

                <!-- ============ POR TIPO ============ -->
                <tr><td>
                    @include('reporte.v2.partials.email-tipos', ['tipos' => $tipos, 't' => $kpis, 'dias' => $periodo->dias])
                </td></tr>

                <!-- ============ ETAPA LOGÍSTICA ============ -->
                <tr><td>
                    @include('reporte.todo.partials.etapa-logistica', ['etapas' => $etapas, 'dias_total' => $periodo->dias])
                </td></tr>

                <!-- ============ RANKINGS ============ -->
                <tr><td>
                    @include('reporte.v2.partials.email-rankings', ['mejores' => $mejores, 'peores' => $peores, 'trts' => $trts, 'llamadas' => $llamadas])
                </td></tr>

                <!-- ============ ERRORES TÉCNICOS + IA VS HUMANO ============ -->
                <tr><td style="padding:0 0 8px 0;">
                    @include('reporte.todo.partials.errores-tecnicos', ['t' => $kpis, 'fallidas' => $kpis->fallidas])
                </td></tr>
                <tr><td>
                    @include('reporte.v2.partials.email-ia', ['ia' => $ia])
                </td></tr>

            @endif

            <!-- ============ FOOTER ============ -->
            <tr><td style="border-top:1px dashed #ccc;padding:14px 4px 0 4px;font-size:12px;color:#6c757d;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td>🗂️ Reporte generado con LUPITA · criterio de éxito <code>llamada_exitosa = 1</code></td>
                        <td align="right">
                            <a href="{{ route('lupita.reportes.rango', ['fecha_inicio' => $periodo->desde, 'fecha_fin' => $periodo->hasta]) }}"
                               style="color:#0d6efd;text-decoration:none;">Recargar rango</a>
                        </td>
                    </tr>
                </table>
            </td></tr>

        </table>
    </td></tr>
</table>
</body>
</html>
