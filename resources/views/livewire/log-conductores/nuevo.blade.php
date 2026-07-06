<!-- resources/views/livewire/log-conductores/nuevo.blade.php -->

<div>
    @if($showModal)

        <div class="modal fade show d-block" style="background: rgba(0,0,0,0.7);" wire:click.self="closeModal">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">
                            <i class="bi bi-plus-circle me-2"></i>
                            {{ isset($logData->id_log_conductor) ? 'Log #'.$logData->id_log_conductor.' - ' . date('d/m/Y', strtotime($logData->created_at)) : 'Nuevo Log de Conductor' }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="closeModal"></button>
                    </div>
                    <div class="modal-body" style="max-height: 80vh; overflow-y: auto;">
                        <form wire:submit.prevent="save">
                            <div class="row">

                                <!-- ========================================== -->
                                <!-- 1. RANGO DE FECHAS                         -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar-range me-1"></i>Rango de Fechas</label>
                                    <div class="p-2 bg-dark bg-opacity-25 rounded d-flex align-items-center gap-3 flex-wrap border border-secondary">
                                        <span><i class="bi bi-calendar-plus me-1 text-info"></i><strong>Desde:</strong> {{$db_core::format_fecha($logData->fecha_rango[0],'d/m/Y') ?: 'Vacio'}}</span>
                                        <span><i class="bi bi-arrow-right me-1 text-light"></i></span>
                                        <span><i class="bi bi-calendar-minus me-1 text-info"></i><strong>Hasta:</strong> {{$db_core::format_fecha($logData->fecha_rango[1],'d/m/Y') ?: 'Vacio' }}</span>
                                        <span class="ms-auto text-light"><i class="bi bi-calendar-range me-1"></i> @if ($logData->fecha_rango[2]) {{$logData->fecha_rango[2]}} días @else Sin rango @endif</span>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 2. FICHA DEL CONDUCTOR                    -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-6">
                                    <label class="form-label fw-bold"><i class="bi bi-person-fill me-1"></i>Conductor</label>
                                    <div class="card border-info bg-info bg-opacity-10">
                                        <div class="card-body py-2 d-flex flex-wrap gap-4 align-items-center">
                                            <span><i class="bi bi-person me-1"></i> {{$logData->conductor['nombres']}}</span>
                                            <span><i class="bi bi-hash me-1"></i><strong>ID:</strong> {{$logData->conductor['id']}}  </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 3. FICHA DEL TRANSPORTISTA (TRT)          -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-6">
                                    <label class="form-label fw-bold"><i class="bi bi-truck me-1"></i>Transportista</label>
                                    <div class="card border-warning bg-warning bg-opacity-10">
                                        <div class="card-body py-2 d-flex flex-wrap gap-4 align-items-center">
                                            <span><i class="bi bi-building me-1"></i>{{$logData->trt['nombres']}}</span>
                                            <span><i class="bi bi-hash me-1"></i><strong>ID:</strong>{{$logData->trt['id']}}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 4. MÉTRICAS + RUTAS CONCURRIDAS            -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-12">
                                    <div class="row g-3">
                                        <!-- Métricas de Llamadas -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold"><i class="bi bi-bar-chart-fill me-1"></i>Métricas de Llamadas</label>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <div class="card text-center bg-success bg-opacity-10 border-success">
                                                        <div class="card-body py-2">
                                                            <div class="h3 mb-0 text-success"><i class="bi bi-check-circle-fill me-1"></i>{{$logData->metricas['exitosas']}}</div>
                                                            <small class="text-light">Exitosas</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="card text-center bg-danger bg-opacity-10 border-danger">
                                                        <div class="card-body py-2">
                                                            <div class="h3 mb-0 text-danger"><i class="bi bi-x-circle-fill me-1"></i>{{$logData->metricas['fallidas']}}</div>
                                                            <small class="text-light">Fallidas</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="card text-center bg-warning bg-opacity-10 border-warning">
                                                        <div class="card-body py-2">
                                                            <div class="h3 mb-0 text-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{$logData->metricas['errores']}}</div>
                                                            <small class="text-light">Errores</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="card text-center bg-info bg-opacity-10 border-info">
                                                        <div class="card-body py-2">
                                                            <div class="h3 mb-0 text-info"><i class="bi bi-phone me-1"></i>{{$logData->metricas['total']}}</div>
                                                            <small class="text-light">Total</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Rutas más concurridas -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold"><i class="bi bi-arrow-left-right me-1"></i>Rutas más concurridas</label>
                                            <div class="card border-primary bg-primary bg-opacity-10 h-100">
                                                <div class="card-body py-2">
                                                    <div class="d-flex flex-wrap gap-2">
                                                        @foreach($logData->rutas['lista'] as $item)
                                                            <span class="badge bg-primary"><i class="bi bi-arrow-right me-1"></i>{{$item['nombre']}}({{$item['cantidad']}})</span>
                                                        @endforeach
                                                    </div>
                                                    <small class="text-light d-block mt-1">Total de rutas: {{$logData->rutas['total']}} viajes</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 5. ETIQUETAS                             -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-tags-fill me-1"></i>Etiquetas</label>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <div class="card border-success bg-success bg-opacity-10">
                                                <div class="card-body py-2">
                                                    <small class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i>Positivas</small>
                                                    <div class="mt-1">
                                                        @forelse($logData->etiquetas['data'][1] ?? [] as $item)
                                                            <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>{{ $item['nombre'] }} ({{ $item['cantidad'] }})</span>
                                                        @empty
                                                            <span class="text-muted">Sin etiquetas</span>
                                                        @endforelse

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card border-danger bg-danger bg-opacity-10">
                                                <div class="card-body py-2">
                                                    <small class="text-danger fw-bold"><i class="bi bi-x-circle-fill me-1"></i>Negativas</small>
                                                    <div class="mt-1">
                                                        @forelse($logData->etiquetas['data'][0]??[] as $item)
                                                            <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>{{$item['nombre']}} ({{$item['cantidad']}})</span>
                                                        @empty
                                                             <span class="text-muted">Sin etiquetas</span>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 6. TELÉFONOS ACTIVOS (BOTONES)            -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-telephone-fill me-1"></i>Teléfonos Activos</label>
                                    <div class="d-flex flex-wrap gap-2">

                                        @forelse ($logData->telefonos as $item)
                                            @php $tlf_format = ltrim( $item->telefono,'51') @endphp
                                            <input class="d-none"
                                                   type="checkbox" name="chk_tlf"
                                                   wire:key="tlf_{{ $item->telefono }}"
                                                   id="chk_tlf_{{ $item->telefono }}"
                                                   wire:model="log_tlfs"
                                                   value="{{ $item->telefono}}"
                                                   @checked($item->activo)>
                                            {{-- Label que activa el checkbox --}}
                                            <label class="btn btn-sm {{ $item->activo==1 ? 'btn-success' : 'btn-secondary' }}"
                                                   onclick="chk_tlf_change(this)"
                                                   for="chk_tlf_{{ $item->telefono }}">{{ $tlf_format }}</label>
                                        @empty
                                            <span class="text-muted">Sin teléfonos</span>
                                        @endforelse
                                    </div>
                                    <input type="hidden" name="telefonos_inactivos" id="telefonosInactivos" value="">
                                </div>

                                <!-- ========================================== -->
                                <!-- 7. ANÁLISIS                              -->
                                <!-- ========================================== -->
                                <div class="mb-3 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-file-earmark-text me-1"></i>Análisis</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary"><i class="bi bi-file-earmark-text"></i></span>
                                        <textarea name="analisis" class="form-control bg-secondary text-white" rows="3"
                                                  wire:model="log_analisis" placeholder="¿Qué patrón detectaste? ¿Por qué evade la llamada?"></textarea>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 8. ACCIÓN                                -->
                                <!-- ========================================== -->
                                <div class="mb-3 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-lightning me-1"></i>Acción</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary"><i class="bi bi-lightning"></i></span>
                                        <textarea name="accion" class="form-control bg-secondary text-white" rows="3"
                                                  wire:model="log_accion" placeholder="¿Qué intentaste hacer para contactarlo?"></textarea>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 9. RESPUESTA                             -->
                                <!-- ========================================== -->
                                <div class="mb-3 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-chat-fill me-1"></i>Respuesta</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary"><i class="bi bi-reply-fill"></i></span>
                                        <textarea name="respuesta" class="form-control bg-secondary text-white" rows="3"
                                                  wire:model="log_respuesta" placeholder="¿Qué pasó después? ¿Funcionó la acción?"></textarea>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 10. CONCLUSIÓN                            -->
                                <!-- ========================================== -->
                                <div class="mb-3 col-12">
                                    <label class="form-label fw-bold"><i class="bi bi-check2-circle me-1"></i>Conclusión</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary"><i class="bi bi-emoji-smile"></i></span>
                                        <select class="form-select bg-secondary text-white border-secondary"
                                                wire:model="log_conclusion" name="conclusion">
                                            <option value="" selected>Seleccionar conclusión...</option>
                                            <option value="1">😊 Positiva</option>
                                            <option value="2">📵 Sin Comunicación</option>
                                            <option value="3">🙅 No Colabora</option>
                                            <option value="4">❌ No es su número</option>
                                            <option value="5">😐 Neutral</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- ========================================== -->
                                <!-- 11. STATUS + LIMA/PROVINCIAS              -->
                                <!-- ========================================== -->
                                <div class="mb-4 col-12">
                                    <div class="row g-3">
                                        <!-- Status -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold"><i class="bi bi-circle-fill me-1"></i>Status</label>
                                            <div class="input-group">
                                                <div class="btn-group" role="group">
                                                    <input type="radio" class="btn-check" name="status"
                                                           wire:model="log_status" id="status_curso" value="EN CURSO" checked>
                                                    <label class="btn btn-outline-warning" for="status_curso">
                                                        <i class="bi bi-play-circle me-1"></i>EN CURSO
                                                    </label>

                                                    <input type="radio" class="btn-check" name="status"
                                                           wire:model= "log_status" id="status_cerrado" value="CERRADO">
                                                    <label class="btn btn-outline-success" for="status_cerrado">
                                                        <i class="bi bi-check-circle me-1"></i>CERRADO
                                                    </label>

                                                    <input type="radio" class="btn-check" name="status"
                                                           wire:model="log_status"
                                                           id="status_cancelado" value="CANCELADO">
                                                    <label class="btn btn-outline-danger" for="status_cancelado">
                                                        <i class="bi bi-x-circle me-1"></i>CANCELADO
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Lima / Provincias -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold"><i class="bi bi-geo-alt-fill me-1"></i>Ubicación</label>
                                            <div class="input-group">
                                                <div class="btn-group" role="group">
                                                    <input type="radio" class="btn-check" name="ubicacion"
                                                           wire:model="log_ubicacion" id="ubicacion_lima" value="LIMA" checked>
                                                    <label class="btn btn-outline-primary" for="ubicacion_lima">
                                                        <i class="bi bi-building me-1"></i>LIMA
                                                    </label>

                                                    <input type="radio" class="btn-check" name="ubicacion"
                                                           wire:model="log_ubicacion" id="ubicacion_provincias" value="PROVINCIA">
                                                    <label class="btn btn-outline-secondary" for="ubicacion_provincias">
                                                        <i class="bi bi-globe me-1"></i>PROVINCIA
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    @if (session()->has('error'))
                                        <div class="mt-3 alert alert-danger alert-dismissible fade show" role="alert">
                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                            {{ session('error') }}
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    @endif

                                </div>



                                <!-- ========================================== -->
                                <!-- 12. BOTONES                              -->
                                <!-- ========================================== -->
                                <div class="d-flex gap-2 border-top border-secondary">
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="bi bi-save me-1"></i>Guardar
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary px-4">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar
                                    </button>
                                    <button type="button" class="btn btn-outline-danger px-4 ms-auto" wire:click="closeModal">
                                        <i class="bi bi-x-circle me-1"></i>Cancelar
                                    </button>
                                </div>

                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
    function chk_tlf_change(lbl){
        lbl.classList.toggle('btn-success')
        lbl.classList.toggle('btn-secondary')
    }
    //
    // function actualizarInactivos() {
    //     var inactivos = [];
    //     document.querySelectorAll('.telefono-btn.btn-secondary').forEach(function(btn) {
    //         inactivos.push(btn.getAttribute('data-telefono'));
    //     });
    //     var input = document.getElementById('telefonosInactivos');
    //     if (input) {
    //         input.value = inactivos.join(',');
    //     }
    // }
</script>
