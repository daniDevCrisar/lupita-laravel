<!DOCTYPE html>
<html lang="es">
<head>
    @php
        $fecha_rango = $llamadas->format_fecha($fecha_str, 'd/m/Y') . ' ' . $llamadas->get_fecha_day_name($fecha_str);
        $resumen_total = $resumen->total;
        $resumen_fallidas = (int) ($resumen_total->llamadas ?? 0) - (int) ($resumen_total->llamada_exitosa ?? 0);
    @endphp
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados del Dia - {{ $fecha_rango }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;">
{{-- Contenedor centrado con ancho fijo apto para correo (Thunderbird pegar como HTML). --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:860px;width:100%;font-family:'Segoe UI',Arial,sans-serif;color:#1e2a3a;">

            <!-- ============ ENCABEZADO ============ -->
            <tr><td style="padding:0 0 20px 0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td valign="top">
                            <h1 style="margin:0 0 6px 0;font-size:30px;font-weight:bold;color:#1e2a3a;">
                                📞 Resultados del Dia
                            </h1>
                            <span style="display:inline-block;background:#2c3e50;color:#fff;border-radius:6px;padding:6px 14px;font-size:14px;">
                                📅 Llamadas analizadas: {{ $fecha_rango }}
                            </span>
                        </td>
                    </tr>
                </table>
            </td></tr>

            <!-- ============ RESUMEN (todas las llamadas) ============ -->
            <tr><td style="padding:0 0 4px 0;">
                <h2 style="margin:0;font-size:22px;font-weight:bold;color:#1e2a3a;">📊 Resumen
                    <a href="https://danidevcrisar.github.io/lupita-laravel/reportes/{{ $folder }}/0-resumen.html" target="_blank"
                       style="display:inline-block;background:#0d6efd;color:#ffffff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:bold;">📄 Reporte</a>
                    <a href="https://calls.pedrosotelo.fun/llamadas?fecha={{ $fecha }}" target="_blank"
                       style="display:inline-block;background:#0d6efd;color:#ffffff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:bold;">✅ Detalle</a>



                </h2>
                <p style="margin:4px 0 12px 0;color:#6c757d;font-size:14px;">Todas las llamadas del día.</p>
            </td></tr>
            <tr><td style="padding:0 0 8px 0;">
                @include('reporte.todo.partials.kpi-cards', ['t' => $resumen_total])
            </td></tr>
            <tr><td>
                @include('reporte.todo.partials.etapa-logistica', ['etapas' => $resumen->etapa_logistica, 'dias_total' => 1])
            </td></tr>
            <tr><td style="padding:0 0 16px 0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:12px;">
                    <tr><td style="padding:20px;">
                        @include('reporte.todo.partials.mapa-rutas', ['rutas' => $rutas, 'total_exitosas' => $resumen_total->llamada_exitosa])
                    </td></tr>
                </table>
            </td></tr>

            <!-- ============ POR TIPO DE LLAMADA ============ -->
            @foreach($tipos as $tipo)
                <tr><td style="border-top:3px solid #dee2e6;padding:20px 0 0 0;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;">
                        <tr>
                            <td valign="middle">
                                <h2 style="margin:0;font-size:20px;font-weight:bold;color:#1e2a3a;">
                                    <span style="display:inline-block;background:#6c757d;color:#fff;border-radius:6px;padding:2px 10px;font-size:15px;margin-right:8px;">#{{ $tipo->id }}</span>{{ $tipo->titulo }}
                                </h2>
                            </td>
                            <td valign="middle" align="right">
                                <a href="{{ $tipo->url }}" target="_blank"
                                   style="display:inline-block;background:#0d6efd;color:#ffffff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:bold;">📄 Ver detalle</a>
                            </td>
                        </tr>
                    </table>
                    @include('reporte.todo.partials.kpi-cards', ['t' => $tipo->total])
                </td></tr>
            @endforeach

            <!-- ============ ERRORES TECNICOS (del resumen) ============ -->
            <tr><td style="border-top:3px solid #dee2e6;padding:20px 0 0 0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td width="50%" valign="top" style="padding-right:8px;">
                            @include('reporte.todo.partials.errores-tecnicos', ['t' => $resumen_total, 'fallidas' => $resumen_fallidas])
                        </td>
                        <td width="50%" valign="top" style="padding-left:8px;">&nbsp;</td>
                    </tr>
                </table>
            </td></tr>

            <!-- ============ FOOTER ============ -->

        </table>
    </td></tr>
</table>
</body>
</html>
