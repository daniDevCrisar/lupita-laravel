@extends('layouts.app')

@section('title', 'Scoring de éxito: modelo vs humano')

@section('content')

    @php
        use App\Database\DBPalabrasLlamadas as Palabras;
        use App\Database\DBScoringLlamadas as Scoring;

        $m = $datos->matriz;
        $query = request()->query();

        $max_decil = 0;
        foreach ($datos->deciles as $d) {
            $max_decil = max($max_decil, $d->puntaje_medio, $d->real_pct);
        }
        $max_decil = max(1, $max_decil);

        $mentirosos = collect($auditoria)->filter(
            fn($e) => $e->apoyable && abs((float) $e->delta) >= 0.25
        )->take(12)->values();

        $sin_puntajes = $datos->totales === 0;
    @endphp

    <div class="row">
        <div class="col-12">
            <h1>Scoring de éxito: modelo vs humano</h1>
            <p class="text-light small mb-2">
                El modelo puntúa cada llamada con
                <strong class="text-white">logit(base del tipo) + los pesos de las palabras que dijo el conductor</strong>;
                el humano decidió <em>llamada_exitosa</em> escuchando el audio. Aquí se comparan los dos veredictos,
                <strong class="text-white">sin que el modelo escriba nada en las llamadas</strong>: un desacuerdo es
                una llamada para revisar en Etiquetar, no una corrección automática.
            </p>
            <p class="text-light small mb-2">
                <strong class="text-white">AUC</strong> mide orden (0,5 = azar, 1 = separa perfecto) y no depende del
                umbral. <strong class="text-white">Concordancia</strong> sí depende del umbral, y hay que compararla
                contra la <strong class="text-white">tasa mayoritaria</strong>: un modelo que siempre dice «fallida»
                acierta solo el {{ number_format($m->mayoritaria, 1) }} % en este rango. Los
                <strong class="text-white">deciles de calibración</strong> responden si el 70 % que dice el modelo
                termina siendo 70 % de éxitos reales.
            </p>
            <p class="text-light small mb-2">
                Las llamadas de <strong class="text-white">buzón de voz no se puntúan</strong> (el contestador no es
                habla del chofer y su efecto ya está dentro de la base medida del tipo). Las que no tienen ningún
                mensaje del conductor quedan con <em>puntaje = base</em> y salen de las métricas, para que la transcripción
                vacía no infle la concordancia.
            </p>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">Filtros del rango</legend>

                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio"
                               value="{{ request('fecha_inicio') }}" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin"
                               value="{{ request('fecha_fin') }}" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="llamada_tipo_id">Tipo de llamada</label>
                        <select name="llamada_tipo_id" id="llamada_tipo_id" class="form-control">
                            <option value="">Todas</option>
                            @foreach($tipos as $tipo)
                                <option value="{{ $tipo->id }}"
                                        @selected((string) request('llamada_tipo_id') === (string) $tipo->id)>
                                    {{ $tipo->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="conductor" class="form-label">Conductor</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="conductor" name="conductor"
                                   value="{{ request('conductor') }}" class="form-control"
                                   placeholder="Conductor, id...">
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label for="trt" class="form-label">Transportista</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <input type="text" id="trt" name="trt"
                                   value="{{ request('trt') }}" class="form-control"
                                   placeholder="Transportista, id...">
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="ruta_id">Ruta</label>
                        <select name="ruta_id" id="ruta_id" class="form-control">
                            <option value="">Todas</option>
                            @foreach($rutas as $ruta)
                                <option value="{{ $ruta->id }}"
                                        @selected((string) request('ruta_id') === (string) $ruta->id)>
                                    {{ \App\Database\DBTiemposRuta::nombre_ruta($ruta) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="universo">Qué entra</label>
                        <select name="universo" id="universo" class="form-control">
                            @foreach(Palabras::$universos as $valor => $texto)
                                <option value="{{ $valor }}" @selected(Scoring::universo() === $valor)>
                                    {{ $texto }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="umbral">Umbral de éxito</label>
                        <select name="umbral" id="umbral" class="form-control">
                            @foreach(Scoring::$umbrales as $u)
                                <option value="{{ $u }}" @selected(Scoring::umbral() === $u)>{{ $u }} %</option>
                            @endforeach
                        </select>
                        <div class="form-text text-light">
                            A partir de qué puntaje el modelo declara «éxito».
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label d-block">Métricas</label>
                        <div class="form-check">
                            {{-- el hidden deja constancia de la casilla desmarcada:
                                 sin él, desactivarla equivale a no enviar el formulario --}}
                            <input type="hidden" name="incluir_sin_texto" value="0">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="incluir_sin_texto" name="incluir_sin_texto"
                                   @checked(Scoring::incluyeSinTexto())>
                            <label class="form-check-label small" for="incluir_sin_texto">
                                Contar también las que no tienen texto
                            </label>
                        </div>
                    </div>

                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Comparar
                        </button>

                        <a href="{{ url()->current() }}" class="btn btn-secondary">Limpiar</a>

                        <button type="button" class="btn btn-outline-info" form="form-calcular">
                            <i class="bi bi-cpu"></i> Calcular rango
                        </button>
                    </div>
                </div>
            </fieldset>
        </form>

        {{-- el boton de calcular vive fuera del form de filtros (un form dentro de
             otro no es valido); apunta al mismo rango que se esta viendo --}}
        <form method="POST" action="{{ route('lupita.scoring.calcular', $query) }}" id="form-calcular"
              class="d-none">@csrf</form>
    </div>

    @if (session('exito'))
        <div class="alert alert-success py-2">{{ session('exito') }}</div>
    @endif

    <div class="row mb-3">
        <div class="col-12">
            <div class="card border-{{ $datos->obsoletas_totales > 0 ? 'warning' : 'success' }}">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
                    @if ($sin_puntajes)
                        <div class="text-warning">
                            <strong class="text-white">Todavía no hay puntajes guardados para este rango.</strong>
                            Use <em>Calcular rango</em>: es la única pasada que lee la transcripción.
                        </div>
                    @elseif ($datos->obsoletas_totales > 0)
                        <div class="small">
                            <strong class="text-warning">{{ number_format($datos->obsoletas_totales) }}</strong>
                            llamadas con pesos obsoletos en toda la tabla
                            (en este rango: {{ number_format($datos->obsoletas) }}) ·
                            <strong class="text-white">Calcular rango</strong> para volver a puntuarlas
                        </div>
                    @else
                        <div class="small text-success">
                            Puntajes al día · versión
                            <code class="text-white">{{ mb_substr($datos->version_actual, 0, 10) }}</code>
                            @if($datos->ultima)
                                · calculado
                                {{ \Carbon\Carbon::parse($datos->ultima)->diffForHumans() }}
                            @endif
                        </div>
                    @endif

                    <div class="small text-muted ms-auto">
                        {{ number_format($datos->totales) }} filas guardadas en el rango ·
                        con texto {{ number_format($datos->por_motivo['ok']) }} ·
                        sin texto {{ number_format($datos->por_motivo['sin_texto']) }} ·
                        buzón {{ number_format($datos->por_motivo['buzon']) }} ·
                        <a href="{{ route('lupita.scoring.pesos', $query) }}" class="text-info">editar pesos</a> ·
                        <a href="{{ route('lupita.scoring.exportar', $query) }}" class="text-info">exportar desacuerdos</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Llamadas con puntaje</div>
                    <div class="fs-4 fw-bold text-white">{{ number_format($m->n) }}</div>
                    <div class="small text-muted">en el rango filtrado</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Concordancia al {{ Scoring::umbral() }} %</div>
                    <div class="fs-4 fw-bold text-info">{{ Palabras::porcentaje($m->accuracy, 1) }}</div>
                    <div class="small text-muted">
                        {{ number_format($datos->en_metricas) }} filas en la métrica ·
                        tasa mayoritaria {{ number_format($m->mayoritaria, 1) }} %
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-{{ $datos->auc !== null && $datos->auc >= 0.65 ? 'success' : 'secondary' }} h-100">
                <div class="card-body py-2">
                    <div class="text-light small">AUC (orden)</div>
                    <div class="fs-4 fw-bold text-white">
                        {{ $datos->auc === null ? '—' : number_format($datos->auc, 3) }}
                    </div>
                    <div class="small text-muted">0,50 es azar</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-warning h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Precisión del umbral</div>
                    <div class="fs-4 fw-bold text-warning">
                        {{ $m->precision === null ? '—' : Palabras::porcentaje($m->precision, 1) }}
                    </div>
                    <div class="small text-muted">de lo que dice «éxito»</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-warning h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Recall (sensibilidad)</div>
                    <div class="fs-4 fw-bold text-warning">
                        {{ $m->recall === null ? '—' : Palabras::porcentaje($m->recall, 1) }}
                    </div>
                    <div class="small text-muted">de los éxitos reales</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-light small">F1</div>
                    <div class="fs-4 fw-bold text-white">{{ number_format($m->f1, 1) }} %</div>
                    <div class="small text-muted">
                        {{ number_format($m->fp) }} falsos positivos · {{ number_format($m->fn) }} falsos negativos
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">
                        Matriz de confusión al {{ Scoring::umbral() }} %
                    </span>
                </div>
                <div class="card-body py-2">
                    <table class="table table-sm mb-0">
                        <thead class="thead-lupita">
                        <tr class="small">
                            <th></th>
                            <th>Humano: éxito</th>
                            <th>Humano: fallida</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <th class="small">Modelo: éxito</th>
                            <td class="bg-success bg-gradient text-white">
                                {{ number_format($m->tp) }}
                                <div class="small">acierto</div>
                            </td>
                            <td class="bg-danger bg-gradient text-white">
                                {{ number_format($m->fp) }}
                                <div class="small">el modelo se entusiasma</div>
                            </td>
                        </tr>
                        <tr>
                            <th class="small">Modelo: fallida</th>
                            <td class="bg-danger bg-gradient text-white">
                                {{ number_format($m->fn) }}
                                <div class="small">se le escapa un éxito</div>
                            </td>
                            <td class="bg-success bg-gradient text-white">
                                {{ number_format($m->tn) }}
                                <div class="small">acierto</div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="small text-muted mt-2">
                        Solo entran las llamadas con texto del conductor:
                        {{ number_format($datos->por_motivo['buzon']) }} de buzón y
                        {{ number_format($datos->por_motivo['sin_texto']) }} sin texto quedan fuera.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">Calibración en deciles</span>
                    <span class="small text-muted ms-2">
                        barra clara = lo que dice el modelo · barra oscura = lo que pasó de verdad
                    </span>
                </div>
                <div class="card-body py-2">
                    @forelse($datos->deciles as $d)
                        <div class="row g-1 align-items-center mb-1">
                            <div class="col-2 small text-light">
                                D{{ $d->decil }}
                                <span class="text-muted">· {{ number_format($d->n) }}</span>
                            </div>
                            <div class="col-10">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <div class="progress flex-grow-1" style="height: 9px">
                                        <div class="progress-bar bg-info"
                                             style="width: {{ Palabras::barra($d->puntaje_medio, $max_decil) }}%"></div>
                                    </div>
                                    <span class="small text-white" style="width: 58px">
                                        {{ number_format($d->puntaje_medio, 1) }} %
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 9px">
                                        <div class="progress-bar bg-{{ $d->real_pct >= $d->puntaje_medio ? 'success' : 'warning' }}"
                                             style="width: {{ Palabras::barra($d->real_pct, $max_decil) }}%"></div>
                                    </div>
                                    <span class="small text-muted" style="width: 58px">
                                        {{ number_format($d->real_pct, 1) }} % real
                                    </span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-muted small">Sin puntajes para abrir en deciles.</div>
                    @endforelse
                    <div class="small text-muted mt-2">
                        Ideal: las dos barras casi iguales y subiendo de izquierda a derecha. Si la del modelo se
                        despega de la real, el problema es de <strong>calibración</strong> (los pesos están
                        sobredimensionados), no de orden.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            @php
                $sg = $datos->sugerencia;
                $ok_con_texto = (int) ($datos->por_motivo['ok'] ?? 0);
                $cobertura = $ok_con_texto > 0 ? $sg->sugeridas * 100 / $ok_con_texto : null;
                $color_coinciden = $sg->pct === null ? 'secondary' : ($sg->pct >= 60 ? 'success' : ($sg->pct >= 30 ? 'warning text-white' : 'danger'));
            @endphp
            <div class="card border-info h-100">
                <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-bold text-white">
                        <i class="bi bi-lightbulb"></i> Análisis sugerido vs nota del humano
                    </span>
                    <span class="small text-muted">
                        solo lectura: cada palabra que disparó vota con la fuerza de su |peso| y la
                        sugerencia nunca se copia al análisis de audio de la llamada
                        · <a href="{{ route('lupita.scoring.pesos', $query) }}" class="text-info">ajustar parámetros</a>
                    </span>
                </div>
                <div class="card-body py-2">
                    @if($sg->sugeridas === 0 && $ok_con_texto > 0)
                        <div class="small text-muted">
                            Todavía no hay sugerencias en este rango. La primera pasada de
                            <strong class="text-light">Calcular rango</strong> guarda los disparos y desde la segunda
                            la sugerencia ya tiene con que comparar; si el rango está calculado, puede que falte el
                            ALTER de <code class="text-white">scoring_llamadas</code> o que ninguna nota alcance la
                            fuerza mínima actual ({{ Scoring::nota_min() }}): se ajusta en
                            <a href="{{ route('lupita.scoring.pesos', $query) }}" class="text-info">Palabras y pesos</a>.
                        </div>
                    @else
                        <div class="row g-2 text-center">
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-light">Con sugerencia</div>
                                    <div class="fs-4 fw-bold text-info">{{ number_format($sg->sugeridas) }}</div>
                                    <div class="small text-muted">
                                        de {{ number_format($ok_con_texto) }} con texto
                                        @if($cobertura !== null)
                                            ({{ number_format($cobertura, 1) }} %)
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-light">Coincidencia exacta</div>
                                    <div class="fs-4 fw-bold">
                                        <span class="badge bg-{{ $color_coinciden }}">
                                            {{ $sg->pct === null ? '—' : number_format($sg->pct, 1) . ' %' }}
                                        </span>
                                    </div>
                                    <div class="small text-muted">
                                        {{ number_format($sg->coinciden) }} de
                                        {{ number_format($sg->comparables) }} comparables
                                    </div>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-light">Comparables</div>
                                    <div class="fs-4 fw-bold text-white">{{ number_format($sg->comparables) }}</div>
                                    <div class="small text-muted">el humano también escribió nota</div>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-light">El modelo calló</div>
                                    <div class="fs-4 fw-bold text-white">{{ number_format($sg->human_sin_sugerencia) }}</div>
                                    <div class="small text-muted">
                                        con nota del humano pero sin la fuerza mínima ({{ Scoring::nota_min() }}):
                                        no se sugiere a la fuerza
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            «Coincidencia exacta» compara nota sugerida y nota del humano normalizadas
                            (mayúsculas sin acentos): mide cuántas veces el modelo habría escrito lo mismo que
                            el revisor. No es un veredicto de éxito, es la calidad del borrador.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">Por tipo de llamada</span>
                </div>
                <div class="card-body py-0">
                    <table class="table table-sm mb-0">
                        <thead class="thead-lupita">
                        <tr class="small">
                            <th>Tipo</th>
                            <th>Puntuadas</th>
                            <th>Base medida</th>
                            <th>Concordancia</th>
                            <th>Éxito vs fracaso real</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($datos->por_tipo as $tipo => $k)
                            @php $base = $datos->bases[$tipo] ?? null; @endphp
                            <tr>
                                <td class="small">{{ $k->nombre ?? ('tipo ' . $tipo) }}</td>
                                <td>{{ number_format($k->n) }}</td>
                                <td class="small">
                                    {{ $base ? Palabras::porcentaje($base->pct, 1) : '—' }}
                                </td>
                                <td>
                                    @php $color = $k->accuracy >= 70 ? 'success' : ($k->accuracy >= 50 ? 'warning text-white' : 'danger'); @endphp
                                    <span class="badge bg-{{ $color }}">
                                        {{ number_format($k->accuracy, 1) }} %
                                    </span>
                                </td>
                                <td class="small">
                                    @if($k->media_e !== null && $k->media_f !== null)
                                        <span class="text-success">{{ number_format($k->media_e, 1) }}</span>
                                        vs
                                        <span class="text-danger">{{ number_format($k->media_f, 1) }}</span>
                                        <span class="text-muted">(brecha {{ number_format($k->brecha, 1) }})</span>
                                    @else
                                        <span class="text-muted">un solo lado presente</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="small text-muted p-2">
                        Si un tipo tiene brecha chica, las palabras del vocabulario de ese tipo no están distinguiendo
                        nada: revise el delta en la pantalla de pesos filtrando ese tipo.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-warning">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">Pesos que están mintiendo</span>
                    <span class="small text-muted ms-2">
                        delta = lo que piden los datos menos lo que usted escribió
                    </span>
                </div>
                <div class="card-body py-2">
                    @forelse($mentirosos as $e)
                        <div class="d-flex align-items-center gap-2 mb-1 small">
                            <a href="{{ route('lupita.scoring.pesos', array_merge($query, ['palabra' => $e->palabra])) }}"
                               class="text-white text-decoration-none" style="min-width: 200px">
                                {{ $e->palabra }}
                                <span class="badge bg-secondary">{{ $e->gram }}</span>
                            </a>
                            <span class="text-muted">
                                tipo {{ $e->tipo === null ? 'global' : $e->tipo }}
                            </span>
                            <span>peso {{ Scoring::peso_texto((float) $e->peso) }}</span>
                            <span class="text-muted">→</span>
                            <span class="text-warning">{{ Scoring::peso_texto((float) $e->sugerido) }}</span>
                            <span class="badge bg-{{ abs((float) $e->delta) >= 0.5 ? 'danger' : 'warning text-white' }}">
                                Δ {{ Scoring::peso_texto((float) $e->delta) }}
                            </span>
                            <span class="text-muted ms-auto">
                                {{ number_format($e->n) }} llamadas · {{ Palabras::porcentaje($e->real_pct, 0) }} reales
                            </span>
                        </div>
                    @empty
                        <div class="text-muted small">
                            @if(count($auditoria))
                                Ningún peso con delta grande y apoyo suficiente en este rango.
                            @else
                                No hay pesos en la tabla: el puntaje de cada llamada es hoy la base medida de su tipo.
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- La tabla "Llamadas del rango" ya no se muestra aquí: vive en su propia
         vista /scoring/llamadas (menú Scoring → Llamadas del rango). --}}
@endsection

