{{-- Coincidencia IA vs humano en tabla 2x2 email-safe.
     Recibe $ia (ambos_si, solo_ia, solo_humano, ambos_no, evaluadas, acuerdo). --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#ffffff;border-left:6px solid #0c7591;border-radius:12px;margin:0 0 16px 0;font-family:'Segoe UI',Arial,sans-serif;">
    <tr><td style="padding:20px;">
        <p style="margin:0 0 4px 0;font-size:18px;font-weight:bold;color:#1e2a3a;">
            🤖 Criterio de la IA vs el humano
            <span style="display:inline-block;background:#e0f2f7;color:#0c7591;border-radius:12px;padding:2px 12px;font-size:13px;margin-left:8px;">{{ $ia->acuerdo }}% de acuerdo</span>
        </p>
        <p style="margin:0 0 14px 0;font-size:13px;color:#5f6368;">
            Sobre {{ $ia->evaluadas }} llamadas donde ambos dejaron criterio.
        </p>

        <table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
            <thead>
            <tr style="background:#2c3e50;color:#ffffff;">
                <th align="left" style="padding:10px;font-weight:500;"></th>
                <th align="center" style="padding:10px;font-weight:500;">Humano: exitosa</th>
                <th align="center" style="padding:10px;font-weight:500;">Humano: fallida</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <th align="left" style="padding:10px;background:#f8f9fa;color:#1e2a3a;">IA: exitosa</th>
                <td align="center" style="padding:10px;color:#2e7d32;font-weight:bold;">{{ $ia->ambos_si }} <span style="font-size:11px;color:#6c757d;">coinciden</span></td>
                <td align="center" style="padding:10px;color:#b26a00;font-weight:bold;">{{ $ia->solo_ia }} <span style="font-size:11px;color:#6c757d;">optimista</span></td>
            </tr>
            <tr>
                <th align="left" style="padding:10px;background:#f8f9fa;color:#1e2a3a;">IA: fallida</th>
                <td align="center" style="padding:10px;color:#0c7591;font-weight:bold;">{{ $ia->solo_humano }} <span style="font-size:11px;color:#6c757d;">pesimista</span></td>
                <td align="center" style="padding:10px;color:#c62828;font-weight:bold;">{{ $ia->ambos_no }} <span style="font-size:11px;color:#6c757d;">coinciden</span></td>
            </tr>
            </tbody>
        </table>

        <p style="margin:14px 0 6px 0;font-size:13px;color:#333;">
            <b>Lectura:</b> cuando la IA marca una llamada como exitosa se equivoca en
            <b>{{ $ia->ia_optimista }}%</b> de los casos. El humano ve más éxitos que la IA
            ({{ $ia->ambos_si + $ia->solo_humano }} contra {{ $ia->ambos_si + $ia->solo_ia }}).
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
            <tr>
                <td width="{{ $ia->acuerdo }}%" style="background:#2e7d32;color:#fff;font-size:12px;font-weight:bold;padding:6px;border-radius:6px 0 0 6px;">
                    {{ $ia->acuerdo }}% acuerdo
                </td>
                <td width="{{ max(0, 100 - $ia->acuerdo) }}%" style="background:#e9ecef;border-radius:0 6px 6px 0;">&nbsp;</td>
            </tr>
        </table>
    </td></tr>
</table>
