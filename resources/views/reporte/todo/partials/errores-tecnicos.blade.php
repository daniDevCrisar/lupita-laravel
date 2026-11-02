{{-- Bloque Errores Técnicos. Layout en TABLA con estilos inline (compatible correo).
     Recibe $t (total) y $fallidas. --}}
@php
    $errores_tecnicos_n = ($t->error_desconocido ?? 0) + ($t->error_red ?? 0)
        + ($t->error_sistema ?? 0) + ($t->error_ia ?? 0);
    $errores_tecnicos_100 = $fallidas ? round(($errores_tecnicos_n / $fallidas) * 100, 1) : 0;
@endphp
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-left:6px solid #0c7591;border-radius:12px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 12px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">
            🛠️ Errores Técnicos
            <span style="display:inline-block;background:#e0f2f7;color:#0c7591;border-radius:12px;padding:2px 12px;font-size:13px;margin-left:8px;">{{ $errores_tecnicos_n }} fallos</span>
        </p>
        <p style="margin:0 0 14px 0;font-size:14px;color:#333;">
            <strong>{{ $errores_tecnicos_100 }}% de los fallos</strong>
            - errores referentes a la IA no necesariamente conllevan a un fallo.
        </p>
        <div style="margin-left:12px;font-size:14px;line-height:1.9;color:#333;">
            {{-- Errores de IA --}}
            <p style="margin:0 0 8px 0;">
                🤖 Error de IA: <strong>{{ $t->error_ia }}</strong><br>
                <span style="color:#6c757d;font-size:12px;">
                    se confunde: {{ $t->ia_se_confunde }},
                    no escucha: {{ $t->ia_no_escucha }},
                    error interpretación: {{ $t->ia_error_interpretacion }},
                    dice variable: {{ $t->ia_dice_variable }},
                    mala pronunciación: {{ $t->ia_mala_pronunciacion }},
                    cuelga en plena llamada: {{ $t->ia_cuelga_en_plena_llamada }}
                </span>
            </p>
            {{-- Otros errores técnicos --}}
            <p style="margin:0;">
                📶 Error de red: <strong>{{ $t->error_red }}</strong><br>
                ❓ Error desconocido: <strong>{{ $t->error_desconocido }}</strong><br>
                🖥️ Error de sistema: <strong>{{ $t->error_sistema }}</strong>
            </p>
        </div>
    </td></tr>
</table>
