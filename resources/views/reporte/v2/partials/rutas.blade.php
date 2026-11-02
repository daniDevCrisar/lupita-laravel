{{-- Rutas recorridas, version WEB (tema del CRM). Recibe $rutas.
     El partial de correo (mapa-rutas) reserva un espacio para pegar una
     captura y usa colores pensados para fondo blanco; en pantalla no aplica. --}}
@php
    $resumen_rutas = $rutas->resumen;
    $detalle_rutas = $rutas->lista_detalle;
    $max_veces = 0;
    foreach ($detalle_rutas as $item) {
        $max_veces = max($max_veces, (int) $item->veces_usada);
    }
    $max_veces = max(1, $max_veces);
@endphp

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body py-2">
                <div class="text-white-50 text-uppercase small">Referencias procesadas (IA)</div>
                <div class="fs-3 fw-bold text-white">{{ number_format((int) $resumen_rutas->ref_total) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body py-2">
                <div class="text-white-50 text-uppercase small">Con ruta identificada</div>
                <div class="fs-3 fw-bold text-success">{{ number_format((int) $resumen_rutas->ref_total_ruta) }}</div>
                <div class="small text-white-50">
                    {{ $resumen_rutas->ref_total
                        ? round($resumen_rutas->ref_total_ruta / $resumen_rutas->ref_total * 100, 1)
                        : 0 }}% del total
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body py-2">
                <div class="text-white-50 text-uppercase small">Rutas distintas</div>
                <div class="fs-3 fw-bold text-info">{{ number_format((int) $resumen_rutas->ruta_distintas) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="thead-lupita">
        <tr>
            <th>#</th>
            <th>Ruta</th>
            <th class="text-center">Concurrencia</th>
            <th class="text-center">Llamadas exitosas</th>
            <th style="min-width:120px;">Peso</th>
        </tr>
        </thead>
        <tbody>
        @forelse($detalle_rutas as $item)
            @php
                $loc_destino_nombre = $item->loc_destino_nombre ?? ($item->ubg_destino_nombre ?? '');
                $loc_origen_nombre = $item->loc_origen_nombre ?? ($item->ubg_origen_nombre ?? '');
            @endphp
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td class="text-light">{{ $loc_origen_nombre }} - {{ $loc_destino_nombre }}</td>
                <td class="text-center">{{ number_format((int) $item->veces_usada) }}</td>
                <td class="text-center">
                    <span class="badge bg-{{ $item->total_exito ? 'success' : 'danger' }}">{{ number_format((int) $item->total_exito) }}</span>
                </td>
                <td>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar bg-info" style="width:{{ max(2, round($item->veces_usada / $max_veces * 100)) }}%"></div>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-white-50 text-center">Sin rutas identificadas en el día.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
