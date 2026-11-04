{{--
    Bloque de RUTAS del dashboard VAPI: esperas de carga/descarga por ubicacion
    (E2/E5) y tiempo promedio de ruta (recorrido E4). Viaja al final del
    dashboard porque lo de llamadas va primero y lo de rutas al fondo.
    $espera viene de DBReportes::espera_por_ubicacion().
--}}
@if(!empty($espera))
    {{-- Mapa por volumen de viajes (mismo calculo que lupita/referencias) --}}
    @if(!empty($mapa) && (!empty($mapa['origen']) || !empty($mapa['destino'])))
        <div class="card bg-dark border-secondary mb-3">
            <div class="card-body">
                <h6 class="fw-bold text-light mb-1"><i class="bi bi-geo-alt text-info me-2"></i>Mapa por volumen (origen y destino)</h6>
                <p class="text-white-50 small mb-3">Viajes cerrados (fin de descargue) del periodo por departamento. El tamano del punto es el volumen.</p>
                @include('reporte.partials.mapa-volumen', ['mapa' => $mapa, 'depas' => $depas, 'texto_class' => 'text-white-50', 'con_animacion' => true, 'fecha_desde' => $periodo->desde ?? null, 'fecha_hasta' => $periodo->hasta ?? null])
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-body">
                    <h6 class="fw-bold text-light mb-1"><i class="bi bi-box-seam text-warning me-2"></i>Espera promedio de carga por ubicación</h6>
                    <p class="text-white-50 small mb-3">E2: desde que la unidad se presenta para cargar hasta el inicio de carga. Top 10 por viajes del periodo.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="thead-lupita">
                            <tr>
                                <th>Ubicación (origen)</th>
                                <th class="text-center">Viajes</th>
                                <th class="text-end">Espera prom.</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($espera->carga as $e)
                                <tr>
                                    <td class="text-light">
                                        {{ $e->ubicacion }}
                                        @if(str_starts_with($e->clave, 'UBG:'))
                                            <span class="badge bg-secondary ms-1" title="No existe un local registrado para este extremo de la ruta; se muestra su ubigeo">ubigeo</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ number_format($e->viajes) }}</td>
                                    <td class="text-end fw-bold">{{ \App\Database\DBReportes::min_a_hhmm($e->espera_min) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-white-50 text-center">Sin viajes con hitos de carga en el periodo.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-body">
                    <h6 class="fw-bold text-light mb-1"><i class="bi bi-truck text-success me-2"></i>Espera promedio de descarga por ubicación</h6>
                    <p class="text-white-50 small mb-3">E5: desde la llegada (QR en destino) hasta el inicio del descargue. Top 10 por viajes del periodo.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="thead-lupita">
                            <tr>
                                <th>Ubicación (destino)</th>
                                <th class="text-center">Viajes</th>
                                <th class="text-end">Espera prom.</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($espera->descarga as $e)
                                <tr>
                                    <td class="text-light">
                                        {{ $e->ubicacion }}
                                        @if(str_starts_with($e->clave, 'UBG:'))
                                            <span class="badge bg-secondary ms-1" title="No existe un local registrado para este extremo de la ruta; se muestra su ubigeo">ubigeo</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ number_format($e->viajes) }}</td>
                                    <td class="text-end fw-bold">{{ \App\Database\DBReportes::min_a_hhmm($e->espera_min) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-white-50 text-center">Sin viajes con hitos de descarga en el periodo.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card bg-dark border-secondary mb-3">
        <div class="card-body">
            <h6 class="fw-bold text-light mb-1"><i class="bi bi-signpost-split text-info me-2"></i>Tiempo promedio de ruta (recorrido origen → destino)</h6>
            <p class="text-white-50 small mb-3">E4: desde el inicio de ruta hasta la llegada a destino (QR). Top 10 por viajes del periodo. Si un extremo no es un local registrado, se muestra su ubigeo con el distrito.</p>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="thead-lupita">
                    <tr>
                        <th>Recorrido (origen → destino)</th>
                        <th class="text-center">Viajes</th>
                        <th class="text-end">Tiempo prom.</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($espera->ruta as $e)
                        <tr>
                            <td class="text-light">{{ $e->recorrido }}</td>
                            <td class="text-center">{{ number_format($e->viajes) }}</td>
                            <td class="text-end fw-bold">{{ \App\Database\DBReportes::min_a_hhmm($e->ruta_min) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-white-50 text-center">Sin viajes con recorrido completo (inicio de ruta y llegada) en el periodo.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
