@extends('layouts.app')

@section('title', 'Probar API Tiempos por Ruta')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <h1 class="text-light">Probar API <small class="text-secondary fs-6">GET /api/tiempos/ruta</small></h1>
            <p class="text-light">
                Arma la query, llama al endpoint público y revisa la respuesta. Los tiempos salen en
                <span class="badge bg-info text-dark">minutos</span>. El rango de fechas usa las
                <b class="text-warning">fechas de las etapas E2–E6</b> (no la fecha de llamada).
                @can('ver tiempos')
                <a href="{{ route('lupita.tiempos') }}" class="text-info">Ver pantalla de tiempos <i class="bi bi-stopwatch"></i></a>
                @endcan
            </p>
        </div>
    </div>

    {{-- ================= PARAMETROS ================= --}}
    <div class="card bg-dark text-light border-secondary mb-3">
        <div class="card-body">
            <form id="form_api" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Tope por etapa (min)</label>
                    <input type="number" name="max_etapa" min="1" placeholder="sin tope" class="form-control form-control-sm" style="width: 150px;">
                </div>
                <div class="col-auto">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="f_trp" name="tiene_ruta_promedio">
                        <label class="form-check-label small" for="f_trp">Solo ruta promedio</label>
                    </div>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Ruta</label>
                    <input list="lista_rutas" name="ruta_id" placeholder="RUT_..." class="form-control form-control-sm" style="width: 240px;">
                    <datalist id="lista_rutas">
                        @foreach($rutas as $r)
                            <option value="{{ $r->id }}">{{ \App\Database\DBTiemposRuta::nombre_ruta($r) }}</option>
                        @endforeach
                    </datalist>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Conductor</label>
                    <input type="text" name="conductor" class="form-control form-control-sm" style="width: 150px;">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Transportista</label>
                    <input type="text" name="trt" class="form-control form-control-sm" style="width: 150px;">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Mín. viajes</label>
                    <input type="number" name="min_viajes" min="1" placeholder="1" class="form-control form-control-sm" style="width: 90px;">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Ordenar por</label>
                    <select name="ordenar_por" class="form-select form-select-sm" style="width: 140px;">
                        <option value="">(por defecto)</option>
                        <option value="ruta">ruta</option>
                        <option value="ciclo">ciclo</option>
                        <option value="viajes">viajes</option>
                        <option value="conductores">conductores</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Orden</label>
                    <select name="orden" class="form-select form-select-sm" style="width: 110px;">
                        <option value="">(defecto)</option>
                        <option value="1">ASC</option>
                        <option value="0">DESC</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Limit</label>
                    <input type="number" name="limit" min="1" max="1000" placeholder="100" class="form-control form-control-sm" style="width: 90px;">
                </div>
                <div class="col-12 mt-2 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" id="btn_llamar">
                        <i class="bi bi-lightning-charge"></i> Llamar al API
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" id="btn_limpiar">
                        <i class="bi bi-eraser"></i> Limpiar
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm" id="btn_copiar_url">
                        <i class="bi bi-clipboard"></i> Copiar URL
                    </button>
                </div>
            </form>
            <input type="text" id="url_final" class="form-control mt-2 bg-black text-light small" readonly>
        </div>
    </div>

    {{-- ================= RESULTADO ================= --}}
    <div id="estado" class="mb-2"></div>

    <div id="bloque_resumen" class="row g-2 mb-3" style="display: none;">
        <div class="col-6 col-md-3"><div class="card bg-dark border-secondary"><div class="card-body py-2"><div class="text-secondary small">Rutas</div><div class="fs-4 text-light" id="rs_rutas">–</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card bg-dark border-secondary"><div class="card-body py-2"><div class="text-secondary small">Viajes</div><div class="fs-4 text-light" id="rs_viajes">–</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card bg-dark border-secondary"><div class="card-body py-2"><div class="text-secondary small">Ruta promedio</div><div class="fs-4 text-info" id="rs_ruta_prom">–</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card bg-dark border-secondary"><div class="card-body py-2"><div class="text-secondary small">Ciclo promedio</div><div class="fs-4 text-warning" id="rs_ciclo_prom">–</div></div></div></div>
    </div>

    <div id="bloque_tabla" class="mb-4" style="display: none;">
        <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
            <table class="table table-bordered table-hover table-sm table-dark">
                <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                <tr>
                    <th>Ruta</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th class="text-end">Viajes</th>
                    <th class="text-end">Conduct.</th>
                    <th class="text-end">Ruta prom</th>
                    <th class="text-end">Ciclo prom</th>
                    <th>E2</th><th>E3</th><th>E4</th><th>E5</th><th>E6</th>
                </tr>
                </thead>
                <tbody id="tbody_rutas"></tbody>
            </table>
        </div>
    </div>

    {{-- ================= UBICACIONES UNICAS ================= --}}
    <div id="bloque_ubicaciones" class="mb-4" style="display: none;">
        <h2 class="text-light fs-5"><i class="bi bi-geo-alt"></i> Ubicaciones únicas <span class="badge bg-secondary" id="ub_count">0</span></h2>
        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
            <table class="table table-bordered table-sm table-dark">
                <thead class="table-secondary">
                <tr><th style="width:240px;">Clave</th><th style="width:260px;">Nombre</th><th>Palabras clave</th></tr>
                </thead>
                <tbody id="tbody_ubicaciones"></tbody>
            </table>
        </div>
    </div>

    <details class="mb-5">
        <summary class="text-info">Ver respuesta JSON cruda</summary>
        <pre id="json_raw" class="bg-black text-light p-3 mt-2 small" style="max-height: 520px; overflow: auto;"></pre>
    </details>

@endsection

@section('scripts')
    <script>
        const ENDPOINT = @json($endpoint);
        const CAMPOS = ['fecha_inicio','fecha_fin','max_etapa','tiene_ruta_promedio','ruta_id','conductor','trt','min_viajes','ordenar_por','orden','limit'];

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

        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        }

        function render(data) {
            const r = data.resumen || {};
            document.getElementById('rs_rutas').textContent = r.rutas ?? '–';
            document.getElementById('rs_viajes').textContent = r.viajes ?? '–';
            document.getElementById('rs_ruta_prom').textContent = min(r.ruta_promedio);
            document.getElementById('rs_ciclo_prom').textContent = min(r.ciclo_promedio);
            document.getElementById('bloque_resumen').style.display = '';

            // mapa de ubicaciones unicas: clave => {nombre, palabras_claves[]}
            const ubs = data.ubicaciones || {};
            const nomUb = (clave) => {
                if (!clave) return '—';
                return ubs[clave] ? ubs[clave].nombre : clave;
            };

            const filas = (data.rutas || []);
            const tbody = document.getElementById('tbody_rutas');
            tbody.innerHTML = filas.map(f => {
                const e = f.etapas || {};
                const cel = k => '<td class="text-nowrap">' + min(e[k] ? e[k].promedio : null) + '</td>';
                return `<tr>
                    <td class="text-nowrap"><span class="text-info">${esc(f.ruta_id)}</span><br><span class="small">${esc(f.nombre)}</span></td>
                    <td class="small text-nowrap">${esc(nomUb(f.origen))}</td>
                    <td class="small text-nowrap">${esc(nomUb(f.destino))}</td>
                    <td class="text-end">${f.viajes ?? ''}</td>
                    <td class="text-end">${f.conductores ?? ''}</td>
                    <td class="text-end text-info">${min(f.ruta_promedio)}</td>
                    <td class="text-end text-warning">${min(f.ciclo_promedio)}</td>
                    ${cel('E2')}${cel('E3')}${cel('E4')}${cel('E5')}${cel('E6')}
                </tr>`;
            }).join('');
            document.getElementById('bloque_tabla').style.display = filas.length ? '' : 'none';

            // tabla de ubicaciones unicas
            const claves = Object.keys(ubs).sort();
            const tbUb = document.getElementById('tbody_ubicaciones');
            tbUb.innerHTML = claves.map(k => {
                const u = ubs[k] || {};
                const pks = (u.palabras_claves || []).map(p => `<code class="text-warning">${esc(p)}</code>`).join(' ');
                return `<tr>
                    <td><code class="text-info">${esc(k)}</code></td>
                    <td>${esc(u.nombre ?? '')}</td>
                    <td>${pks || '—'}</td>
                </tr>`;
            }).join('');
            document.getElementById('ub_count').textContent = claves.length;
            document.getElementById('bloque_ubicaciones').style.display = claves.length ? '' : 'none';
        }

        document.getElementById('form_api').addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const url = build_url();
            document.getElementById('url_final').value = url;
            const estado = document.getElementById('estado');
            const btn = document.getElementById('btn_llamar');
            btn.disabled = true;
            estado.innerHTML = '<span class="text-secondary"><i class="bi bi-hourglass-split"></i> Llamando…</span>';
            document.getElementById('json_raw').textContent = '';

            const t0 = performance.now();
            try {
                const res = await fetch(url, {headers: {'Accept': 'application/json'}});
                const ms = Math.round(performance.now() - t0);
                const data = await res.json();
                document.getElementById('json_raw').textContent = JSON.stringify(data, null, 2);

                if (!res.ok || data.success === false) {
                    estado.innerHTML = `<span class="badge bg-danger">HTTP ${res.status} · ${ms} ms</span>`;
                    document.getElementById('bloque_resumen').style.display = 'none';
                    document.getElementById('bloque_tabla').style.display = 'none';
                    document.getElementById('bloque_ubicaciones').style.display = 'none';
                } else {
                    estado.innerHTML = `<span class="badge bg-success">HTTP ${res.status} · ${ms} ms · ${(data.rutas || []).length} rutas</span>`;
                    render(data);
                }
            } catch (e) {
                estado.innerHTML = `<span class="badge bg-danger">Error de red: ${esc(e.message)}</span>`;
            } finally {
                btn.disabled = false;
            }
        });

        document.getElementById('btn_limpiar').addEventListener('click', function () {
            document.getElementById('form_api').reset();
            document.getElementById('url_final').value = '';
            document.getElementById('estado').innerHTML = '';
            document.getElementById('json_raw').textContent = '';
            document.getElementById('bloque_resumen').style.display = 'none';
            document.getElementById('bloque_tabla').style.display = 'none';
            document.getElementById('bloque_ubicaciones').style.display = 'none';
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
    </script>
@endsection
