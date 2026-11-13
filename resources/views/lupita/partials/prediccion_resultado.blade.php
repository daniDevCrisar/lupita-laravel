@php
    /** @var \stdClass $r */
    $r = $resultado;
    $colores = ['medido' => 'success', 'parcial' => 'warning', 'cadena' => 'danger', 'sin_datos' => 'secondary'];
    $color = $colores[$r->evidencia ?? 'sin_datos'] ?? 'secondary';
    $minutos = fn ($fecha) => $fecha !== null
        ? (int) round(($fecha->getTimestamp() - $r->ahora->getTimestamp()) / 60)
        : null;
    $texto = function (?int $min): string {
        if ($min === null) {
            return '—';
        }
        $signo = $min < 0 ? '−' : '';
        $min = abs($min);
        if ($min < 60) {
            return $signo . $min . ' min';
        }
        return $signo . intdiv($min, 60) . ' h ' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) . ' m';
    };
@endphp

@if(!empty($r->error))
    <div class="alert alert-warning">
        <i class="bi bi-question-circle"></i>
        <strong>No se pudo predecir</strong> <span class="text-muted small">({{ $r->error }})</span>
        <ul class="mb-0 small">
            @foreach($r->advertencias ?? [] as $aviso)
                <li>{{ $aviso }}</li>
            @endforeach
        </ul>
    </div>
@elseif($r->ya_presento)
    <div class="alert alert-info">
        <i class="bi bi-check2-circle"></i>
        <strong>{{ $r->ref }}</strong> ya tiene <em>presenta para carga</em> registrado; no hay nada que adivinar.
    </div>
@elseif($r->presenta === null)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        <strong>Sin hora estimada.</strong>
        <ul class="mb-0 small">
            @foreach($r->advertencias ?? [] as $aviso)
                <li>{{ $aviso }}</li>
            @endforeach
        </ul>
    </div>
@else
    <div class="card border-{{ $color }} mb-3">
        <div class="card-header bg-{{ $color }} {{ $color === 'warning' ? 'text-dark' : 'text-white' }} d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong><i class="bi bi-clock-history"></i> Presenta para carga de {{ $r->ref }}</strong>
            <span class="badge bg-light text-dark">
                evidencia {{ $r->evidencia }} · confianza {{ $r->confianza }}%
            </span>
        </div>
        <div class="card-body">
            @php
                $tres = $r->presentaciones ?? [];
                $coloresTres = ['rapido' => 'success', 'normal' => 'primary', 'tardado' => 'warning'];
            @endphp

            <div class="row g-2 mb-3">
                @foreach($tres as $p3)
                    <div class="col-md-3">
                        <div class="card h-100 border-{{ $coloresTres[$p3['clave']] ?? 'secondary' }}
                                    {{ $p3['clave'] === 'normal' ? 'shadow-sm' : '' }}">
                            <div class="card-body py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-{{ $coloresTres[$p3['clave']] ?? 'secondary' }}">
                                        {{ $p3['etiqueta'] }}
                                    </span>
                                    <span class="badge bg-light text-dark">en {{ $p3['desde_ahora'] }}</span>
                                </div>
                                <div class="fs-3 fw-bold text-nowrap">
                                    {{ \Carbon\Carbon::parse($p3['fecha'])->format('d/m H:i') }}
                                </div>
                                <div class="text-muted" style="font-size:.7rem">{{ $p3['descripcion'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
                <div class="col-md-3">
                    <div class="card h-100 border-info">
                        <div class="card-body py-2">
                            <div class="fw-bold text-info">Inicio de carga</div>
                            <div class="fs-3 fw-bold text-nowrap">
                                {{ $r->inicio_carga ? $r->inicio_carga->format('d/m H:i') : '—' }}
                            </div>
                            <div class="text-muted" style="font-size:.7rem">
                                normal + espera en planta ({{ $texto($r->espera_min) }})
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <div class="small text-muted">
                        Evidencia <strong>{{ $r->evidencia }}</strong> · confianza {{ $r->confianza }}%
                        · {{ $r->confianza_motivo }}
                        @if($r->atrasado)
                            · <span class="badge bg-danger">atrasado</span>
                            el cálculo lo daba a las {{ $r->presenta_crudo?->format('d/m H:i') }}
                        @endif
                    </div>
                    <div class="small text-muted">
                        Hueco calibrado con
                        @if(($r->historia->grupo ?? 'placa') === 'conductor')
                            <strong>el comportamiento del conductor</strong>
                            @if(!empty($r->historia->conductor))
                                ({{ $r->historia->conductor }})
                            @endif
                        @else
                            <strong>el historial de la placa</strong> ({{ $r->placa }})
                        @endif
                        · {{ $r->historia->n ?? 0 }} pares · nivel {{ $r->historia->nivel ?? '—' }}
                        <span class="badge bg-light text-dark"
                              title="{{ ($r->historia->grupo ?? 'placa') === 'conductor'
                                  ? 'ese chofer concentra menos de la mitad de los viajes previos de la placa'
                                  : 'la placa manda: su conductor habitual no está definido' }}">
                            {{ ($r->historia->grupo ?? 'placa') === 'conductor' ? 'por chofer' : 'por placa' }}
                        </span>
                        @if($r->historia->cerca ?? false)
                            <span class="badge bg-info text-dark"
                                  title="el camión descargó en el mismo local o distrito de la planta donde carga: se calibró con los viajes en que también quedó cerca{{ !empty($r->historia->cerca_regimen) ? ' (régimen medido en la ' . $r->historia->cerca_regimen . ')' : '' }}">
                                descarga cerca de la planta de carga
                            </span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        Estado del camión: <strong>{{ $r->estado->etiqueta ?? '—' }}</strong>
                        — {{ $r->estado->motivo ?? '' }}
                        @if($r->geoapify->usado ?? false)
                            · <span class="badge bg-info text-dark" title="{{ $r->geoapify->motivo }}">
                                traslado con Geoapify
                                @if($r->geoapify->km)
                                    · {{ $r->geoapify->km }} km
                                @endif
                                @if(($r->geoapify->llamadas ?? 0) > 0)
                                    · {{ $r->geoapify->llamadas }}
                                    {{ $r->geoapify->llamadas === 1 ? 'llamada' : 'llamadas' }} al API
                                @endif
                            </span>
                        @endif
                        @if($r->ventana['desde'])
                            · ventana p25–p75:
                            {{ $r->ventana['desde']->format('d/m H:i') }} – {{ $r->ventana['hasta']->format('d/m H:i') }}
                        @endif
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="text-muted small">Total desde el ancla</div>
                    <div class="fs-5 fw-bold">{{ $r->duracion_total ?? '—' }}</div>
                </div>
            </div>

            <hr>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="fw-bold small text-uppercase text-muted mb-1">
                        Cómo se armó la hora ({{ $r->duracion_total ?? '—' }} en total)
                    </div>
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                        <tr><th style="width:34px">#</th><th>Paso</th><th class="text-end">Duración</th><th>Cuándo</th></tr>
                        </thead>
                        <tbody>
                        @foreach($r->pasos ?? [] as $paso)
                            <tr @class(['table-light' => in_array($paso['concepto'], ['presenta', 'inicio_carga'], true)])>
                                <td class="text-muted small">{{ $paso['n'] }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $paso['titulo'] }}</div>
                                    <div class="small text-muted">{{ $paso['detalle'] }}</div>
                                </td>
                                <td class="text-end fw-bold text-nowrap">{{ $paso['duracion'] }}</td>
                                <td class="small text-nowrap">
                                    {{ $paso['fecha'] ? \Carbon\Carbon::parse($paso['fecha'])->format('d/m H:i') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <details class="mt-2">
                        <summary class="small text-muted">Desglose crudo en minutos</summary>
                        <table class="table table-sm mb-0 align-middle mt-1">
                            <thead class="table-light">
                            <tr><th>Concepto</th><th class="text-end">Minutos</th><th>Fuente</th></tr>
                            </thead>
                            <tbody>
                            @foreach($r->desglose as $fila)
                                <tr>
                                    <td class="small">{{ $fila['concepto'] }}</td>
                                    <td class="text-end fw-bold">{{ number_format($fila['min'], 0, ',', '.') }}</td>
                                    <td class="small text-muted">{{ $fila['fuente'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </details>

                    <div class="small text-muted mt-1">
                        Ancla: <code>{{ $r->ancla->ref ?: '—' }}</code>
                        · {{ $r->ancla->hito ?? 'sin hito' }}
                        @if($r->ancla->fecha)
                            del {{ $r->ancla->fecha->format('d/m H:i') }}
                            (hace {{ $texto($r->ancla->edad_min) }})
                        @endif
                        · {{ $r->ancla->cerrada ? 'viaje cerrado' : 'viaje en curso' }}
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="fw-bold small text-uppercase text-muted mb-1">Supuestos</div>
                    <ul class="small mb-2">
                        @foreach($r->supuestos ?? [] as $supuesto)
                            <li>{{ $supuesto }}</li>
                        @endforeach
                    </ul>

                    @if(!empty($r->historia))
                        <div class="fw-bold small text-uppercase text-muted mb-1">Historia usada</div>
                        <div class="small">
                            {{ $r->historia->pares }} pares de
                            <code>{{ $r->historia->clave }}</code> ({{ $r->historia->grupo }})
                            · nivel {{ $r->historia->nivel }} · n={{ $r->historia->n }}
                        </div>
                        <div class="small text-muted">
                            franjas inactivas ({{ $r->historia->ventanas_origen === 'perfil_del_camion'
                                ? 'de su propio perfil'
                                : 'por defecto: ' . str_replace('_', ' ', $r->historia->ventanas_origen) }}):
                            @forelse($r->historia->ventanas as $ventana)
                                {{ sprintf('%02d:%02d-%02d:%02d', intdiv($ventana['desde'], 60), $ventana['desde'] % 60, intdiv($ventana['hasta'], 60), $ventana['hasta'] % 60) }}{{ $loop->last ? '' : ' · ' }}
                            @empty
                                ninguna detectada
                            @endforelse
                        </div>
                    @endif

                    <div class="small text-muted mt-2">Confianza: {{ $r->confianza_motivo }}</div>
                </div>
            </div>

            @if(!empty($r->advertencias))
                <div class="alert alert-warning mt-3 mb-0 py-2">
                    <strong class="small">Advertencias</strong>
                    <ul class="small mb-0">
                        @foreach($r->advertencias as $aviso)
                            <li>{{ $aviso }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif
