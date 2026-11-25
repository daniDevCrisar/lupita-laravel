@extends('layouts.app')

@section('title', 'Parámetros del motor de predicción')

@section('content')
    @php
        $hoy = fn ($valor) => number_format((int) $valor);
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-sliders"></i> Parámetros del motor de predicción</h1>
            <p class="text-muted small mb-1">
                Todo lo que cambia <strong>cómo predice</strong> el motor de la hora de presentación para carga. Lo que
                se toca acá vale para <strong>todos</strong> (calculadora, API y tabla de aciertos) y se guarda en la
                tabla <code>prediccion_config</code>; si la tabla no existe, la pantalla igual funciona con los valores
                de <code>config/prediccion.php</code>.
            </p>
            <p class="text-muted small mb-1">
                Los ocho que se editan a mano están explicados uno por uno más abajo, en
                <a href="#detalle-pares_recientes_max">«Explicación detallada de los ocho parámetros editables»</a>:
                qué es cada uno, qué mueve, para dónde moverlo y qué mirar antes de tocarlo.
            </p>
            <p class="text-muted small mb-0">
                <a href="{{ route('lupita.prediccion.presenta') }}">&larr; Calculadora</a> ·
                <a href="{{ route('lupita.prediccion.aciertos') }}">Tabla de aciertos</a> ·
                <a href="{{ route('lupita.prediccion.geoapify') }}">Uso de Geoapify</a>
            </p>
        </div>
    </div>

    {{-- lo que dice el último guardar / calibrar / importar --}}
    @if(session('estado'))
        <div class="alert alert-success py-2">{{ session('estado') }}</div>
    @endif

    @if(session('importar_error'))
        <div class="alert alert-warning py-2 small">
            <strong>No se importó nada.</strong> {{ session('importar_error') }}
        </div>
    @endif

    {{-- ============ CÓMO SE USA ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>Cómo se usa</strong></div>
        <div class="card-body">
            <ol class="mb-2">
                <li class="mb-1">
                    <strong>Mirá los valores en uso</strong> (abajo, en "Parámetros que se editan a mano" y en la
                    tarjeta de cada calibración). Los ocho de la primera fila se cambian escribiendo el número y
                    apretando <em>Guardar parámetros</em>.
                </li>
                <li class="mb-1">
                    <strong>Calibrá lo que no se pone a mano.</strong> Hay dos cosas que no se eligen a ojo porque
                    dependen de tus datos:
                    <ul class="mb-1 mt-1">
                        <li>
                            <code>cruce_pares_k</code>: cuántos viajes propios necesita una placa para pesar más que
                            el promedio de la flota. El botón <strong>Calibrar</strong> prueba
                            {{ implode(' · ', $rejilla_cruce) }} sobre la tabla de aciertos (entrena con los últimos 30
                            días y valida con los últimos 7) y recomienda el que mejor mide en la validación.
                        </li>
                        <li>
                            <strong>Ventana rápido/tardado</strong>: cuánto abrir antes y después de la hora
                            publicada para que la hora real caiga adentro. El botón <strong>Medir</strong> la calcula
                            con el error real del motor (el p25–p75 del hueco cubre sólo ~35 %).
                        </li>
                    </ul>
                </li>
                <li class="mb-1">
                    <strong>Aplicá.</strong> La recomendación <em>no</em> cambia nada hasta que apretás
                    <em>Aplicar</em> al lado. Recién ahí se guarda en <code>prediccion_config</code> y el motor la usa
                    en la próxima predicción.
                </li>
                <li class="mb-0">
                    <strong>Comprobá.</strong> Volvé a la <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de
                    aciertos</a> con el mismo rango y compará MAE, ±3 h y % de la ventana antes y después. Si empeora,
                    volvé al valor anterior (no hay historial: anotá el número que estaba).
                </li>
            </ol>
            <div class="alert alert-warning py-2 mb-0 small">
                <strong>Ojo:</strong> calibrar con pocas filas es ruido. La tabla de aciertos evalúa las más recientes:
                subí el límite a <strong>300-1000</strong> antes de creerle a un botón. Con 100 filas la validación
                queda con 10-20 y cualquier valor "gana" por suerte.
            </div>
        </div>
    </div>

    {{-- ============ EXPORTAR / IMPORTAR LA CONFIGURACIÓN ============ --}}
    @php
        $vistaImport = session('importar_vista');
        $resultadoImport = session('importar_resultado');
        $guardadas = $claves_guardadas ?? [];
    @endphp

    <div class="card mb-3 border-secondary">
        <div class="card-header bg-secondary text-white">
            <strong>Exportar / importar la configuración</strong>
            <span class="small">— la configuración completa del motor en un archivo</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <div class="small fw-semibold mb-1">
                        <i class="bi bi-download"></i> Exportar
                    </div>
                    <p class="small text-muted mb-2">
                        Baja un JSON con las <strong>{{ (int) ($claves_totales ?? 0) }} claves</strong> del motor:
                        los parámetros, la ventana rápido/tardado, el sesgo por clase y los interruptores. Se lleva
                        los valores <strong>en uso</strong> (fábrica + lo guardado), así el mismo archivo deja otro
                        servidor igual.
                    </p>
                    <a class="btn btn-sm btn-primary"
                       href="{{ route('lupita.prediccion.parametros.exportar') }}">
                        <i class="bi bi-filetype-json"></i> Descargar configuración
                    </a>
                    <div class="text-muted mt-2" style="font-size:.7rem">
                        {{ count($guardadas) }} claves tienen valor propio en
                        <code>prediccion_config</code>; las demás corren con el default de
                        <code>config/prediccion.php</code>.
                        @if($guardadas !== [])
                            <div class="mt-1">
                                guardadas: <code>{{ implode(', ', $guardadas) }}</code>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="small fw-semibold mb-1">
                        <i class="bi bi-upload"></i> Importar
                    </div>
                    <form method="POST" action="{{ route('lupita.prediccion.parametros.importar') }}"
                          enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2">
                        @csrf
                        <div class="flex-grow-1">
                            <label class="form-label small mb-0" for="archivo-config">Archivo JSON</label>
                            <input type="file" id="archivo-config" name="archivo"
                                   class="form-control form-control-sm"
                                   accept=".json,application/json">
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" name="accion" value="ver">
                            <i class="bi bi-search"></i> Ver qué cambiaría
                        </button>
                        <button class="btn btn-sm btn-success" name="accion" value="aplicar"
                                onclick="return confirm('¿Importar la configuración del archivo? Se pisan los valores en uso.');">
                            <i class="bi bi-upload"></i> Importar y aplicar
                        </button>
                    </form>
                    <div class="text-muted mt-2" style="font-size:.7rem">
                        <strong>«Ver qué cambiaría» no guarda nada</strong>: muestra el antes/después y desde ahí
                        confirmás. Sólo se aceptan claves que existen en <code>config/prediccion.php</code> y valores
                        numéricos; lo demás se ignora y se informa. Los negativos sólo entran en el sesgo por clase.
                    </div>
                </div>
            </div>

            {{-- vista previa: el diff antes de pisar nada --}}
            @if(is_array($vistaImport))
                <div class="alert alert-info mt-3 mb-0">
                    <div class="small mb-2">
                        <strong>{{ $vistaImport['nombre'] ?? 'archivo' }}</strong> ·
                        {{ (int) ($vistaImport['recibidas'] ?? 0) }} claves recibidas ·
                        <strong>{{ count($vistaImport['cambios'] ?? []) }}</strong> cambiarían.
                        <span class="text-muted">Todavía no se guardó nada.</span>
                    </div>

                    @include('lupita.partials.prediccion_cambios', ['cambios' => $vistaImport['cambios'] ?? []])

                    @if(($vistaImport['ignoradas'] ?? []) !== [])
                        <div class="small text-muted mt-2">
                            ignoradas (no existen en <code>config/prediccion.php</code>):
                            <code>{{ implode(', ', $vistaImport['ignoradas']) }}</code>
                        </div>
                    @endif
                    @if(($vistaImport['rechazadas'] ?? []) !== [])
                        <div class="small text-warning mt-1">
                            rechazadas:
                            @foreach($vistaImport['rechazadas'] as $clave => $motivo)
                                <code>{{ $clave }}</code> ({{ $motivo }})@if(!$loop->last) · @endif
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('lupita.prediccion.parametros.importar') }}"
                          class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        @csrf
                        <input type="hidden" name="confirmado" value="1">
                        <input type="hidden" name="accion" value="aplicar">
                        <input type="hidden" name="archivo_nombre" value="{{ $vistaImport['nombre'] ?? '' }}">
                        @foreach(($vistaImport['valores'] ?? []) as $clave => $valor)
                            <input type="hidden" name="cambios[{{ $clave }}]" value="{{ $valor }}">
                        @endforeach
                        <button class="btn btn-sm btn-success"
                                onclick="return confirm('¿Guardar la configuración revisada? Se pisan los valores en uso.');">
                            <i class="bi bi-check2"></i> Importar {{ count($vistaImport['valores'] ?? []) }} claves
                        </button>
                        <span class="text-muted small">
                            cambian {{ count($vistaImport['cambios'] ?? []) }}
                            · o subí otro archivo para comparar de nuevo
                        </span>
                    </form>
                </div>
            @endif

            {{-- resultado después de aplicar --}}
            @if(is_array($resultadoImport))
                @php $fallo = ($resultadoImport['error'] ?? null) !== null; @endphp
                <div class="alert {{ $fallo ? 'alert-danger' : 'alert-success' }} mt-3 mb-0">
                    <div class="small mb-2">
                        @if($fallo)
                            <strong>No se guardó.</strong> {{ $resultadoImport['error'] }}
                        @else
                            <strong>{{ $resultadoImport['nombre'] ?? 'archivo' }}</strong> ·
                            {{ (int) ($resultadoImport['recibidas'] ?? 0) }} claves recibidas ·
                            <strong>{{ count($resultadoImport['cambios'] ?? []) }}</strong> cambiadas.
                            Ya valen para la próxima predicción (calculadora, API y aciertos al mismo tiempo).
                        @endif
                    </div>

                    @unless($fallo)
                        @include('lupita.partials.prediccion_cambios', ['cambios' => $resultadoImport['cambios'] ?? []])
                    @endunless

                    @if(($resultadoImport['ignoradas'] ?? []) !== [])
                        <div class="small text-muted mt-2">
                            ignoradas: <code>{{ implode(', ', $resultadoImport['ignoradas']) }}</code>
                        </div>
                    @endif
                    @if(($resultadoImport['rechazadas'] ?? []) !== [])
                        <div class="small mt-1">
                            rechazadas:
                            @foreach($resultadoImport['rechazadas'] as $clave => $motivo)
                                <code>{{ $clave }}</code> ({{ $motivo }})@if(!$loop->last) · @endif
                            @endforeach
                        </div>
                    @endif

                    @unless($fallo)
                        <div class="small text-muted mt-2">
                            Si algo salió mal, volvé a importar el archivo que exportaste antes: ese es el
                            «deshacer», porque la base no guarda historial.
                        </div>
                    @endunless
                </div>
            @endif
        </div>
    </div>

    {{-- ============ ABLACIÓN: ¿qué mecanismo aporta y cuál resta? ============ --}}
    <div class="card mb-3 border-primary">
        <div class="card-header bg-primary text-white">
            <strong>Medir qué aporta y qué resta (ablación)</strong>
            <span class="small">— corre la tabla de aciertos con variantes y compara el MAE</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">
                Corre la tabla de aciertos <strong>cuatro veces</strong> con la misma configuración salvo una pieza, y
                muestra el MAE de cada variante contra la que está en uso. Sirve para saber si una corrección ayuda o
                estorba <em>antes</em> de dejarla puesta. <strong>No guarda nada</strong>: los parámetros se pisan en
                memoria, así que después de medir el motor sigue igual.
            </p>

            <form method="POST" action="{{ route('lupita.prediccion.ablacion') }}"
                  class="d-flex flex-wrap align-items-end gap-2">
                @csrf
                <div>
                    <label class="form-label small mb-0">Rango</label>
                    <select name="dias" class="form-select form-select-sm">
                        <option value="30">30 días</option>
                        <option value="90" selected>90 días</option>
                        <option value="180">180 días</option>
                        <option value="365">365 días</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-0">Filas por corrida</label>
                    <select name="limite" class="form-select form-select-sm">
                        <option value="200">200 (rápido)</option>
                        <option value="300" selected>300</option>
                        <option value="600">600 (más confiable)</option>
                        <option value="1000">1000 (lento)</option>
                    </select>
                </div>
                <button class="btn btn-sm btn-primary">
                    <i class="bi bi-clipboard-data"></i> Medir ablación
                </button>
                <button class="btn btn-sm btn-outline-secondary" name="refrescar" value="1"
                        title="ignora la medición guardada en caché (15 min)">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <div class="small text-muted ms-auto">
                    tarda unos segundos por variante la primera vez; después sale de la caché
                </div>
            </form>

            <div class="mt-2">
                @include('lupita.partials.prediccion_valores_en_uso', [
                    'filas' => $en_uso_calibraciones['ablacion'] ?? [],
                    'titulo' => 'En uso ahora (es lo que pisa cada variante)',
                ])
            </div>

            @php $ablacion = session('ablacion'); @endphp

            @if(is_array($ablacion))
                @if(isset($ablacion['error']))
                    <div class="alert alert-warning mt-3 mb-0 small">
                        <strong>No se pudo medir.</strong> {{ $ablacion['error'] }}
                    </div>
                @elseif(!empty($ablacion['filas']))
                    <div class="alert alert-light border mt-3 mb-0">
                        <div class="small text-muted mb-1">
                            {{ $ablacion['desde'] }} → {{ $ablacion['hasta'] }} ·
                            {{ $ablacion['limite'] }} filas por corrida · n = {{ $ablacion['n'] }}
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                                <thead class="table-light">
                                <tr>
                                    <th>variante</th>
                                    <th class="text-end">n</th>
                                    <th class="text-end">MAE</th>
                                    <th class="text-end">±3 h</th>
                                    <th class="text-end">±6 h</th>
                                    <th class="text-end">ventana</th>
                                    <th class="text-end">vs en uso</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($ablacion['filas'] as $fila)
                                    <tr class="{{ $loop->first ? 'table-info' : '' }}">
                                        <td>
                                            {{ $fila['nombre'] }}
                                            @if(!empty($fila['cambios']))
                                                <div class="text-muted" style="font-size:.7rem">
                                                    @foreach($fila['cambios'] as $clave => $valor)
                                                        <code>{{ $clave }}={{ $valor }}</code>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ (int) $fila['n'] }}</td>
                                        <td class="text-end fw-bold">{{ $fila['mae'] ?? '—' }}</td>
                                        <td class="text-end">{{ $fila['pct_180'] === null ? '—' : $fila['pct_180'] . ' %' }}</td>
                                        <td class="text-end">{{ $fila['pct_360'] === null ? '—' : $fila['pct_360'] . ' %' }}</td>
                                        <td class="text-end">{{ $fila['pct_ventana'] === null ? '—' : $fila['pct_ventana'] . ' %' }}</td>
                                        <td class="text-end">
                                            @if($fila['delta'] === null || $fila['delta'] === 0)
                                                <span class="text-muted">—</span>
                                            @elseif($fila['delta'] < 0)
                                                <span class="badge bg-success">{{ $fila['delta'] }} min</span>
                                            @else
                                                <span class="badge bg-danger">+{{ $fila['delta'] }} min</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted mt-2" style="font-size:.7rem">
                            MAE más bajo <strong>con ±3 h y ventana iguales o mejores</strong> es la variante a elegir;
                            ganar sólo en MAE con menos aciertos dentro de ±3 h no es una mejora. Para medir más
                            variantes y contra la mediana de una placa: <code>php artisan prediccion:ablacion</code>.
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ============ CAMIÓN VACÍO + COORDENADAS ============ --}}
    <div class="card mb-3 border-info">
        <div class="card-header bg-info text-dark">
            <strong>Camión vacío y coordenadas</strong>
            <span class="small">— el regreso a cargar va sin carga, y sin promedios manda la distancia</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">
                El <strong>traslado de regreso a la planta</strong> se hace <strong>sin carga</strong>, y un camión
                vacío tarda menos: medido el 19/11/2026 comparando los dos sentidos de los pares con ambos compilados,
                la vuelta tarda <strong>0,83</strong> de la ida (mediana, 5 de 6 pares). El factor se aplica
                <em>sólo</em> cuando la única ruta medida del par es la de <strong>ida</strong> (sale de la planta);
                si la ruta ya es la del regreso vacío, no se toca. El viaje que le falta al camión NUNCA se corrige:
                ese tramo va cargado.
            </p>
            <p class="small text-muted mb-2">
                Cuando no hay <strong>ningún</strong> promedio para el par (ni de la ruta, ni de la referencia, ni de
                los ubigeos), el traslado sale de las <strong>coordenadas de los locales</strong>
                (<code>locales.lat/lng</code>, o el centroide del ubigeo, que si falta se <strong>geocodifica con
                Geoapify</strong>): distancia recta × el ajuste <code>minutos = base + pendiente × km</code> que se
                recalcula solo con las rutas que sí tienen promedio. Va <strong>antes</strong> de Geoapify a propósito:
                no gasta cuota de ruteo.
            </p>
            <p class="small text-muted mb-2">
                El <strong>tramo desde la ubicación declarada</strong> (lo que le falta al camión hasta el destino de
                su viaje) se estima distinto: es <strong>manejo sin paradas</strong>, a velocidad de camión
                (<code>coords_parcial_*</code>). Usar el ajuste del viaje completo —que incluye descansos— contaba los
                descansos dos veces y daba 15:50 para un tramo de 6 h. Si el resultado implica menos de
                <code>coords_parcial_km_h_min</code> km/h en línea recta, se acota y se avisa <code>tramo_lento</code>.
            </p>

            @include('lupita.partials.prediccion_valores_en_uso', [
                'filas' => $vacio_coords ?? [],
                'titulo' => 'En uso ahora',
            ])

            <div class="text-muted mt-2" style="font-size:.7rem">
                <code>vacio_factor</code> = 1 apaga la corrección (queda como antes).
                <code>coords_activo</code> = 0 apaga el escalón de distancia.
                Los números de <code>coords_*</code> (viaje completo) y <code>coords_parcial_*</code> (manejo sin
                paradas, a velocidad de camión) se usan sólo cuando todavía no hay rutas con promedio y coordenadas
                con las que recalcular el ajuste.
            </div>
        </div>
    </div>

    {{-- ============ EDITABLES + CALIBRACIÓN (partial compartido) ============ --}}
    @include('lupita.partials.prediccion_parametros')

    {{-- ============ QUÉ HACE CADA UNO ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-info text-dark"><strong>Qué mueve cada parámetro</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light">
                        <tr>
                            <th>Parámetro</th>
                            <th>Qué mueve</th>
                            <th class="text-end">En uso</th>
                            <th>Cómo se cambia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $detalle = [
                                'pares_recientes_max' => ['Pares recientes por placa', 'con cuántos huecos recientes se calibra la vuelta; con 0 usa toda la historia'],
                                'min_pares_ruta' => ['Mínimo por par de rutas', 'pares que hacen falta para llamar "medido" al hueco'],
                                'min_pares_entidad' => ['Mínimo por placa', 'pares que hacen falta para "parcial" y para calibrar por chofer (C3)'],
                                'min_hitos_bin' => ['Hitos por franja', 'marcas mínimas para que una franja de 30 min no sea descanso'],
                                'max_desplazamiento_min' => ['Desplazamiento máximo', 'minutos que puede moverse una predicción para salir de una franja muerta'],
                                'traslado_defecto_min' => ['Traslado por defecto', 'minutos de regreso cuando no hay ruta medida'],
                                'muerto_defecto_min' => ['Tiempo muerto por defecto', 'minutos de espera cuando no hay huecos medidos'],
                                'espera_carga_defecto_min' => ['Espera en planta por defecto', 'minutos entre presenta e inicio de carga sin compilado'],
                            ];
                        @endphp
                        @foreach($parametros_editables as $clave)
                            @php
                                $valor = (int) ($en_uso->{$clave} ?? 0);
                                $fabrica = $por_defecto->{$clave} ?? null;
                                $cambiado = $fabrica !== null && (int) $fabrica !== $valor;
                            @endphp
                            <tr>
                                <td><code>{{ $clave }}</code><div class="text-muted" style="font-size:.75rem">{{ $detalle[$clave][0] ?? '' }}</div></td>
                                <td class="small text-muted">{{ $detalle[$clave][1] ?? '' }}</td>
                                <td class="text-end">
                                    <span class="fw-bold">{{ $hoy($valor) }}</span>
                                    <div class="text-muted" style="font-size:.7rem">
                                        fábrica {{ $hoy($fabrica ?? 0) }}
                                        @if($cambiado)
                                            <span class="badge bg-warning text-dark">cambiado</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="small">
                                    A mano
                                    <div><a href="#detalle-{{ $clave }}">explicación &darr;</a></div>
                                </td>
                            </tr>
                        @endforeach

                        @foreach($calibrables as $clave)
                            <tr class="table-info">
                                <td><code>{{ $clave }}</code></td>
                                <td class="small text-muted">
                                    @switch($clave)
                                        @case('cruce_pares_k') Peso de la placa contra el compilado en el VIAJE (w = n/(n+k)) @break
                                        @case('ventana_residuos') Usar la ventana medida con los residuos (1) o el p25–p75 del hueco (0) @break
                                        @case('ventana_adelanto_min') Minutos que se le restan a la hora publicada para el "rápido" @break
                                        @case('ventana_atraso_min') Minutos que se le suman para el "tardado" @break
                                        @case('ventana_adelanto_cadena') Lo mismo, cuando no hay pares medidos (evidencia cadena) @break
                                        @case('ventana_atraso_cadena') Idem atraso, sin pares medidos @break
                                        @default —
                                    @endswitch
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold">{{ $hoy($en_uso->{$clave} ?? 0) }}</span>
                                    <div class="text-muted" style="font-size:.7rem">
                                        fábrica {{ $hoy($por_defecto->{$clave} ?? 0) }}
                                    </div>
                                </td>
                                <td class="small">Con los botones de arriba</td>
                            </tr>
                        @endforeach

                        @foreach($fijos as $fila)
                            <tr>
                                <td><code>{{ $fila['clave'] }}</code></td>
                                <td class="small text-muted">{{ $fila['texto'] }}</td>
                                <td class="text-end fw-bold">{{ $fila['valor'] ? 'sí' : 'no' }}</td>
                                <td class="small text-muted">Sólo por consola / <code>prediccion_config</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============ EXPLICACIÓN DETALLADA DE LOS OCHO EDITABLES ============ --}}
    @php
        $detallados = [
            'pares_recientes_max' => [
                'nombre' => 'Pares recientes por placa',
                'que_es' => 'Cuántos huecos (pares fin_descargue → presenta_para_carga) MÁS RECIENTES de cada placa se usan para calibrar la vuelta. Antes de agrupar por par de rutas, por placa y por flota, el calibrador se queda con los N últimos viajes de cada entidad; con 0 usa toda la historia del lookback (120 días).',
                'mueve' => 'La mediana y los cuantiles del hueco con los que se calcula la hora cuando la evidencia es «medido» o «parcial», y el IQR que alimenta la confianza. El hueco de la flota cae mes a mes: calibrarlo con 120 días parejos sobreestima la vuelta en casi medio día.',
                'subir' => 'Más muestra: los cuantiles se estabilizan y la confianza se castiga menos por dispersión. Pero el hueco queda promediado con meses viejos y la predicción se va larga (el camión terminó presentando antes).',
                'bajar' => 'Más sensible al último mes, que es lo que suele querer. El problema: con 5-8 pares la mediana es frágil, un viaje raro mueve la hora y el IQR se dispara (con más de 240 min la confianza baja 20 puntos).',
                'ojo' => 'El recorte es POR PLACA y ocurre ANTES de agrupar. Una placa con 20 viajes recientes deja afuera todo lo anterior, incluso los viajes del par de rutas que le toca predecir ahora.',
                'codigo' => 'Calibrador::calibrar(...) → recientes_por_entidad()',
            ],
            'min_pares_ruta' => [
                'nombre' => 'Mínimo por par de rutas',
                'que_es' => 'Cuántos pares tiene que haber en el PAR DE RUTAS (la ruta del viaje anterior y la del siguiente) para que el hueco se llame «medido». Es el primer escalón de la jerarquía de evidencia del calibrador: par de rutas → placa → flota → cadena.',
                'mueve' => 'El nivel de evidencia y con él la confianza (tope 95 para «medido», 70 para «parcial», 45 para «cadena») y la ventana rápido/tardado: cuando la evidencia es «cadena» la banda se abre a ventana_adelanto_cadena y ventana_atraso_cadena (±3.175 min de fábrica) porque ese escalón se equivoca mucho más.',
                'subir' => 'Exigís más para creerle al par de rutas, así que más predicciones caen a «parcial» (mediana de la placa) o, si la placa tampoco tiene muestra, a «cadena». En cadena la hora sale de traslado + tiempo muerto por defecto y la confianza no pasa de 45.',
                'bajar' => 'Más predicciones llegan a «medido». Con 2-3 pares la mediana es un par suelto: una noche de 54 h en la franja de las 19 corrió la predicción dos días, y ese fue el motivo del mínimo de 3 de fábrica.',
                'ojo' => 'Si en la pantalla de Predicciones guardadas ves que casi ninguna predicción llega a «medido», subir este número no mejora nada: el problema es que falta muestra de los pares de rutas, no el umbral.',
                'codigo' => 'Calibrador::estimar() (nivel «medido» cuando n >= min_pares_ruta)',
            ],
            'min_pares_entidad' => [
                'nombre' => 'Mínimo por placa',
                'que_es' => 'Cuántos pares necesita una PLACA (o un chofer) para que su mediana sirva. Tiene cuatro usos a la vez: (1) nivel «parcial» por placa; (2) nivel «parcial» con la mediana de toda la flota, que también exige este mínimo; (3) CALIBRAR POR CHOFER: el motor calibra con el chofer y no con la placa (C3) cuando ese conductor tiene al menos estos pares propios Y al menos la mitad de los pares de la placa; (4) el régimen CERCA, que sólo corrige si hay al menos estos pares en los que la placa también quedó cerca de la planta de carga.',
                'mueve' => 'El nivel de evidencia, la confianza, la ventana, y QUIÉN es la entidad de calibración. Calibrar por chofer fue la mejora más grande medida en este motor: la validación bajó de 1.876 a 1.540 minutos de MAE.',
                'subir' => 'Más predicciones caen a «cadena» y se apaga C3, porque son estos mismos pares los que deciden si el chofer es una entidad confiable (con 6, un chofer con 3-5 viajes deja de calificar y vuelve a mandar la placa). Subirlo de 3 a 6 devuelve el motor al escalón que peor mide.',
                'bajar' => 'Más «parcial» y más calibración por chofer, a costa de apoyarse en 2-3 viajes que pueden ser atípicos.',
                'ojo' => 'Los tres mínimos (min_pares_ruta, min_pares_entidad y min_hitos_bin) deciden cuánta muestra hace falta para creerle a un perfil. Si los subís juntos, el motor se cae casi siempre a «cadena»: la banda más ancha (±3.175 min) y la confianza más baja.',
                'codigo' => 'Calibrador::estimar(), Servicio::clave_calibracion(), Servicio::cerca()',
            ],
            'min_hitos_bin' => [
                'nombre' => 'Hitos por franja',
                'que_es' => 'Cuántas marcas del camión (hitos: inicio de ruta, llegada a destino, inicio y fin de descargue, carga…) tienen que aparecer en una franja del día para que esa franja cuente como ACTIVA. El día se parte en franjas de granularidad_min (30 min = 48 franjas) y se cuenta todo el lookback: la franja que no llega a N hitos es una franja muerta (descanso, almuerzo, noche en planta).',
                'mueve' => 'La lista de franjas inactivas del camión, que es lo que después empuja una predicción que cae adentro (ver Desplazamiento máximo) y lo que se informa como ventana.fuente y ventana.cobertura en el JSON.',
                'subir' => 'Más franjas se declaran muertas: más predicciones se desplazan y más advertencias «desplazada por franja inactiva» aparecen (cada desplazamiento cuesta 25 puntos de confianza).',
                'bajar' => 'A 1, dos o tres marcas dispersas ya abren un hueco en la noche: el descanso deja de detectarse y la predicción puede publicar las 3 de la mañana como hora de presentación.',
                'ojo' => 'Cuenta MARCAS, no intervalos. El hueco entre un fin de descargue y la presentación siguiente también es un intervalo: contarlo como actividad haría invisible el descanso (fue el primer error de ese cálculo).',
                'codigo' => 'Servicio::ventanas_con_hitos() → Ventanas::inactivas()',
            ],
            'max_desplazamiento_min' => [
                'nombre' => 'Desplazamiento máximo',
                'que_es' => 'Cuántos minutos puede moverse una predicción para sacarla de una franja inactiva. El motor la lleva al final de la ventana en la que cayó, repite si con eso cae en otra (hasta 3 pasadas) y NUNCA camina más que este tope: si lo alcanza, publica lo que pudo moverse y deja la advertencia desplazamiento_maximo.',
                'mueve' => 'Sólo la hora final de los casos que caen en franja muerta; a las predicciones que no caen no las toca. 600 = 10 h, así que en el peor caso la hora publicada se aparta 10 horas de la que salió del hueco medido.',
                'subir' => 'La predicción puede salir completa del descanso y quedar más «operativa», pero se aleja del dato medido y el error crece.',
                'bajar' => 'Respeta el hueco medido, con el riesgo de publicar una hora a la que el camión nunca se presenta.',
                'ojo' => 'Antes de tocar este número mirá cuántas predicciones traen la advertencia de franja inactiva: si son muchas, el problema suele estar en min_hitos_bin o en granularidad_min, no en el tope.',
                'codigo' => 'MotorPresenta → Ventanas::desplazar()',
            ],
            'traslado_defecto_min' => [
                'nombre' => 'Traslado por defecto',
                'que_es' => 'El último escalón del TRASLADO DE VUELTA (dónde está el camión → la planta donde tiene que cargar). La jerarquía de RESOLUCIÓN es: (1) la ruta medida entre esos dos locales; (2) el mismo par de ubigeos; (3) la ruta de la propia referencia, porque el regreso se parece a la ida; (4) las coordenadas de los dos locales, cuando no hay ningún promedio; (5) Geoapify, si hay key y no se agotó; (6) la mediana de las rutas con ese par de departamentos; (7) este valor. Dentro de los tres primeros escalones el TIEMPO de la ruta se calcula igual que el tramo que le falta al camión: lo medido de la ruta, mezclado con lo que esa placa tarda en la misma ruta (cruce_pares_k) y mezclado con el compilado POR DÍA Y HORA de esa ruta (horario_activo), todo con peso n/(n+k). Si la ruta que se usó es la de ida (sale de la planta), se corrige con vacio_factor porque la vuelta va vacía.',
                'mueve' => 'El hueco cuando la evidencia es «cadena» (ahí hueco = traslado + tiempo muerto) y sirve de referencia cuando el hueco medido es menor: si el medido queda por debajo, se avisa hueco_menor_que_traslado pero se respeta el medido. Cuando se usa este valor, el traslado queda con fuente «defecto» y la confianza baja 20 puntos.',
                'subir' => 'Predicciones más tarde cuando falta el dato.',
                'bajar' => 'Predicciones más temprano, con el riesgo de publicar una hora imposible.',
                'ojo' => 'Este valor no debería usarse casi nunca. Si aparece seguido en la calculadora (fuente «defecto») es que faltan rutas compiladas o se quedó sin Geoapify: eso se arregla con datos, no moviendo el número.',
                'codigo' => 'DBPrediccion::traslado() (escalones 1 a 6)',
            ],
            'muerto_defecto_min' => [
                'nombre' => 'Tiempo muerto por defecto',
                'que_es' => 'Los minutos que se le suman al traslado cuando NO hay hueco medido (evidencia «cadena»). Representa lo que el camión espera en la planta entre llegar y presentarse, en el caso en que no hay historia para medirlo.',
                'mueve' => 'Sólo las predicciones en «cadena»; no toca las «medido» ni las «parcial». También es el tiempo muerto que se muestra en el desglose y en los supuestos de la calculadora.',
                'subir' => 'Las predicciones en cadena salen más tarde.',
                'bajar' => 'Las predicciones en cadena salen más temprano.',
                'ojo' => 'Es el parámetro con menos respaldo posible: se usa justo cuando el motor no sabe nada. Para que deje de usarse hacen falta pares medidos, o sea los dos mínimos de arriba.',
                'codigo' => 'MotorPresenta (hueco de cadena)',
            ],
            'espera_carga_defecto_min' => [
                'nombre' => 'Espera en planta por defecto',
                'que_es' => 'Minutos entre presenta_para_carga e inicio_de_carga cuando no hay compilado. La espera real sale de la tabla por planta (/api/tiempos/promedio/plantas) y, si esa planta no tiene dato, del compilado por ruta; este número es el que se usa cuando no hay ninguno de los dos.',
                'mueve' => 'inicio_carga y espera_min del JSON, o sea la hora a la que el camión empieza a cargar, NO la hora de presentación. Con la cola de planta activa (C1) se le suman cola_espera_extra_min cuando hay 11 o más llegadas por hora.',
                'subir' => 'Inicio de carga más tarde.',
                'bajar' => 'Inicio de carga más temprano.',
                'ojo' => 'Medido con 10.293 referencias, la espera para cargar tiene mediana 36 min con 1-2 llegadas por hora y 55 min con 11 o más: cualquier valor por encima de eso tiende a irse largo cuando se usa. Si aparece seguido, falta compilar la planta.',
                'codigo' => 'MotorPresenta (espera) → DBPrediccion::espera_carga()',
            ],
        ];
    @endphp

    <div class="card mb-3 border-info">
        <div class="card-header bg-info text-dark">
            <strong>Explicación detallada de los ocho parámetros editables</strong>
            <span class="small">— qué es cada uno, qué mueve, para dónde moverlo y qué mirar antes</span>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                <strong>En uso</strong> es el valor que está gobernando hoy (viene de <code>prediccion_config</code>);
                <strong>fábrica</strong> es el que dice <code>config/prediccion.php</code>. Si no coinciden, alguien los
                cambió desde esta pantalla. Estos ocho son los únicos que se escriben a mano: todo lo demás se mide o
                se calcula solo.
            </p>

            @foreach($detallados as $clave => $d)
                @php
                    $valor = (int) ($en_uso->{$clave} ?? 0);
                    $fabrica = $por_defecto->{$clave} ?? null;
                @endphp
                <div class="card mb-2" id="detalle-{{ $clave }}">
                    <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                        <code class="fs-6">{{ $clave }}</code>
                        <span class="text-muted small">{{ $d['nombre'] }}</span>
                        <span class="ms-auto small">
                            en uso <strong>{{ $hoy($valor) }}</strong>
                            @if($fabrica !== null)
                                <span class="text-muted">· fábrica {{ $hoy($fabrica) }}</span>
                                @if((int) $fabrica !== $valor)
                                    <span class="badge bg-warning text-dark">cambiado</span>
                                @endif
                            @endif
                        </span>
                    </div>
                    <div class="card-body py-2">
                        <p class="small mb-2"><strong>Qué es.</strong> {{ $d['que_es'] }}</p>
                        <p class="small mb-2"><strong>Qué mueve.</strong> {{ $d['mueve'] }}</p>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="small border-start border-success border-3 ps-2 h-100">
                                    <strong class="text-success">Si lo subís.</strong> {{ $d['subir'] }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="small border-start border-warning border-3 ps-2 h-100">
                                    <strong class="text-warning">Si lo bajás.</strong> {{ $d['bajar'] }}
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-secondary py-2 px-2 mt-2 mb-1 small">
                            <strong>Ojo.</strong> {{ $d['ojo'] }}
                        </div>
                        <div class="text-muted" style="font-size:.7rem">
                            Dónde entra: <code>{{ $d['codigo'] }}</code>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="alert alert-warning py-2 mb-0 small">
                <strong>Cómo comprobar un cambio.</strong> Anotá el valor actual, guardá el nuevo, volvé a la
                <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de aciertos</a> con el mismo rango (300-1000
                filas) y compará <strong>MAE</strong>, <strong>±3 h</strong> y <strong>% de la ventana</strong>. Después
                mirá en <a href="{{ route('lupita.prediccion.guardadas') }}">Predicciones guardadas</a> cómo se movió la
                evidencia: si más predicciones cayeron a «cadena», el parámetro está apretando de más.
            </div>
        </div>
    </div>

    {{-- ============ AVISOS ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white"><strong>Antes de apretar Guardar o Aplicar</strong></div>
        <div class="card-body">
            <ul class="mb-0 small">
                <li>Los valores valen para <strong>todos</strong>: cambian la calculadora, el API y la tabla de aciertos al mismo tiempo.</li>
                <li>Toman efecto en la <strong>próxima</strong> predicción (no hay que reiniciar nada).</li>
                <li>No hay historial de cambios ni botón de deshacer: <strong>exportá la configuración antes de tocar</strong> (tarjeta «Exportar / importar»), así podés volver al estado anterior importando ese archivo.</li>
                <li>Cambiar parámetros <strong>no</strong> cambia los datos: si el MAE es malo por hitos incompletos, refs sin ruta o huecos de varios días, ningún parámetro lo arregla. Para eso está
                    <code>php artisan prediccion:diagnostico</code>.</li>
                <li>La calibración y la medición de la ventana usan la tabla de aciertos con Geoapify apagado: no gastan API.</li>
            </ul>
        </div>
    </div>
@endsection
