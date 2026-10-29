@extends('layouts.app')

@section('title', 'Palabras clave por resultado de la llamada')

@section('content')

    @php
        // enlace que conserva los filtros activos y pide la evidencia de una palabra
        $url_palabra = function ($palabra) {
            $params = request()->query();
            unset($params['palabra'], $params['metrica']);
            $params['palabra'] = $palabra;

            return url()->current() . '?' . http_build_query($params);
        };

        // enlace de una pestana, conservando los filtros
        $url_metrica = function ($metrica) {
            $params = request()->query();
            unset($params['palabra']);
            $params['metrica'] = $metrica;

            return url()->current() . '?' . http_build_query($params);
        };

        // enlace para volver a contar solo el texto de una de las partes
        $url_sin_palabra = function ($fuente) {
            $params = request()->query();
            unset($params['palabra'], $params['metrica']);
            $params['fuente'] = $fuente;

            return url()->current() . '?' . http_build_query($params);
        };

        // enlace que investiga un codigo del revisor: fuerza la fuente con notas
        // y las frases necesarias para que la expresion exista en el ranking
        $url_codigo = function ($codigo, int $gram) {
            $params = request()->query();
            unset($params['metrica']);

            $fuente_actual = request('fuente') ?: 'usuario';

            $params['palabra'] = $codigo;
            $params['max_gram'] = max((int) request('max_gram'), $gram);
            // si se estaba leyendo solo al conductor, hay que abrir la nota para
            // que el codigo investigado pueda aparecer en el texto
            $params['fuente'] = $fuente_actual === 'usuario' ? 'usuario_notas' : $fuente_actual;

            return url()->current() . '?' . http_build_query($params);
        };

        $codigos = $datos->codigos;
        $sugerencias = $datos->sugerencias;
        $buzones = $datos->buzones;
        $filas_codigo = (int) ceil(count($codigos->lista) / 2);

        $metrica_activa = in_array(request('metrica'), array_keys($analisis::$metricas))
            ? request('metrica') : 'lift';

        $max_freq_e = 0;
        $max_freq_f = 0;
        foreach ($vistas->frecuencia['exitosas'] as $p) {
            $max_freq_e = max($max_freq_e, $p->e);
        }
        foreach ($vistas->frecuencia['fallidas'] as $p) {
            $max_freq_f = max($max_freq_f, $p->f);
        }

        $max_share = 0;
        foreach ($vistas->lift as $p) {
            $max_share = max($max_share, $p->share_e, $p->share_f);
        }
        $max_share = max(0.1, $max_share);

        $max_tfidf_e = 0;
        $max_tfidf_f = 0;
        foreach ($vistas->tfidf['exitosas'] as $p) {
            $max_tfidf_e = max($max_tfidf_e, $p->tfidf_e);
        }
        foreach ($vistas->tfidf['fallidas'] as $p) {
            $max_tfidf_f = max($max_tfidf_f, $p->tfidf_f);
        }

        $total_palabras = count($datos->palabras);
    @endphp

    <div class="row">
        <div class="col-12">
            <h1>Palabras clave: llamadas exitosas vs fallidas</h1>
            <p class="text-light small mb-2">
                Se compara el texto de las transcripciones contra el resultado
                <strong class="text-white">revisado por un humano</strong> (<em>llamada exitosa</em> = 1,
                <em>fallida</em> = 0; las indeterminadas quedan fuera).
                Cada número es <strong class="text-white">cuántas llamadas contienen la palabra</strong>, no
                cuántas veces aparece: así un conductor que habla mucho no domina el ranking.
            </p>
            <p class="text-light small mb-2">
                <strong class="text-white">Las palabras solo salen del texto humano</strong>: de lo que dijo el
                conductor y/o de la nota que escribe el revisor (<em>analisis_audio</em>).
                El guion de la IA <strong class="text-white">nunca aporta palabras</strong>, solo sirve de
                comparación: la columna <em>IA también</em> dice en qué % de las llamadas con esa palabra
                la pronunció también la IA. Por encima de {{ $datos->umbral_eco }}% es un
                <span class="text-warning">eco</span>: la palabra existe porque la IA la pregunta, no porque
                el conductor la use, y no sirve como señal.
            </p>
            <p class="text-light small mb-2">
                Las tres pestañas usan el mismo conteo y responden cosas distintas:
                <strong class="text-white">frecuencia</strong> = lo más repetido en cada lado;
                <strong class="text-white">diferencia y lift</strong> = lo que <em>distingue</em> un lado del otro
                (una palabra puede ser común y no distinguir nada);
                <strong class="text-white">TF-IDF</strong> = lo típico de un grupo que no es típico del otro.
                El asterisco es la significancia estadística
                (<em>*</em> p&lt;0,05 · <em>**</em> p&lt;0,01 · <em>***</em> p&lt;0,001): sin asterisco
                la diferencia puede ser puro azar del muestreo.
            </p>
            <p class="text-light small mb-2">
                El aviso de <strong class="text-white">buzón de voz llega escrito como mensaje del conductor</strong>
                en la transcripción, por eso viene activado <em>excluir buzón</em>: sin eso,
                “buzón/mensaje/tono” aparecerían como las palabras típicas de las fallidas
                sin decir nada del chofer. El criterio es el campo
                <strong class="text-white">llamadas.buzon_de_voz</strong>, que fija quien escucha el audio
                (7 321 llamadas marcadas y <strong class="text-white">ninguna exitosa</strong>); el detector de
                frases de contestador queda solo como respaldo, porque reconoce menos llamadas y parte el aviso
                en pedazos que parecen habla humana (<em>«grabe su mensaje.»</em>, <em>«después el tono»</em>).
            </p>
            <p class="text-light small mb-2">
                <strong class="text-white"><em>buzon_de_voz = 1</em> siempre es buzón</strong>, sin matices. El
                sufijo <em>- NO</em> que algunos revisores escribían detrás
                (<code>BUZON DE VOZ - NO</code>) no niega el buzón: era el criterio de un usuario anterior para
                decir que la llamada era fallida, así que no se re-etiqueta nada por esa señal.
                La tarjeta <strong class="text-white">«Buzón de voz excluido»</strong> es clicable y abre un modal
                con las llamadas que el texto o la nota delatan como contestador y tienen la casilla en 0, para
                que un humano las escuche.
                Abajo están los
                <a href="#codigos-revisor" class="text-info">códigos que escribe el revisor</a> y las
                <a href="#sugerencias" class="text-info">palabras clave sugeridas</a>.
            </p>
        </div>
    </div>

    <div class="row">
        <form method="GET">
            <fieldset class="border p-3 rounded mb-3">
                <legend class="float-none w-auto px-2 fs-6">
                    Filtros del análisis
                </legend>

                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio"
                               value="{{ request('fecha_inicio') }}" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin"
                               value="{{ request('fecha_fin') }}" class="form-control">
                    </div>

                    <div class="col-md-3">
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

                    <div class="col-md-3">
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

                    <div class="col-md-3">
                        <label for="conductor" class="form-label">Conductor</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="conductor" name="conductor"
                                   value="{{ request('conductor') }}" class="form-control"
                                   placeholder="Conductor, id...">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="trt" class="form-label">Transportista</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <input type="text" id="trt" name="trt"
                                   value="{{ request('trt') }}" class="form-control"
                                   placeholder="Transportista, id...">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="universo">Qué entra al análisis</label>
                        <select name="universo" id="universo" class="form-control">
                            @foreach($analisis::$universos as $valor => $texto)
                                <option value="{{ $valor }}"
                                        @selected($datos->universo === $valor)>
                                    {{ $texto }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="fuente">De quién se leen las palabras</label>
                        <select name="fuente" id="fuente" class="form-control">
                            @foreach($analisis::$fuentes as $valor => $texto)
                                <option value="{{ $valor }}"
                                        @selected($datos->fuente === $valor)>
                                    {{ $texto }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text text-light">
                            La IA ya no es fuente de palabras. La nota del revisor es vocabulario
                            que él escribió sabiendo el desenlace: mezclarla con lo hablado infla la
                            separación, por eso también tiene su tabla aparte más abajo.
                            <a href="{{ $url_sin_palabra('usuario') }}" class="text-info">solo conductor</a>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="max_gram">Unidades</label>
                        <select name="max_gram" id="max_gram" class="form-control">
                            @foreach($analisis::$max_grams as $n => $texto)
                                <option value="{{ $n }}" @selected($datos->max_gram === $n)>
                                    {{ $texto }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text text-light">
                            Con 4 o 5 palabras salen frases completas, incluidas las de la nota del
                            revisor (<em>confirma y da motivos</em>).
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="min_llamadas">Mínimo de llamadas</label>
                        <select name="min_llamadas" id="min_llamadas" class="form-control">
                            @foreach($analisis::$soportes as $opcion)
                                <option value="{{ $opcion }}"
                                        @selected((string) $datos->soporte === (string) $opcion)>
                                    {{ $opcion }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            Fuera de esta lista quedan {{ number_format(max(0, $datos->palabras_totales - $total_palabras)) }}
                            palabras con menos presencia.
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label d-block">Filtro de buzón</label>
                        <div class="form-check">
                            {{-- el hidden deja constancia de la casilla desmarcada:
                                 sin el, desactivar el filtro equivale a no enviar el
                                 formulario y el excluidor volvia a activarse solo --}}
                            <input type="hidden" name="excluir_buzon" value="0">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="excluir_buzon" name="excluir_buzon"
                                   @checked($datos->excluir_buzon)>
                            <label class="form-check-label" for="excluir_buzon">
                                Excluir avisos de buzón
                            </label>
                        </div>
                        <div class="form-text small text-light">
                            El criterio es el campo <em>buzon_de_voz</em> de la llamada: si vale 1 se descarta
                            todo lo que dijo el “conductor” de esa grabación, porque era la contestadora. El
                            detector de frases queda solo como respaldo para las llamadas no marcadas.
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="palabra">Investigar palabra</label>
                        <input type="text" id="palabra" name="palabra"
                               value="{{ request('palabra') }}" class="form-control"
                               placeholder="p. ej. buzon" list="dl_palabras">
                        <datalist id="dl_palabras">
                            @foreach($vistas->lift as $p)
                                <option value="{{ $p->palabra }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <input type="hidden" name="metrica" value="{{ $metrica_activa }}">

                    <div class="col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> Analizar
                        </button>

                        <a href="{{ url()->current() }}" class="btn btn-secondary">
                            Limpiar
                        </a>
                    </div>

                </div>
            </fieldset>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-primary h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Llamadas analizadas</div>
                    <div class="fs-4 fw-bold text-white">{{ $datos->llamadas }}</div>
                    <div class="small text-light">{{ $datos->segundos }} s de cálculo</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-success h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Exitosas</div>
                    <div class="fs-4 fw-bold text-success">{{ $datos->llamadas_e }}</div>
                    <div class="small text-light">{{ $datos->con_texto_e }} con texto útil</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-danger h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Fallidas</div>
                    <div class="fs-4 fw-bold text-danger">{{ $datos->llamadas_f }}</div>
                    <div class="small text-light">{{ $datos->con_texto_f }} con texto útil</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Sin texto humano</div>
                    <div class="fs-4 fw-bold text-white">{{ $datos->sin_texto }}</div>
                    <div class="small text-light">
                        sin mensajes ni nota · {{ $datos->sin_usable }} sin palabra útil
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-info h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Notas del revisor</div>
                    <div class="fs-4 fw-bold text-info">{{ number_format($codigos->llamadas) }}</div>
                    <div class="small text-light">
                        con analisis_audio · {{ number_format($datos->con_nota) }} leídas por el ranking
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100">
                <div class="card-body py-2">
                    <div class="text-light small">Palabras en el ranking</div>
                    <div class="fs-4 fw-bold text-white">{{ number_format($total_palabras) }}</div>
                    <div class="small text-light">
                        de {{ number_format($datos->palabras_totales) }} distintas
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            {{-- toda la tarjeta es el disparador del modal: es el unico sitio donde
                 se ven las llamadas que el texto denuncia como buzon y nadie marco --}}
            <div class="card border-warning h-100" role="button" style="cursor: pointer"
                 data-bs-toggle="modal" data-bs-target="#modal-buzones"
                 title="Clic para ver las llamadas que parecen buzón y no están marcadas">
                <div class="card-body py-2">
                    <div class="text-light small">Buzón de voz excluido</div>
                    <div class="fs-4 fw-bold text-warning">
                        {{ number_format($datos->llamadas_buzon_campo) }}
                    </div>
                    <div class="small text-light">
                        llamadas con <em>buzon_de_voz = 1</em> ·
                        {{ number_format($datos->mensajes_buzon_campo) }} mensajes fuera
                        @if ($datos->mensajes_buzon > $datos->mensajes_buzon_ia)
                            · {{ number_format($datos->mensajes_buzon - $datos->mensajes_buzon_ia) }} más
                            detectados por el texto
                        @endif
                        <div class="text-white">
                            <i class="bi bi-hand-index-thumb"></i>
                            {{ number_format($buzones->total) }} parecen buzón sin marcar
                            <span class="text-light">— clic</span>
                        </div>
                        @if (!$datos->excluir_buzon)
                            <div class="text-warning">el filtro está apagado: entran al ranking</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($datos->palabra_filtrada !== '')
        <div class="row mb-3">
            <div class="col-12">
                <div class="card border-info">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            Evidencia de
                            <strong>“{{ $datos->palabra_filtrada }}”</strong>
                            en {{ count($datos->ejemplos) }} llamadas
                            (se muestran hasta {{ $datos->max_ejemplos }})
                        </span>
                        <a href="{{ $url_metrica($metrica_activa) }}" class="btn btn-sm btn-outline-secondary">
                            Quitar la palabra
                        </a>
                    </div>
                    <div class="card-body p-0">
                        @if (count($datos->ejemplos))
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Conductor</th>
                                        <th>Resultado</th>
                                        <th>Fragmentos donde aparece</th>
                                        <th class="text-end">Audio</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($datos->ejemplos as $ej)
                                        <tr>
                                            <td class="small text-nowrap text-light">
                                                {{ \Carbon\Carbon::parse($ej->created_at)->format('d/m/y H:i') }}
                                                <div class="text-light">{{ $ej->conductor }}</div>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $ej->llamada_exitosa ? 'success' : 'danger' }}">
                                                    {{ $ej->llamada_exitosa ? 'exitosa' : 'fallida' }}
                                                </span>
                                                <div class="small text-light">{{ $ej->trt }}</div>
                                            </td>
                                            <td class="small text-light">
                                                {{ $ej->audio_duracion }} s
                                            </td>
                                            <td>
                                                @foreach($ej->fragmentos as $fr)
                                                    <div class="small text-light">{{ $fr }}</div>
                                                @endforeach
                                                @if (trim((string) $ej->analisis_audio) !== '')
                                                    <div class="small text-info">
                                                        <strong>analisis_audio:</strong> {{ $ej->analisis_audio }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($ej->audio_link)
                                                    <a href="{{ $ej->audio_link }}" target="_blank"
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-play-circle"></i>
                                                    </a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-light small m-3">
                                Ninguna llamada del filtro contiene esa palabra con estos filtros
                                (revisa mayúsculas/acentos o el mínimo de llamadas).
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ CODIGOS QUE ESCRIBE EL REVISOR (analisis_audio) ============ --}}
    <div class="row mb-3" id="codigos-revisor">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>
                        <strong class="text-white">Clasificación escrita por el revisor</strong>
                        <span class="text-light small">(<em>analisis_audio</em>, {{ $codigos->min }}+ llamadas)</span>
                    </span>
                    <span class="small text-light">
                        {{ number_format($codigos->llamadas) }} llamadas con nota
                        ({{ $analisis::porcentaje($codigos->cobertura) }} del filtro) ·
                        tasa de éxito entre ellas
                        <strong class="text-white">{{ $analisis::porcentaje($codigos->base) }}</strong>
                    </span>
                </div>
                <div class="card-body p-0">
                    @if (count($codigos->lista))
                        <div class="row g-0">
                            @foreach(array_chunk($codigos->lista, $filas_codigo) as $grupo)
                                <div class="col-lg-6">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Código del revisor</th>
                                                <th class="text-end">Llamadas</th>
                                                <th class="text-end">% exitosa</th>
                                                <th class="text-end" title="Puntos porcentuales respecto a la media de las llamadas con nota">vs media</th>
                                                <th class="text-center">Sig.</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($grupo as $r)
                                                <tr>
                                                    <td>
                                                        <a href="{{ $url_codigo($r->buscar, max(1, $r->gram)) }}"
                                                           class="text-decoration-none text-white">
                                                            {{ $r->codigo }}
                                                        </a>
                                                        <span class="text-light small">
                                                            · {{ $r->e }} éxit / {{ $r->f }} fall
                                                        </span>
                                                    </td>
                                                    <td class="text-end text-white">{{ number_format($r->n) }}</td>
                                                    <td class="text-end text-{{ $r->pct_e >= 50 ? 'success' : 'danger' }}">
                                                        {{ $analisis::porcentaje($r->pct_e, 0) }}
                                                    </td>
                                                    <td class="text-end fw-bold text-{{ $analisis::color_lado($r->diff) }}">
                                                        {{ $r->diff > 0 ? '+' : '' }}{{ number_format($r->diff, 1) }} pp
                                                    </td>
                                                    <td class="text-center small text-light"
                                                        title="{{ is_null($r->z) ? 'sin prueba' : 'z = ' . number_format($r->z, 2) }}">
                                                        {{ $r->sig ?: '—' }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-light small m-3">
                            Ningún código alcanza {{ $codigos->min }} llamadas con estos filtros.
                        </p>
                    @endif
                </div>
                <div class="card-footer py-2 small text-light">
                    <strong class="text-white">Cómo leerla:</strong> estas etiquetas se asignan sabiendo ya el
                    desenlace, así que separan casi perfecto (<em>CONFIRMA</em> casi todas exitosas,
                    <em>NO HABLA</em> ninguna) y por eso <strong class="text-white">no van mezcladas con las
                    palabras habladas</strong>: mezclarlas solo serviría para predecir lo que el revisor ya
                    decidió. Úsala para ver qué motivos pesan más y para cazar llamadas mal etiquetadas.
                    @if ($codigos->ocultos)
                        {{ number_format($codigos->ocultos) }} llamadas con nota quedaron fuera de la tabla
                        (código con menos de {{ $codigos->min }} llamadas o más largo de 60 caracteres).
                    @endif
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        @foreach($analisis::$metricas as $clave => $nombre)
            <li class="nav-item">
                <a class="nav-link {{ $metrica_activa === $clave ? 'active' : '' }}"
                   href="{{ $url_metrica($clave) }}">
                    {{ $nombre }}
                </a>
            </li>
        @endforeach
    </ul>

    @if ($total_palabras === 0)
        <div class="alert alert-warning">
            Ninguna palabra alcanza el mínimo de {{ $datos->soporte }} llamadas con estos
            filtros. Baja el mínimo, revisa el universo o quita filtros.
        </div>
    @endif

    {{-- =================== PESTANA 1: FRECUENCIA =================== --}}
    <div class="tab-content">
        <div class="tab-pane fade {{ $metrica_activa === 'frecuencia' ? 'show active' : '' }}">
            <div class="row g-3">
                @foreach(['exitosas' => $vistas->frecuencia['exitosas'], 'fallidas' => $vistas->frecuencia['fallidas']] as $lado => $lista)
                    <div class="col-lg-6">
                        <div class="card border-{{ $lado === 'exitosas' ? 'success' : 'danger' }} h-100">
                            <div class="card-header py-2">
                                <strong>
                                    {{ $lado === 'exitosas' ? 'Exitosas' : 'Fallidas' }}:
                                    palabras más frecuentes
                                </strong>
                                <span class="text-light small">
                                    sobre {{ $lado === 'exitosas' ? $datos->con_texto_e : $datos->con_texto_f }} llamadas con texto
                                </span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th style="width: 2rem">#</th>
                                            <th>Palabra</th>
                                            <th style="width: 45%">Llamadas</th>
                                            <th class="text-end">%</th>
                                            <th>Origen y eco de la IA</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($lista as $i => $p)
                                            @php
                                                $conteo = $lado === 'exitosas' ? $p->e : $p->f;
                                                $porciento = $lado === 'exitosas' ? $p->share_e : $p->share_f;
                                                $tope = $lado === 'exitosas' ? $max_freq_e : $max_freq_f;
                                            @endphp
                                            <tr>
                                                <td class="text-light small">{{ $i + 1 }}</td>
                                                <td>
                                                    <a href="{{ $url_palabra($p->palabra) }}"
                                                       class="text-decoration-none">
                                                        {{ $p->palabra }}
                                                    </a>
                                                    @if ($p->gram > 1)
                                                        <span class="badge bg-secondary">{{ $p->gram }} palabras</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="fw-bold">{{ $conteo }}</span>
                                                        <div class="progress flex-grow-1" style="height: 6px">
                                                            <div class="progress-bar bg-{{ $lado === 'exitosas' ? 'success' : 'danger' }}"
                                                                 style="width: {{ $analisis::barra($conteo, $tope) }}%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-end small text-light">{{ $analisis::porcentaje($porciento) }}</td>
                                                <td class="small text-light"
                                                    title="De donde salio la palabra (siempre texto humano) y en que % de esas llamadas la dijo tambien la IA">
                                                    {{ $p->origen }}
                                                    · <span class="text-{{ $p->eco ? 'warning' : ($p->pct_ia >= 40 ? 'info' : 'success') }}">
                                                        IA {{ $p->pct_ia }}%
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer py-2 small text-light">
                                Clic en una palabra para ver las llamadas donde aparece.
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- =================== PESTANA 2: DIFERENCIA Y LIFT =================== --}}
        <div class="tab-pane fade {{ $metrica_activa === 'lift' ? 'show active' : '' }}">
            <div class="card">
                <div class="card-header py-2">
                    <strong>Palabras que distinguen un resultado del otro</strong>
                    <span class="text-light small">
                        ordenadas por la mayor diferencia entre exitosas y fallidas
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th style="width: 2rem">#</th>
                                <th>Palabra</th>
                                <th class="text-end" title="Llamadas exitosas que contienen la palabra">Éxit.</th>
                                <th class="text-end" title="Llamadas fallidas que contienen la palabra">Fall.</th>
                                <th style="width: 18%"
                                    title="Proporción de llamadas de cada grupo que contiene la palabra">
                                    Proporción por grupo
                                </th>
                                <th class="text-end"
                                    title="Puntos porcentuales de diferencia: % en exitosas menos % en fallidas">
                                    Diferencia
                                </th>
                                <th class="text-end"
                                    title="Cuántas veces más (o menos) aparece en exitosas que en fallidas">
                                    Lift
                                </th>
                                <th class="text-center"
                                    title="Significancia estadística de la diferencia de proporciones">Sig.</th>
                                <th>Origen y eco de la IA</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($vistas->lift as $i => $p)
                                <tr>
                                <td class="text-light small">{{ $i + 1 }}</td>
                                    <td>
                                        <a href="{{ $url_palabra($p->palabra) }}"
                                           class="text-decoration-none">{{ $p->palabra }}</a>
                                        @if ($p->gram > 1)
                                            <span class="badge bg-secondary">{{ $p->gram }} palabras</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-white">{{ $p->e }}</td>
                                    <td class="text-end text-white">{{ $p->f }}</td>
                                    <td>
                                        <div class="mb-1">
                                            <div class="progress" style="height: 5px">
                                                <div class="progress-bar bg-success"
                                                     style="width: {{ $analisis::barra($p->share_e, $max_share) }}%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="progress" style="height: 5px">
                                                <div class="progress-bar bg-danger"
                                                     style="width: {{ $analisis::barra($p->share_f, $max_share) }}%"></div>
                                            </div>
                                        </div>
                                        <div class="small text-light">
                                            {{ $analisis::porcentaje($p->share_e) }}
                                            vs {{ $analisis::porcentaje($p->share_f) }}
                                        </div>
                                    </td>
                                    <td class="text-end text-{{ $analisis::color_lado($p->diff) }} fw-bold">
                                        {{ $p->diff > 0 ? '+' : '' }}{{ number_format($p->diff, 1) }} pp
                                    </td>
                                    <td class="text-end small text-light">{{ $analisis::lift_texto($p->lift) }}</td>
                                    <td class="text-center small text-light"
                                        title="{{ is_null($p->z) ? 'sin prueba' : 'z = ' . number_format($p->z, 2) }}">
                                        {{ $p->sig ?: '—' }}
                                    </td>
                                    <td class="small text-light"
                                        title="De donde salio la palabra (siempre texto humano) y en que % de esas llamadas la dijo tambien la IA">
                                        {{ $p->origen }}
                                        · <span class="text-{{ $p->eco ? 'warning' : ($p->pct_ia >= 40 ? 'info' : 'success') }}">
                                            IA {{ $p->pct_ia }}%
                                        </span>
                                        @if ($p->pct_nota > 0 and $p->pct_conductor > 0)
                                            <div class="text-light">{{ $p->pct_conductor }}% hablado · {{ $p->pct_nota }}% nota</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer py-2 small text-light">
                    <strong>Diferencia</strong> en puntos porcentuales (% de exitosas menos % de fallidas):
                    positivo = palabra de llamadas exitosas, negativo = de fallidas.
                    El <strong>lift</strong> dice cuántas veces más aparece en un lado;
                    <em>0,0×</em> significa que casi no aparece en exitosas.
                </div>
            </div>
        </div>

        {{-- =================== PESTANA 3: TF-IDF =================== --}}
        <div class="tab-pane fade {{ $metrica_activa === 'tfidf' ? 'show active' : '' }}">
            <p class="text-light small">
                El TF-IDF pesa la palabra dentro de su grupo y le baja el puntaje cuando aparece
                en muchísimas llamadas de ambos lados: sirve para leer <em>lo típico de cada
                resultado</em> sin que “aló” o “sí” tapen todo. Valores multiplicados por 1000
                para que se puedan comparar a ojo.
            </p>
            <div class="row g-3">
                @foreach([
                    'exitosas' => ['lista' => $vistas->tfidf['exitosas'], 'max' => $max_tfidf_e, 'campo' => 'tfidf_e'],
                    'fallidas' => ['lista' => $vistas->tfidf['fallidas'], 'max' => $max_tfidf_f, 'campo' => 'tfidf_f'],
                ] as $lado => $grupo)
                    <div class="col-lg-6">
                        <div class="card border-{{ $lado === 'exitosas' ? 'success' : 'danger' }} h-100">
                            <div class="card-header py-2">
                                <strong>TF-IDF en {{ $lado }}</strong>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th style="width: 2rem">#</th>
                                            <th>Palabra</th>
                                            <th style="width: 40%">Puntaje</th>
                                            <th class="text-end">Llamadas</th>
                                            <th class="text-end">%</th>
                                            <th>Origen y eco de la IA</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($grupo['lista'] as $i => $p)
                                            @php
                                                $score = $p->{$grupo['campo']};
                                                $conteo = $lado === 'exitosas' ? $p->e : $p->f;
                                                $porciento = $lado === 'exitosas' ? $p->share_e : $p->share_f;
                                            @endphp
                                            <tr>
                                                <td class="text-light small">{{ $i + 1 }}</td>
                                                <td>
                                                    <a href="{{ $url_palabra($p->palabra) }}"
                                                       class="text-decoration-none">{{ $p->palabra }}</a>
                                                    @if ($p->gram > 1)
                                                        <span class="badge bg-secondary">{{ $p->gram }} palabras</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="fw-bold small">{{ number_format($score * 1000, 1) }}</span>
                                                        <div class="progress flex-grow-1" style="height: 6px">
                                                            <div class="progress-bar bg-{{ $lado === 'exitosas' ? 'success' : 'danger' }}"
                                                                 style="width: {{ $analisis::barra($score, $grupo['max']) }}%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-end text-light">{{ $conteo }}</td>
                                                <td class="text-end small text-light">{{ $analisis::porcentaje($porciento) }}</td>
                                                <td class="small text-light"
                                                    title="De donde salio la palabra (siempre texto humano) y en que % de esas llamadas la dijo tambien la IA">
                                                    {{ $p->origen }}
                                                    · <span class="text-{{ $p->eco ? 'warning' : ($p->pct_ia >= 40 ? 'info' : 'success') }}">
                                                        IA {{ $p->pct_ia }}%
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- =================== SUGERENCIAS DE DICCIONARIO =================== --}}
    <div class="row mb-3" id="sugerencias">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>
                        <strong class="text-white">Palabras clave sugeridas</strong>
                        <span class="text-light small">
                            ({{ number_format($sugerencias->totales) }} candidatas en el ranking)
                        </span>
                    </span>
                    <span class="small text-light">
                        criterio: diferencia significativa (asterisco), al menos 3 pp de separación
                        y que la IA no la diga en más de {{ $sugerencias->umbral }}% de sus llamadas
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="text-success fw-bold small mb-1">
                                <i class="bi bi-check2-circle"></i> Marcadores de llamada exitosa
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($sugerencias->exito as $p)
                                    <a href="{{ $url_palabra($p->palabra) }}"
                                       class="badge bg-success text-white text-decoration-none"
                                       title="{{ $p->e }} llamadas exitosas y {{ $p->f }} fallidas la contienen · {{ $p->origen }} · la IA también en {{ $p->pct_ia }}%">
                                        {{ $p->palabra }} +{{ number_format($p->diff, 1) }} pp
                                    </a>
                                @empty
                                    <span class="small text-light">
                                        Ninguna con estos filtros: baja el mínimo de llamadas o amplía las fechas.
                                    </span>
                                @endforelse
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="text-danger fw-bold small mb-1">
                                <i class="bi bi-x-circle"></i> Marcadores de llamada fallida
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($sugerencias->falla as $p)
                                    <a href="{{ $url_palabra($p->palabra) }}"
                                       class="badge bg-danger text-white text-decoration-none"
                                       title="{{ $p->e }} llamadas exitosas y {{ $p->f }} fallidas la contienen · {{ $p->origen }} · la IA también en {{ $p->pct_ia }}%">
                                        {{ $p->palabra }} {{ number_format($p->diff, 1) }} pp
                                    </a>
                                @empty
                                    <span class="small text-light">Ninguna con estos filtros.</span>
                                @endforelse
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="text-info fw-bold small mb-1">
                                <i class="bi bi-blockquote-left"></i> Frases que distinguen
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($sugerencias->frases as $p)
                                    <a href="{{ $url_palabra($p->palabra) }}"
                                       class="badge bg-info text-white text-decoration-none"
                                       title="{{ $p->gram }} palabras · {{ $p->e }} éxit / {{ $p->f }} fall · {{ $p->origen }}">
                                        {{ $p->palabra }}
                                        {{ $p->diff > 0 ? '+' : '' }}{{ number_format($p->diff, 1) }} pp
                                    </a>
                                @empty
                                    <span class="small text-light">
                                        Sin frases: sube “Unidades” a 3, 4 o 5 palabras.
                                    </span>
                                @endforelse
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="text-warning fw-bold small mb-1">
                                <i class="bi bi-arrow-repeat"></i> No usar como señal (eco de la IA)
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($sugerencias->ecos as $p)
                                    <a href="{{ $url_palabra($p->palabra) }}"
                                       class="badge border border-warning text-warning text-decoration-none"
                                       title="la IA dice esta palabra en {{ $p->pct_ia }}% de las llamadas que la contienen: viene del guion, no del conductor">
                                        {{ $p->palabra }} · IA {{ $p->pct_ia }}%
                                    </a>
                                @empty
                                    <span class="small text-light">Ninguna palabra es eco del guion con estos filtros.</span>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="input-group mt-3">
                        <span class="input-group-text text-white bg-primary">
                            <i class="bi bi-clipboard"></i>
                        </span>
                        <textarea id="diccionario-sugerido" class="form-control" rows="2"
                                  readonly>{{ $sugerencias->diccionario }}</textarea>
                        <button type="button" id="btn-copiar-dic" class="btn btn-outline-info">
                            Copiar diccionario
                        </button>
                    </div>
                    <div id="aviso-copiado" class="small text-success mt-1 d-none">
                        Diccionario copiado al portapapeles.
                    </div>
                </div>
                <div class="card-footer py-2 small text-light">
                    <strong class="text-white">Cómo usarlo:</strong> son sugerencias de diccionario, no
                    conclusiones. Antes de adoptarlo, haz clic en la palabra y escucha los audios de la
                    evidencia: si la palabra solo aparece cuando la IA ya iba bien encaminada, mide el
                    éxito, no lo produce. Con la fuente <em>Solo notas</em> el diccionario se llena con
                    el vocabulario del revisor, que es más limpio pero ya conoce el resultado.
                </div>
            </div>
        </div>
    </div>

    {{-- ============ MODAL: BUZONES RECONOCIDOS PERO NO MARCADOS ============ --}}
    <div class="modal fade" id="modal-buzones" tabindex="-1"
         aria-labelledby="modal-buzones-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title" id="modal-buzones-title">
                        <i class="bi bi-voicemail text-warning"></i>
                        <strong class="text-white">{{ number_format($buzones->total) }} llamadas</strong>
                        <span class="text-light">parecen buzón y tienen</span>
                        <em class="text-light">buzon_de_voz = 0</em>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <p class="small text-light">
                        <strong class="text-white">El campo manda y no se discute.</strong>
                        {{ number_format($datos->llamadas_buzon_campo) }} llamadas de este filtro tienen
                        <em>buzon_de_voz = 1</em> y ninguna está en esta lista: para el sistema todas son buzón,
                        sin excepción. Lo que ves acá es lo contrario — llamadas con la casilla en 0 cuyo
                        contenido sí parece un contestador. Son candidatos para que alguien escuche el audio;
                        el sistema no re-etiqueta nada ni cambia el ranking con esta señal.
                    </p>
                    <p class="small text-light">
                        Cada fila dice por qué apareció:
                        <span class="badge bg-info">texto</span>
                        el mensaje del conductor coincide con una frase de contestador
                        ({{ number_format($buzones->por_texto) }} llamadas),
                        <span class="badge bg-secondary">nota</span>
                        el revisor escribió BUZÓN en <em>analisis_audio</em> o
                        <em>analisis_transcripcion</em> y dejó la casilla en 0
                        ({{ number_format($buzones->por_nota) }}),
                        <span class="badge bg-info">texto&nbsp;y&nbsp;nota</span>
                        las dos a la vez. Ojo con el sufijo <em>- NO</em> en esas notas:
                        <code>BUZON DE VOZ - NO</code> no niega el buzón, era el criterio de un usuario anterior
                        para decir que la llamada era fallida, así que tampoco se toma al pie de la letra.
                    </p>

                    @if (!$datos->excluir_buzon)
                        <div class="alert alert-warning py-2 small">
                            <strong>El filtro de buzón está apagado:</strong> el texto de estas llamadas está
                            entrando al ranking de palabras ahora mismo. Enciéndelo para sacar el aviso de
                            contestador del conteo.
                        </div>
                    @endif

                    @if ($buzones->llamadas_vistas > $buzones->por_texto)
                        <div class="alert alert-secondary py-2 small">
                            Se cortó la lista en {{ number_format($buzones->tope) }} llamadas: hay
                            {{ number_format($buzones->llamadas_vistas) }} con aviso de contestador sin marcar.
                        </div>
                    @endif

                    @if (count($buzones->lista))
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Conductor / TRT</th>
                                    <th>Resultado</th>
                                    <th>Por qué aparece</th>
                                    <th>El aviso detectado</th>
                                    <th>Qué escribió el revisor</th>
                                    <th class="text-end">Audio</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($buzones->lista as $b)
                                    <tr>
                                        <td class="small text-nowrap text-light">
                                            {{ \Carbon\Carbon::parse($b->created_at)->format('d/m/y H:i') }}
                                            <div>{{ $b->audio_duracion }} s</div>
                                        </td>
                                        <td class="small text-light">
                                            {{ $b->conductor }}
                                            <div class="text-light">{{ $b->trt }}</div>
                                            <div class="text-info">{{ $b->destino }}</div>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $b->llamada_exitosa ? 'success' : 'danger' }}">
                                                {{ $b->llamada_exitosa ? 'exitosa' : 'fallida' }}
                                            </span>
                                        </td>
                                        <td class="small">
                                            <span class="badge bg-{{ $b->motivo === 'nota' ? 'secondary' : 'info' }}">
                                                {{ $b->motivo }}
                                            </span>
                                            @if ($b->patron !== '')
                                                <div class="text-light mt-1">frase: <em>{{ $b->patron }}</em></div>
                                            @endif
                                        </td>
                                        <td class="small text-white">
                                            {{ $b->aviso !== '' ? $b->aviso : '— (la señal salió de la nota, no del audio transcrito)' }}
                                        </td>
                                        <td class="small text-light">
                                            @if (trim((string) $b->analisis_audio) !== '')
                                                <div><em>analisis_audio:</em> {{ $b->analisis_audio }}</div>
                                            @endif
                                            @if (trim((string) $b->analisis_transcripcion) !== '')
                                                <div><em>transcripcion:</em> {{ $b->analisis_transcripcion }}</div>
                                            @endif
                                            @if (count($b->etiquetas))
                                                <div class="text-info">etiquetas: {{ implode(' · ', $b->etiquetas) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($b->audio_link)
                                                <a href="{{ $b->audio_link }}" target="_blank"
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Escuchar esta llamada">
                                                    <i class="bi bi-play-circle"></i>
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-light small mb-0">
                            Ninguna llamada de este filtro se parece a un buzón sin estar marcada.
                            {{ number_format($datos->llamadas_buzon_campo) }} llamadas sí están marcadas y esas
                            no se listan.
                        </p>
                    @endif
                </div>

                <div class="modal-footer py-2">
                    <span class="small text-light me-auto">
                        {{ number_format($datos->llamadas_buzon_campo) }} llamadas con
                        <em>buzon_de_voz = 1</em> en este filtro · excluidas del ranking
                    </span>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('btn-copiar-dic').addEventListener('click', function () {
            var campo = document.getElementById('diccionario-sugerido');
            var aviso = document.getElementById('aviso-copiado');

            campo.select();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(campo.value).then(function () {
                    aviso.classList.remove('d-none');
                }).catch(function () {
                    document.execCommand('copy');
                    aviso.classList.remove('d-none');
                });
            } else {
                document.execCommand('copy');
                aviso.classList.remove('d-none');
            }
        });
    </script>

@endsection
