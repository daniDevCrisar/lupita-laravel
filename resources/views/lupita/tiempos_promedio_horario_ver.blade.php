@extends('layouts.app')

@section('title', 'Promedios compilados por día y hora')

@section('content')

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="mb-1 text-light">Promedios compilados por día y hora</h1>
            <p class="text-light small mb-2">
                Lo que ya está guardado en <code class="text-info">tiempos_ruta_horario</code> por
                <a href="{{ route('lupita.tiempos.promedio.horario') }}" class="text-info">Compilar horarios</a>:
                matriz <strong>ruta &times; día de la semana &times; hora de inicio de ruta</strong> de la etapa
                <em>inicio de ruta &rarr; llegada a destino</em>. Los tiempos salen en
                <span class="badge bg-info text-dark">minutos</span>.
            </p>
            <p class="text-light small mb-0">
                Esta pantalla no calcula: consulta el endpoint público
                <code class="text-warning">GET /api/tiempos/promedio/horario</code>. El mapa de calor y los
                promedios por hora se arman con la respuesta, pesando cada celda por sus viajes.
            </p>
        </div>
        <div class="text-end small">
            <span class="badge bg-{{ $estado->tablas ? 'success' : 'warning' }} text-dark">
                {{ $estado->tablas ? 'Tablas listas' : 'Faltan las tablas (aplicar migración)' }}
            </span>
            @if($estado->celdas > 0)
                <div class="text-light mt-1">
                    {{ number_format($estado->celdas) }} celda(s) de {{ $estado->rutas }} ruta(s)<br>
                    {{ number_format($estado->viajes) }} viaje(s) · {{ $estado->topes }} tope(s)
                </div>
            @endif
            <div class="mt-1">
                <a href="{{ route('lupita.tiempos.promedio.ver') }}" class="text-info">
                    Ver promedios por ruta <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- ================= FILTROS ================= --}}
    <div class="card border-secondary mt-3 mb-3">
        <div class="card-body py-3">
            <form id="form_api" class="row g-2 align-items-end">
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
                    <label class="form-label small mb-0" for="f_dia">Día</label>
                    <select name="dia" id="f_dia" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($dias as $indice => $nombre)
                            <option value="{{ $indice }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-1">
                    <label class="form-label small mb-0" for="f_hora_desde">Desde</label>
                    <input type="number" name="hora_desde" id="f_hora_desde" min="0" max="23"
                           placeholder="0" class="form-control form-control-sm">
                </div>

                <div class="col-md-1">
                    <label class="form-label small mb-0" for="f_hora_hasta">Hasta</label>
                    <input type="number" name="hora_hasta" id="f_hora_hasta" min="0" max="23"
                           placeholder="23" class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_min_viajes">Mín. viajes</label>
                    <input type="number" name="min_viajes" id="f_min_viajes" min="1" step="1"
                           value="1" class="form-control form-control-sm">
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="f_orden">Ordenar celdas por</label>
                    <select name="orden" id="f_orden" class="form-control form-control-sm">
                        @foreach($ordenes as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($clave === 'viajes')>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="f_limit">Filas</label>
                    <select name="limit" id="f_limit" class="form-control form-control-sm">
                        @foreach([200, 500, 1000] as $opcion)
                            <option value="{{ $opcion }}" @selected($opcion === $limite)>{{ $opcion }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">
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
                <div class="small text-light">Celdas</div>
                <div class="fs-4 text-light" id="rs_celdas">–</div>
                <div class="small text-white-50" id="rs_celdas_nota">–</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Rutas</div>
                <div class="fs-4 text-light" id="rs_rutas">–</div>
                <div class="small text-white-50" id="rs_topes">–</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Viajes</div>
                <div class="fs-4 text-light" id="rs_viajes">–</div>
                <div class="small text-white-50">Suma de las celdas</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Promedio ponderado</div>
                <div class="fs-4 text-info" id="rs_prom">–</div>
                <div class="small text-white-50">Cada celda pesa por viajes</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Más rápido</div>
                <div class="fs-4 text-success" id="rs_min">–</div>
                <div class="small text-white-50">Celda con menor tiempo</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-secondary h-100"><div class="card-body py-2">
                <div class="small text-light">Más lento</div>
                <div class="fs-4 text-danger" id="rs_max">–</div>
                <div class="small text-white-50">Celda con mayor tiempo</div>
            </div></div>
        </div>
    </div>

    <div id="sin_datos" class="text-center text-white-50 py-5" style="display: none;">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        No hay celdas día/hora compiladas con estos filtros.
        <div class="small mt-1">
            Compílalas en <a href="{{ route('lupita.tiempos.promedio.horario') }}" class="text-info">Compilar horarios</a>.
        </div>
    </div>

    {{-- ================= MAPA DE CALOR ================= --}}
    <div id="bloque_mapa" style="display: none;">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <h2 class="fs-5 text-light mb-0">Día &times; hora</h2>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-light mb-0" for="f_metrica">Ver</label>
                <select id="f_metrica" class="form-select form-select-sm" style="width: 170px">
                    <option value="prom">Promedio (min)</option>
                    <option value="viajes">Viajes</option>
                </select>
                <span class="small text-white-50" id="mapa_nota"></span>
            </div>
        </div>

        <div class="table-responsive mt-2">
            <table class="table table-sm table-bordered align-middle mb-0" style="table-layout: fixed">
                <thead class="thead-lupita">
                <tr>
                    <th style="width: 96px">Día</th>
                    <th class="text-center" id="th_00" style="width: 34px">00</th>
                    <th class="text-center" style="width: 34px">01</th>
                    <th class="text-center" style="width: 34px">02</th>
                    <th class="text-center" style="width: 34px">03</th>
                    <th class="text-center" style="width: 34px">04</th>
                    <th class="text-center" style="width: 34px">05</th>
                    <th class="text-center" style="width: 34px">06</th>
                    <th class="text-center" style="width: 34px">07</th>
                    <th class="text-center" style="width: 34px">08</th>
                    <th class="text-center" style="width: 34px">09</th>
                    <th class="text-center" style="width: 34px">10</th>
                    <th class="text-center" style="width: 34px">11</th>
                    <th class="text-center" style="width: 34px">12</th>
                    <th class="text-center" style="width: 34px">13</th>
                    <th class="text-center" style="width: 34px">14</th>
                    <th class="text-center" style="width: 34px">15</th>
                    <th class="text-center" style="width: 34px">16</th>
                    <th class="text-center" style="width: 34px">17</th>
                    <th class="text-center" style="width: 34px">18</th>
                    <th class="text-center" style="width: 34px">19</th>
                    <th class="text-center" style="width: 34px">20</th>
                    <th class="text-center" style="width: 34px">21</th>
                    <th class="text-center" style="width: 34px">22</th>
                    <th class="text-center" style="width: 34px">23</th>
                    <th class="text-end" style="width: 84px" title="Promedio del día pesado por viajes">Día</th>
                </tr>
                </thead>
                <tbody id="tbody_mapa"></tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-1 small text-white-50">
            <span>Verde: más rápido · Rojo: más lento. Cada celda muestra el promedio de esa hora.</span>
            <span id="mapa_escala"></span>
        </div>

        {{-- promedio por hora: refuerza la lectura del mapa --}}
        <h2 class="fs-6 text-light mt-4">Promedio por hora (todas las celdas visibles)</h2>
        <div class="d-flex align-items-end gap-1" id="barras_hora" style="height: 90px"></div>
        <div class="d-flex gap-1 mt-1" id="etiquetas_hora"></div>
    </div>

    {{-- ================= TABLA DE CELDAS ================= --}}
    <div id="bloque_tabla" class="mt-4" style="display: none;">
        <div class="d-flex justify-content-between align-items-end">
            <h2 class="fs-5 text-light mb-0">Celdas compiladas</h2>
            <div class="small text-white-50" id="tabla_nota"></div>
        </div>

        <div class="table-responsive mt-2" style="max-height: 60vh; overflow: auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="thead-lupita" style="position: sticky; top: 0; z-index: 1">
                <tr>
                    <th>Ruta</th>
                    <th>Día</th>
                    <th class="text-end">Hora</th>
                    <th class="text-end">Viajes</th>
                    <th class="text-end">Promedio</th>
                    <th class="text-end">Tope</th>
                </tr>
                </thead>
                <tbody id="tbody_celdas"></tbody>
            </table>
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
        const DIAS = @json($dias);
        const CAMPOS = ['ruta_id', 'dia', 'hora_desde', 'hora_hasta', 'min_viajes', 'orden', 'limit'];

        let ULTIMA = null;

        function build_url() {
            const params = new URLSearchParams();
            CAMPOS.forEach(c => {
                const el = document.querySelector(`#form_api [name="${c}"]`);
                if (!el) return;
                const v = el.value.trim();
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

        function hora(h) {
            return String(h).padStart(2, '0') + ':00';
        }

        // verde (rapido) -> rojo (lento); el texto se mantiene claro
        function fondo_prom(prom, min_v, max_v) {
            if (prom === null || prom === undefined) return 'transparent';
            const t = max_v > min_v ? (prom - min_v) / (max_v - min_v) : 0.5;
            return `hsla(${Math.round(140 * (1 - t))}, 62%, 30%, .92)`;
        }

        function fondo_viajes(viajes, min_v, max_v) {
            if (!viajes) return 'transparent';
            const t = max_v > min_v ? (viajes - min_v) / (max_v - min_v) : 0.5;
            return `hsla(200, 70%, 32%, ${(0.15 + 0.75 * t).toFixed(2)})`;
        }

        function render_resumen(data) {
            const r = data.resumen || {};
            document.getElementById('rs_celdas').textContent = num(r.celdas);
            document.getElementById('rs_celdas_nota').textContent = (data.mostradas ?? 0) + ' en la tabla · ' +
                (r.tablas ? 'tablas listas' : 'sin tablas');
            document.getElementById('rs_rutas').textContent = num(r.rutas);
            document.getElementById('rs_topes').textContent = (r.topes ?? 0) + ' tope(s) distinto(s)';
            document.getElementById('rs_viajes').textContent = num(r.viajes);
            document.getElementById('rs_prom').textContent = min(r.prom_ponderado);
            document.getElementById('rs_min').textContent = min(r.min);
            document.getElementById('rs_max').textContent = min(r.max);
            document.getElementById('bloque_resumen').style.display = '';
        }

        function render_mapa(data) {
            const metrica = document.getElementById('f_metrica').value;
            const matriz = data.matriz || [];
            const dias = data.dias || DIAS;

            // escalas: promedios y viajes de las celdas existentes
            const promedios = [], viajes = [];
            matriz.forEach(fila => (fila || []).forEach(c => {
                if (!c) return;
                if (c.prom_min !== null && c.prom_min !== undefined) promedios.push(Number(c.prom_min));
                viajes.push(Number(c.viajes) || 0);
            }));
            const min_prom = promedios.length ? Math.min(...promedios) : 0;
            const max_prom = promedios.length ? Math.max(...promedios) : 0;
            const min_viajes = viajes.length ? Math.min(...viajes) : 0;
            const max_viajes = viajes.length ? Math.max(...viajes) : 0;

            const tbody = document.getElementById('tbody_mapa');
            const por_dia = data.por_dia || [];

            tbody.innerHTML = (matriz.length ? matriz : Array.from({length: 7}, () => Array(24).fill(null))).map((fila, dia) => {
                const celdas = Array.from({length: 24}, (_, h) => {
                    const c = fila[h];
                    const prom = c ? Number(c.prom_min) : null;
                    const v = c ? Number(c.viajes) : 0;
                    const fondo = metrica === 'viajes'
                        ? fondo_viajes(v, min_viajes, max_viajes)
                        : fondo_prom(prom, min_prom, max_prom);
                    const texto = !c ? '·' : (metrica === 'viajes' ? num(v) : min(prom));
                    const clase = c ? 'text-light' : 'text-white-50';
                    const title = c
                        ? `${dias[dia]} ${hora(h)}: ${num(v)} viaje(s) · ${min(prom)} · ${
                            c.tope_min == null ? 'sin tope' : 'tope ' + num(c.tope_min) + ' min'}`
                        : `${dias[dia]} ${hora(h)}: sin datos`;

                    return `<td class="text-center ${clase} p-0" style="background: ${fondo}; font-size: .66rem"
                                title="${esc(title)}">${esc(texto)}</td>`;
                }).join('');

                const resumen_dia = por_dia[dia] || {};
                return `<tr>
                    <th class="text-nowrap text-light" style="font-size: .78rem">${esc(dias[dia])}</th>
                    ${celdas}
                    <td class="text-end text-nowrap text-white-50" style="font-size: .72rem">
                        ${min(resumen_dia.prom_min)}
                    </td>
                </tr>`;
            }).join('');

            document.getElementById('mapa_nota').textContent =
                metrica === 'viajes'
                    ? `${num(min_viajes)} a ${num(max_viajes)} viaje(s) por celda`
                    : `${min(min_prom)} a ${min(max_prom)}`;
            document.getElementById('mapa_escala').textContent =
                metrica === 'viajes' ? 'Azul más intenso: más viajes' : 'Escala de tiempo: verde → rojo';

            render_barras_hora(data.por_hora || []);
        }

        function render_barras_hora(por_hora) {
            const valores = por_hora.map(h => Number(h.viajes) || 0);
            const maximo = Math.max(1, ...valores);

            document.getElementById('barras_hora').innerHTML = por_hora.map((h, hora_actual) => {
                const alto = Math.max(2, Math.round((Number(h.viajes) || 0) / maximo * 100));
                const title = `${hora(hora_actual)}: ${num(h.viajes)} viaje(s) · ${min(h.prom_min)}`;
                return `<div class="flex-fill d-flex align-items-end" title="${esc(title)}" style="height: 100%">
                    <div style="width: 100%; height: ${alto}%; background: hsla(200, 70%, 40%, .85); border-radius: 2px 2px 0 0"></div>
                </div>`;
            }).join('');

            document.getElementById('etiquetas_hora').innerHTML = por_hora.map((h, hora_actual) =>
                `<div class="flex-fill text-center text-white-50" style="font-size: .6rem">${
                    hora_actual % 3 === 0 ? String(hora_actual).padStart(2, '0') : ''
                }</div>`
            ).join('');
        }

        function render_celdas(data) {
            const celdas = data.celdas || [];
            const dias = data.dias || DIAS;

            document.getElementById('tbody_celdas').innerHTML = celdas.map(c => `<tr>
                <td>
                    <div class="text-nowrap text-light">${esc(c.nombre)}</div>
                    <div class="text-white-50" style="font-size: .72rem">${esc(c.ruta_id)}</div>
                </td>
                <td class="text-nowrap">${esc(dias[c.dia] ?? c.dia)}</td>
                <td class="text-end text-nowrap">${hora(c.hora)}</td>
                <td class="text-end">${num(c.viajes)}</td>
                <td class="text-end text-nowrap text-info">${min(c.prom_min)}</td>
                <td class="text-end text-nowrap text-white-50">${
                    c.tope_min == null ? 'sin tope' : num(c.tope_min) + ' min'}</td>
            </tr>`).join('');

            document.getElementById('bloque_tabla').style.display = celdas.length ? '' : 'none';
            document.getElementById('tabla_nota').textContent =
                (data.mostradas ?? 0) + ' de ' + (data.total ?? 0) + ' celda(s)';
        }

        function render(data) {
            ULTIMA = data;
            render_resumen(data);
            render_mapa(data);
            render_celdas(data);

            const hay = (data.celdas || []).length > 0;
            document.getElementById('bloque_mapa').style.display = hay ? '' : 'none';
            document.getElementById('sin_datos').style.display = hay ? 'none' : '';
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
                    document.getElementById('bloque_mapa').style.display = 'none';
                    document.getElementById('bloque_tabla').style.display = 'none';
                    document.getElementById('sin_datos').style.display = 'none';
                } else {
                    estado.innerHTML = `<span class="badge bg-success text-dark">HTTP ${res.status} · ${ms} ms ·
                        ${(data.celdas || []).length} celda(s)</span>`;
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

        document.getElementById('f_metrica').addEventListener('change', function () {
            if (ULTIMA) render_mapa(ULTIMA);
        });

        document.getElementById('btn_limpiar').addEventListener('click', function () {
            document.getElementById('form_api').reset();
            document.getElementById('url_final').value = '';
            document.getElementById('estado').innerHTML = '';
            document.getElementById('json_raw').textContent = '';
            document.getElementById('bloque_resumen').style.display = 'none';
            document.getElementById('bloque_mapa').style.display = 'none';
            document.getElementById('bloque_tabla').style.display = 'none';
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
