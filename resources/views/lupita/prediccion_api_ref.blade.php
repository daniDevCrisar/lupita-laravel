@extends('layouts.app')

@section('title', 'Predicción por API — ' . $ref)

@section('content')
    @php
        $params = array_filter([
            'ref' => $ref,
            'ahora' => $ahora !== '' ? $ahora : null,
            'ancla' => $ancla !== '' ? $ancla : null,
            'ubicacion_tipo' => in_array($tipoUbicacion, ['local', 'ubigeo'], true) ? $tipoUbicacion : null,
            'ubicacion_id' => $ubicacionId !== '' ? $ubicacionId : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
        $urlApi = url('/api/prediccion/presenta') . '?' . http_build_query($params);
    @endphp

    <div class="row mb-3">
        <div class="col-12">
            <h1><i class="bi bi-cloud-arrow-down"></i> Predicción por API</h1>
            <p class="text-muted small mb-1">
                Esta pantalla no calcula nada por su cuenta: le pega al <strong>API público</strong> con la
                referencia y muestra la respuesta. Cada clic <strong>genera una predicción y la guarda</strong>
                en <code>prediccion_resultados</code> (con origen <code>api</code>), así después se puede medir
                si acertó.
            </p>
            <p class="small mb-2">
                Ref a predecir: <strong>{{ $ref }}</strong>
                @if($ancla !== '')
                    · último viaje hecho (ancla): <strong>{{ $ancla }}</strong>
                @endif
                @if($ahora !== '')
                    · corte: <strong>{{ $ahora }}</strong>
                @else
                    · corte: <strong>ahora</strong>
                @endif
            </p>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('lupita.prediccion.aciertos', request()->only(['fecha_inicio', 'fecha_fin', 'placa'])) }}">
                <i class="bi bi-arrow-left"></i> Volver a aciertos
            </a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('lupita.prediccion.presenta', $params) }}">
                <i class="bi bi-calculator"></i> Abrir en la calculadora
            </a>
            <a class="btn btn-sm btn-outline-dark" href="{{ $urlApi }}" target="_blank" rel="noopener">
                <i class="bi bi-braces"></i> Ver el JSON crudo
            </a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <strong>Request</strong>
        </div>
        <div class="card-body">
            <pre class="small p-2 bg-dark text-light rounded mb-2" id="request-texto">GET {{ $urlApi }}
Accept: application/json</pre>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button class="btn btn-success" id="btn-predecir">
                    <i class="bi bi-lightning-charge"></i> Generar predicción (API)
                </button>
                <span class="small text-muted" id="estado">todavía no se pidió nada</span>
            </div>
        </div>
    </div>

    <div id="resultado" style="display:none">
        <div class="card mb-3 border-primary">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <strong id="res-titulo">Resultado</strong>
                <span class="badge bg-light text-dark" id="res-badge"></span>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-2" id="res-fechas"></div>
                <div class="small text-muted" id="res-resumen"></div>
                <div class="alert alert-warning mt-2 py-1 small mb-0" id="res-avisos" style="display:none"></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><strong>Pasos</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr><th style="width:34px">#</th><th>Paso</th><th class="text-end">Duración</th><th>Cuándo</th></tr>
                    </thead>
                    <tbody id="res-pasos"></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>JSON completo del API</strong></div>
            <div class="card-body">
                <pre class="small p-2 bg-dark text-light rounded mb-0"
                     style="max-height:520px; overflow:auto" id="res-json"></pre>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            const url = @json($urlApi);
            const boton = document.getElementById('btn-predecir');
            const estado = document.getElementById('estado');
            const caja = document.getElementById('resultado');
            const tablaPasos = document.getElementById('res-pasos');

            const colores = { rapido: 'success', normal: 'primary', tardado: 'warning' };

            function fecha(valor) {
                if (!valor) return '—';
                const [d, h] = valor.split(' ');
                const [a, m, dia] = d.split('-');
                return `${dia}/${m} ${h.slice(0, 5)}`;
            }

            function escapar(texto) {
                const div = document.createElement('div');
                div.textContent = texto === null || texto === undefined ? '' : texto;
                return div.innerHTML;
            }

            function pintar(datos) {
                document.getElementById('res-titulo').textContent =
                    'Ref ' + datos.ref + ' · ' + (datos.placa || '—') + ' · ' + (datos.ruta_nombre || '');
                document.getElementById('res-badge').textContent =
                    'evidencia ' + datos.evidencia + ' · confianza ' + datos.confianza + '%';

                const fechas = document.getElementById('res-fechas');
                fechas.innerHTML = '';
                (datos.presentaciones || []).forEach(function (p) {
                    const div = document.createElement('div');
                    div.className = 'col-md-3';
                    div.innerHTML =
                        '<div class="card h-100 border-' + (colores[p.clave] || 'secondary') + '">' +
                        '<div class="card-body py-2">' +
                        '<div class="d-flex justify-content-between"><span class="fw-bold text-' + (colores[p.clave] || 'secondary') + '">' +
                        escapar(p.etiqueta) + '</span><span class="badge bg-light text-dark">en ' + escapar(p.desde_ahora) + '</span></div>' +
                        '<div class="fs-3 fw-bold">' + fecha(p.fecha) + '</div>' +
                        '<div class="text-muted" style="font-size:.7rem">' + escapar(p.descripcion) + '</div>' +
                        '</div></div>';
                    fechas.appendChild(div);
                });
                const inicio = document.createElement('div');
                inicio.className = 'col-md-3';
                inicio.innerHTML =
                    '<div class="card h-100 border-info"><div class="card-body py-2">' +
                    '<div class="fw-bold text-info">Inicio de carga</div>' +
                    '<div class="fs-3 fw-bold">' + fecha(datos.inicio_carga) + '</div>' +
                    '<div class="text-muted" style="font-size:.7rem">espera en planta ' + escapar(datos.espera_hhmm) + '</div>' +
                    '</div></div>';
                fechas.appendChild(inicio);

                document.getElementById('res-resumen').innerHTML =
                    'Corte ' + fecha(datos.ahora) +
                    ' · total desde el ancla <strong>' + escapar(datos.duracion_total_hhmm) + '</strong>' +
                    ' · estado ' + escapar(datos.estado ? datos.estado.etiqueta : '—') +
                    ' · traslado ' + escapar(datos.historia ? datos.historia.traslado_fuente : '—') +
                    (datos.geoapify && datos.geoapify.usado
                        ? ' · <span class="badge bg-info text-dark">Geoapify ' + (datos.geoapify.km || '') + ' km'
                            + (datos.geoapify.llamadas ? ' · ' + datos.geoapify.llamadas + ' llamada(s) al API' : '')
                            + '</span>'
                        : '');

                const avisos = document.getElementById('res-avisos');
                if (datos.advertencias && datos.advertencias.length) {
                    avisos.style.display = '';
                    avisos.innerHTML = datos.advertencias.map(escapar).join('<br>');
                } else {
                    avisos.style.display = 'none';
                }

                tablaPasos.innerHTML = '';
                (datos.pasos || []).forEach(function (p) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td class="text-muted small">' + p.n + '</td>' +
                        '<td><div class="fw-semibold">' + escapar(p.titulo) + '</div>' +
                        '<div class="small text-muted">' + escapar(p.detalle) + '</div></td>' +
                        '<td class="text-end fw-bold text-nowrap">' + escapar(p.duracion) + '</td>' +
                        '<td class="small text-nowrap">' + (p.fecha ? fecha(p.fecha) : '—') + '</td>';
                    tablaPasos.appendChild(tr);
                });

                document.getElementById('res-json').textContent = JSON.stringify(datos, null, 2);
                caja.style.display = '';
            }

            boton.addEventListener('click', function () {
                boton.disabled = true;
                estado.textContent = 'pidiendo al API…';
                const t0 = performance.now();

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
                    .then(function ([code, datos]) {
                        const ms = Math.round(performance.now() - t0);
                        if (!datos.success) {
                            estado.innerHTML = '<span class="text-danger">error ' + code + ': ' +
                                escapar(datos.error || 'desconocido') + '</span>';
                            return;
                        }
                        estado.innerHTML = 'HTTP ' + code + ' en ' + ms + ' ms · guardado en ' +
                            '<code>prediccion_resultados</code> con origen <code>api</code>';
                        pintar(datos);
                    })
                    .catch(function (e) {
                        estado.innerHTML = '<span class="text-danger">' + escapar(e.message) + '</span>';
                    })
                    .finally(function () { boton.disabled = false; });
            });
        })();
    </script>
@endsection
