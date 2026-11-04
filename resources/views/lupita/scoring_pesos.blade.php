@extends('layouts.app')

@section('title', 'Palabras y pesos del scoring')

@section('content')

    @php
        use App\Database\DBPalabrasLlamadas as Palabras;
        use App\Database\DBScoringLlamadas as Scoring;

        // clave con la que la auditoria devolvio el efecto medido de cada entrada
        $clave_de = fn($p) => $p->palabra . '|' . $p->gram . '|'
            . ($p->llamada_tipo_id === null ? 'G' : $p->llamada_tipo_id);

        $por_grupo = [];
        foreach ($pesos as $p) {
            $por_grupo[$p->llamada_tipo_id === null ? 'G' : (string) $p->llamada_tipo_id][] = $p;
        }

        // los filtros viajan en la query para que "Calcular rango" y "Sugerir"
        // usen exactamente el mismo rango que se esta viendo
        $query = request()->query();
        unset($query['palabra']);
        $url_calcular = route('lupita.scoring.calcular', $query);
        $url_evaluacion = route('lupita.scoring', $query);
        $url_palabras = route('lupita.palabras', $query);

        $hay_pesos = count($pesos) > 0;

        // el panel de "pesos que mienten" de la evaluacion enlaza con ?palabra=...:
        // aqui se usa para dejar visible solo la entrada investigada
        $buscar = Palabras::normalizar(trim((string) request('palabra')));
        if ($buscar !== '') {
            $pesos = array_values(array_filter($pesos,
                fn($p) => str_contains($p->palabra, $buscar)));
        }

        // entradas con apoyo suficiente y delta grande: son las que el panel llama
        // "pesos que mienten", las que valen la pena corregir
        $mentirosos = collect($efectos)
            ->filter(fn($e) => $e->apoyable && abs((float) $e->delta) >= 0.25)
            ->count();
    @endphp

    <div class="row">
        <div class="col-12">
            <h1>Palabras y pesos del scoring</h1>
            <p class="text-light small mb-2">
                Cada llamada se puntúa así:
                <strong class="text-white">logit(p) = logit(base del tipo) + suma de los pesos que dispararon</strong>.
                El peso está en <strong class="text-white">logits</strong>, no en puntos porcentuales:
                <em>+0,65</em> significa <strong class="text-white">duplicar la posibilidad</strong> de que la llamada
                termine en éxito, <em>-0,65</em> la reduce a la mitad, y <em>0</em> es neutra (la palabra existe en la
                tabla pero no mueve nada). Como el resultado pasa por una sigmoide, dos palabras optimistas nunca
                empujan el puntaje por encima de 100 %.
            </p>
            <p class="text-light small mb-2">
                La <strong class="text-white">base no se escribe a mano</strong>: se mide en el rango que está filtrando
                abajo (tasa real de éxito de cada tipo de llamada). Por eso el mismo peso significa cosas distintas según
                el tipo: arrancar de 16,8 % (Confirmación) no es arrancar de 33 % (Fuera de planta para carga).
                Una fila de <strong class="text-white">Global</strong> aplica a todos los tipos; la fila del mismo
                palabra <em>en un tipo</em> la pisa y vale solo para ese tipo.
            </p>
            <p class="text-light small mb-2">
                Las columnas <em>dispara</em> y <em>% real</em> son la auditoría: cuántas llamadas del rango
                contuvieron esa palabra y cuántas de esas terminaron realmente en éxito.
                El <strong class="text-white">sugerido</strong> es el peso que los datos piden
                (<em>logit del % real menos logit de la base, amortiguado por el apoyo</em>); si el
                <strong class="text-white">delta</strong> es grande y el apoyo alto,
                <span class="text-warning">ese peso está mintiendo</span>. Nada de esto toca
                <code>llamadas</code>: el modelo solo puntúa, el humano sigue decidiendo en Etiquetar.
            </p>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <form method="GET" class="card bg-dark-subtle border-secondary">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small" for="fecha_inicio">Fecha inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio"
                                   value="{{ request('fecha_inicio') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="fecha_fin">Fecha fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin"
                                   value="{{ request('fecha_fin') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="universo">Qué entra al conteo</label>
                            <select name="universo" id="universo" class="form-control form-control-sm">
                                @foreach(Palabras::$universos as $valor => $texto)
                                    <option value="{{ $valor }}"
                                            @selected(Scoring::universo() === $valor)>
                                        {{ $texto }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="max_gram_sugerir">Sugerir hasta</label>
                            <select name="max_gram" id="max_gram_sugerir" class="form-control form-control-sm">
                                @foreach(Palabras::$max_grams as $n => $texto)
                                    <option value="{{ $n }}" @selected((int) request('max_gram', 2) === $n)>
                                        {{ $texto }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-funnel"></i> Aplicar rango
                            </button>
                            <a href="{{ route('lupita.scoring.pesos') }}" class="btn btn-sm btn-secondary">Limpiar</a>
                        </div>
                    </div>
                    <div class="form-text text-light small mt-1">
                        El rango solo cambia <strong class="text-white">el efecto medido</strong> de las columnas
                        <em>dispara / % real / delta</em>; los pesos guardados son independientes del rango.
                        <a href="{{ $url_palabras }}" class="text-info">Ver el análisis de palabras completo</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if (session('exito'))
        <div class="alert alert-success py-2">
            {{ session('exito') }}
        </div>
    @endif

    <div class="row mb-3">
        <div class="col-12">
            <div class="card border-{{ $obsoletas > 0 ? 'warning' : 'success' }}">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
                    <div class="small">
                        Pesos en la versión
                        <code class="text-white">{{ mb_substr($version, 0, 10) }}</code>
                        · {{ count($pesos) }} entrada{{ count($pesos) === 1 ? '' : 's' }} en la tabla
                        (activas: {{ count(Scoring::pesos_activos()['global']) + array_sum(array_map('count', Scoring::pesos_activos()['tipos'])) }})
                    </div>

                    @if ($obsoletas > 0)
                        <div class="small text-warning">
                            <strong class="text-white">{{ number_format($obsoletas ) }}</strong>
                            llamadas con puntaje obsoleto (los pesos cambiaron después de calcularlas)
                        </div>
                    @else
                        <div class="small text-success">Puntajes al día para la versión actual de los pesos</div>
                    @endif

                    <form method="POST" action="{{ $url_calcular }}" class="ms-auto">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-cpu"></i> Calcular rango
                        </button>
                    </form>

                    <a href="{{ $url_evaluacion }}" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-graph-up"></i> Modelo vs humano
                    </a>
                </div>
            </div>
        </div>
    </div>

    @php
        // vocabulario real que esta puntuando: solo las entradas activas, agrupadas
        // por alcance. Es la respuesta a "que palabras estas usando para evaluar".
        $activos = Scoring::pesos_activos();
        $nombres_tipo = [];
        foreach ($tipos as $t) {
            $nombres_tipo[$t->id === null ? 'G' : (string) $t->id] = $t;
        }
        $total_activos = count($activos['global'])
            + array_sum(array_map('count', $activos['tipos']));
    @endphp

    <div class="row mb-3">
        <div class="col-12">
            <div class="card border-secondary">
                <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-bold text-white">
                        <i class="bi bi-key"></i> Palabras clave que usa el scoring para puntuar
                    </span>
                    <span class="badge bg-secondary">{{ $total_activos }} activas</span>
                    <span class="small text-muted ms-auto">
                        peso en logits · <span class="text-success">verde sube</span>
                        · <span class="text-danger">rojo baja</span> la posibilidad de exito
                    </span>
                </div>
                <div class="card-body py-2">
                    @if ($total_activos === 0)
                        <div class="small text-muted">
                            Todavia no hay palabras activas: sin pesos el puntaje de cada
                            llamada es exactamente la base medida de su tipo. Use
                            <strong class="text-white">Sugerir desde Palabras clave</strong>
                            o <em>Agregar palabra</em> para armar el vocabulario.
                        </div>
                    @else
                        @if (count($activos['global']))
                            <div class="mb-2">
                                <span class="badge bg-secondary text-white"><i class="bi bi-globe2"></i> Global</span>
                                @foreach ($activos['global'] as $a)
                                    <span class="badge bg-{{ $a->peso > 0 ? 'success' : ($a->peso < 0 ? 'danger' : 'secondary') }} text-white me-1"
                                          title="{{ $a->nota ?: $a->origen }}">
                                        {{ $a->palabra }}@if($a->gram > 1) <span class="fw-normal">·{{ $a->gram }}p</span>@endif
                                        <span class="fw-bold">{{ Scoring::peso_texto($a->peso) }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($activos['tipos'] as $id_tipo => $lista)
                            @php $t = $nombres_tipo[(string) $id_tipo] ?? null; @endphp
                            @if ($t && count($lista))
                                <div class="mb-2">
                                    <span class="badge bg-info text-white">{{ $t->emoji }} {{ $t->nombre }}</span>
                                    @foreach ($lista as $a)
                                        <span class="badge bg-{{ $a->peso > 0 ? 'success' : ($a->peso < 0 ? 'danger' : 'secondary') }} text-white me-1"
                                              title="{{ $a->nota ?: $a->origen }}">
                                            {{ $a->palabra }}@if($a->gram > 1) <span class="fw-normal">·{{ $a->gram }}p</span>@endif
                                            <span class="fw-bold">{{ Scoring::peso_texto($a->peso) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="pesosTabs" role="tablist">
        @if($buscar !== '')
            <li class="nav-item">
                <span class="nav-link text-warning">
                    filtrando «{{ $buscar }}»
                    <a href="{{ route('lupita.scoring.pesos', $query) }}" class="text-info">ver todas</a>
                </span>
            </li>
        @endif
        @foreach($tipos as $t)
            @php $clave = $t->id === null ? 'G' : (string) $t->id; @endphp
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                        id="tab-{{ $clave }}-btn" data-bs-toggle="tab" data-bs-target="#tab-{{ $clave }}"
                        type="button" role="tab">
                    @if($t->id === null)
                        <i class="bi bi-globe2"></i>
                    @else
                        {{ $t->emoji }}
                    @endif
                    {{ $t->nombre }}
                    <span class="badge bg-secondary">{{ count($por_grupo[$clave] ?? []) }}</span>
                    <span class="small text-muted">· {{ number_format($t->n) }} llamadas</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">
        @foreach($tipos as $t)
            @php
                $clave = $t->id === null ? 'G' : (string) $t->id;
                $base = $bases[$t->id ?? 0] ?? null;
                $filas_grupo = $por_grupo[$clave] ?? [];
            @endphp
            <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                 id="tab-{{ $clave }}" role="tabpanel" aria-labelledby="tab-{{ $clave }}-btn">

                <div class="d-flex flex-wrap align-items-center gap-2 my-2">
                    <div class="small text-light">
                        @if($t->id === null)
                            <strong class="text-white">Global</strong> · aplica a los cinco tipos
                        @else
                            <strong class="text-white">{{ $t->nombre }}</strong>
                            · {{ number_format($t->n) }} llamadas,
                            {{ number_format($t->e) }} exitosas
                        @endif
                        @if($base)
                            · base medida
                            <span class="badge bg-info text-white">{{ Palabras::porcentaje($base->pct, 1) }}</span>
                            <span class="text-muted">(logit {{ number_format(Scoring::logit($base->pct), 2) }})</span>
                        @endif
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-success ms-auto"
                            data-agregar="{{ $clave }}">
                        <i class="bi bi-plus-lg"></i> Agregar palabra
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-bs-toggle="modal" data-bs-target="#modal-copiar">
                        <i class="bi bi-files"></i> Copiar un set a otro
                    </button>

                    <button type="button" class="btn btn-sm btn-warning" data-guardar="1">
                        <i class="bi bi-save"></i> Guardar cambios
                    </button>
                    <span class="small text-muted contador-sucias">sin cambios pendientes</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" data-tabla="{{ $clave }}">
                        <thead class="thead-lupita">
                        <tr class="small">
                            <th style="min-width: 180px">Palabra o frase</th>
                            <th>N</th>
                            <th style="min-width: 190px">Peso (logit)</th>
                            <th>Activa</th>
                            <th>Dispara</th>
                            <th>% real</th>
                            <th>Sugerido / delta</th>
                            <th>Origen</th>
                            <th style="min-width: 160px">Nota</th>
                            <th>Última edición</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($filas_grupo as $p)
                            @php $e = $efectos[$clave_de($p)] ?? null; @endphp
                            <tr data-fila="1" data-id="{{ $p->id }}"
                                data-palabra="{{ $p->palabra }}" data-gram="{{ $p->gram }}"
                                data-tipo="{{ $p->llamada_tipo_id === null ? '' : $p->llamada_tipo_id }}"
                                data-peso-original="{{ $p->peso }}"
                                data-activa-original="{{ $p->activa }}"
                                data-nota-original="{{ $p->nota }}">
                                <td>
                                    <span class="text-white">{{ $p->palabra }}</span>
                                </td>
                                <td><span class="badge bg-secondary">{{ $p->gram }}</span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="number" class="form-control form-control-sm peso-num"
                                               style="width: 90px" step="0.05" min="-4" max="4"
                                               value="{{ $p->peso }}">
                                        <input type="range" class="form-range peso-slider"
                                               style="width: 80px" step="0.05" min="-4" max="4"
                                               value="{{ $p->peso }}">
                                    </div>
                                    <div class="small text-muted">{{ Scoring::factor($p->peso) }} posibilidad</div>
                                </td>
                                <td>
                                    <div class="form-check">
                                        <input class="form-check-input peso-activa" type="checkbox"
                                               value="1" @checked($p->activa)>
                                    </div>
                                </td>
                                <td>
                                    @if($e && $e->n)
                                        {{ number_format($e->n) }}
                                        <div class="small text-muted">{{ number_format($e->e) }} éxitos</div>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if($e && $e->n)
                                        <span class="badge bg-{{ Palabras::color_lado($e->real_pct - ($base->pct ?? 0)) }}">
                                            {{ Palabras::porcentaje($e->real_pct, 0) }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($e && $e->n)
                                        <span class="text-white">{{ Scoring::peso_texto((float) $e->sugerido) }}</span>
                                        <span class="badge bg-{{ $e->apoyable && abs((float) $e->delta) >= 0.25 ? 'warning text-white' : 'secondary' }}">
                                            Δ {{ Scoring::peso_texto((float) $e->delta) }}
                                        </span>
                                        @if($e->apoyable && abs((float) $e->delta) >= 0.25)
                                            <div class="small text-warning">peso que miente</div>
                                        @endif
                                    @else
                                        <span class="text-muted">sin apoyo</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $p->origen === 'manual' ? 'info text-white' : 'secondary' }}">
                                        {{ $p->origen }}
                                    </span>
                                </td>
                                <td><input type="text" class="form-control form-control-sm peso-nota"
                                           style="max-width: 180px" maxlength="190"
                                           value="{{ $p->nota }}" placeholder="por qué existe"></td>
                                <td class="small text-muted">
                                    {{ $p->editada_por ?? '—' }}<br>
                                    {{ $p->updated_at ? \Carbon\Carbon::parse($p->updated_at)->format('d/m/Y H:i') : '' }}
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-borrar="{{ $p->id }}" title="Eliminar del vocabulario">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr class="tabla-vacia">
                                <td colspan="11" class="text-muted small py-3">
                                    @if(!$hay_pesos)
                                        Esta tabla está vacía: sin pesos el puntaje de cada llamada es exactamente la
                                        base medida de su tipo. Use <strong class="text-white">Sugerir desde Palabras
                                        clave</strong> (arriba) o <em>Agregar palabra</em> para armar el vocabulario.
                                    @else
                                        Este rango de llamada todavía no tiene entradas; puede copiar el set de otro
                                        tipo o agregarlas a mano.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row mt-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">Sugerir pesos desde el análisis de palabras</span>
                </div>
                <div class="card-body py-2">
                    <p class="small text-light mb-2">
                        Lee el ranking de <a href="{{ $url_palabras }}" class="text-info">lupita/palabras</a>
                        con el rango filtrado y propone palabras sueltas y frases que distinguen, con su peso de
                        salida <em>log(OR) · n/(n+30)</em>: una palabra rara nace con peso chico porque el factor de
                        amortiguación la encoge.
                        <strong class="text-white">No escribe nada</strong>: primero sale una vista previa con casillas
                        y usted elige qué entra. Se descartan el eco de la IA y las stopwords.
                    </p>
                    <button type="button" class="btn btn-sm btn-primary" id="btn-sugerir"
                            data-bs-toggle="modal" data-bs-target="#modal-sugerir">
                        <i class="bi bi-magic"></i> Sugerir desde Palabras clave
                    </button>
                    <span class="small text-muted ms-2" id="estado-sugerir"></span>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-secondary">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white">Efecto del rango tal como está guardado</span>
                </div>
                <div class="card-body py-2">
                    <div class="row small">
                        <div class="col-6">
                            Llamadas puntuadas con texto:
                            <strong class="text-white">{{ number_format($concordancia->n) }}</strong>
                        </div>
                        <div class="col-6">
                            Concordancia con el humano al {{ $concordancia->umbral }} %:
                            <strong class="text-white">{{ Palabras::porcentaje($concordancia->pct, 1) }}</strong>
                        </div>
                        <div class="col-6">
                            AUC (que tan bien ordena):
                            <strong class="text-white">
                                {{ $concordancia->auc === null ? '—' : number_format($concordancia->auc, 3) }}
                            </strong>
                        </div>
                        <div class="col-6">
                            Pesos marcados como mentira:
                            <strong class="text-warning">{{ $mentirosos }}</strong>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">
                        Después de guardar, el número solo se mueve cuando se vuelve a
                        <strong>Calcular rango</strong>: los puntajes guardados llevan la versión de pesos con la que
                        se calcularon. El detalle completo está en
                        <a href="{{ $url_evaluacion }}" class="text-info">Modelo vs humano</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ parametros de la sugerencia de analisis ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-secondary">
                <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-bold text-white">
                        <i class="bi bi-lightbulb"></i> Parámetros de la sugerencia de análisis
                    </span>
                    <span class="small text-muted ms-auto">
                        no tocan el puntaje: solo cambian qué nota se sugiere
                    </span>
                </div>
                <div class="card-body py-2">
                    <form method="POST" action="{{ route('lupita.scoring.sugerencia.guardar', $query) }}"
                          class="d-flex flex-wrap align-items-end gap-3">
                        @csrf @method('PATCH')

                        <div>
                            <label class="form-label small mb-1" for="nota_min">Fuerza mínima de apoyo</label>
                            <input type="number" id="nota_min" name="nota_min" min="1" max="50" step="1"
                                   value="{{ $params['nota_min'] }}" class="form-control form-control-sm" style="width: 110px">
                        </div>

                        <div>
                            <label class="form-label small mb-1" for="nota_top">Notas top del corpus</label>
                            <input type="number" id="nota_top" name="nota_top" min="20" max="1000" step="10"
                                   value="{{ $params['nota_top'] }}" class="form-control form-control-sm" style="width: 120px">
                        </div>

                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-save"></i> Guardar parámetros
                        </button>
                    </form>

                    <div class="small text-muted mt-2">
                        Cada palabra que disparó vota la nota con la fuerza de su
                        <strong class="text-white">|peso|</strong>: <strong class="text-white">fuerza mínima</strong>
                        es la suma de esos votos que una nota humana necesita para ser
                        sugerible — subirla da sugerencias más finas pero menos (baja la
                        cobertura), bajarla sugiere más pero con más ruido.
                        <strong class="text-white">Notas top</strong> acota cuántas notas
                        distintas del revisor entran a la co-ocurrencia (las más usadas).
                        Se aplican en la próxima pasada de
                        <strong class="text-white">Calcular rango</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ modal de preview de sugerencias ============ --}}
    <div class="modal fade" id="modal-sugerir" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Candidatas a peso (vista previa, nada escrito todavía)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2" id="sugerir-cuerpo">
                    <div class="text-muted small">Calculando…</div>
                </div>
                <div class="modal-footer py-2">
                    <span class="small text-muted me-auto" id="sugerir-nota"></span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-aplicar-sugeridas">
                        <i class="bi bi-check2"></i> Aplicar las marcadas
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ modal copiar set ============ --}}
    <div class="modal fade" id="modal-copiar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Copiar el set de pesos de un alcance a otro</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="small text-light">
                        Solo copia las entradas <strong class="text-white">activas</strong> y
                        <strong class="text-white">no pisa</strong> las que ya existen en el destino.
                    </p>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small" for="copiar-desde">Desde</label>
                            <select id="copiar-desde" class="form-control form-control-sm">
                                @foreach($tipos as $t)
                                    <option value="{{ $t->id === null ? '' : $t->id }}">
                                        {{ $t->id === null ? 'Global' : $t->nombre }}
                                        ({{ $t->pesos }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small" for="copiar-hacia">Hacia</label>
                            <select id="copiar-hacia" class="form-control form-control-sm">
                                @foreach($tipos as $t)
                                    <option value="{{ $t->id === null ? '' : $t->id }}"
                                            @selected($t->id !== null)>
                                        {{ $t->id === null ? 'Global' : $t->nombre }}
                                        ({{ $t->pesos }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="small text-muted mt-2" id="copiar-estado"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-copiar">Copiar</button>
                </div>
            </div>
        </div>
    </div>

    {{-- plantilla de una fila nueva: la clona el boton Agregar palabra --}}
    <template id="tpl-fila">
        <tr data-fila="1" data-nuevo="1" data-id="">
            <td><input type="text" class="form-control form-control-sm peso-palabra"
                       style="max-width: 220px" maxlength="80" placeholder="palabra o frase"></td>
            <td><span class="badge bg-secondary peso-gram">1</span></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <input type="number" class="form-control form-control-sm peso-num"
                           style="width: 90px" step="0.05" min="-4" max="4" value="0">
                    <input type="range" class="form-range peso-slider"
                           style="width: 80px" step="0.05" min="-4" max="4" value="0">
                </div>
                <div class="small text-muted peso-factor">1,00× posibilidad</div>
            </td>
            <td>
                <div class="form-check">
                    <input class="form-check-input peso-activa" type="checkbox" value="1" checked>
                </div>
            </td>
            <td class="text-muted small">fuera del rango</td>
            <td class="text-muted small">—</td>
            <td class="text-muted small">—</td>
            <td><span class="badge bg-info text-white">manual</span></td>
            <td><input type="text" class="form-control form-control-sm peso-nota"
                       style="max-width: 180px" maxlength="190" placeholder="por qué existe"></td>
            <td class="text-muted small">sin guardar</td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-borrar="">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        </tr>
    </template>
@endsection

@section('scripts')
    <script>
        const RUTAS = {
            guardar: '{{ route('lupita.scoring.pesos.guardar') }}',
            sugerir: '{{ route('lupita.scoring.pesos.sugerir') }}',
            aplicar: '{{ route('lupita.scoring.pesos.aplicar') }}',
            copiar: '{{ route('lupita.scoring.pesos.copiar') }}',
        };
        const CSRF = '{{ csrf_token() }}';
        const QUERY = new URLSearchParams(window.location.search);

        // hay un contador por pestana: se actualizan todos a la vez
        const avisar = (t, clase) => document.querySelectorAll('.contador-sucias').forEach(el => {
            el.textContent = t;
            if (clase !== undefined) el.className = clase;
        });
        const borrados = new Set();
        function texto(r, sel, porDefecto = '') {
            const e = r.querySelector(sel);
            return e ? e.value : porDefecto;
        }

        function numero(r) {
            return parseFloat(texto(r, '.peso-num', '0')) || 0;
        }

        function gramDe(palabra) {
            return Math.min(5, Math.max(1, (palabra || '').trim().split(/\s+/).filter(Boolean).length));
        }

        // una fila esta sucia si algo de lo que el servidor guardo cambio en pantalla
        function estaSucia(r) {
            if (r.dataset.nuevo === '1') {
                return true;
            }

            if (Math.abs(numero(r) - parseFloat(r.dataset.pesoOriginal)) > 0.0005) return true;
            if ((r.querySelector('.peso-activa').checked ? 1 : 0) !== parseInt(r.dataset.activaOriginal)) return true;
            if (texto(r, '.peso-nota') !== (r.dataset.notaOriginal || '')) return true;

            return false;
        }

        function refrescarFila(r) {
            const peso = numero(r);
            const slider = r.querySelector('.peso-slider');
            const num = r.querySelector('.peso-num');
            const factor = r.querySelector('.peso-factor');

            if (slider) slider.value = peso;
            if (num) num.value = peso;
            if (factor) factor.textContent = Math.exp(peso).toFixed(2) + '× posibilidad';

            const palabra = r.querySelector('.peso-palabra');
            if (palabra) {
                const gram = r.querySelector('.peso-gram');
                if (gram) gram.textContent = gramDe(palabra.value);
            }

            r.classList.toggle('table-warning', estaSucia(r));
            contarSucias();
        }

        function contarSucias() {
            const sucias = [...document.querySelectorAll('tr[data-fila="1"]')]
                .filter(r => !r.classList.contains('fila-borrada') && estaSucia(r)).length;

            avisar(sucias
                ? sucias + ' fila' + (sucias === 1 ? '' : 's') + ' pendiente' + (sucias === 1 ? '' : 's')
                : 'sin cambios pendientes',
                'small ' + (sucias ? 'text-warning' : 'text-muted'));
        }

        function filas() {
            return [...document.querySelectorAll('tr[data-fila="1"]')].filter(r => !r.classList.contains('fila-borrada'));
        }

        document.addEventListener('input', ev => {
            const r = ev.target.closest('tr[data-fila="1"]');
            if (!r) return;

            // numero y slider son la misma variable: se copian el uno al otro
            if (ev.target.matches('.peso-num, .peso-slider')) {
                const otro = ev.target.matches('.peso-num')
                    ? r.querySelector('.peso-slider') : r.querySelector('.peso-num');
                if (otro) otro.value = ev.target.value;
            }

            refrescarFila(r);
        });

        document.addEventListener('change', ev => {
            const r = ev.target.closest('tr[data-fila="1"]');
            if (r) refrescarFila(r);
        });

        // ---------- alta ----------
        document.addEventListener('click', ev => {
            const agregar = ev.target.closest('[data-agregar]');
            if (agregar) {
                const clave = agregar.dataset.agregar;
                const cuerpo = document.querySelector('table[data-tabla="' + clave + '"] tbody');
                const vacia = cuerpo.querySelector('tr.tabla-vacia');
                if (vacia) vacia.remove();

                const copia = document.getElementById('tpl-fila').content.firstElementChild.cloneNode(true);
                copia.dataset.tipo = clave === 'G' ? '' : clave;
                cuerpo.prepend(copia);
                refrescarFila(copia);
                copia.querySelector('.peso-palabra').focus();
                return;
            }

            const borr = ev.target.closest('[data-borrar]');
            if (borr) {
                const r = borr.closest('tr');
                if (r.dataset.nuevo === '1') {
                    r.remove();
                } else {
                    r.classList.add('fila-borrada');
                    r.style.display = 'none';
                    borrados.add(r.dataset.id);
                }
                contarSucias();
            }
        });

        // ---------- guardado ----------
        const guardarCambios = async () => {
            const cambios = filas()
                .filter(estaSucia)
                .map(r => ({
                    id: r.dataset.id ? parseInt(r.dataset.id) : null,
                    palabra: r.dataset.nuevo === '1'
                        ? r.querySelector('.peso-palabra').value.trim()
                        : r.dataset.palabra,
                    gram: parseInt(r.dataset.gram || 0) || null,
                    llamada_tipo_id: r.dataset.tipo === '' ? null : parseInt(r.dataset.tipo),
                    peso: numero(r),
                    activa: r.querySelector('.peso-activa').checked ? 1 : 0,
                    nota: texto(r, '.peso-nota'),
                }))
                .filter(c => c.peso !== null && (c.id || c.palabra));

            if (!cambios.length && !borrados.size) {
                avisar('no hay nada que guardar', 'small text-muted');
                return;
            }

            const resp = await fetch(RUTAS.guardar, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                },
                body: JSON.stringify({cambios, borrar: [...borrados]}),
            });

            const data = await resp.json();

            if (!resp.ok) {
                avisar('error al guardar: ' + JSON.stringify(data), 'small text-danger');
                return;
            }

            borrados.clear();
            filas().forEach(r => {
                if (r.dataset.nuevo === '1') {
                    // las altas quedan como filas normales pero sin id conocido:
                    // se recarga para volver a tomar el estado de la base
                    r.classList.add('fila-nueva');
                }
                r.dataset.pesoOriginal = numero(r);
                r.dataset.activaOriginal = r.querySelector('.peso-activa').checked ? 1 : 0;
                r.dataset.notaOriginal = texto(r, '.peso-nota');
                r.classList.remove('table-warning');
            });

            const partes = [
                (data.creados || 0) + ' alta(s)',
                (data.actualizados || 0) + ' edición(es)',
                (data.borrados || 0) + ' baja(s)',
            ];
            if (data.errores && data.errores.length) partes.push('⚠ ' + data.errores.join(', '));

            avisar('guardado: ' + partes.join(' · ')
                + ' — los puntajes del rango quedaron obsoletos, recalcule', 'small text-warning');

            // las filas de alta nueva no tienen id conocido: se recarga la pagina
            // para volver a leer la tabla desde la base
            if (document.querySelector('tr.fila-nueva')) window.location.reload();
        };

        // el boton esta repetido en cada pestana: se engancha a todos (guarda
        // todas las filas sucias del documento, no solo las de la pestana activa)
        document.querySelectorAll('[data-guardar]')
            .forEach(b => b.addEventListener('click', guardarCambios));
        // ---------- sugerencias ----------
        document.getElementById('btn-sugerir').addEventListener('click', async () => {
            const cuerpo = document.getElementById('sugerir-cuerpo');
            const estado = document.getElementById('estado-sugerir');

            cuerpo.innerHTML = '<div class="text-muted small">Contando el rango… puede tardar unos segundos.</div>';
            estado.textContent = 'analizando';

            const q = new URLSearchParams(QUERY);
            q.set('max_gram', document.getElementById('max_gram_sugerir').value);
            q.set('limite', '25');

            let data;
            try {
                const resp = await fetch(RUTAS.sugerir + '?' + q.toString(), {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF},
                });
                data = await resp.json();
            } catch (e) {
                cuerpo.innerHTML = '<div class="text-danger small">falló el cálculo: ' + e + '</div>';
                estado.textContent = '';
                return;
            }

            estado.textContent = data.segundos + ' s de conteo';

            const nombres = data.nombres || {};
            const grupos = data.grupos || {};
            const orden = Object.keys(grupos).sort((a, b) => (a === 'G' ? -1 : b === 'G' ? 1 : a - b));

            let html = '';
            let total = 0;

            for (const clave of orden) {
                const lista = grupos[clave];
                if (!lista || !lista.length) continue;

                html += '<h6 class="mt-3 mb-1">' + (clave === 'G' ? 'Global' : nombres[clave] || ('tipo ' + clave))
                    + ' <span class="badge bg-secondary">' + lista.length + '</span></h6>';
                html += '<table class="table table-sm table-hover align-middle mb-2">'
                    + '<thead class="thead-lupita"><tr class="small"><th></th><th>Palabra</th><th>N</th><th>Peso sugerido</th>'
                    + '<th>En exitosas</th><th>En fallidas</th><th>z</th><th>Alcance</th><th>Ya existe</th></tr></thead><tbody>';

                for (const p of lista) {
                    total++;
                    const color = p.peso >= 0 ? 'success' : 'danger';
                    html += '<tr>'
                        + '<td><input class="form-check-input sug-check" type="checkbox" value="1"'
                        + (p.existe ? ' checked' : '') + ' data-palabra="'
                        + p.palabra + '" data-gram="' + p.gram + '" data-tipo="'
                        + (p.tipo === null ? '' : p.tipo) + '" data-peso="' + p.peso + '"></td>'
                        + '<td class="text-white">' + p.palabra + '</td>'
                        + '<td>' + p.gram + '</td>'
                        + '<td><span class="badge bg-' + color + '">'
                        + (p.peso > 0 ? '+' : '') + p.peso.toFixed(2) + '</span></td>'
                        + '<td>' + p.e + '</td>'
                        + '<td>' + p.f + '</td>'
                        + '<td>' + (p.z === null ? '—' : p.z.toFixed(2)) + (p.sig || '') + '</td>'
                        + '<td class="small">' + p.origen + '</td>'
                        + '<td>' + (p.existe ? '<span class="badge bg-warning text-white">sí</span>' : '') + '</td>'
                        + '</tr>';
                }

                html += '</tbody></table>';
            }

            if (!html) {
                html = '<div class="text-muted small">No salieron candidatas en este rango: suba <em>max_gram</em>, '
                    + 'ampe el rango o revise el filtro de tipo.</div>';
            }

            cuerpo.innerHTML = html;
            document.getElementById('sugerir-nota').textContent = total
                ? total + ' candidatas listas · vienen marcadas las que ya están en la tabla'
                    + ' (origen «sugerida»; las manuales no se pisan)'
                : '';
        });

        document.getElementById('btn-aplicar-sugeridas').addEventListener('click', async () => {
            const marcadas = [...document.querySelectorAll('.sug-check:checked')].map(c => ({
                palabra: c.dataset.palabra,
                gram: parseInt(c.dataset.gram),
                llamada_tipo_id: c.dataset.tipo === '' ? null : parseInt(c.dataset.tipo),
                peso: parseFloat(c.dataset.peso),
            }));

            if (!marcadas.length) {
                document.getElementById('sugerir-nota').textContent = 'marque al menos una casilla';
                return;
            }

            const resp = await fetch(RUTAS.aplicar, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({lista: marcadas}),
            });

            const data = await resp.json();

            if (data.creados || data.actualizados) {
                window.location.reload();
                return;
            }

            document.getElementById('sugerir-nota').textContent =
                'nada nuevo: ' + (data.omitidos || 0) + ' ya estaban como manuales'
                + (data.errores && data.errores.length ? ' · ' + data.errores.join(', ') : '');

            bootstrap.Modal.getInstance(document.getElementById('modal-sugerir'))?.hide();
        });

        // ---------- copiar set ----------
        document.getElementById('btn-copiar').addEventListener('click', async () => {
            const desde = document.getElementById('copiar-desde').value;
            const hacia = document.getElementById('copiar-hacia').value;
            const estado = document.getElementById('copiar-estado');

            if (desde === hacia) {
                estado.textContent = 'origen y destino son el mismo alcance';
                return;
            }

            const q = new URLSearchParams();
            if (desde !== '') q.set('desde', desde);
            if (hacia !== '') q.set('hacia', hacia);

            const resp = await fetch(RUTAS.copiar + '?' + q.toString(), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF},
            });

            const data = await resp.json();
            estado.textContent = (data.copiadas || 0) + ' entradas copiadas, recargando…';

            setTimeout(() => window.location.reload(), 600);
        });
    </script>
@endsection
