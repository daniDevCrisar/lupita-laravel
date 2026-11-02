{{-- Mapa de Llamadas Exitosas + Top 5 Rutas más Concurridas.
     El mapa dinámico con marcadores NO funciona al pegar en correo (position:absolute se
     recorta), así que se deja un ESPACIO demarcado para pegar ahí una captura (pantallazo).
     Recibe $rutas (stdClass: lista_detalle, resumen) y $total_exitosas. --}}

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;border-spacing:16px;">
    <tr>
        <td width="46%" valign="top">
            {{-- Espacio reservado: pegar aquí la captura (pantallazo) del mapa --}}
            <div style="border:2px dashed #adb5bd;border-radius:12px;background:#f8f9fa;text-align:center;color:#6c757d;font-family:'Segoe UI',Arial,sans-serif;height:400px;">
                <p style="margin:0;padding-top:165px;font-size:14px;line-height:1.6;">
                    📌 Pegar aquí la<br>captura del mapa<br>
                    <span style="font-size:12px;color:#adb5bd;">(Mapa de Llamadas Exitosas)</span>
                </p>
            </div>
        </td>



        <td width="54%" valign="top">
            <p style="margin:0 0 6px 0;font-size:20px;font-weight:bold;color:#1e2a3a;">Llamadas Exitosas por Ruta</p>
            <p style="margin:0 0 12px 0;color:#6c757d;font-size:13px;">
                Total de referencias procesadas (IA): {{ $rutas->resumen->ref_total }}<br>
                Referencias con ruta identificada: {{ $rutas->resumen->ref_total_ruta }}<br>
                Diversidad de rutas (distintas): {{ $rutas->resumen->ruta_distintas }}
            </p>
            <p style="margin:0 0 8px 0;font-size:16px;font-weight:bold;color:#1e2a3a;">🏆 Top 5 Rutas más Concurridas</p>
            <table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;">
                <thead>
                <tr style="background:#2c3e50;color:#ffffff;">
                    <th align="left" style="padding:10px;font-weight:500;">#</th>
                    <th align="center" style="padding:10px;font-weight:500;">Concurrencia</th>
                    <th align="center" style="padding:10px;font-weight:500;">Llamadas Exitosas</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rutas->lista_detalle as $item)
                    @php
                        $loc_destino_nombre = $item->loc_destino_nombre ?? $item->ubg_destino_nombre;
                        $loc_origen_nombre = $item->loc_origen_nombre ?? $item->ubg_origen_nombre;
                        $hex = $item->total_exito ? '#2e7d32' : '#c62828';
                    @endphp
                    <tr style="border-bottom:1px solid #e9ecef;{!! $loop->odd ? 'background:#f8f9fa;' : '' !!}">
                        <td style="padding:10px;">{{ $loop->iteration }} <b>{{ 'RUTA ' . $loc_origen_nombre . ' - ' . $loc_destino_nombre }}</b></td>
                        <td align="center" style="padding:10px;">{{ $item->veces_usada }}</td>
                        <td align="center" style="padding:10px;">
                            <span style="display:inline-block;background:{{ $hex }};color:#fff;border-radius:12px;padding:2px 10px;">{{$item->total_exito}}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p style="margin:8px 0 0 0;color:#6c757d;font-size:12px;">* Datos actualizados hasta hoy a las 7am</p>
        </td>
    </tr>
</table>
