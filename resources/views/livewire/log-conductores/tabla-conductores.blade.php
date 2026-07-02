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
                    <th>Ultimo TRT</th>
                    <th>Llamadas sin errores</th>
                    <th>Exitosas</th>
                    <th>Fallidas</th>
                    <th>Tasa de Exito</th>
                    <th>Etiquetas Positivas</th>
                    <th>Etiquetas Negativas</th>
                    <th @if($reporte) style="display:none;" @endif>Errores</th>
                    <th>Puntaje</th>
                    <th>Tiempo en llamada</th>
                </tr>
                </thead>
                <tbody>
                @forelse($conductores as $row)
                    <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                        <td class="bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}">

                            @php
                            $log_data=[];
                            $log_data['accion'] = ['nuevo',0];
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
                            $log_data_json =  json_encode($log_data);
                            @endphp
                            <button class="btn btn-success btn-sm" wire:click="nuevoLog({{ $log_data_json }})">
                                <i class="bi bi-plus-circle me-1"></i> Nuevo Log
                            </button>

                            {{ $row->conductor_id }}
                        </td>
                        <td>
                            <a href="{{ route('lupita.llamadas') . '?' . http_build_query(array_merge(request()->all(), ['conductor' => $row->conductor_id, 'page' => 1])) }}">
                                {{ $row->conductor }}
                            </a>
                            <a href="https://wa.me/+{{ $row->ultimo_tlf }}?text=Buen%20dia%20sr%20{{ $row->conductor }}" target="_blank">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <br>
                        </td>
                        <td style="font-size: 0.5rem;">{{ $row->ultimo_trt }}</td>
                        <td><span class="badge bg-primary">{{ $row->total - $row->total_error }}</span></td>
                        <td><span class="badge bg-success">{{ $row->exitosas }}</span></td>
                        <td><span class="badge bg-danger">{{ $row->fallidas - $row->total_error }}</span></td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar bg-{{ $llamadas::color_porcentaje($row->tasa_exito) }}" role="progressbar" style="width: {{ $row->tasa_exito }}%;" aria-valuenow="{{ $row->tasa_exito }}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="d-block text-center text-{{ $llamadas::color_porcentaje($row->tasa_exito) }}">
                                {{ $row->tasa_exito }} @if(!$reporte) % @endif
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
                        @php $puntaje = $llamadas::puntaje_conductor($row); @endphp
                        <td class="text-{{ $puntaje > 0 ? 'success' : 'danger' }} fw-bold">
                            {{ $puntaje }}
                        </td>
                        <td class="small">{{ $llamadas::audio_duracion_format($row->audio_duracion) }}</td>
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
