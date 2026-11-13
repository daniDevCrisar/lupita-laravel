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
        No se pudo predecir: {{ $r->error }}. {{ $r->advertencias[0] ?? '' }}
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
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <div class="text-muted small">
                        Se presenta para cargar el
                        @if($r->atrasado)
                            <span class="badge bg-danger">atrasado</span>
                        @endif
                    </div>
                    <div class="display-6 fw-bold text-{{ $color === 'warning' ? 'warning' : $color }}">
                        {{ $r->presenta->format('d/m/Y') }} · {{ $r->presenta->format('H:i') }}
                    </div>
                    <div class="small text-muted">
                        @if($r->atrasado && $r->presenta_crudo)
                            el cálculo lo daba a las {{ $r->presenta_crudo->format('d/m H:i') }}, o sea que ya
                            debería haber presentado: la hora de arriba es el corte
                        @else
                            {{ $r->presenta->isoFormat('dddd') }} · faltan {{ $texto($minutos($r->presenta)) }}
                        @endif
                        @if($r->ventana['desde'])
                            · ventana {{ $r->ventana['desde']->format('d/m H:i') }} – {{ $r->ventana['hasta']->format('d/m H:i') }}
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Empieza a cargar (presenta + espera en planta)</div>
                    <div class="fs-4 fw-bold">
                        {{ $r->inicio_carga ? $r->inicio_carga->format('d/m H:i') : '—' }}
                    </div>
                    <div class="small text-muted">espera esperada {{ $texto($r->espera_min) }}</div>
                </div>
                <div class="col-md-3 text-md-end">
                    <div class="text-muted small">Estado del camión</div>
                    <div class="fw-bold">{{ $r->estado->etiqueta ?? '—' }}</div>
                    <div class="small text-muted">{{ $r->estado->motivo ?? '' }}</div>
                </div>
            </div>

            <hr>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="fw-bold small text-uppercase text-muted mb-1">Cómo se armó la hora</div>
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                        <tr><th>Concepto</th><th class="text-end">Minutos</th><th>Fuente</th></tr>
                        </thead>
                        <tbody>
                        @foreach($r->desglose as $fila)
                            <tr>
                                <td>{{ $fila['concepto'] }}</td>
                                <td class="text-end fw-bold">{{ number_format($fila['min'], 0, ',', '.') }}</td>
                                <td class="small text-muted">{{ $fila['fuente'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
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
                            franjas inactivas:
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
