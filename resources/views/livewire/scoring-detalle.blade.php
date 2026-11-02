<div>
    @php
        use App\Database\DBScoringLlamadas as Scoring;

        $f = $this->fila;
        $total_ids = count($this->ids);
        $pos = $this->indice + 1;

        $tiene_puntaje = $f && $f->puntaje !== null;
        $humano = $f && (int) $f->humano_exitosa === 1;
        $color_motivo = ['ok' => 'secondary', 'buzon' => 'warning', 'sin_texto' => 'info'];
    @endphp

    @if($showModal)
        {{-- el listener de teclado vive solo mientras el modal esta abierto --}}
        <div x-data
             x-on:keydown.left.window="$wire.call('anterior')"
             x-on:keydown.right.window="$wire.call('siguiente')">
            <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,.5);">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">

                        {{-- ===== cabecera: quien soy y en cual llamada estoy ===== --}}
                        <div class="modal-header py-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="modal-title mb-0">La llamada por dentro</h6>
                                @if($f)
                                    <span class="badge bg-{{ $color_motivo[$f->motivo] ?? 'secondary' }}">
                                        {{ Scoring::$motivos[$f->motivo] ?? $f->motivo }}
                                    </span>
                                    @if($tiene_puntaje)
                                        <span class="badge bg-{{ Scoring::color_puntaje((float) $f->puntaje) }}">
                                            {{ number_format((float) $f->puntaje, 1) }} %
                                        </span>
                                        <span class="small text-muted">
                                            base {{ number_format((float) $f->base_pct, 1) }} % ·
                                            humano {{ $humano ? 'éxito' : 'fallida' }}
                                        </span>
                                    @else
                                        <span class="small text-muted">sin puntaje</span>
                                    @endif
                                @endif
                            </div>

                            {{-- flechas ◀ ▶ para recorrer las llamadas de la pagina --}}
                            <div class="btn-group btn-group-sm ms-3" role="group">
                                <button type="button" class="btn btn-outline-info"
                                        wire:click="anterior" wire:loading.attr="disabled"
                                        @disabled($this->indice <= 0) title="Anterior (←)">
                                    <i class="bi bi-skip-backward-fill"></i>
                                </button>
                                <span class="btn btn-outline-secondary disabled small">
                                    {{ $pos }} / {{ $total_ids }}
                                </span>
                                <button type="button" class="btn btn-outline-info"
                                        wire:click="siguiente" wire:loading.attr="disabled"
                                        @disabled($this->indice >= $total_ids - 1) title="Siguiente (→)">
                                    <i class="bi bi-skip-forward-fill"></i>
                                </button>
                            </div>

                            <button type="button" class="btn-close ms-2" wire:click="cerrar"></button>
                        </div>

                        {{-- ===== cuerpo ===== --}}
                        <div class="modal-body py-2">
                            @if(!$f)
                                <div class="text-danger small">Esa llamada no tiene puntaje guardado.</div>
                            @else
                                {{-- conversacion, igual que procesar_audio --}}
                                <h6 class="mt-1">La conversación</h6>
                                <div class="border border-secondary rounded p-3 bg-black bg-opacity-25 overflow-auto small"
                                     style="max-height: 320px;">
                                    @forelse($this->mensajes as $item)
                                        @if($item->tipo == 'USER')
                                            {{-- MENSAJE CONDUCTOR (IZQUIERDA) --}}
                                            <div class="d-flex justify-content-start mb-3">
                                                <div class="bg-success text-white p-2 rounded" style="max-width: 75%;">
                                                    {{ $item->mensaje }}
                                                </div>
                                            </div>
                                        @else
                                            {{-- MENSAJE IA (DERECHA) --}}
                                            <div class="d-flex justify-content-end mb-3">
                                                <div class="bg-primary text-white p-2 rounded" style="max-width: 75%;">
                                                    {{ $item->mensaje }}
                                                </div>
                                            </div>
                                        @endif
                                    @empty
                                        <div class="text-muted">Sin mensajes en esta llamada.</div>
                                    @endforelse
                                </div>

                                {{-- pesos aplicados --}}
                                @php $disparos = $f->disparos ?? []; @endphp
                                <h6 class="mt-3">Pesos aplicados</h6>
                                @if(count($disparos) === 0)
                                    <div class="text-muted small">Ninguno: el puntaje es la base del tipo.</div>
                                @else
                                    <table class="table table-sm">
                                        <thead class="thead-lupita">
                                        <tr class="small">
                                            <th>Palabra o frase</th>
                                            <th>N</th>
                                            <th>Alcance</th>
                                            <th>Peso</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($disparos as $d)
                                            <tr>
                                                <td class="text-white">{{ $d->palabra ?? '' }}</td>
                                                <td class="text-white">{{ $d->gram ?? '' }}</td>
                                                <td class="text-white">
                                                    {{ isset($d->tipo) && $d->tipo !== null && $d->tipo !== '' ? ('tipo ' . $d->tipo) : 'global' }}
                                                </td>
                                                <td>
                                                    <span class="badge bg-{{ (float) ($d->peso ?? 0) >= 0 ? 'success' : 'danger' }}">
                                                        {{ $d->peso ?? '' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                @endif

                                {{-- nota del revisor + audio --}}
                                @if(trim((string) $f->analisis_audio) !== '')
                                    <h6 class="mt-3">Nota del revisor (análisis de audio)</h6>
                                    <div class="small text-white">{{ $f->analisis_audio }}</div>
                                @endif

                                @if(trim((string) $f->audio_link) !== '')
                                    <div class="mt-2">
                                        <a href="{{ $f->audio_link }}" target="_blank" rel="noopener"
                                           class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-play-circle"></i> Escuchar
                                        </a>
                                    </div>
                                @endif

                                {{-- reclasificar: abre procesar_audio filtrado a ESTA llamada --}}
                                <div class="mt-3">
                                    <a href="{{ route('lupita.audio', ['ids' => $f->vapi_id]) }}"
                                       target="_blank" rel="noopener" class="btn btn-sm btn-warning">
                                        <i class="bi bi-tags-fill"></i> Reclasificar esta llamada
                                    </a>
                                </div>
                            @endif
                        </div>

                        {{-- ===== pie ===== --}}
                        <div class="modal-footer py-2">
                            <span class="small text-muted me-auto">{{ $f->vapi_id ?? '' }}</span>
                            <button type="button" class="btn btn-sm btn-outline-info" wire:click="anterior"
                                    @disabled($this->indice <= 0)>
                                <i class="bi bi-skip-backward-fill"></i> Anterior
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" wire:click="siguiente"
                                    @disabled($this->indice >= $total_ids - 1)>
                                Siguiente <i class="bi bi-skip-forward-fill"></i>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="cerrar">Cerrar</button>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
