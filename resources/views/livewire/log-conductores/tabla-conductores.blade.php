<!-- resources/views/livewire/log-conductores/tabla-conductores.blade.php -->

<div>
    {{-- Paginación superior --}}
    <div class="col-12">
        {{ $conductores->links() }}
    </div>

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
                    <th @if($reporte) style="display:none;" @endif>Errores</th>
                </tr>
                </thead>
                <tbody>
                @forelse($conductores as $row)
                    <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                        <td class="bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}">
                            @php
                            $log_data=[];
                            $log_data['id_log_conductor']=$row->last_id_log;
                            if (!$row->last_id_log){
                                $log_data['conductor'] = ['id'=>$row->conductor_id , 'nombres'=> $row->conductor ];
                                $log_data['trt']= ['id'=>$row->ultimo_trt_id,'nombres'=>$row->ultimo_trt];
                                $log_data['fecha_rango']=[$request->fecha_inicio,$request->fecha_fin];

                                $log_data['metricas'] = [
                                    'exitosas'=> $row->exitosas,'fallidas'=> $row->fallidas-$row->total_error,
                                    'errores'=>$row->total_error,
                                    'total' => $row->total];
                                $log_data['etiquetas'] = [
                                    0 => $llamadas::etiquetas_icon_bi($row, '', 0, true, $row->fallidas - $row->total_error, true),
                                    1 => $llamadas::etiquetas_icon_bi($row, '', 1, true, false, true),
                                ];
                            }
                            $log_data_json =  json_encode($log_data);
                            @endphp
                            {{ $row->conductor_id }}
                        </td>
                        <td>
                            <i class="bi bi-person"></i>
                            <a href="{{ route('lupita.llamadas') . '?' . http_build_query(array_merge(request()->all(), ['conductor' => $row->conductor_id, 'page' => 1])) }}">
                                {{ $row->conductor }}
                            </a>
                            <a href="https://wa.me/+{{ $row->ultimo_tlf }}?text=Buen%20dia%20sr%20{{ $row->conductor }}" target="_blank">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <br>
                            <i class="bi bi-shop text-muted"> {{ $row->ultimo_trt ?: 'Sin TRT' }}</i>
                            <br>
                            <button class="btn btn-outline-info btn-sm" wire:click="nuevoLog({{ $log_data_json }})">
                                <i class="bi @if(!$row->last_id_log) bi-plus-circle @else bi-pencil @endif me-1"></i> @if(!$row->last_id_log)  Nuevo Log @else Modificar Log @endif
                            </button>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-3 mb-1">
                                <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                    <i class="bi bi-check-circle me-1"></i>{{ number_format($row->exitosas, 0, ',', '.') }}
                                </span>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">
                                    <i class="bi bi-x-circle me-1"></i>{{ number_format($row->fallidas - $row->total_error, 0, ',', '.') }}
                                </span>
                                    <span class="badge bg-primary bg-opacity-10 text-white border border-white">
                                    <i class="bi bi-phone me-1"></i>{{ number_format($row->total - $row->total_error, 0, ',', '.') }}
                                </span>
                            </div>
                            <div class="progress mx-auto" style="height: 6px; max-width: 180px;">
                                <div class="progress-bar bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}" role="progressbar"
                                     style="width: {{ $row->tasa_exito }}%;"
                                     aria-valuenow="{{ $row->tasa_exito }}"
                                     aria-valuemin="0" aria-valuemax="100">
                                </div>
                                <div class="progress-bar bg-dark" role="progressbar"
                                     style="width: {{ 100 - $row->tasa_exito }}%;"
                                     aria-valuenow="{{ 100 - $row->tasa_exito }}"
                                     aria-valuemin="0" aria-valuemax="100">
                                </div>
                            </div>
                            <small class="d-block fw-bold text-{{ $llamadas::color_porcentaje($row->tasa_exito) }}"
                                   style="font-size: 0.75rem; margin-top: 2px;">
                                <i class="bi bi-graph-up-arrow me-1"></i>
                                {{ number_format($row->tasa_exito, 1) }}% de éxito
                            </small>
                        </td>
                        <td>{!! $llamadas::etiquetas_icon_bi($row, '', 1, true, false, $reporte) !!}</td>
                        <td>{!! $llamadas::etiquetas_icon_bi($row, '', 0, true, $row->fallidas - $row->total_error, $reporte) !!}</td>
                        <td class="text-danger" @if($reporte) style="display:none;" @endif>
                            @if($row->error_desconocido)
                                <i class="{{ $llamadas::icon_exito(-1, true) }}"></i>
                                Desconocido({{ $row->error_desconocido }}) <br>
                            @endif
                            @if($row->error_ia)
                                <i class="{{ $llamadas::icon_exito(1, true) }}"></i>
                                IA:({{ $row->error_ia }}) <br>
                            @endif
                            @if($row->error_red)
                                <i class="{{ $llamadas::icon_exito(2, true) }}"></i>
                                Red:({{ $row->error_red }}) <br>
                            @endif
                            @if($row->error_sistema)
                                <i class="{{ $llamadas::icon_exito(3, true) }}"></i>
                                Sistema:({{ $row->error_sistema }})
                            @endif
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

    {{-- Paginación inferior --}}
    <div class="col-12">
        {{ $conductores->links() }}
    </div>
</div>
