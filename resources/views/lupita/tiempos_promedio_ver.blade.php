@extends('layouts.app')

@section('title', 'Promedios compilados por ruta')

@section('content')

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1 text-light">Promedios compilados por ruta</h1>
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
                si no, el promedio del sentido. Las casillas <em>Respuesta resumida</em> y
                <em>Palabras clave</em> agregan <code>?resumir=1</code> y <code>?palabras_claves=1</code>.
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
                        <input class="form-check-input" type="checkbox" value="1" id="f_palabras_claves" name="palabras_claves">
                        <label class="form-check-label small text-light" for="f_palabras_claves">
                            Incluir palabras clave <span class="text-white-50">(mapa de ubicaciones)</span>
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
                <div class="small text-light">Promedio sin tope</div>
                <div class="fs-4 text-warning" id="rs_sin_tope">–</div>
                <div class="small text-white-50">Toda la data</div>
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

    {{-- ================= UBICACIONES (palabras_claves=1) ================= --}}
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
        const DIAS_MODO = {global: 'global', ruta: 'p97', propio: 'tope propio'};

        function build_url() {
            const params = new URLSearchParams();
            CAMPOS.forEach(c => {
                const el = document.querySelector(`#form_api [name="${c}"]`);
                if (!el) return;
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
                document.getElementById('rs_sin_tope').textContent = min(r.prom_sin_tope);
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
                const tope = f.tope_min == null
                    ? '<span class="text-white-50">—</span>'
                    : `<span class="badge bg-secondary">${num(f.tope_min)} min</span>
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

        // la pantalla abre con la consulta ya hecha
        consultar();
    </script>
@endsection
