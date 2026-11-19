@extends('layouts.app')

@section('title', 'Desde Google Maps')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Desde Google Maps</h1>
                <span class="text-white-50 small">
                    pegá el link de la planta y salen las coordenadas
                </span>
                <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-shop"></i> Locales
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Las coordenadas se sacan <b>del propio link</b>: no se pide nada a ninguna API ni hace falta
                una key. En Google Maps buscá la planta, tocá <b>Compartir</b> → <b>Copiar link</b> y pegalo acá.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3 mb-0">{{ session('exito') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">{{ session('error') }}</div>
    @endif

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-4 text-light small">
                        <span><b class="text-success">{{ $con_coordenadas }}</b> locales con coordenadas</span>
                        <span><b class="text-warning">{{ count($sin_coordenadas) }}</b> sin coordenadas</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3 g-3">
        {{-- ============ PEGAR EL LINK ============ --}}
        <div class="col-12 col-lg-7">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-header text-light"><i class="bi bi-link-45deg"></i> 1 · Pegá el link</div>
                <div class="card-body">
                    <label class="form-label text-light small mb-1" for="link-maps">Link de Google Maps</label>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm" id="link-maps"
                               placeholder="https://www.google.com/maps/place/.../@-11.10,-77.45,17z">
                        <button class="btn btn-primary btn-sm" type="button" id="btn-extraer">
                            <i class="bi bi-magic"></i> Extraer
                        </button>
                    </div>
                    <div class="form-text text-white-50">
                        Sirve el link largo del navegador y también el corto de
                        <code>maps.app.goo.gl</code> (ese se abre para leer las coordenadas).
                    </div>

                    <div id="resultado-maps" class="mt-3 d-none">
                        <div class="alert alert-secondary mb-2 py-2">
                            <div class="row g-2 text-light small">
                                <div class="col-12 col-md-4">
                                    <span class="text-white-50 d-block">Latitud</span>
                                    <code class="text-warning" id="res-lat">—</code>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-white-50 d-block">Longitud</span>
                                    <code class="text-warning" id="res-lng">—</code>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-white-50 d-block">Fuente</span>
                                    <span id="res-fuente">—</span>
                                </div>
                                <div class="col-12">
                                    <span class="text-white-50 d-block">Nombre en el link</span>
                                    <span id="res-nombre">—</span>
                                </div>
                            </div>
                        </div>
                        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm"
                           id="res-ver-mapa">
                            <i class="bi bi-map"></i> Ver en el mapa
                        </a>
                    </div>

                    <div id="error-maps" class="alert alert-danger mt-3 mb-0 py-2 d-none small"></div>
                </div>
            </div>
        </div>

        {{-- ============ ASIGNAR AL LOCAL ============ --}}
        <div class="col-12 col-lg-5">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-header text-light"><i class="bi bi-shop"></i> 2 · Asignalo a un local</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('lupita.ubicaciones.maps.asignar') }}" id="form-asignar">
                        @csrf
                        <input type="hidden" name="lat" id="campo-lat">
                        <input type="hidden" name="lng" id="campo-lng">
                        <input type="hidden" name="geo_texto" id="campo-texto">

                        <label class="form-label text-light small mb-1" for="local_id">Local</label>
                        <select class="form-select form-select-sm" id="local_id" name="local_id" required>
                            <option value="">— Elegí el local —</option>
                            @foreach($sin_coordenadas as $local)
                                <option value="{{ $local->id }}">{{ $local->nombre }} ({{ $local->id }})</option>
                            @endforeach
                            @if(count($sin_coordenadas) > 0)
                                <option disabled>──────────</option>
                            @endif
                            @foreach($locales as $local)
                                @if($local->lat !== null && $local->lng !== null)
                                    <option value="{{ $local->id }}">
                                        {{ $local->nombre }} ({{ $local->id }}) — ya tiene
                                    </option>
                                @endif
                            @endforeach
                        </select>
                        <div class="form-text text-white-50">
                            Arriba están los que no tienen coordenadas.
                        </div>

                        <button class="btn btn-success btn-sm mt-3 w-100" type="submit" id="btn-asignar" disabled>
                            <i class="bi bi-check-circle"></i> Guardar coordenadas
                        </button>
                        <div class="form-text text-white-50 mt-2">
                            Se guarda en <code>locales.lat</code> y <code>locales.lng</code>, con fuente
                            <code>maps</code>.
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ SIN COORDENADAS ============ --}}
    <div class="row mt-4">
        <div class="col-12">
            <h5 class="text-light">Locales sin coordenadas ({{ count($sin_coordenadas) }})</h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="thead-lupita">
                    <tr>
                        <th>Local</th>
                        <th>Cliente</th>
                        <th>Distrito</th>
                        <th style="width: 120px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($sin_coordenadas as $local)
                        <tr>
                            <td class="text-light">
                                {{ $local->nombre }}
                                <span class="d-block text-white-50 small"><code>{{ $local->id }}</code></span>
                            </td>
                            <td class="text-white-50 small">{{ $local->cliente_id }}</td>
                            <td class="text-white-50 small">
                                {{ $local->distrito ?? '—' }}
                                @if($local->distrito_ubigeo)
                                    <span class="text-warning">({{ $local->distrito_ubigeo }})</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('lupita.locales.editar', $local->id) }}"
                                   class="btn btn-outline-light btn-sm">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-white-50 text-center py-4">
                                Todos los locales tienen coordenadas.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        (function () {
            'use strict';

            var input = document.getElementById('link-maps');
            var boton = document.getElementById('btn-extraer');
            var cajaOk = document.getElementById('resultado-maps');
            var cajaError = document.getElementById('error-maps');
            var asignar = document.getElementById('btn-asignar');
            var campoLat = document.getElementById('campo-lat');
            var campoLng = document.getElementById('campo-lng');
            var campoTexto = document.getElementById('campo-texto');

            function texto(id, valor) {
                document.getElementById(id).textContent = valor === null || valor === ''
                    ? '—' : valor;
            }

            function limpiar() {
                cajaOk.classList.add('d-none');
                cajaError.classList.add('d-none');
                asignar.disabled = true;
                campoLat.value = '';
                campoLng.value = '';
                campoTexto.value = '';
            }

            boton.addEventListener('click', function () {
                limpiar();

                var link = input.value.trim();
                if (!link) {
                    cajaError.textContent = 'Pegá un link de Google Maps.';
                    cajaError.classList.remove('d-none');
                    return;
                }

                boton.disabled = true;

                fetch({{ Illuminate\Support\Js::from(route('lupita.ubicaciones.maps.extraer')) }}, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ link: link })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (datos) {
                        if (!datos.ok) {
                            cajaError.textContent = datos.error || 'No se pudieron sacar las coordenadas.';
                            cajaError.classList.remove('d-none');
                            return;
                        }

                        texto('res-lat', datos.lat);
                        texto('res-lng', datos.lng);
                        texto('res-fuente', datos.fuente);
                        texto('res-nombre', datos.nombre);
                        document.getElementById('res-ver-mapa').href =
                            'https://www.google.com/maps?q=' + datos.lat + ',' + datos.lng;
                        cajaOk.classList.remove('d-none');

                        campoLat.value = datos.lat;
                        campoLng.value = datos.lng;
                        campoTexto.value = datos.nombre || '';
                        asignar.disabled = false;
                    })
                    .catch(function () {
                        cajaError.textContent = 'No se pudo leer el link. Probá de nuevo.';
                        cajaError.classList.remove('d-none');
                    })
                    .finally(function () { boton.disabled = false; });
            });

            // Enter en el input = mismo que apretar Extraer
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    boton.click();
                }
            });
        })();
    </script>
@endsection
