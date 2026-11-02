@extends('layouts.app')

@php
    use App\Database\DBTiemposRuta;

    // Referencias para el reloj de la animacion (naive UTC): limite y total filtrado.
    $totalMovs = count($movimientos);
    $truncado  = $totalMovs >= $limite;

    // Mapa de departamentos para el contexto estatico del canvas.
    // (string) en el cod: PHP numeriza las claves numericas ('15') y en JS la
    // comparacion estricta contra dep0/dep1 (string del SQL) fallaria.
    $depasLista = collect($depas)
        ->map(fn($d, $c) => ['cod' => (string) $c, 'nombre' => $d['nombre'], 'x' => $d['x'], 'y' => $d['y']])
        ->values();
@endphp

@section('title', 'Mapa animado de rutas')

@section('content')

<style>
    /* Modo presentacion: oculta contexto y ensancha el mapa */
    #mainWrap.pres .no-pres { display: none !important; }
    #mainWrap.pres .map-col { flex: 0 0 70%; max-width: 70%; }
    #mainWrap.pres .panel-col { flex: 0 0 30%; max-width: 30%; }
    #mainWrap.pres #mapWrap { max-width: 820px; }
    #mainWrap:fullscreen { background: #1a1d21; padding: 1rem; overflow: auto; }
    /* Contenedor del mapa transparente: el tinte dia/noche del canvas se ve sobre el
       fondo de la pagina y la transicion se percibe en toda el area del mapa */
    #mainWrap .mapCard, #mainWrap .mapCard .card-header, #mainWrap .mapCard .card-body { background: transparent; }
    #mainWrap .mapCard { border-color: rgba(255,255,255,.08); }
    #tooltip { box-shadow: 0 2px 10px rgba(0,0,0,.5); }
    #depPop { box-shadow: 0 3px 14px rgba(0,0,0,.6); }
    .viaje-row.row-spot { background: rgba(13,202,240,.14); }
    #ticks { cursor: pointer; }
    .trt-chip { line-height: 1.6; }
</style>

<div id="mainWrap">

    <div class="row no-pres">
        <div class="col-12">
            <h1><i class="bi bi-signpost-split"></i> Mapa animado de rutas <span class="text-white-50 fs-6">· movimientos por eventos</span></h1>
            <a href="{{ route('lupita.referencias', request()->query()) }}" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left"></i> Volver a la consulta (mismos filtros)
            </a>
        </div>
    </div>

    {{-- ===================== FILTROS (mismos que la consulta) ===================== --}}
    <div class="row no-pres">
        <form method="GET" class="mb-3">
            <fieldset class="border p-3 rounded">
                <legend class="float-none w-auto px-2 fs-6">Filtros</legend>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" value="{{ request('fecha_inicio') }}" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="fecha_fin">Fecha fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin" value="{{ request('fecha_fin') }}" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="q">Buscar</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" class="form-control" placeholder="ref, placa, conductor, titulo...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="ruta">Ruta</label>
                        <input type="text" id="ruta" name="ruta" value="{{ request('ruta') }}" class="form-control" placeholder="origen o destino...">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3 align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="sin_fin" name="sin_fin" {{ request('sin_fin') ? 'checked' : '' }}>
                            <label class="form-check-label" for="sin_fin"><i class="bi bi-hourglass-split"></i> Sin fin de descargue</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="completas" name="completas" {{ request('completas') ? 'checked' : '' }}>
                            <label class="form-check-label" for="completas"><i class="bi bi-check2-all"></i> Solo completas</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-funnel"></i> Aplicar</button>
                        <a href="{{ route('lupita.referencias.mapa') }}" class="btn btn-secondary btn-sm">Limpiar</a>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>

    {{-- ===================== INFO ===================== --}}
    <div class="row mb-2 no-pres">
        <div class="col-12">
            <span class="badge bg-secondary"><i class="bi bi-truck"></i> {{ $totalMovs }} movimientos cargados</span>
            @if($truncado)
                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Tope de {{ $limite }} alcanzado: acota el rango de fechas para ver todos</span>
            @endif
            <span class="badge bg-info"><i class="bi bi-layers"></i> Tramo: inicio_ruta &rarr; llegada destino</span>
            <span class="text-white-50 small ms-2">Camiones coloreados por TRT · hover = detalle · clic = llamadas del viaje.</span>
        </div>
    </div>

    {{-- ===================== CONTROLES ===================== --}}
    <div class="row mb-2">
        <div class="col-12">
            <div class="card">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button id="btnPlay" class="btn btn-success btn-sm" {{ $totalMovs ? '' : 'disabled' }}>
                            <i class="bi bi-play-fill"></i> Reproducir
                        </button>
                        <button id="btnReset" class="btn btn-outline-light btn-sm" {{ $totalMovs ? '' : 'disabled' }}>
                            <i class="bi bi-skip-start-fill"></i> Reiniciar
                        </button>
                        <button id="btnPres" class="btn btn-outline-info btn-sm" {{ $totalMovs ? '' : 'disabled' }}>
                            <i class="bi bi-display"></i> Presentación
                        </button>

                        <div class="d-flex align-items-center gap-1">
                            <span class="text-white-50 small me-1">Velocidad (s/hora):</span>
                            @foreach([0.5, 1, 2, 4, 8] as $p)
                                <button class="btn btn-sm btn-outline-info vel-preset" data-v="{{ $p }}">{{ $p }}</button>
                            @endforeach
                            <input type="number" id="velInput" class="form-control form-control-sm" style="width:90px"
                                   min="0.1" step="0.1" value="2" title="Segundos reales por cada hora simulada">
                        </div>

                        <div class="ms-auto text-end">
                            <div class="fw-bold text-light"><i class="bi bi-clock"></i> <span id="clock">--:--</span>
                                <span class="bi bi-moon-stars text-info" id="iconNoche" title="ciclo día/noche" style="display:none"></span>
                            </div>
                            <small class="text-white-50" id="clockDate">&nbsp;</small>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="text-white-50 small">T</span>
                        <div class="flex-grow-1">
                            <canvas id="ticks" height="14" class="w-100 d-block"></canvas>
                            <input type="range" id="scrub" class="form-range" min="0" max="1000" value="0" {{ $totalMovs ? '' : 'disabled' }}>
                        </div>
                        <span class="text-white-50 small">fin</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button id="btnPrevEv" class="btn btn-outline-light" title="evento anterior" {{ $totalMovs ? '' : 'disabled' }}><i class="bi bi-skip-back-fill"></i></button>
                            <button id="btnNextEv" class="btn btn-outline-light" title="siguiente evento" {{ $totalMovs ? '' : 'disabled' }}><i class="bi bi-skip-forward-fill"></i></button>
                        </div>
                        <span class="text-white-50 small ms-2" style="white-space:nowrap">
                            <span class="badge bg-success" id="statActivos">0</span> camiones ·
                            <span class="badge bg-secondary" id="statCompletados">0</span> completados
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== MAPA + CANVAS + RUTAS ACTIVAS ===================== --}}
    <div class="row g-3">
        <div class="col-lg-7 map-col">
            <div class="card h-100 mapCard">
                <div class="card-header"><i class="bi bi-geo-alt"></i> Movimientos de rutas en el tiempo</div>
                <div class="card-body">
                    <div id="mapWrap" class="position-relative" style="max-width:640px;margin:0 auto;">
                        <img id="mapImg" src="{{ asset('images/peru.png') }}" alt="Mapa Perú" class="w-100 rounded d-block" style="opacity:0">
                        <canvas id="mapCanvas" style="position:absolute;left:0;top:0;width:100%;height:100%;"></canvas>
                        <div id="tooltip" class="rounded p-2"
                             style="display:none;position:absolute;z-index:20;pointer-events:none;max-width:230px;background:rgba(20,24,28,.95);border:1px solid rgba(255,255,255,.2);"></div>
                        <div id="depPop" class="rounded p-2 small"
                             style="display:none;position:absolute;z-index:22;max-width:240px;background:rgba(20,24,28,.95);border:1px solid rgba(255,255,255,.25);"></div>
                    </div>
                    <div id="leyendaTrts" class="d-flex flex-wrap gap-1 justify-content-center mt-2"></div>
                    <div class="text-center mt-2 small text-white-50">
                        <span class="badge" style="background:rgba(40,167,69,.85)">&nbsp;</span> origen (onda verde al salir)
                        <span class="badge" style="background:rgba(255,193,7,.85)">&nbsp;</span> llegada (onda ámbar)
                        · 1 hora = <span id="velLabel">2</span> s · se reproduce en bucle · el mapa se oscurece de noche
                    </div>
                    <div class="text-center mt-1 small text-white-50">
                        <i class="bi bi-mouse"></i> rueda: zoom · arrastrar: mover · doble clic: reset
                        · <i class="bi bi-clock-history"></i> ticks arriba: <span class="text-success">salidas</span> / <span class="text-warning">llegadas</span>, clic salta al evento
                        · <i class="bi bi-geo-alt"></i> clic en un punto: detalle del departamento
                    </div>
                    @if(!$totalMovs)
                        <div class="alert alert-warning mt-3 mb-0 text-center">No hay movimientos para animar con este filtro (se requieren rutas con origen/destino y fechas de inicio_ruta y llegada).</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5 panel-col">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-broadcast"></i> Rutas activas en el instante</div>
                <div class="card-body p-0 d-flex flex-column">
                    <div class="px-3 py-2 border-bottom">
                        <span class="badge bg-primary" id="cntViajes">0</span> viajes en ruta
                        <span class="badge bg-info" id="cntRutas">0</span> rutas activas
                        <span class="text-white-50 small ms-1">% = avance origen &rarr; destino</span>
                    </div>
                    <div id="activos" style="overflow-y:auto;max-height:600px;" class="p-2 small"></div>
                </div>
            </div>
        </div>
    </div>

</div>

    <script>
    (function () {
        const MOV   = @json($movimientos);
        const DEPAS = @json($depasLista);
        const LLAMADAS_URL = '{{ route('lupita.llamadas') }}';
        const API_REF = '{{ route('api.referencias.llamadas', ['ref' => '__REF__']) }}';
        const hasData = MOV.length > 0;

        const canvas = document.getElementById('mapCanvas');
        const ctx = canvas.getContext('2d');
        const wrap = document.getElementById('mapWrap');
        const img = document.getElementById('mapImg');
        const tooltip = document.getElementById('tooltip');

        // Rango temporal de la animacion.
        let tMin = Infinity, tMax = -Infinity;
        MOV.forEach(m => { if (m.inicio < tMin) tMin = m.inicio; if (m.fin > tMax) tMax = m.fin; });
        if (!isFinite(tMin)) { tMin = 0; tMax = 1; }
        const span = Math.max(1, tMax - tMin);

        let t = tMin;                 // reloj simulado (ms)
        let sph = 2;                  // segundos reales por hora simulada
        let playing = false;
        let last = null;
        let raf = null;

        // ---- Vista zoom/pan (todo en memoria, sin backend) ----
        const view = { s: 1, tx: 0, ty: 0 };
        let W0 = 1, H0 = 1;           // tamano del mundo (canvas a s=1)
        const Z = () => 1 / view.s;   // para mantener grosores/tamanos constantes en pantalla
        let spotRef = null;           // ruta resaltada desde el panel
        let lastHover = null;         // camion bajo el cursor (para resaltar su fila)
        let dragMoved = false;

        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        // ---- Color por TRT (leyenda clickeable filtra camiones). Se colorean TODOS los
        // TRTs con la paleta en ciclo (nunca gris: el mapa base es gris y desapareceria).
        // La leyenda muestra solo los 12 mas frecuentes + un chip "Otros" que filtra el resto. ----
        const PALETA = ['#0dcaf0', '#20c997', '#fd7e14', '#d63384', '#6610f2', '#e0a800', '#dc3545', '#7d5ba6', '#198754', '#e35d5b', '#4cc9f0', '#f4a261'];
        const SIN_TRT = '\u2014';            // viaje sin transportista asignado
        const COLOR_SIN = '#f8f9fa';         // color constante, visible sobre el mapa gris
        const conteoTrt = {};
        MOV.forEach(m => { const k = m.trt || SIN_TRT; conteoTrt[k] = (conteoTrt[k] || 0) + 1; });
        const trtTodos = Object.keys(conteoTrt)
            .sort((a, b) => conteoTrt[b] - conteoTrt[a] || (a < b ? -1 : 1));
        const trtCon = trtTodos.filter(n => n !== SIN_TRT);   // el sin TRT no rota la paleta
        const trtTop = trtCon.slice(0, 12);
        const esTop = new Set(trtTop);
        const OTROS = '__OTROS__';
        const colorDe = {};
        trtCon.forEach((n, i) => colorDe[n] = PALETA[i % PALETA.length]);
        colorDe[SIN_TRT] = COLOR_SIN;
        MOV.forEach(m => { m.color = colorDe[m.trt || SIN_TRT]; });
        const hidden = new Set();
        const oculto = (m) => {
            const k = m.trt || SIN_TRT;
            if (k === SIN_TRT) return hidden.has(SIN_TRT);   // filtro propio, independiente de Otros
            return esTop.has(k) ? hidden.has(k) : hidden.has(OTROS);
        };

        // ---- Carriles paraleos: viajes de la misma ruta se desplazan lado a lado ----
        const grupos = {};
        MOV.forEach(m => {
            const k = Math.round(m.x0) + ',' + Math.round(m.y0) + ',' + Math.round(m.x1) + ',' + Math.round(m.y1);
            (grupos[k] = grupos[k] || []).push(m);
        });
        Object.keys(grupos).forEach(k => {
            const g = grupos[k];
            g.sort((a, b) => a.ref < b.ref ? -1 : 1);
            g.forEach((m, i) => m.lane = Math.max(-8, Math.min(8, i - (g.length - 1) / 2)));
        });

        // Tamano real del canvas al del area visible del mapa.
        function resize() {
            const r = wrap.getBoundingClientRect();
            canvas.width = Math.round(r.width);
            canvas.height = Math.round(r.height);
            W0 = canvas.width; H0 = canvas.height;
            view.s = 1; view.tx = 0; view.ty = 0;
            MOV.forEach(m => m._g = null);   // invalidate cache geometrica
            drawTicks();
            draw();
        }
        const X = (xp) => xp / 100 * canvas.width;
        const Y = (yp) => yp / 100 * canvas.height;

        // ---- Geometria del tramo: curva cuadratica con curvatura y offset de carril.
        // En viaje intra-departamento (origen==destino geografico) se usa un saltito
        // corto (3.5% del mapa) acotado dentro del area para no salirse del mapa. ----
        function geom(m) {
            if (m._g) return m._g;
            const W = canvas.width, H = canvas.height;
            const ox = X(m.x0), oy = Y(m.y0);
            let dx = X(m.x1), dy = Y(m.y1);
            if (Math.abs(dx - ox) < 1 && Math.abs(dy - oy) < 1) {
                const a = ((parseInt(m.ref, 36) || 0) % 360) * Math.PI / 180;
                const rr = Math.min(W, H) * 0.035;
                dx = Math.max(W * 0.04, Math.min(W * 0.96, ox + Math.cos(a) * rr));
                dy = Math.max(H * 0.04, Math.min(H * 0.96, oy + Math.sin(a) * rr));
            }
            const vx = dx - ox, vy = dy - oy;
            const len = Math.hypot(vx, vy) || 1;
            const ux = -vy / len, uy = vx / len;          // perpendicular al tramo
            const intra = len < Math.min(W, H) * 0.08;
            const curv = intra ? len * 0.5 : Math.min(70, len * 0.16);
            const off = (m.lane || 0) * 5;
            m._g = {
                ox: ox, oy: oy, dx: dx, dy: dy,
                cx: Math.max(2, Math.min(W - 2, (ox + dx) / 2 + ux * (curv + off))),
                cy: Math.max(2, Math.min(H - 2, (oy + dy) / 2 + uy * (curv + off)))
            };
            return m._g;
        }
        function bez(g, p) {
            const q = 1 - p;
            return {
                x: q * q * g.ox + 2 * q * p * g.cx + p * p * g.dx,
                y: q * q * g.oy + 2 * q * p * g.cy + p * p * g.dy
            };
        }
        function bezAng(g, p) {
            return Math.atan2(
                2 * (1 - p) * (g.cy - g.oy) + 2 * p * (g.dy - g.cy),
                2 * (1 - p) * (g.cx - g.ox) + 2 * p * (g.dx - g.cx)
            );
        }

        // ---- Camion RELLENO coloreable via SVG data-URI. bi-truck-fill no existe en
        // Bootstrap Icons y bi-truck se ve como contorno, asi que se usa una silueta
        // solida propia (caja + cabina + ruedas), morro a la derecha para rotarla
        // segun la tangente de la curva. ----
        function truckSvg(color) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="' + color + '">'
                + '<rect x="0.5" y="2.4" width="9.7" height="7.2" rx="1.1"/>'
                + '<path d="M10.6 4.6h2.7c.35 0 .68.17.88.46l1.85 2.6c.12.17.17.33.17.54v1.42h-5.6z"/>'
                + '<circle cx="3.5" cy="11.2" r="1.95"/><circle cx="12.1" cy="11.2" r="1.95"/>'
                + '</svg>';
        }
        const truckCache = {};
        function truckImg(color) {
            if (truckCache[color]) return truckCache[color];
            const im = new Image();
            im.onload = () => draw();            // redibujar cuando el sprite este listo
            im.src = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(truckSvg(color));
            truckCache[color] = im;
            return im;
        }
        function drawTruck(m, x, y, ang) {
            const im = truckImg(m.color);
            if (!im.complete || im.naturalWidth === 0) { dot(x, y, 3.2 * Z(), m.color); return; }
            const s = 17 * Z();
            ctx.save();
            ctx.translate(x, y);
            ctx.rotate(ang);
            ctx.shadowColor = 'rgba(0,0,0,.55)';
            ctx.shadowBlur = 3;
            ctx.drawImage(im, -s / 2, -s / 2, s, s);
            ctx.restore();
        }

        // ---- Ripples por hitos (salida verde, llegada ambar), vida en tiempo simulado ----
        const RIPPLE_LIFE = 25 * 60000;          // 25 min simulados
        function ripple(x, y, age, rgb) {
            if (age < 0 || age > RIPPLE_LIFE) return;
            const f = age / RIPPLE_LIFE;
            ctx.beginPath();
            ctx.arc(x, y, (2 + f * 9) * Z(), 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(' + rgb + ',' + (0.55 * (1 - f)).toFixed(3) + ')';
            ctx.lineWidth = 1.5 * Z();
            ctx.stroke();
        }

        // ---- Ciclo dia/noche sobre el reloj simulado (naive UTC) ----
        function nightness(ms) {
            const d = new Date(ms);
            const h = d.getUTCHours() + d.getUTCMinutes() / 60;
            if (h >= 6 && h < 18) return 0;
            if (h >= 18 && h < 21) return (h - 18) / 3;
            if (h >= 21 || h < 4) return 1;
            return 1 - (h - 4) / 2;              // amanece 04:00-06:00
        }

        function dot(x, y, rad, color, alpha) {
            ctx.globalAlpha = alpha == null ? 1 : alpha;
            ctx.beginPath();
            ctx.arc(x, y, rad, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.globalAlpha = 1;
        }

        function fmt(ms) {
            const d = new Date(ms);
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const mi = String(d.getUTCMinutes()).padStart(2, '0');
            return hh + ':' + mi;
        }
        const DIAS = ['Domingo', 'Lunes', 'Martes', 'Mi\u00e9rcoles', 'Jueves', 'Viernes', 'S\u00e1bado'];
        const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        function fmtDate(ms) {
            const d = new Date(ms);
            const dd = String(d.getUTCDate()).padStart(2, '0');
            const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
            return DIAS[d.getUTCDay()] + ' ' + dd + '/' + mm + '/' + d.getUTCFullYear() + ' \u00b7 ' + MESES[d.getUTCMonth()];
        }

        // ---- Panel de rutas activas en el instante simulado ----
        const panel = document.getElementById('activos');
        const cntViajes = document.getElementById('cntViajes');
        const cntRutas = document.getElementById('cntRutas');
        let lastPanel = 0;

        function actualizarPanel() {
            const activos = [];
            const rutas = new Set();
            for (const m of MOV) {
                if (oculto(m)) continue;
                if (t >= m.inicio && t <= m.fin) {
                    const p = m.fin > m.inicio ? (t - m.inicio) / (m.fin - m.inicio) : 1;
                    activos.push({ m: m, p: p });
                    rutas.add(m.origen + '|' + m.destino);
                }
            }
            cntViajes.textContent = activos.length;
            cntRutas.textContent = rutas.size;

            if (!activos.length) {
                panel.innerHTML = '<div class="text-center text-white-50 py-3">Ning\u00fan viaje en ruta en este instante.</div>';
                return;
            }
            activos.sort((a, b) => b.p - a.p);
            const top = activos.slice(0, 50);
            let html = '';
            for (const a of top) {
                const m = a.m, pct = Math.round(a.p * 100);
                html += '<div class="mb-2 pb-2 border-bottom viaje-row" data-ref="' + esc(m.ref) + '">'
                    + '<div class="d-flex justify-content-between align-items-center">'
                    + '<span class="text-light fw-bold text-truncate" title="' + esc(m.origen + ' - ' + m.destino) + '">'
                    + '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + m.color + '"></span> '
                    + esc(m.origen) + ' - ' + esc(m.destino) + '</span>'
                    + '<span class="badge bg-primary">' + pct + '%</span></div>'
                    + '<div class="text-white-50 text-truncate"><i class="bi bi-person"></i> ' + esc(m.conductor || '\u2014')
                    + ' \u00b7 <i class="bi bi-truck"></i> ' + esc(m.placa || '\u2014')
                    + ' \u00b7 ' + esc(m.trt || '\u2014')
                    + ' \u00b7 ref ' + esc(m.ref) + ' \u00b7 llega ' + fmt(m.fin) + '</div>'
                    + '<div class="progress mt-1" style="height:5px;"><div class="progress-bar" style="background:' + m.color + ';width:' + pct + '%"></div></div>'
                    + '</div>';
            }
            if (activos.length > top.length) {
                html += '<div class="text-center text-white-50 py-1">+' + (activos.length - top.length) + ' viajes m\u00e1s\u2026</div>';
            }
            panel.innerHTML = html;
            if (lastHover) setRowSpot(lastHover);   // el panel se reconstruye: re-aplicar resaltado
        }

        // ---- Leyenda TRT clickeable (filtra camiones del mapa) ----
        function construirLeyenda() {
            const nSin = conteoTrt[SIN_TRT] || 0;
            const nOtros = MOV.filter(m => {
                const k = m.trt || SIN_TRT;
                return !esTop.has(k) && k !== SIN_TRT;
            }).length;
            const items = trtTop.map(n => ({ trt: n, color: colorDe[n], n: conteoTrt[n], label: n }));
            if (nOtros > 0) items.push({ trt: OTROS, color: '#adb5bd', n: nOtros, label: 'Otros' });
            if (nSin > 0) items.push({ trt: SIN_TRT, color: COLOR_SIN, n: nSin, label: 'Sin transportista' });
            let html = '';
            items.forEach(it => {
                html += '<button type="button" class="btn btn-sm py-0 px-2 border trt-chip" data-trt="' + esc(it.trt) + '"'
                    + ' style="background:' + it.color + '22;color:#fff;border-color:' + it.color + '">'
                    + '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + it.color + '"></span> '
                    + esc(it.label) + ' <span class="text-white-50">(' + it.n + ')</span></button>';
            });
            const leyenda = document.getElementById('leyendaTrts');
            leyenda.innerHTML = html;
            leyenda.querySelectorAll('.trt-chip').forEach(ch => ch.addEventListener('click', () => {
                const n = ch.dataset.trt;
                if (hidden.has(n)) hidden.delete(n); else hidden.add(n);
                ch.style.opacity = hidden.has(n) ? '.35' : '1';
                draw();
            }));
        }
        if (hasData) construirLeyenda();

        function drawContexto() {
            // contexto geografico: puntos con halo y nombres MUTENIDOS, pintados antes del
            // tinte nocturno para que su color siga el ciclo dia/noche como todo el mapa
            // font bold: el texto fino de 9px se percibe sucio por antialiasing
            ctx.font = 'bold ' + Math.max(7, Math.round(9 * Z())) + 'px system-ui, sans-serif';
            ctx.textAlign = 'center';
            DEPAS.forEach(dp => {
                const px = X(dp.x), py = Y(dp.y);
                // punto central (el halo del departamento se quito por pedido del usuario;
                // la zona de clic de 22px en el click handler permanece)
                dot(px, py, 5.5 * Z(), '#ffffff', 0.7);
                // nombre hueso poco visible: .45 de opacidad, fondo absorbe el resto
                ctx.globalAlpha = 0.45;
                ctx.fillStyle = '#f8f9fa';
                ctx.fillText(dp.nombre, px, py - 17 * Z());
                ctx.globalAlpha = 1;
            });
        }

        // ---- HUD dentro del canvas: hora, ciclo dia/noche, dia de la semana, fecha y mes ----
        function drawHUD() {
            const d = new Date(t);
            const fecha = String(d.getUTCDate()).padStart(2, '0') + '/' + String(d.getUTCMonth() + 1).padStart(2, '0') + '/' + d.getUTCFullYear();
            const txt = fmt(t) + '  \u00b7  ' + DIAS[d.getUTCDay()] + ' ' + fecha + '  \u00b7  ' + MESES[d.getUTCMonth()];
            const icon = nightness(t) > 0.5 ? '\uD83C\uDF19' : '\u2600\uFE0F';    // luna / sol
            ctx.font = 'bold 13px system-ui, sans-serif';
            const pad = 8, bh = 28;
            const bw = ctx.measureText(txt).width + pad * 2 + 24;
            ctx.fillStyle = 'rgba(0,0,0,.55)';
            if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(8, 8, bw, bh, 7); ctx.fill(); }
            else ctx.fillRect(8, 8, bw, bh);
            ctx.fillStyle = '#ffffff';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(icon, 8 + pad, 8 + bh / 2 + 1);
            ctx.fillText(txt, 8 + pad + 22, 8 + bh / 2 + 1);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'alphabetic';
        }

        let dib = [];   // posiciones dibujadas este frame, para hover/clic

        function draw() {
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.save();
            ctx.setTransform(view.s, 0, 0, view.s, view.tx, view.ty);
            // el mapa base se pinta dentro del canvas para escalar junto con todo lo demas
            if (img.complete && img.naturalWidth) ctx.drawImage(img, 0, 0, W0, H0);
            drawContexto();
            dib = [];
            let activos = 0, completados = 0;

            for (const m of MOV) {
                if (oculto(m)) continue;
                // spotlight: fila del panel enfocada O camion bajo el cursor (ambos resaltan igual)
                const spot = spotRef || (lastHover ? lastHover.ref : null);

                if (m.fin < t) {
                    completados++;
                    // onda de llegada persiste unos minutos despues del arribo
                    if (t - m.fin <= RIPPLE_LIFE) {
                        const g = geom(m);
                        ripple(g.dx, g.dy, t - m.fin, '255,193,7');
                    }
                    continue;
                }
                if (t < m.inicio) continue;
                activos++;

                const g = geom(m);
                const p = m.fin > m.inicio ? (t - m.inicio) / (m.fin - m.inicio) : 1;

                // tramo completo tenue en verde (como antes)
                ctx.strokeStyle = 'rgba(40,167,69,.35)';
                ctx.lineWidth = 1.2 * Z();
                ctx.beginPath(); ctx.moveTo(g.ox, g.oy); ctx.quadraticCurveTo(g.cx, g.cy, g.dx, g.dy); ctx.stroke();

                // avance en cian (como antes); el color TRT se reserva para el camion
                const pt = bez(g, p);
                const qx = g.ox + (g.cx - g.ox) * p, qy = g.oy + (g.cy - g.oy) * p;
                ctx.strokeStyle = 'rgba(23,162,184,.9)';
                ctx.lineWidth = 1.8 * Z();
                ctx.lineCap = 'round';
                ctx.beginPath(); ctx.moveTo(g.ox, g.oy); ctx.quadraticCurveTo(qx, qy, pt.x, pt.y); ctx.stroke();

                // hitos y ondas de salida/llegada
                dot(g.ox, g.oy, 2.4 * Z(), 'rgba(40,167,69,.95)');
                dot(g.dx, g.dy, 2.4 * Z(), 'rgba(255,193,7,.95)');
                ripple(g.ox, g.oy, t - m.inicio, '40,167,69');

                // spotlight desde panel/cursor: resalta el tramo completo y su camion
                if (m.ref === spot) {
                    ctx.strokeStyle = 'rgba(255,255,255,.65)';
                    ctx.lineWidth = 3 * Z();
                    ctx.beginPath(); ctx.moveTo(g.ox, g.oy); ctx.quadraticCurveTo(g.cx, g.cy, g.dx, g.dy); ctx.stroke();
                }

                // camion rotado segun la tangente
                drawTruck(m, pt.x, pt.y, bezAng(g, p));
                if (m.ref === spot) {
                    ctx.beginPath(); ctx.arc(pt.x, pt.y, 12 * Z(), 0, Math.PI * 2);
                    ctx.strokeStyle = 'rgba(255,255,255,.85)';
                    ctx.lineWidth = 1.5 * Z();
                    ctx.stroke();
                }
                dib.push({ m: m, x: pt.x, y: pt.y, p: p });
            }

            ctx.restore();   // fin del mundo transformado; HUD y tinte van en coordenadas de pantalla

            // tinte de noche sobre todo el lienzo
            const n = nightness(t);
            if (n > 0) {
                ctx.fillStyle = 'rgba(13,23,64,' + (0.42 * n).toFixed(3) + ')';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            // los nombres de departamento se pintaron ANTES del tinte, asi que aqui se
            // oscurecen solos al llegar la noche (siguen el ciclo dia/noche)
            drawHUD();   // encima del tinte para que se lea siempre
            const ic = document.getElementById('iconNoche');
            if (ic) ic.style.display = n > 0.5 ? 'inline' : 'none';

            document.getElementById('statActivos').textContent = activos;
            document.getElementById('statCompletados').textContent = completados;
            document.getElementById('clock').textContent = fmt(t);
            document.getElementById('clockDate').textContent = fmtDate(t);
            const scr = document.getElementById('scrub');
            if (document.activeElement !== scr) scr.value = Math.round((t - tMin) / span * 1000);

            // panel con throttle: refrescar como maximo 4 veces por segundo
            const ahora = Date.now();
            if (ahora - lastPanel > 250) { lastPanel = ahora; actualizarPanel(); }
        }

        // ---- Hit-test: el puntero pasa a coordenadas de mundo (zoom/pan) ----
        function hit(e) {
            const r = canvas.getBoundingClientRect();
            const px = (e.clientX - r.left - view.tx) / view.s;
            const py = (e.clientY - r.top - view.ty) / view.s;
            const rad = 12 * Z();          // radio de 12px de pantalla
            let best = null, bd = rad * rad;
            for (const d of dib) {
                const dd = (d.x - px) * (d.x - px) + (d.y - py) * (d.y - py);
                if (dd < bd) { bd = dd; best = d; }
            }
            return best;
        }

        // resaltar la fila del panel correspondiente al camion enfocado
        function setRowSpot(m) {
            document.querySelectorAll('#activos .row-spot').forEach(el => {
                if (!m || el.dataset.ref !== m.ref) el.classList.remove('row-spot');
            });
            if (!m) return;
            const sel = '#activos [data-ref="' + (window.CSS && CSS.escape ? CSS.escape(m.ref) : m.ref) + '"]';
            const row = document.querySelector(sel);
            if (row) row.classList.add('row-spot');
        }

        canvas.addEventListener('mousemove', (e) => {
            const b = hit(e);
            const prev = lastHover;
            lastHover = b ? b.m : null;
            setRowSpot(lastHover);
            // en pausa no hay bucle de animacion: redibujar solo cuando cambia el hover
            if (!playing && prev !== lastHover) draw();
            if (!b) {
                tooltip.style.display = 'none';
                canvas.style.cursor = view.s > 1 ? 'grab' : 'default';
                return;
            }
            const m = b.m, pct = Math.round(b.p * 100);
            tooltip.innerHTML = '<div class="text-light fw-bold">' + esc(m.origen) + ' - ' + esc(m.destino) + '</div>'
                + '<div class="text-white-50"><i class="bi bi-person"></i> ' + esc(m.conductor || '\u2014')
                + ' \u00b7 <i class="bi bi-truck"></i> ' + esc(m.placa || '\u2014') + '</div>'
                + '<div class="text-white-50">' + esc(m.trt || '\u2014') + ' \u00b7 ref ' + esc(m.ref) + '</div>'
                + '<div><span class="badge bg-primary">' + pct + '%</span> <span class="text-white-50">llega ' + fmt(m.fin) + '</span></div>'
                + '<div class="text-info" style="font-size:.75rem">clic: ver llamadas del viaje</div>';
            const sx = b.x * view.s + view.tx, sy = b.y * view.s + view.ty;   // mundo -> pantalla
            tooltip.style.left = Math.max(4, Math.min(canvas.width - 235, sx + 12)) + 'px';
            tooltip.style.top = Math.max(4, sy - 48) + 'px';
            tooltip.style.display = 'block';
            canvas.style.cursor = 'pointer';
        });
        canvas.addEventListener('mouseleave', () => {
            tooltip.style.display = 'none';
            const tenia = lastHover;
            lastHover = null; setRowSpot(null);
            if (!playing && tenia) draw();   // quitar el glow del camion enfocado
        });

        // ---- Popup de departamento en el instante (solo datos en memoria) ----
        const depPop = document.getElementById('depPop');
        function hideDep() { depPop.style.display = 'none'; }
        function popupDep(dp, sx, sy) {
            let sal = 0, ene = 0, loc = 0;
            const chofs = new Set();
            for (const m of MOV) {
                if (oculto(m) || t < m.inicio || t > m.fin) continue;
                if (m.dep0 === dp.cod && m.dep1 === dp.cod) loc++;
                else if (m.dep0 === dp.cod) sal++;
                else if (m.dep1 === dp.cod) ene++;
                if (m.dep0 === dp.cod || m.dep1 === dp.cod) chofs.add(m.conductor || '\u2014');
            }
            depPop.innerHTML = '<div class="text-light fw-bold mb-1"><i class="bi bi-geo-alt"></i> ' + esc(dp.nombre)
                + ' <span class="text-white-50">a las ' + fmt(t) + '</span></div>'
                + (sal + ene + loc === 0
                    ? '<div class="text-white-50">Sin viajes activos en este instante.</div>'
                    : '<div class="mb-1"><span class="badge bg-success">' + sal + '</span> salientes \u00b7 '
                      + '<span class="badge bg-primary">' + ene + '</span> entrantes \u00b7 '
                      + '<span class="badge bg-info">' + loc + '</span> locales</div>'
                      + '<div class="text-white-50">' + chofs.size + ' conductor(es) en la zona</div>');
            depPop.style.left = Math.max(4, Math.min(canvas.width - 250, sx + 8)) + 'px';
            depPop.style.top = Math.max(4, Math.min(canvas.height - 120, sy - 20)) + 'px';
            depPop.style.display = 'block';
        }

        canvas.addEventListener('click', (e) => {
            if (dragMoved) { dragMoved = false; return; }   // era un paneo, no un clic
            const b = hit(e);
            if (b) {
                const m = b.m;
                // lupita.llamadas?ids= espera vapi_id separados por salto de linea
                const url = m.vids
                    ? LLAMADAS_URL + '?ids=' + encodeURIComponent(m.vids.split(',').join('\n'))
                    : API_REF.replace('__REF__', encodeURIComponent(m.ref));
                window.open(url, '_blank');
                return;
            }
            // clic en zona de departamento -> popup del instante
            const r = canvas.getBoundingClientRect();
            const wx = (e.clientX - r.left - view.tx) / view.s;
            const wy = (e.clientY - r.top - view.ty) / view.s;
            let best = null, bd = Math.pow(22 * Z(), 2);   // zona de clic acorde al halo agrandado
            DEPAS.forEach(dp => {
                const ddx = X(dp.x) - wx, ddy = Y(dp.y) - wy;
                const dd = ddx * ddx + ddy * ddy;
                if (dd < bd) { bd = dd; best = dp; }
            });
            if (best) popupDep(best, e.clientX - r.left, e.clientY - r.top);
            else hideDep();
        });

        // ---- Zoom (rueda, centrado en el cursor) y pan (arrastrar) ----
        canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            const r = canvas.getBoundingClientRect();
            const px = e.clientX - r.left, py = e.clientY - r.top;
            const wx = (px - view.tx) / view.s, wy = (py - view.ty) / view.s;
            const f = e.deltaY < 0 ? 1.15 : 1 / 1.15;
            const s = Math.max(1, Math.min(8, view.s * f));
            view.s = s;
            if (s === 1) { view.tx = 0; view.ty = 0; }
            else {
                view.tx = Math.max(W0 - W0 * s, Math.min(0, px - wx * s));
                view.ty = Math.max(H0 - H0 * s, Math.min(0, py - wy * s));
            }
            hideDep();
            draw();
        }, { passive: false });

        let drag = null;
        canvas.addEventListener('mousedown', (e) => {
            if (view.s <= 1) return;
            drag = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty };
        });
        window.addEventListener('mousemove', (e) => {
            if (!drag) return;
            const dx2 = e.clientX - drag.x, dy2 = e.clientY - drag.y;
            if (Math.abs(dx2) + Math.abs(dy2) > 4) { dragMoved = true; hideDep(); }
            view.tx = Math.max(W0 - W0 * view.s, Math.min(0, drag.tx + dx2));
            view.ty = Math.max(H0 - H0 * view.s, Math.min(0, drag.ty + dy2));
            draw();
        });
        window.addEventListener('mouseup', () => { drag = null; });
        canvas.addEventListener('dblclick', () => { view.s = 1; view.tx = 0; view.ty = 0; hideDep(); draw(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideDep(); });

        // ---- Linea de eventos sobre el scrub: ticks clicables y saltos prev/next ----
        const ticksC = document.getElementById('ticks');
        const tctx = ticksC.getContext('2d');
        const EVENTS = [];
        MOV.forEach(m => { EVENTS.push({ t: m.inicio, k: 0 }); EVENTS.push({ t: m.fin, k: 1 }); });
        EVENTS.sort((a, b) => a.t - b.t);
        function drawTicks() {
            if (!ticksC.clientWidth) return;
            ticksC.width = ticksC.clientWidth;
            tctx.clearRect(0, 0, ticksC.width, ticksC.height);
            for (const ev of EVENTS) {
                const x = Math.round((ev.t - tMin) / span * (ticksC.width - 1));
                tctx.fillStyle = ev.k ? 'rgba(255,193,7,.85)' : 'rgba(40,167,69,.85)';
                tctx.fillRect(x, ev.k ? 0 : 7, 1, 7);    // llegadas arriba, salidas abajo
            }
        }
        function jumpTo(target) {
            let best = null, bd = Infinity;
            for (const ev of EVENTS) { const dd = Math.abs(ev.t - target); if (dd < bd) { bd = dd; best = ev; } }
            if (!best) return;
            pause(); t = best.t; draw();
        }
        function jumpEv(dir) {
            let cand = null;
            if (dir > 0) { for (const ev of EVENTS) if (ev.t > t + 1000) { cand = ev; break; } if (!cand && EVENTS.length) cand = EVENTS[0]; }
            else { for (let i = EVENTS.length - 1; i >= 0; i--) if (EVENTS[i].t < t - 1000) { cand = EVENTS[i]; break; } if (!cand && EVENTS.length) cand = EVENTS[EVENTS.length - 1]; }
            if (cand) { pause(); t = cand.t; draw(); }
        }
        ticksC.addEventListener('click', (e) => {
            const r = ticksC.getBoundingClientRect();
            jumpTo(tMin + ((e.clientX - r.left) / r.width) * span);
        });
        document.getElementById('btnNextEv').addEventListener('click', () => jumpEv(1));
        document.getElementById('btnPrevEv').addEventListener('click', () => jumpEv(-1));

        // ---- Spotlight panel -> mapa (hover sobre filas del panel) ----
        document.getElementById('activos').addEventListener('mouseover', (e) => {
            const row = e.target.closest('.viaje-row');
            const nuevo = row ? row.dataset.ref : null;
            if (nuevo !== spotRef) { spotRef = nuevo; if (!playing) draw(); }
        });
        document.getElementById('activos').addEventListener('mouseleave', () => {
            if (spotRef !== null) { spotRef = null; if (!playing) draw(); }
        });

        function loop(ts) {
            if (!playing) return;
            if (last == null) last = ts;
            const dReal = ts - last; last = ts;
            t += dReal * (3600 / sph);      // 3,600,000 ms simulados por sph segundos reales
            if (t > tMax) t = tMin;          // bucle
            draw();
            raf = requestAnimationFrame(loop);
        }

        function play() {
            if (!hasData) return;
            playing = true; last = null;
            const b = document.getElementById('btnPlay');
            b.className = 'btn btn-danger btn-sm';
            b.innerHTML = '<i class="bi bi-pause-fill"></i> Pausa';
            raf = requestAnimationFrame(loop);
        }
        function pause() {
            playing = false; if (raf) cancelAnimationFrame(raf);
            const b = document.getElementById('btnPlay');
            b.className = 'btn btn-success btn-sm';
            b.innerHTML = '<i class="bi bi-play-fill"></i> Reproducir';
        }

        document.getElementById('btnPlay').addEventListener('click', () => playing ? pause() : play());
        document.getElementById('btnReset').addEventListener('click', () => { t = tMin; draw(); });

        // ---- Modo presentacion: oculta filtros/info, ensancha mapa y pantalla completa ----
        const mainWrap = document.getElementById('mainWrap');
        let presOn = false;
        document.getElementById('btnPres').addEventListener('click', () => {
            presOn = !presOn;
            mainWrap.classList.toggle('pres', presOn);
            if (presOn) {
                if (mainWrap.requestFullscreen) mainWrap.requestFullscreen().catch(() => {});
                if (!playing) play();
            } else if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            setTimeout(resize, 400);        // reajustar tras la animacion de layout
        });
        document.addEventListener('fullscreenchange', () => setTimeout(resize, 150));

        function setVel(v) {
            sph = v;
            document.getElementById('velInput').value = v;
            document.getElementById('velLabel').textContent = v;
        }
        document.querySelectorAll('.vel-preset').forEach(btn =>
            btn.addEventListener('click', () => setVel(parseFloat(btn.dataset.v))));
        document.getElementById('velInput').addEventListener('change', (e) => {
            const v = parseFloat(e.target.value);
            if (v > 0) setVel(v);
        });

        document.getElementById('scrub').addEventListener('input', (e) => {
            pause();
            t = tMin + (parseInt(e.target.value, 10) / 1000) * span;
            draw();
        });

        window.addEventListener('resize', resize);
        if (img.complete) resize(); else img.addEventListener('load', resize);
        draw();
    })();
    </script>
@endsection
