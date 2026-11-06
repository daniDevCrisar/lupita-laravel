@extends('layouts.app')

@section('title', 'Promedios compilados por ruta y planta')

@section('content')

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1 text-light">Promedios compilados por ruta y planta</h1>
            <p class="text-light small mb-2">
                Lo que ya está guardado en <code class="text-info">tiempos_ruta_promedio</code> por
                <a href="{{ route('lupita.tiempos.promedio') }}" class="text-info">Compilar promedios</a>:
                un promedio por ruta y sentido de la etapa <em>inicio de ruta &rarr; llegada a destino</em>.
                Los tiempos salen en <span class="badge bg-info text-dark">minutos</span>.
            </p>
            <p class="text-light small mb-0">
                Esta pantalla no calcula: consulta el endpoint público
                <code class="text-warning">GET /api/tiempos/promedio</code> y pinta la respuesta.
                El <strong>tiempo a usar</strong> es el promedio fusionado (ida &harr; vuelta) cuando existe;
                si no, el promedio del sentido. El mapa <code class="text-info">ubicaciones</code> con el nombre y las
                <em>palabras clave</em> de cada local viene por defecto; la casilla <em>Respuesta resumida</em> agrega
                <code>?resumir=1</code> y quitar <em>Palabras clave</em> envía <code>?palabras_claves=0</code>.
            </p>
            <p class="text-light small mb-0">
                Debajo de las rutas, <strong>Promedios por planta</strong> consulta aparte
                <code class="text-warning">GET /api/tiempos/promedio/plantas</code> y pinta lo guardado en
                <code class="text-info">tiempos_planta_promedio</code>: espera <em>fuera</em> y <em>dentro</em> de planta,
                separada en carga y descarga, más el fusionado de ambas.
            </p>
        </div>
        <div class="text-end small">
            <span class="badge bg-{{ $estado->tablas ? 'success' : 'warning' }} text-dark">
                {{ $estado->tablas ? 'Tablas listas' : 'Faltan las tablas (aplicar migración)' }}
            </span>
            @if($estado->rutas > 0)
                <div class="text-light mt-1">
                    {{ number_format($estado->rutas) }} ruta(s) compiladas<br>
                    {{ number_format($estado->celdas) }} celda(s) día/hora<br>
                    Última: {{ $estado->ultima }}
                </div>
            @endif
            <div class="mt-1">
                <a href="{{ route('lupita.tiempos.promedio.horario.ver') }}" class="text-info">
                    Ver horarios compilados <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    @include('lupita._tiempos_promedio_tabs', ['tab' => 'ver'])

    {{-- ================= FILTROS ================= --}}
    <div class="card border-secondary mt-3 mb-3">
        <div class="card-body py-3">
            <form id="form_api" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-0" for="f_buscar">Buscar</label>
                    <input type="search" id="f_buscar" name="buscar" class="form-control form-control-sm"
                           placeholder="Ruta, tramo o departamento" autocomplete="off">
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="f_ruta">Ruta</label>
                    <select name="ruta_id" id="f_ruta" class="form-control form-control-sm">
                        <option value="">Todas las compiladas</option>
                        @foreach($rutas as $id => $ruta)
                            <option value="{{ $id }}">{{ $ruta->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_dep_origen">Dep. origen</label>
                    <select name="dep_origen" id="f_dep_origen" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($departamentos as $codigo => $nombre)
                            <option value="{{ $codigo }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_dep_destino">Dep. destino</label>
                    <select name="dep_destino" id="f_dep_destino" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($departamentos as $codigo => $nombre)
                            <option value="{{ $codigo }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_min_viajes">Mín. viajes</label>
                    <input type="number" name="min_viajes" id="f_min_viajes" min="0" step="1"
                           value="1" class="form-control form-control-sm">
                </div>

                <div class="col-md-4">
                    <label class="form-label small mb-0" for="f_orden">Ordenar por</label>
                    <select name="orden" id="f_orden" class="form-control form-control-sm">
                        @foreach($ordenes as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($clave === 'viajes')>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_limit">Filas</label>
                    <select name="limit" id="f_limit" class="form-control form-control-sm">
                        @foreach([50, 100, 300, 1000] as $opcion)
                            <option value="{{ $opcion }}" @selected($opcion === $limite)>{{ $opcion }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="f_fusion" name="con_fusion">
                        <label class="form-check-label small text-light" for="f_fusion">Solo con fusión ida/vuelta</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="f_resumir" name="resumir">
                        <label class="form-check-label small text-light" for="f_resumir">
                            Respuesta resumida <span class="text-white-50">(sin resumen y con menos campos)</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="f_palabras_claves"
                               name="palabras_claves" checked>
                        <label class="form-check-label small text-light" for="f_palabras_claves">
                            Incluir palabras clave
                            <span class="text-white-50">(mapa de ubicaciones, viene por defecto)</span>
                        </label>
                    </div>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" id="btn_llamar">
                        <i class="bi bi-search"></i> Consultar
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" id="btn_limpiar">
                        <i class="bi bi-eraser"></i> Limpiar
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm" id="btn_copiar_url">
                        <i class="bi bi-clipboard"></i> Copiar URL
                    </button>
                </div>

                <div class="col-12">
                    <input type="text" id="url_final" class="form-control form-control-sm bg-black text-light"
                           readonly placeholder="La URL del endpoint aparece aquí al consultar">
                </div>
            </form>
        </div>
    </div>

    <div id="estado" class="mb-2"></div>

    {{-- ================= RESUMEN ================= --}}
    <div id="bloque_resumen" class="row g-2 mb-3" style="display: none;">
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Rutas</div>
                <div class="fs-4 text-light" id="rs_rutas">–</div>
                <div class="small text-white-50" id="rs_rutas_nota">–</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Viajes usados</div>
                <div class="fs-4 text-light" id="rs_viajes">–</div>
                <div class="small text-white-50" id="rs_viajes_nota">–</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Promedio ponderado</div>
                <div class="fs-4 text-info" id="rs_prom">–</div>
                <div class="small text-white-50">Cada ruta pesa por sus viajes</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Ciclo E2 → E6</div>
                <div class="fs-4 text-info" id="rs_ciclo">–</div>
                <div class="small text-white-50">
                    rápido <span id="rs_ciclo_rapido">–</span> · tardado <span id="rs_ciclo_tardado">–</span>
                </div>
                <div class="small text-white-50">
                    espera fuera <span id="rs_espera_fuera">–</span> · dentro <span id="rs_espera_dentro">–</span>
                </div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Descartados por tope</div>
                <div class="fs-4" id="rs_perdido">–</div>
                <div class="small text-white-50" id="rs_perdido_nota">–</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Con fusión</div>
                <div class="fs-4 text-light" id="rs_fusion">–</div>
                <div class="small text-white-50" id="rs_ultima">–</div>
            </div></div>
        </div>
    </div>

    {{-- ================= TABLA ================= --}}
    <div id="bloque_tabla" style="display: none;">
        <div class="d-flex justify-content-between align-items-end">
            <h2 class="fs-5 text-light mb-0">Rutas compiladas</h2>
            <div class="small text-white-50" id="tabla_nota"></div>
        </div>

        <div class="table-responsive mt-2" style="max-height: 65vh; overflow: auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="thead-lupita" style="position: sticky; top: 0; z-index: 1">
                <tr>
                    <th>Ruta</th>
                    <th class="text-end" title="Promedio fusionado (ida y vuelta) o, si no existe, el del sentido">
                        Tiempo a usar
                    </th>
                    <th class="text-end">Prom. con tope</th>
                    <th class="text-end">Prom. sin tope</th>
                    <th class="text-end"
                        title="Ciclo E2 → E6 en tres escenarios de espera: rápido (p50), promedio (p82, pierde como máximo el 18 % de viajes) y tardado (toda la espera real). Debajo, la espera media fuera (E2+E5) y dentro (E3+E6)">
                        Ciclo prom.
                    </th>
                    <th class="text-end" title="P25 – mediana – P75 de los viajes dentro del tope">
                        Mediana (P25–P75)
                    </th>
                    <th class="text-end">Desv.</th>
                    <th class="text-end">Viajes</th>
                    <th class="text-end">Descartados</th>
                    <th class="text-end">% perdido</th>
                    <th class="text-end">Tope</th>
                    <th>Compilado</th>
                </tr>
                </thead>
                <tbody id="tbody_rutas"></tbody>
            </table>
        </div>
    </div>

    {{-- ================= PROMEDIOS POR PLANTA (debajo de las rutas) ================= --}}
    <div class="mt-5" id="bloque_plantas">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div>
                <h2 class="fs-5 text-light mb-0">Promedios por planta: fuera y dentro</h2>
                <div class="small text-white-50">
                    Espera ya compilada, separada por el rol de la planta:
                    <strong class="text-light">fuera</strong> (E2 al cargar · E5 al descargar),
                    <strong class="text-light">dentro</strong> (E3 al cargar · E6 al descargar) y el
                    <strong class="text-light">fusión</strong> de carga + descarga de cada rol.
                    El total es fuera + dentro.
                </div>
            </div>
            <div class="small text-end">
                <span class="badge bg-{{ $estado_plantas->tabla ? 'success' : 'warning' }} text-dark">
                    {{ $estado_plantas->tabla
                        ? number_format($estado_plantas->plantas) . ' planta(s) compiladas'
                        : 'Faltan las tablas de plantas (aplicar migración)' }}
                </span>
                @if($estado_plantas->ultima)
                    <div class="text-white-50 mt-1">Última: {{ $estado_plantas->ultima }}</div>
                @endif
                <div class="mt-1">
                    <a href="{{ route('lupita.tiempos.promedio.plantas') }}" class="text-info">
                        Compilar plantas <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="card border-secondary mt-2 mb-3">
            <div class="card-body py-3">
                <form id="form_plantas" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="p_buscar">Buscar</label>
                        <input type="search" id="p_buscar" name="buscar" class="form-control form-control-sm"
                               placeholder="Planta, id, palabra clave o departamento" autocomplete="off">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="p_planta">Planta</label>
                        <select name="planta_id" id="p_planta" class="form-control form-control-sm">
                            <option value="">Todas las compiladas</option>
                            @foreach($plantas as $id => $planta)
                                <option value="{{ $id }}">{{ $planta->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small mb-0" for="p_departamento">Departamento</label>
                        <select name="departamento" id="p_departamento" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            @foreach($departamentos_plantas as $nombre)
                                <option value="{{ $nombre }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small mb-0" for="p_min_lados">Mín. lados</label>
                        <input type="number" name="min_lados" id="p_min_lados" min="0" step="1"
                               value="1" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="p_orden">Ordenar por</label>
                        <select name="orden" id="p_orden" class="form-control form-control-sm">
                            @foreach($ordenes_plantas as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($clave === 'viajes')>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small mb-0" for="p_limit">Filas</label>
                        <select name="limit" id="p_limit" class="form-control form-control-sm">
                            @foreach([50, 100, 300, 1000] as $opcion)
                                <option value="{{ $opcion }}" @selected($opcion === $limite_plantas)>{{ $opcion }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" id="btn_plantas">
                            <i class="bi bi-search"></i> Consultar
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" id="btn_limpiar_plantas">
                            <i class="bi bi-eraser"></i> Limpiar
                        </button>
                    </div>

                    <div class="col-md-7">
                        <input type="text" id="url_plantas" class="form-control form-control-sm bg-black text-light"
                               readonly placeholder="La URL del endpoint de plantas aparece aquí al consultar">
                    </div>
                </form>
            </div>
        </div>

        <div id="estado_plantas" class="mb-2"></div>

        <div class="row g-2 mb-3" id="resumen_plantas" style="display: none;">
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Plantas</div>
                    <div class="fs-4 text-light" id="rp_plantas">–</div>
                    <div class="small text-white-50" id="rp_plantas_nota">–</div>
                </div></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Lados medibles</div>
                    <div class="fs-4 text-light" id="rp_lados">–</div>
                    <div class="small text-white-50" id="rp_lados_nota">–</div>
                </div></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Fuera prom.</div>
                    <div class="fs-4 text-info" id="rp_fuera">–</div>
                    <div class="small text-white-50">Ponderado por lados</div>
                </div></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Dentro prom.</div>
                    <div class="fs-4 text-info" id="rp_dentro">–</div>
                    <div class="small text-white-50">Ponderado por lados</div>
                </div></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Tiempo en planta</div>
                    <div class="fs-4 text-warning" id="rp_total">–</div>
                    <div class="small text-white-50">Fuera + dentro</div>
                </div></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-secondary h-100"><div class="card-body py-2">
                    <div class="small text-light">Descartados por tope</div>
                    <div class="fs-4" id="rp_descartados">–</div>
                    <div class="small text-white-50" id="rp_descartados_nota">–</div>
                </div></div>
            </div>
        </div>

        <div id="tabla_plantas" style="display: none;">
            <div class="d-flex justify-content-between align-items-end">
                <h3 class="fs-6 text-light mb-0">Plantas compiladas</h3>
                <div class="small text-white-50" id="nota_plantas"></div>
            </div>

            <div class="table-responsive mt-2" style="max-height: 55vh; overflow: auto">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="thead-lupita" style="position: sticky; top: 0; z-index: 1">
                    <tr>
                        <th rowspan="2">Planta</th>
                        <th rowspan="2" class="text-end"
                            title="Veces que un viaje tocó esta planta dentro de sus topes: carga + descarga">
                            Lados
                        </th>
                        <th colspan="3" class="text-center"
                            title="Espera FUERA de planta: E2 (presenta → inicio de carga) y E5 (qr llegada → inicio descargue)">
                            Fuera de planta
                        </th>
                        <th colspan="3" class="text-center"
                            title="Espera DENTRO de planta: E3 (inicio de carga → inicio de ruta) y E6 (inicio descargue → fin descargue)">
                            Dentro de planta
                        </th>
                        <th rowspan="2" class="text-end" title="Fuera + dentro">Total</th>
                        <th rowspan="2" class="text-end">Tope</th>
                        <th rowspan="2" class="text-end">Descartados</th>
                        <th rowspan="2">Compilado</th>
                    </tr>
                    <tr>
                        <th class="text-end" title="E2: presenta → inicio de carga">Carga</th>
                        <th class="text-end" title="E5: qr llegada → inicio descargue">Descarga</th>
                        <th class="text-end" title="Fusionado: carga y descarga juntas, pesando cada rol por sus lados">
                            Fusión
                        </th>
                        <th class="text-end" title="E3: inicio de carga → inicio de ruta">Carga</th>
                        <th class="text-end" title="E6: inicio descargue → fin descargue">Descarga</th>
                        <th class="text-end" title="Fusionado: carga y descarga juntas, pesando cada rol por sus lados">
                            Fusión
                        </th>
                    </tr>
                    </thead>
                    <tbody id="tbody_plantas"></tbody>
                </table>
            </div>
        </div>

        <div id="sin_plantas" class="text-center text-white-50 py-4" style="display: none;">
            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
            No hay promedios de planta compilados con estos filtros.
            <div class="small mt-1">
                Compílalos en
                <a href="{{ route('lupita.tiempos.promedio.plantas') }}" class="text-info">Plantas</a>.
            </div>
        </div>
    </div>

    {{-- ================= UBICACIONES (por defecto; palabras_claves=0 las omite) ================= --}}
    <div id="bloque_ubicaciones" class="mt-4" style="display: none;">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <h2 class="fs-5 text-light mb-0">
                Ubicaciones únicas <span class="badge bg-secondary" id="ub_count">0</span>
            </h2>
            <div class="small text-white-50">
                Se listan una sola vez; las rutas las referencian por <code class="text-info">origen_id</code> y
                <code class="text-info">destino_id</code>
            </div>
        </div>

        <div class="table-responsive mt-2" style="max-height: 40vh; overflow: auto">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="thead-lupita">
                <tr>
                    <th style="width: 220px">Clave</th>
                    <th style="width: 260px">Nombre</th>
                    <th>Palabras clave</th>
                </tr>
                </thead>
                <tbody id="tbody_ubicaciones"></tbody>
            </table>
        </div>
    </div>

    <div id="sin_datos" class="text-center text-white-50 py-5" style="display: none;">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        No hay promedios compilados con estos filtros.
        <div class="small mt-1">
            Compílalos en <a href="{{ route('lupita.tiempos.promedio') }}" class="text-info">Compilar promedios</a>.
        </div>
    </div>

    <details class="mt-4 mb-5">
        <summary class="text-info">Ver respuesta JSON cruda</summary>
        <div class="small text-white-50 mt-1" id="json_nota"></div>
        <pre id="json_raw" class="bg-black text-light p-3 mt-2 small" style="max-height: 520px; overflow: auto;"></pre>
    </details>

@endsection

@section('scripts')
    <script>
        const ENDPOINT = @json($endpoint);
        const CAMPOS = ['buscar', 'ruta_id', 'dep_origen', 'dep_destino', 'min_viajes', 'con_fusion',
            'resumir', 'palabras_claves', 'orden', 'limit'];
        const DIAS_MODO = {global: 'global', ruta: 'p82', propio: 'tope propio'};

        // --- seccion de plantas: endpoint y campos propios ---
        const ENDPOINT_PLANTAS = @json($endpoint_plantas);
        const CAMPOS_PLANTAS = ['buscar', 'planta_id', 'departamento', 'min_lados', 'orden', 'limit'];

        function build_url() {
            const params = new URLSearchParams();
            CAMPOS.forEach(c => {
                const el = document.querySelector(`#form_api [name="${c}"]`);
                if (!el) return;
                // las palabras clave viajan siempre (1 u 0): el endpoint las
                // incluye por defecto y solo se omiten con palabras_claves=0
                if (c === 'palabras_claves') {
                    params.set(c, el.checked ? '1' : '0');
                    return;
                }
                const v = (el.type === 'checkbox') ? (el.checked ? '1' : '') : el.value.trim();
                if (v !== '') params.set(c, v);
            });
            const q = params.toString();
            return ENDPOINT + (q ? '?' + q : '');
        }

        function min(v) {
            if (v === null || v === undefined || v === '') return '—';
            const m = Math.round(v);
            const d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60), mm = m % 60;
            if (d > 0) return d + 'd ' + h + 'h';
            if (h > 0) return h + 'h ' + String(mm).padStart(2, '0') + 'm';
            return mm + 'm';
        }

        function num(v) {
            if (v === null || v === undefined || v === '') return '—';
            return Number(v).toLocaleString('es-PE');
        }

        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
        }

        function color_perdido(pct) {
            if (pct === null || pct === undefined) return 'secondary';
            if (pct <= 2) return 'success';
            if (pct <= 10) return 'warning';
            return 'danger';
        }

        function render(data) {
            const r = data.resumen || null;

            // con resumir=1 el endpoint no devuelve `resumen`: se ocultan las tarjetas
            if (r) {
                document.getElementById('rs_rutas').textContent = num(r.rutas);
                document.getElementById('rs_rutas_nota').textContent = (data.mostradas ?? 0) + ' en la tabla';
                document.getElementById('rs_viajes').textContent = num(r.viajes);
                document.getElementById('rs_viajes_nota').textContent = 'de ' + num(r.viajes_total) + ' medibles';
                document.getElementById('rs_prom').textContent = min(r.prom_ponderado);
                document.getElementById('rs_ciclo').textContent = min(r.prom_ciclo);
                document.getElementById('rs_ciclo_rapido').textContent = min(r.prom_ciclo_rapido);
                document.getElementById('rs_ciclo_tardado').textContent = min(r.prom_ciclo_sin_tope);
                document.getElementById('rs_espera_fuera').textContent = min(r.prom_espera_fuera);
                document.getElementById('rs_espera_dentro').textContent = min(r.prom_espera_dentro);
                document.getElementById('rs_perdido').textContent = num(r.descartados);
                document.getElementById('rs_perdido').className = 'fs-4 text-' + color_perdido(r.pct_perdido);
                document.getElementById('rs_perdido_nota').textContent = (r.pct_perdido === null ? '—' : r.pct_perdido + ' %') +
                    ' · ' + (r.topes ?? 0) + ' tope(s)';
                document.getElementById('rs_fusion').textContent = num(r.fusionadas);
                document.getElementById('rs_ultima').textContent = r.ultima ? 'Última: ' + r.ultima : 'Sin compilaciones';
            }
            document.getElementById('bloque_resumen').style.display = r ? '' : 'none';

            const filas = data.rutas || [];
            const tbody = document.getElementById('tbody_rutas');
            tbody.innerHTML = filas.map(f => {
                // con resumir=1 faltan ruta_id, prom_fusion_min, tope, totales y fecha
                const fusion = f.prom_fusion_min != null || f.viajes_fusion != null;
                const rango = (f.p25_min === null && f.p75_min === null)
                    ? '—'
                    : min(f.p25_min) + ' – ' + min(f.mediana_min) + ' – ' + min(f.p75_min);
                const referencia = [
                    f.ruta_id ? esc(f.ruta_id) : '',
                    esc(f.dep_origen ?? '—') + ' &rarr; ' + esc(f.dep_destino ?? '—'),
                ].filter(Boolean).join(' · ');
                const nota_viajes = f.viajes_total == null ? '' :
                    `<div class="text-white-50" style="font-size: .72rem">de ${num(f.viajes_total)}</div>`;
                // tres escenarios del ciclo: rapido (p50 de espera), promedio (p82)
                // y tardado (toda la espera real de viajes con cadena completa)
                const ciclo_rapido = f.prom_ciclo_rapido_min == null ? '' :
                    `<div class="text-white-50" style="font-size: .72rem"
                        title="Ciclo con el tope rápido: deja entrar los viajes con menos espera (p50)">
                        rápido ${min(f.prom_ciclo_rapido_min)}</div>`;
                const ciclo_tardado = f.prom_ciclo_sin_tope_min == null ? '' :
                    `<div class="text-white-50" style="font-size: .72rem"
                        title="Ciclo con toda la espera real, sin aplicar el tope del 18 %">
                        tardado ${min(f.prom_ciclo_sin_tope_min)}</div>`;
                const esperas = (f.prom_fuera_min == null && f.prom_dentro_min == null) ? '' :
                    `<div class="text-warning" style="font-size: .72rem"
                        title="Espera media fuera (E2 + E5) y dentro (E3 + E6) de planta, medida con el tope del 18 %">
                        fuera ${min(f.prom_fuera_min)} · dentro ${min(f.prom_dentro_min)}</div>`;
                const tope = f.tope_min == null
                    ? `<span class="badge bg-secondary" title="Compilada sin tope global: entraron todos los viajes medibles">sin tope</span>
                       <div class="text-white-50" style="font-size: .68rem">${DIAS_MODO[f.modo] ?? esc(f.modo)}</div>`
                    : `<span class="badge bg-secondary">${num(f.tope_min)} min</span>
                       ${f.tope_espera_min == null ? '' : `<span class="badge bg-info text-dark"
                           title="Tope de espera (p82 de la ruta): pierde como máximo el 18 % de los viajes; hoy deja fuera ${num(f.espera_descartados)}">esp. ${num(f.tope_espera_min)}</span>`}
                       <div class="text-white-50" style="font-size: .68rem">${DIAS_MODO[f.modo] ?? esc(f.modo)}</div>`;

                return `<tr>
                    <td>
                        <div class="text-nowrap text-light">${esc(f.nombre)}</div>
                        <div class="text-white-50" style="font-size: .72rem">${referencia}</div>
                    </td>
                    <td class="text-end text-nowrap">
                        <span class="fs-6 text-info">${min(f.prom_efectivo)}</span>
                        ${fusion ? `<div class="badge bg-success text-dark" style="font-size: .65rem"
                            title="Promedio de los dos promedios guardados del mismo par (ida y vuelta)">
                            fusión · ${num(f.viajes_fusion)} viaje(s)</div>` : ''}
                    </td>
                    <td class="text-end text-nowrap text-light">${min(f.prom_min)}</td>
                    <td class="text-end text-nowrap text-white-50">${min(f.prom_sin_tope_min)}</td>
                    <td class="text-end text-nowrap">
                        ${min(f.prom_ciclo_min)}
                        ${f.ciclo_viajes ? `<div class="text-white-50" style="font-size: .72rem">${num(f.ciclo_viajes)} viaje(s)</div>` : ''}
                        ${ciclo_rapido}
                        ${ciclo_tardado}
                        ${esperas}
                    </td>
                    <td class="text-end text-nowrap text-white-50">${rango}</td>
                    <td class="text-end text-nowrap text-white-50">${min(f.desv_min)}</td>
                    <td class="text-end text-nowrap">
                        ${num(f.viajes)}
                        ${nota_viajes}
                    </td>
                    <td class="text-end text-nowrap">${num(f.descartados)}</td>
                    <td class="text-end text-nowrap">
                        <span class="badge bg-${color_perdido(f.pct_perdido)}">
                            ${f.pct_perdido == null ? '—' : f.pct_perdido + ' %'}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">${tope}</td>
                    <td class="text-nowrap text-white-50" style="font-size: .75rem">${esc(f.actualizado_en ?? '—')}</td>
                </tr>`;
            }).join('');

            document.getElementById('bloque_tabla').style.display = filas.length ? '' : 'none';
            document.getElementById('sin_datos').style.display = filas.length ? 'none' : '';
            document.getElementById('tabla_nota').textContent = (data.mostradas ?? 0) + ' de ' + (data.total ?? 0) + ' ruta(s)'
                + (data.filtros && data.filtros.resumir ? ' · respuesta resumida' : '');

            render_ubicaciones(data.ubicaciones);
        }

        function render_ubicaciones(ubs) {
            const bloque = document.getElementById('bloque_ubicaciones');
            if (!ubs) {
                bloque.style.display = 'none';
                return;
            }

            const claves = Object.keys(ubs).sort();
            document.getElementById('tbody_ubicaciones').innerHTML = claves.map(k => {
                const u = ubs[k] || {};
                const pks = (u.palabras_claves || [])
                    .map(p => `<code class="text-warning">${esc(p)}</code>`).join(' ');
                return `<tr>
                    <td><code class="text-info">${esc(k)}</code></td>
                    <td>${esc(u.nombre ?? '')}</td>
                    <td>${pks || '—'}</td>
                </tr>`;
            }).join('');

            document.getElementById('ub_count').textContent = claves.length;
            bloque.style.display = '';
        }

        // ============ promedios por planta ============

        function build_url_plantas() {
            const params = new URLSearchParams();
            CAMPOS_PLANTAS.forEach(c => {
                const el = document.querySelector(`#form_plantas [name="${c}"]`);
                if (!el) return;
                const v = el.value.trim();
                if (v !== '') params.set(c, v);
            });
            const q = params.toString();
            return ENDPOINT_PLANTAS + (q ? '?' + q : '');
        }

        // el criterio de las plantas es que el tope deje fuera como maximo el 18 %
        function color_descartado(pct) {
            if (pct === null || pct === undefined) return 'secondary';
            if (pct <= 0) return 'success';
            if (pct <= 18) return 'warning';
            return 'danger';
        }

        function render_plantas(data) {
            const r = data.resumen || {};

            document.getElementById('rp_plantas').textContent = num(r.plantas);
            document.getElementById('rp_plantas_nota').textContent = (data.mostradas ?? 0) + ' en la tabla';
            document.getElementById('rp_lados').textContent = num(r.lados);
            document.getElementById('rp_lados_nota').textContent =
                num(r.lados_carga) + ' carga · ' + num(r.lados_descarga) + ' descarga';
            document.getElementById('rp_fuera').textContent = min(r.prom_fuera_min);
            document.getElementById('rp_dentro').textContent = min(r.prom_dentro_min);
            document.getElementById('rp_total').textContent = min(r.prom_espera_min);
            document.getElementById('rp_descartados').textContent = num(r.descartados);
            document.getElementById('rp_descartados').className = 'fs-4 text-' + color_descartado(r.pct_descartado);
            document.getElementById('rp_descartados_nota').textContent =
                (r.pct_descartado === null ? '—' : r.pct_descartado + ' %')
                + ' · fuera ' + num(r.descartados_fuera) + ' · dentro ' + num(r.descartados_dentro);
            document.getElementById('resumen_plantas').style.display = '';

            const filas = data.plantas || [];
            document.getElementById('tbody_plantas').innerHTML = filas.map(p => {
                const tope = (p.tope_fuera_min == null && p.tope_dentro_min == null)
                    ? '<span class="text-white-50">—</span>'
                    : `<div class="text-nowrap">
                           <span class="badge bg-secondary" title="Tope de espera fuera">${min(p.tope_fuera_min)}</span>
                           <span class="badge bg-secondary" title="Tope de espera dentro">${min(p.tope_dentro_min)}</span>
                       </div>
                       <div class="text-white-50" style="font-size: .68rem">
                           fuera · dentro · ${esc(p.modo)}${p.percentil ? ' p' + p.percentil : ''}
                       </div>`;
                const descartados = `<div class="text-nowrap">${num(p.descartados)}</div>
                    <div class="text-white-50" style="font-size: .72rem">
                        ${p.pct_descartado == null ? '—' : p.pct_descartado + ' %'}
                    </div>
                    <div class="text-white-50" style="font-size: .68rem">
                        fuera ${num(p.descartados_fuera)} · dentro ${num(p.descartados_dentro)}
                    </div>`;
                const referencia = [
                    esc(p.planta_id),
                    p.palabra_clave ? esc(p.palabra_clave) : '',
                    p.departamento ? esc(p.departamento) : '',
                ].filter(Boolean).join(' · ');

                return `<tr>
                    <td>
                        <div class="text-nowrap text-light">${esc(p.nombre)}</div>
                        <div class="text-white-50" style="font-size: .72rem">${referencia}</div>
                    </td>
                    <td class="text-end text-nowrap">
                        ${num(p.lados)}
                        <div class="text-white-50" style="font-size: .72rem">
                            ${num(p.viajes_carga)} carga · ${num(p.viajes_descarga)} descarga
                        </div>
                        <div class="text-white-50" style="font-size: .68rem">
                            ${num(p.rutas_carga)} / ${num(p.rutas_descarga)} rutas
                        </div>
                    </td>
                    <td class="text-end text-nowrap text-light">${min(p.prom_fuera_carga_min)}</td>
                    <td class="text-end text-nowrap text-light">${min(p.prom_fuera_descarga_min)}</td>
                    <td class="text-end text-nowrap text-info">${min(p.prom_fuera_min)}</td>
                    <td class="text-end text-nowrap text-light">${min(p.prom_dentro_carga_min)}</td>
                    <td class="text-end text-nowrap text-light">${min(p.prom_dentro_descarga_min)}</td>
                    <td class="text-end text-nowrap text-info">${min(p.prom_dentro_min)}</td>
                    <td class="text-end text-nowrap fw-bold text-warning">${min(p.prom_espera_min)}</td>
                    <td class="text-end text-nowrap">${tope}</td>
                    <td class="text-end text-nowrap">${descartados}</td>
                    <td class="text-nowrap text-white-50" style="font-size: .75rem">${esc(p.actualizado_en ?? '—')}</td>
                </tr>`;
            }).join('');

            document.getElementById('tabla_plantas').style.display = filas.length ? '' : 'none';
            document.getElementById('sin_plantas').style.display = filas.length ? 'none' : '';
            document.getElementById('nota_plantas').textContent =
                (data.mostradas ?? 0) + ' de ' + (data.total ?? 0) + ' planta(s)';
        }

        async function consultar_plantas() {
            const url = build_url_plantas();
            document.getElementById('url_plantas').value = url;
            const estado = document.getElementById('estado_plantas');
            const btn = document.getElementById('btn_plantas');

            btn.disabled = true;
            estado.innerHTML = '<span class="text-white-50"><i class="bi bi-hourglass-split"></i> Consultando plantas…</span>';

            const t0 = performance.now();
            try {
                const res = await fetch(url, {headers: {'Accept': 'application/json'}});
                const ms = Math.round(performance.now() - t0);
                const data = await res.json();

                if (!res.ok || data.success === false) {
                    estado.innerHTML = `<span class="badge bg-danger">HTTP ${res.status} · ${ms} ms</span>`;
                    document.getElementById('resumen_plantas').style.display = 'none';
                    document.getElementById('tabla_plantas').style.display = 'none';
                    document.getElementById('sin_plantas').style.display = 'none';
                } else {
                    estado.innerHTML = `<span class="badge bg-success text-dark">HTTP ${res.status} · ${ms} ms ·
                        ${(data.plantas || []).length} planta(s)</span>`;
                    render_plantas(data);
                }
            } catch (e) {
                estado.innerHTML = `<span class="badge bg-danger">Error de red: ${esc(e.message)}</span>`;
            } finally {
                btn.disabled = false;
            }
        }

        async function consultar() {
            const url = build_url();
            document.getElementById('url_final').value = url;
            const estado = document.getElementById('estado');
            const btn = document.getElementById('btn_llamar');

            btn.disabled = true;
            estado.innerHTML = '<span class="text-white-50"><i class="bi bi-hourglass-split"></i> Consultando…</span>';
            document.getElementById('json_raw').textContent = '';

            const t0 = performance.now();
            try {
                const res = await fetch(url, {headers: {'Accept': 'application/json'}});
                const ms = Math.round(performance.now() - t0);
                const data = await res.json();
                document.getElementById('json_raw').textContent = JSON.stringify(data, null, 2);
                document.getElementById('json_nota').textContent = 'GET ' + url;

                if (!res.ok || data.success === false) {
                    estado.innerHTML = `<span class="badge bg-danger">HTTP ${res.status} · ${ms} ms</span>`;
                    document.getElementById('bloque_resumen').style.display = 'none';
                    document.getElementById('bloque_tabla').style.display = 'none';
                    document.getElementById('bloque_ubicaciones').style.display = 'none';
                    document.getElementById('sin_datos').style.display = 'none';
                } else {
                    estado.innerHTML = `<span class="badge bg-success text-dark">HTTP ${res.status} · ${ms} ms ·
                        ${(data.rutas || []).length} ruta(s)</span>`;
                    render(data);
                }
            } catch (e) {
                estado.innerHTML = `<span class="badge bg-danger">Error de red: ${esc(e.message)}</span>`;
            } finally {
                btn.disabled = false;
            }
        }

        document.getElementById('form_api').addEventListener('submit', function (ev) {
            ev.preventDefault();
            consultar();
        });

        document.getElementById('btn_limpiar').addEventListener('click', function () {
            document.getElementById('form_api').reset();
            document.getElementById('url_final').value = '';
            document.getElementById('estado').innerHTML = '';
            document.getElementById('json_raw').textContent = '';
            document.getElementById('bloque_resumen').style.display = 'none';
            document.getElementById('bloque_tabla').style.display = 'none';
            document.getElementById('bloque_ubicaciones').style.display = 'none';
            document.getElementById('sin_datos').style.display = 'none';
            consultar();
        });

        document.getElementById('btn_copiar_url').addEventListener('click', function () {
            const url = build_url();
            document.getElementById('url_final').value = url;
            navigator.clipboard.writeText(url).then(() => {
                const b = this;
                b.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                setTimeout(() => b.innerHTML = '<i class="bi bi-clipboard"></i> Copiar URL', 1500);
            });
        });

        document.getElementById('form_plantas').addEventListener('submit', function (ev) {
            ev.preventDefault();
            consultar_plantas();
        });

        document.getElementById('btn_limpiar_plantas').addEventListener('click', function () {
            document.getElementById('form_plantas').reset();
            document.getElementById('url_plantas').value = '';
            document.getElementById('estado_plantas').innerHTML = '';
            document.getElementById('resumen_plantas').style.display = 'none';
            document.getElementById('tabla_plantas').style.display = 'none';
            document.getElementById('sin_plantas').style.display = 'none';
            consultar_plantas();
        });

        // la pantalla abre con las dos consultas hechas (rutas y plantas)
        consultar();
        consultar_plantas();
    </script>
@endsection
