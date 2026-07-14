<!-- resources/views/livewire/log-conductores/tabla-conductores.blade.php -->

<div>
    {{-- Tabla --}}
    <div class="col-12">
        <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
            <table class="table table-bordered table-hover table-sm table-dark">
                <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                <tr>
                    <th>Id</th>
                    <th>Nombres</th>
                    <th>Llamadas sin errores</th>
                    <th>Etiquetas Positivas</th>
                    <th>Etiquetas Negativas</th>
                    <th>Rutas</th>
                </tr>
                </thead>
                <tbody>

                @forelse($clogs->lista as $row)
                    @php
                        $row_metricas= json_decode($row->metricas);
                        $row_metricas_porcentaje=round(($row_metricas->exitosas/$row_metricas->total)*100);
                        $row_etiquetas_1=json_decode($row->etiquetas_1);
                        $row_etiquetas_0=json_decode($row->etiquetas_0);
                        $row_telefonos=json_decode($row->telefonos);
                        $row_rutas = json_decode($row->rutas);
                    @endphp
                    <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                        <td class="bg-{{$clogs->clase::getColorStatus($row->status)}}">
                            {{ $row->id_log_conductor}}
                        </td>
                        <td>
                            <i class="bi bi-person"></i>
                            <a href="{{ route('lupita.llamadas') . '?' . http_build_query(array_merge(request()->all(), ['conductor' => $row->id_conductor, 'page' => 1])) }}"
                            target="_blank">
                                {{ $row->conductor_nombres }}
                            </a>
                            <br>
                            <i class="bi bi-shop text-muted"> {{ $row->trt_nombres ?: 'Sin TRT' }}</i>
                            <br>

                            <h5>LOG:</h5>
                            @forelse($row_telefonos??[] as $item)
                                <span class="bg-{{$item->activo? 'success' : 'dark' }} text-white">{{ltrim($item->telefono,'51')}}</span>
                            @empty
                            @endforelse
                            <br>
                            <i class="bi bi-file-earmark-text"></i>
                            {!!  nl2br($row->analisis) !!}
                        </td>
                        {{---------------metricas------------}}
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-3 mb-1">
                                <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                    <i class="bi bi-check-circle me-1"></i>{{ $row_metricas->exitosas }}
                                </span>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">
                                    <i class="bi bi-x-circle me-1"></i>{{ $row_metricas->fallidas }}
                                </span>
                                <span class="badge bg-primary bg-opacity-10 text-white border border-white">
                                    <i class="bi bi-phone me-1"></i>{{ $row_metricas->total }}
                                </span>
                            </div>
                            <div class="progress mx-auto exito_progress">
                                <div class="progress-bar bg-{{ $llamadas::color_porcentaje($row_metricas_porcentaje) }}" role="progressbar"
                                     style="width: {{ $row_metricas_porcentaje}}%;"
                                     aria-valuenow="{{ $row_metricas_porcentaje }}"
                                     aria-valuemin="0" aria-valuemax="100">
                                </div>
                                <div class="progress-bar bg-dark" role="progressbar"
                                     style="width: {{ 100 - $row_metricas_porcentaje }}%;"
                                     aria-valuenow="{{ 100 - $row_metricas_porcentaje }}"
                                     aria-valuemin="0" aria-valuemax="100">
                                </div>
                            </div>
                            <small class="d-block fw-bold text-{{ $llamadas::color_porcentaje($row_metricas_porcentaje) }}"
                                   style="font-size: 0.75rem; margin-top: 2px;">
                                <i class="bi bi-graph-up-arrow me-1"></i>
                                {{ number_format($row_metricas_porcentaje, 1) }}% de éxito
                                @if($row_metricas->errores)
                                    <span class="text-warning ms-1">(⚠️ {{$row_metricas->errores}} errores)</span>
                                @endif
                            </small>
                        </td>
                        <td class="text-success">
                            @forelse($row_etiquetas_1??[] as $item)
                                {{"$item->nombre($item->cantidad)"  }}
                            @empty
                            @endforelse
                        </td>
                        <td class="text-danger">
                            @forelse($row_etiquetas_0??[] as $item)
                                {{"$item->nombre($item->cantidad)"  }}
                            @empty
                            @endforelse
                        </td>
                        <td>

                            @forelse($row_rutas->lista??[] as $item)
                                <span class="badge bg-primary"><i class="bi bi-arrow-right me-1"></i>{{"$item->nombre($item->cantidad)"}}</span>
                            @empty
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="text-center text-muted">No se encontraron conductores</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{--{#1564 ▼ // resources\views/livewire/log-conductores/tabla-logs-conductores.blade.php--}}
{{--+"id_log_conductor": 1--}}
{{--+"id_conductor": 954--}}
{{--+"last_id_trt": 16--}}
{{--+"fecha_inicio": "2026-02-01 00:00:00"--}}
{{--+"fecha_fin": "2026-07-13 00:00:00"--}}
{{--+"metricas": "{"total": 21, "errores": "0", "exitosas": "0", "fallidas": 21}"--}}
{{--+"etiquetas_1": null--}}
{{--+"etiquetas_0": "[{"nombre": "No habla", "cantidad": "9"}, {"nombre": "Contesta otra persona", "cantidad": "5"}, {"nombre": "Numero equivocado", "cantidad": "5"}, {"nombre": "Cu ▶"--}}
{{--+"analisis": "EL USUARIO INDICA QUE NO ES CESAR BRAVO Y SOLICITA ELIMINAR SU NUMERO DE LA BASE DE DATOS."--}}
{{--+"accion": "No es su numero"--}}
{{--+"respuesta": null--}}
{{--+"status": "EN CURSO"--}}
{{--+"ubicacion": "PROVINCIA"--}}
{{--+"id_conclusion": 4--}}
{{--+"telefonos": "[{"activo": 1, "telefono": "51978948191"}]"--}}
{{--+"rutas": "{"lista": [{"nombre": "PLANTA CHICLAYO - IQUITOS", "cantidad": 6}, {"nombre": "PLANTA TARAPOTO - SALEM JAEN", "cantidad": 3}, {"nombre": "BABEL CHICLAYO - PIURA ▶"--}}
{{--+"created_at": "2026-07-13 12:28:05"--}}
{{--+"updated_at": "2026-07-13 12:30:33"--}}
{{--+"conductor_nombres": "CESAR BRAVO"--}}
{{--+"trt_nombres": "FESELL DISTRIBUIDORA S.A.C."--}}
}
