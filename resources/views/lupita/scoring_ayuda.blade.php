@extends('layouts.app')

@section('title', 'Cómo funciona el scoring')

@section('content')

    @php
        use App\Database\DBPalabrasLlamadas as Palabras;
        use App\Database\DBScoringLlamadas as Scoring;

        $query = request()->query();
        unset($query['palabra']);
        $url_pesos     = route('lupita.scoring.pesos', $query);
        $url_eval      = route('lupita.scoring', $query);
        $url_palabras  = route('lupita.palabras', $query);
        $url_calcular  = route('lupita.scoring.calcular', $query);

        // ---- ejemplo arithmetic de la fórmula, con números redondos ----
        $ej_base   = 33.0;                                   // base medida de "Fuera de planta para carga"
        $ej_pesos  = ['todo bien' => 2.346, 'ya vengo' => -0.50];
        $ej_suma   = array_sum($ej_pesos);
        $ej_logit0 = Scoring::logit($ej_base);
        $ej_logit  = $ej_logit0 + $ej_suma;
        $ej_punt   = max(0.5, min(99.5, Scoring::invertido($ej_logit)));

        $total_activos = count($activos['global'])
            + array_sum(array_map('count', $activos['tipos']));
    @endphp

    <div class="row mb-3">
        <div class="col-12">
            <h1><i class="bi bi-question-circle"></i> Cómo funciona el scoring de llamadas</h1>
            <p class="text-light mb-0">
                Documentación del modelo que puntúa el <strong class="text-white">porcentaje de éxito</strong> de cada
                llamada a partir de las palabras y frases que dijo el conductor. Está escrita sobre el mismo código que
                corre en producción (<code>App\Database\DBScoringLlamadas</code>), así que cada número que ve acá es el
                que usa la pantalla de <a href="{{ $url_pesos }}" class="text-info">Palabras y pesos</a>.
            </p>
        </div>
    </div>

    {{-- ============ 1. la idea ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 border-info">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-lightbulb"></i> El modelo en una frase</span>
                </div>
                <div class="card-body py-2">
                    <p class="text-light small mb-2">
                        Cada tipo de llamada arranca con una <strong class="text-white">base</strong> (su tasa real de
                        éxito medida en el rango). Sobre esa base, cada palabra que dijo el conductor
                        <strong class="text-white">empuja el puntaje hacia arriba o hacia abajo</strong>.
                    </p>
                    <pre class="bg-dark text-white p-2 rounded small mb-0">logit(p) = logit(base del tipo) + Σ pesos que dispararon</pre>
                    <div class="small text-muted mt-2">
                        Se trabaja en <strong class="text-light">logits</strong> (la unidad de una regresión logística),
                        no en puntos porcentuales. Al final una sigmoide devuelve un porcentaje entre 0 y 100, por eso
                        dos palabras optimistas nunca empujan el puntaje por encima de 100 %.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-secondary">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-calculator"></i> Ejemplo paso a paso</span>
                </div>
                <div class="card-body py-2">
                    <div class="small text-light">
                        <div class="mb-1">
                            1. Base medida del tipo:
                            <strong class="text-white">{{ number_format($ej_base, 1) }} %</strong>
                            <span class="text-muted">→ logit {{ number_format($ej_logit0, 3, ',', '.') }}</span>
                        </div>
                        @foreach($ej_pesos as $pal => $p)
                            <div class="mb-1">
                                2. Dispara «{{ $pal }}»:
                                <span class="badge bg-{{ $p >= 0 ? 'success' : 'danger' }}">{{ Scoring::peso_texto($p) }}</span>
                                <span class="text-muted">({{ Scoring::factor($p) }} posibilidad)</span>
                            </div>
                        @endforeach
                        <div class="mb-1">
                            3. Suma de pesos: <strong class="text-white">{{ Scoring::peso_texto($ej_suma) }}</strong>
                            → logit final {{ number_format($ej_logit, 3, ',', '.') }}
                        </div>
                        <div>
                            4. Sigmoide inversa →
                            <span class="badge bg-{{ Scoring::color_puntaje($ej_punt) }}">
                                {{ number_format($ej_punt, 1) }} % de éxito
                            </span>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">
                        Partiendo de 33 %, la frase muy positiva «todo bien» domina y el modelo sube el veredicto;
                        «ya vengo» tira en contra pero pesa menos. Así se combinan evidencias sin saturarse.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 2. leer un peso ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-sliders"></i> Cómo se lee un peso</span>
                </div>
                <div class="card-body py-2">
                    <p class="small text-light mb-2">
                        El peso vive en la columna <em>Peso (logit)</em> y su lectura es un
                        <strong class="text-white">multiplicador de la posibilidad (odds)</strong>:
                        <code class="text-white">factor = e^peso</code>. El slider y el número son la misma variable.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="thead-lupita">
                            <tr class="small">
                                <th>Peso</th>
                                <th>Factor</th>
                                <th>Qué significa</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach([4.0, 0.69, 0.0, -0.69, -4.0] as $p)
                                <tr>
                                    <td><span class="badge bg-{{ $p >= 0 ? 'success' : 'danger' }}">{{ Scoring::peso_texto($p) }}</span></td>
                                    <td class="text-white">{{ Scoring::factor($p) }}</td>
                                    <td class="small text-light">
                                        @if($p > 0.68) mucho más probable el éxito
                                        @elseif($p > 0.001) sube la posibilidad de éxito
                                        @elseif($p == 0.0) neutra: existe pero no mueve nada
                                        @elseif($p > -0.68) baja la posibilidad de éxito
                                        @else mucho más probable la fallida
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-muted mt-2">
                        <span class="text-success"><strong>Verde</strong></span> = palabra asociada a éxito (sube el
                        puntaje). <span class="text-danger"><strong>Rojo</strong></span> = palabra asociada a fracaso
                        (baja el puntaje): es la que hay que evaluar como señal de llamada fallida.
                        El techo editable es <strong class="text-light">±4</strong> (constante <code>PESO_MAX</code>)
                        para que un desliz no convierta la base en ruido.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100 border-secondary">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-baselayer"></i> La base medida por tipo</span>
                    <span class="small text-muted ms-1">(rango actual)</span>
                </div>
                <div class="card-body py-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-lupita">
                            <tr class="small">
                                <th>Tipo</th>
                                <th>Llamadas</th>
                                <th>Base éxito</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($tipos as $t)
                                <tr>
                                    <td class="small text-light">
                                        {{ $t->id === null ? 'Global' : $t->nombre }}
                                    </td>
                                    <td>{{ number_format($t->n) }}</td>
                                    <td>
                                        <span class="badge bg-info text-white">
                                            {{ $t->pct === null ? '—' : number_format($t->pct, 1) . ' %' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-muted p-2">
                        La base <strong class="text-light">no se escribe a mano</strong>: se mide sobre las llamadas del
                        rango filtrado. El tipo <strong class="text-light">Indeterminada (id 0)</strong> queda excluido:
                        no tiene desenlace clasificable y contaminaría las métricas.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 3. de donde salen los pesos ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-magic"></i> De dónde salen los pesos</span>
                </div>
                <div class="card-body py-2">
                    <div class="row g-3 small text-light">
                        <div class="col-md-4">
                            <h6 class="text-white">1 · Sugerir desde Palabras clave</h6>
                            <p class="mb-1">
                                Lee el ranking de <a href="{{ $url_palabras }}" class="text-info">lupita/palabras</a>
                                (exitosas vs fallidas) y propone un peso de salida:
                            </p>
                            <pre class="bg-dark text-white p-2 rounded mb-1">peso = log(OR) · n / (n + 30)</pre>
                            <div class="text-muted">
                                <code>log(OR)</code> = cuánto más frecuente es la palabra entre las exitosas.
                                El factor <code>n/(n+30)</code> (<code>SHRINK</code>) encoge las palabras raras para que
                                una palabra con pocas llamadas no hunda el puntaje.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-white">2 · Agregar a mano</h6>
                            <p class="mb-1">
                                Usted escribe la palabra o frase y su peso. Sale con <em>origen «manual»</em>, y una fila
                                manual <strong class="text-white">nunca la pisa</strong> una sugerencia automática.
                            </p>
                            <h6 class="text-white mt-2">3 · Copiar un set</h6>
                            <p class="mb-0">
                                Duplica las entradas activas de un alcance a otro (p. ej. de Global a un tipo) sin tocar
                                lo que ya existe en el destino.
                            </p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-white">Qué se descarta al sugerir</h6>
                            <ul class="mb-0 ps-3">
                                <li><strong class="text-white">Eco de la IA:</strong> una palabra está en ≥ {{ Palabras::UMBRAL_ECO }} % de las llamadas porque la IA la pregunta. No informa nada.</li>
                                <li><strong class="text-white">Stopwords:</strong> conectores vacíos (mismo listado que la página de palabras).</li>
                                <li><strong class="text-white">Poco apoyo:</strong> menos de {{ Scoring::MIN_APOYO }} llamadas no es candidata.</li>
                                <li><strong class="text-white">No significativo:</strong> si la z no supera el umbral, no se ofrece.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 4. columnas de la tabla ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-table"></i> Qué dice cada columna de la tabla de pesos</span>
                </div>
                <div class="card-body py-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="thead-lupita">
                            <tr class="small">
                                <th>Columna</th>
                                <th>Significado</th>
                            </tr>
                            </thead>
                            <tbody class="small text-light">
                            <tr><td class="text-white">Palabra o frase</td><td>El token normalizado (minúsculas, sin acentos). <em>N</em> indica cuántas palabras tiene (1 = suelta, 2-5 = frase).</td></tr>
                            <tr><td class="text-white">Peso (logit)</td><td>Lo que usted fija. El texto de abajo («…× posibilidad») es el factor <code>e^peso</code> ya traducido.</td></tr>
                            <tr><td class="text-white">Activa</td><td>Si entra o no al cálculo. Desactivarla la deja fuera sin borrarla.</td></tr>
                            <tr><td class="text-white">Dispara</td><td>Cuántas llamadas del rango contuvieron esa palabra (y cuántas fueron éxito).</td></tr>
                            <tr><td class="text-white">% real</td><td>Tasa de éxito <em>real</em> entre esas llamadas donde disparó.</td></tr>
                            <tr><td class="text-white">Sugerido / delta</td><td>El peso que piden los datos, y la diferencia <em>delta = sugerido − su peso</em>.</td></tr>
                            <tr><td class="text-white">Origen</td><td><span class="badge bg-info text-white">manual</span> escrita por usted · <span class="badge bg-secondary">sugerida</span> nacida del botón Sugerir.</td></tr>
                            <tr><td class="text-white">Nota</td><td>Por qué existe esa palabra (memo libre, no afecta el cálculo).</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-2 small text-muted">
                        <strong class="text-warning">«peso que miente»</strong> = delta grande (≥ 0,25) con apoyo
                        suficiente: la palabra está en la tabla con un peso que los datos no respaldan. Se marca en
                        <span class="badge bg-warning text-white">Δ</span> amarillo y conviene revisarlo.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 5. exclusiones + versionado ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 border-danger">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-slash-circle"></i> Qué NO se puntúa</span>
                </div>
                <div class="card-body py-2 small text-light">
                    <ul class="mb-0 ps-3">
                        <li><strong class="text-white">Tipo Indeterminada (id 0):</strong> la IA no pudo clasificar; sale
                            de la base, del universo y de las sugerencias.</li>
                        <li><strong class="text-white">Buzón de voz</strong> (<code>buzon_de_voz = 1</code>): es un
                            contestador; su efecto ya está dentro de la base del tipo. Sale con motivo
                            «{{ Scoring::$motivos['buzon'] }}» y <strong class="text-white">puntaje vacío</strong>.</li>
                        <li><strong class="text-white">Sin texto del conductor:</strong> el modelo no opina; su puntaje
                            queda igual a la base del tipo (motivo «{{ Scoring::$motivos['sin_texto'] }}»).</li>
                        <li>Solo se lee el texto <strong class="text-white">humano</strong> (mensajes <code>USER</code>);
                            el guion de la IA nunca cuenta como evidencia.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-warning">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-arrow-repeat"></i> Versionado y «Calcular rango»</span>
                </div>
                <div class="card-body py-2 small text-light">
                    <p class="mb-2">
                        El puntaje se <strong class="text-white">guarda</strong> en <code>scoring_llamadas</code> y lleva
                        la huella de los pesos con que se calculó (<code>pesos_version</code>):
                    </p>
                    <pre class="bg-dark text-white p-2 rounded mb-2">versión actual: {{ mb_substr($version, 0, 12) }}…</pre>
                    <p class="mb-2">
                        Al editar un peso, la huella cambia y las llamadas guardadas quedan
                        <strong class="text-warning">obsoletas</strong>
                        (
                            @if($obsoletas > 0)
                                <strong class="text-white">{{ number_format($obsoletas) }}</strong> ahora mismo
                            @else
                                ninguna ahora mismo
                            @endif
                        ).
                        No se recalculan solas: se aprieta
                        <strong class="text-white">Calcular rango</strong> cuando conviene.
                    </p>
                    <div class="text-muted">
                        Así la vista de evaluación es barata de abrir aunque el rango tenga
                        {{ number_format($bases[0]->n ?? 0) }} llamadas: lee los puntajes ya guardados.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 6. metricas ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-graph-up"></i> Cómo se evalúa contra el humano</span>
                    <a href="{{ $url_eval }}" class="btn btn-sm btn-outline-info ms-auto">Abrir Modelo vs humano</a>
                </div>
                <div class="card-body py-2">
                    <div class="row g-3 small text-light">
                        <div class="col-md-3">
                            <h6 class="text-white">Concordancia</h6>
                            % de llamadas donde el modelo y el humano coinciden, cortando el puntaje por el umbral
                            ({{ implode(' / ', Scoring::$umbrales) }} %).
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-white">Matriz de confusión</h6>
                            TP / FP / FN / TN entre «modelo dice éxito» y «humano dice éxito». Solo cuentan las que
                            tienen texto del conductor.
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-white">AUC</h6>
                            Qué tan bien <em>ordena</em> el modelo (Mann-Whitney): 0,5 es azar, 1 es perfecto. Mide
                            capacidad de separar éxito de fracaso sin importar el umbral.
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-white">Calibración (deciles)</h6>
                            Divide las llamadas en 10 grupos por puntaje y compara el % que predice el modelo con el %
                            real. Si se despega, el problema es de calibración (pesos sobredimensionados), no de orden.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 7. flujo recomendado ============ --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-signpost-split"></i> Flujo de trabajo recomendado</span>
                </div>
                <div class="card-body py-2">
                    <ol class="small text-light mb-0">
                        <li>Filtre el <strong class="text-white">rango</strong> (fechas, tipo, ruta) en
                            <a href="{{ $url_pesos }}" class="text-info">Palabras y pesos</a>.</li>
                        <li>Abra <strong class="text-white">Sugerir desde Palabras clave</strong>: sale una vista previa
                            con casillas (nada se escribe todavía). Las que ya están salen marcadas.</li>
                        <li>Aplique las marcadas; los pesos entran con origen «sugerida».</li>
                        <li>Ajuste a mano los que vea raros y rellene la <em>Nota</em> (por qué existe).</li>
                        <li>Pulse <strong class="text-white">Calcular rango</strong> para puntuar con la nueva versión.</li>
                        <li>Vaya a <a href="{{ $url_eval }}" class="text-info">Modelo vs humano</a>: revise concordancia,
                            AUC y los desacuerdos; escuche el audio de los casos dudosos en Etiquetar.</li>
                        <li>Corrija los <span class="text-warning">pesos que mienten</span> y repita.</li>
                    </ol>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100 border-secondary">
                <div class="card-header py-2">
                    <span class="small fw-bold text-white"><i class="bi bi-braces"></i> Constantes del modelo</span>
                </div>
                <div class="card-body py-2">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-lupita">
                            <tr class="small"><th>Constante</th><th>Valor</th></tr>
                            </thead>
                            <tbody class="small">
                            <tr><td class="text-light">SHRINK (amortiguación)</td><td class="text-white">{{ Scoring::SHRINK }}</td></tr>
                            <tr><td class="text-light">MIN_APOYO</td><td class="text-white">{{ Scoring::MIN_APOYO }}</td></tr>
                            <tr><td class="text-light">PESO_MAX</td><td class="text-white">± {{ Scoring::PESO_MAX }}</td></tr>
                            <tr><td class="text-light">UMBRAL_ECO (IA)</td><td class="text-white">{{ Palabras::UMBRAL_ECO }} %</td></tr>
                            <tr><td class="text-light">LOTE_LLAMADAS</td><td class="text-white">{{ Scoring::LOTE_LLAMADAS }}</td></tr>
                            <tr><td class="text-light">MAX_FILAS</td><td class="text-white">{{ Scoring::MAX_FILAS }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ 8. estado actual + accesos ============ --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-dark-subtle border-secondary">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
                    <div class="small text-light">
                        Estado de su base de conocimiento:
                        <strong class="text-white">{{ $total_activos }}</strong> palabras activas
                        ({{ count($activos['global']) }} globales) puntuando
                        <strong class="text-white">{{ number_format($bases[0]->n ?? 0) }}</strong> llamadas del rango.
                    </div>
                    <div class="ms-auto d-flex flex-wrap gap-2">
                        <a href="{{ $url_pesos }}" class="btn btn-sm btn-primary"><i class="bi bi-key"></i> Palabras y pesos</a>
                        <a href="{{ $url_eval }}" class="btn btn-sm btn-outline-info"><i class="bi bi-graph-up"></i> Modelo vs humano</a>
                        <a href="{{ $url_palabras }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Análisis de palabras</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
