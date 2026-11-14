@php
    /** @var \App\Prediccion\Parametros $p */
    $p = $catalogos->parametros;
    $etiquetas = [
        'pares_recientes_max' => ['Pares recientes por placa', 'con cuántos huecos recientes se calibra la vuelta'],
        'min_pares_ruta' => ['Mínimo por par de rutas', 'para llamarlo "medido"'],
        'min_pares_entidad' => ['Mínimo por placa', 'para llamarlo "parcial"'],
        'min_hitos_bin' => ['Hitos por franja', 'marcas mínimas para que una franja de 30 min no sea descanso'],
        'max_desplazamiento_min' => ['Desplazamiento máximo', 'minutos que puede moverse una predicción para salir de una franja muerta'],
        'traslado_defecto_min' => ['Traslado por defecto', 'minutos de regreso cuando no hay ruta medida'],
        'muerto_defecto_min' => ['Tiempo muerto por defecto', 'minutos de espera cuando no hay huecos medidos'],
        'espera_carga_defecto_min' => ['Espera en planta por defecto', 'minutos entre presenta e inicio de carga'],
    ];
@endphp

<div class="card mb-3">
    <div class="card-header bg-secondary text-white">
        <strong>Parámetros del motor</strong>
        <span class="small">— se guardan en <code>prediccion_config</code> y valen para todos</span>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('lupita.prediccion.parametros') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="ref" value="{{ $ref ?? '' }}">
            <input type="hidden" name="placa" value="{{ $placa ?? '' }}">
            <input type="hidden" name="ahora" value="{{ $ahora ?? '' }}">

            @foreach($parametros_editables as $clave)
                @php $info = $etiquetas[$clave] ?? [$clave, '']; @endphp
                <div class="col-md-3">
                    <label class="form-label small mb-0">{{ $info[0] }}</label>
                    <input type="number" min="0" class="form-control form-control-sm"
                           name="{{ $clave }}" value="{{ $p->{$clave} }}">
                    <div class="text-muted" style="font-size:.7rem">{{ $info[1] }}</div>
                </div>
            @endforeach

            <div class="col-md-3">
                <button class="btn btn-sm btn-outline-secondary w-100">
                    <i class="bi bi-save"></i> Guardar parámetros
                </button>
            </div>
        </form>

        <div class="small text-muted mt-2">
            Sin tabla <code>prediccion_config</code> (o sin permisos) la pantalla igual funciona con los valores de
            <code>config/prediccion.php</code>.
        </div>

        @php
            $calibracion = session('calibracion_cruce');
            $numero = fn ($valor) => $valor === null ? '—' : (int) round((float) $valor);
        @endphp

        {{-- Calibración de cruce_pares_k: entrena + valida y deja la recomendación --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
            <form method="POST" action="{{ route('lupita.prediccion.calibrar') }}" class="d-flex flex-wrap align-items-center gap-2">
                @csrf
                <div>
                    <div class="small fw-semibold">Calibrar <code>cruce_pares_k</code></div>
                    <div class="text-muted" style="font-size:.7rem">
                        prueba 1 · 2 · 3 · 5 · 8 · 12 sobre la tabla de aciertos (entrena y valida) y recomienda
                    </div>
                </div>
                <select name="limite" class="form-select form-select-sm" style="width:auto">
                    <option value="80">80 filas</option>
                    <option value="100" selected>100 filas</option>
                    <option value="200">200 filas</option>
                </select>
                <button class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-sliders"></i> Calibrar
                </button>
                <button class="btn btn-sm btn-outline-secondary" name="refrescar" value="1"
                        title="ignora la corrida guardada en caché (10 min) y vuelve a medir">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </form>
            <div class="ms-auto small text-muted">
                en uso: <strong>{{ $p->cruce_pares_k ?? '—' }}</strong>
            </div>
        </div>

        @if(is_array($calibracion))
            @if(!($calibracion['ok'] ?? false))
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>No se pudo calibrar.</strong> {{ $calibracion['error'] ?? '' }}
                </div>
            @else                @php
                    $mejor = $calibracion['mejor'] ?? [];
                    $rec = $calibracion['recomendacion'][0] ?? null;
                    $actualVal = $calibracion['actual']['validacion'] ?? null;
                    $maeMejor = $mejor['validacion']->mae_min ?? null;
                    $maeActual = $actualVal->mae_min ?? null;
                    $mejora = $maeMejor !== null && $maeActual !== null && (float) $maeMejor < (float) $maeActual;
                @endphp

                <div class="alert {{ $mejora ? 'alert-success' : 'alert-info' }} mt-3 mb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <strong>Recomendación: <code>cruce_pares_k = {{ $rec['recomendado'] ?? '—' }}</code></strong>
                            <span class="small">(en uso: {{ $rec['actual'] ?? '—' }})</span>
                            <div class="small mt-1">
                                MAE en validación <strong>{{ $numero($maeMejor) }} min</strong>
                                @if($maeActual !== null)
                                    contra <strong>{{ $numero($maeActual) }} min</strong> con lo que está en uso
                                    @if($mejora)
                                        → mejora <strong>{{ $numero((float) $maeActual - (float) $maeMejor) }} min</strong>
                                    @else
                                        → no mejora
                                    @endif
                                @endif
                                · ±3 h {{ $numero($mejor['validacion']->pct_180 ?? null) }} %
                                · n = {{ (int) ($mejor['validacion']->n ?? 0) }}
                            </div>
                            <div class="text-muted" style="font-size:.7rem">
                                entrenamiento {{ $calibracion['entrenamiento']['desde'] }} → {{ $calibracion['entrenamiento']['hasta'] }}
                                · validación {{ $calibracion['validacion']['desde'] }} → {{ $calibracion['validacion']['hasta'] }}
                                · {{ $calibracion['combinaciones'] }} combinaciones · {{ $calibracion['limite'] }} filas por corrida
                            </div>
                        </div>
                        <form method="POST" action="{{ route('lupita.prediccion.parametros') }}">
                            @csrf
                            <input type="hidden" name="ref" value="{{ $ref ?? '' }}">
                            <input type="hidden" name="placa" value="{{ $placa ?? '' }}">
                            <input type="hidden" name="ahora" value="{{ $ahora ?? '' }}">
                            <input type="hidden" name="cruce_pares_k" value="{{ $rec['recomendado'] ?? '' }}">
                            <button class="btn btn-sm {{ $mejora ? 'btn-success' : 'btn-outline-success' }}">
                                <i class="bi bi-check2"></i> Aplicar {{ $rec['recomendado'] ?? '' }}
                            </button>
                        </form>
                    </div>

                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-borderless mb-0" style="font-size:.78rem">
                            <thead>
                                <tr class="text-muted">
                                    <th>parámetro probado</th>
                                    <th class="text-end">MAE entrena</th>
                                    <th class="text-end">n</th>
                                    <th class="text-end">MAE valida</th>
                                    <th class="text-end">n</th>
                                    <th class="text-end">±3 h</th>
                                    <th class="text-end">% ventana</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(collect($calibracion['resultados'])->take(6) as $fila)
                                    <tr class="{{ ($fila['elegido'] ?? false) ? 'table-success' : '' }}">
                                        <td><code>{{ $fila['etiqueta'] }}</code></td>
                                        @if($fila['entrenamiento'] === null || $fila['validacion'] === null)
                                            <td colspan="6" class="text-danger small">
                                                no corrió: {{ $fila['error'] ?? 'sin datos' }}
                                            </td>
                                        @else
                                            <td class="text-end">{{ $numero($fila['entrenamiento']->mae_min ?? null) }}</td>
                                            <td class="text-end">{{ (int) ($fila['entrenamiento']->n ?? 0) }}</td>
                                            <td class="text-end">{{ $numero($fila['validacion']->mae_min ?? null) }}</td>
                                            <td class="text-end">{{ (int) ($fila['validacion']->n ?? 0) }}</td>
                                            <td class="text-end">{{ $numero($fila['validacion']->pct_180 ?? null) }} %</td>
                                            <td class="text-end">{{ $numero($fila['validacion']->pct_ventana ?? null) }} %</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted mt-1" style="font-size:.7rem">
                        Se elige por el MAE de la <strong>validación</strong> (el tramo final, que no se usó para entrenar):
                        ganar en entrenamiento siempre es trampa.
                    </div>
                </div>
            @endif
        @endif

        @php $ventana = session('ventana_medida'); @endphp

        {{-- Ventana rápido/tardado: medida contra el error real, no contra el hueco --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
            <form method="POST" action="{{ route('lupita.prediccion.ventana') }}" class="d-flex flex-wrap align-items-center gap-2">
                @csrf
                <div>
                    <div class="small fw-semibold">Medir ventana rápido / tardado</div>
                    <div class="text-muted" style="font-size:.7rem">
                        corre la tabla de aciertos y abre la ventana con el error real (el p25–p75 del hueco cubre ~35 %)
                    </div>
                </div>
                <select name="cobertura" class="form-select form-select-sm" style="width:auto">
                    <option value="0.5">50 %</option>
                    <option value="0.7" selected>70 %</option>
                    <option value="0.8">80 %</option>
                </select>
                <select name="limite" class="form-select form-select-sm" style="width:auto">
                    <option value="200">200 filas</option>
                    <option value="600" selected>600 filas</option>
                    <option value="1000">1000 filas</option>
                </select>
                <button class="btn btn-sm btn-outline-primary" name="cobertura_enviar" value="1">
                    <i class="bi bi-arrows-expand"></i> Medir
                </button>
                <button class="btn btn-sm btn-outline-secondary" name="refrescar" value="1"
                        title="ignora la corrida guardada en caché (10 min)">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </form>
            <div class="ms-auto small text-muted">
                en uso: <strong>−{{ (int) ($p->ventana_adelanto_min ?? 0) }} / +{{ (int) ($p->ventana_atraso_min ?? 0) }} min</strong>
                @if((int) ($p->ventana_residuos ?? 0) === 1)
                    <span class="badge bg-success">aplicada</span>
                @else
                    <span class="badge bg-secondary">sin aplicar</span>
                @endif
            </div>
        </div>

        @if(is_array($ventana))
            @if(!($ventana['ok'] ?? false))
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>No se pudo medir.</strong> {{ $ventana['error'] ?? '' }}
                </div>
            @else
                <div class="alert alert-info mt-3 mb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <strong>Ventana recomendada: −{{ $ventana['adelanto'] }} / +{{ $ventana['atraso'] }} min</strong>
                            <span class="small">({{ \App\Prediccion\Tiempo::hhmm((int) $ventana['radio']) }} a cada lado)</span>
                            <div class="small mt-1">
                                cubre <strong>{{ $ventana['cobertura_lograda'] }} %</strong> de las filas
                                · la ventana actual (p25–p75 del hueco) cubre <strong>{{ $ventana['cobertura_actual'] ?? '—' }} %</strong>
                                · MAE {{ $ventana['mae'] }} min · sesgo {{ $ventana['sesgo'] }} min · n = {{ $ventana['n'] }}
                            </div>
                            @if((int) ($ventana['cadena_radio'] ?? 0) > 0)
                                <div class="small">
                                    sin pares medidos (cadena, n = {{ $ventana['cadena_n'] }}):
                                    <strong>±{{ $ventana['cadena_radio'] }} min</strong>
                                    (cubre {{ $ventana['cadena_cobertura'] }} % — con la banda general quedaría corto)
                                </div>
                            @endif
                            <div class="text-muted" style="font-size:.7rem">
                                corrida {{ $ventana['desde'] }} → {{ $ventana['hasta'] }}
                                · cobertura pedida {{ (int) round(100 * (float) ($ventana['cobertura'] ?? 0.7)) }} %
                                · {{ $ventana['limite'] ?? '' }} filas
                            </div>
                        </div>
                        <form method="POST" action="{{ route('lupita.prediccion.parametros') }}">
                            @csrf
                            <input type="hidden" name="ventana_residuos" value="1">
                            <input type="hidden" name="ventana_adelanto_min" value="{{ (int) $ventana['adelanto'] }}">
                            <input type="hidden" name="ventana_atraso_min" value="{{ (int) $ventana['atraso'] }}">
                            <input type="hidden" name="ventana_adelanto_cadena" value="{{ (int) ($ventana['cadena_radio'] ?? 0) }}">
                            <input type="hidden" name="ventana_atraso_cadena" value="{{ (int) ($ventana['cadena_radio'] ?? 0) }}">
                            <button class="btn btn-sm btn-success">
                                <i class="bi bi-check2"></i> Aplicar ventana
                            </button>
                        </form>
                    </div>
                    <div class="text-muted mt-1" style="font-size:.7rem">
                        Es simétrica a propósito: la mediana del error salta cientos de minutos entre ventanas, así que
                        correr la banda con el sesgo sería calzar ruido. El sesgo se informa aparte.
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
