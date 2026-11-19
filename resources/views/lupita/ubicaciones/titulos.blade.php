@extends('layouts.app')

@section('title', 'Títulos sin ruta')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Títulos sin ruta</h1>
                <span class="text-white-50 small">
                    refs que quedaron sin <code class="text-warning">ruta_id</code>, agrupadas por título
                </span>
                <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-shop"></i> Locales
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                El <b>motivo</b> sale de replicar la lógica de <code class="text-warning">sp_actualizar_ruta_id</code>:
                dice qué lado del título no se pudo identificar contra <code>locales</code> ni contra el ubigeo.
                Si el título se cortó por un paréntesis o un guion, el local puede existir igual.
            </p>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-4 text-light small">
                        <span><b class="text-warning">{{ number_format($refs, 0, ',', '.') }}</b> refs sin ruta</span>
                        <span><b class="text-warning">{{ number_format($titulos_total, 0, ',', '.') }}</b> títulos distintos</span>
                        <span><b class="text-warning">{{ number_format($fragmentos, 0, ',', '.') }}</b> fragmentos sin resolver</span>
                        <span><b class="text-info">{{ number_format($ya_resolverian, 0, ',', '.') }}</b> ya resolverían</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(!$sp_disponible)
        <div class="alert alert-warning mt-3 mb-0">
            <b><i class="bi bi-exclamation-triangle"></i> El procedimiento no está instalado.</b>
            Reprocesar está deshabilitado hasta que se ejecute el bloque activo (V3) de
            <code>sql/rutas.sql</code> (líneas 243-400) en la base.
        </div>
    @endif

    <div class="row mt-3 g-2 align-items-end">
        <div class="col-12 col-md-4 col-lg-3">
            <label class="form-label text-light small mb-1" for="filtro-motivo">Motivo</label>
            <select class="form-select form-select-sm" id="filtro-motivo">
                <option value="">Todos</option>
                <option value="origen">Falla el origen</option>
                <option value="destino">Falla el destino</option>
                <option value="ambos">Falla origen y destino</option>
                <option value="multiparada">Multiparada (el SP las rechaza)</option>
                <option value="sin_titulo">Sin título de viaje</option>
            </select>
        </div>
        <div class="col-12 col-md-5 col-lg-4">
            <label class="form-label text-light small mb-1" for="filtro-texto">Buscar</label>
            <input type="text" class="form-control form-control-sm" id="filtro-texto"
                   value="{{ $fragmento ?? '' }}"
                   placeholder="título, ref, origen o destino">
        </div>
        <div class="col-12 col-lg-5">
            <span class="text-white-50 small" id="contador-titulos"></span>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover align-middle" id="tabla-titulos">
                    <thead class="thead-lupita">
                    <tr>
                        <th>Título del viaje</th>
                        <th style="width: 70px;">Refs</th>
                        <th style="width: 150px;">Por qué falla</th>
                        <th>Origen</th>
                        <th>Destino</th>
                        <th style="width: 130px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($pendientes as $fila)
                        @php
                            $motivo = $fila['motivo'];
                            $texto = mb_strtolower($fila['titulo'] . ' ' . implode(' ', $fila['refs_lista']) . ' '
                                . $fila['origen'] . ' ' . $fila['destino']);
                            $clase = ['origen' => 'text-warning', 'destino' => 'text-warning',
                                      'ambos' => 'text-danger', 'multiparada' => 'text-secondary',
                                      'sin_titulo' => 'text-secondary'][$motivo] ?? 'text-white-50';
                            $etiqueta = ['origen' => 'Origen', 'destino' => 'Destino', 'ambos' => 'Origen y destino',
                                         'multiparada' => 'Multiparada', 'sin_titulo' => 'Sin título'][$motivo] ?? $motivo;
                        @endphp
                        <tr data-motivo="{{ $motivo }}" data-buscar="{{ $texto }}">
                            <td class="text-light">
                                @if($fila['titulo'] === '')
                                    <span class="text-white-50">(sin título de viaje)</span>
                                @else
                                    <code class="text-light">{{ $fila['titulo'] }}</code>
                                @endif
                                @if(count($fila['refs_lista']) <= 6)
                                    <span class="d-block text-white-50 small">
                                        {{ implode(', ', $fila['refs_lista']) }}
                                    </span>
                                @else
                                    <span class="d-block text-white-50 small">
                                        {{ implode(', ', array_slice($fila['refs_lista'], 0, 6)) }}
                                        … (+{{ count($fila['refs_lista']) - 6 }} más)
                                    </span>
                                @endif
                            </td>
                            <td class="text-light">{{ $fila['refs'] }}</td>
                            <td><span class="{{ $clase }} small">{{ $etiqueta }}</span></td>
                            @foreach(['origen', 'destino'] as $lado)
                                <td class="small">
                                    @if($fila[$lado] === '')
                                        <span class="text-white-50">—</span>
                                    @else
                                        <code class="text-light">{{ $fila[$lado] }}</code>
                                        @if($fila[$lado . '_resuelto'])
                                            <span class="d-block text-success">
                                                <i class="bi bi-check-circle"></i>
                                                {{ $fila[$lado . '_resuelto']['id'] }}
                                            </span>
                                        @else
                                            <span class="d-block text-danger">
                                                <i class="bi bi-x-circle"></i> sin local
                                            </span>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                @if($motivo === 'sin_titulo')
                                    <span class="text-white-50 small">—</span>
                                @else
                                    <form method="POST" action="{{ route('lupita.ubicaciones.reprocesar') }}"
                                          onsubmit="this.querySelectorAll('button').forEach(function (b) { b.disabled = true; });">
                                        @csrf
                                        @foreach($fila['refs_lista'] as $ref)
                                            <input type="hidden" name="refs[]" value="{{ $ref }}">
                                        @endforeach
                                        <button class="btn btn-outline-warning btn-sm"
                                                @if(!$sp_disponible)
                                                    disabled title="Instalá sp_actualizar_ruta_id para usarlo"
                                                @else
                                                    title="Llamar al procedimiento sobre estas {{ $fila['refs'] }} refs"
                                                @endif>
                                            <i class="bi bi-signpost-split"></i> Encontrar ruta
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-white-50 text-center py-4">
                                No hay títulos pendientes: todas las refs tienen ruta.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(count($resueltos) > 0)
        <div class="row mt-4">
            <div class="col-12">
                <div class="accordion" id="acordeon-resueltos">
                    <div class="accordion-item bg-dark border-secondary">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-dark text-light" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#cuerpo-resueltos">
                                <span>
                                    <i class="bi bi-arrow-repeat"></i>
                                    {{ count($resueltos) }} títulos ({{ $ya_resolverian }} refs)
                                    que <b>ya resolverían</b> con el catálogo de hoy
                                </span>
                            </button>
                        </h2>
                        <div id="cuerpo-resueltos" class="accordion-collapse collapse" data-bs-parent="#acordeon-resueltos">
                            <div class="accordion-body">
                                <p class="text-white-50 small">
                                    No les falta ningún local: nunca se procesaron. Se resuelven corriendo
                                    <code>sp_actualizar_ruta_id</code> sobre cada ref.
                                </p>
                                <form method="POST" action="{{ route('lupita.ubicaciones.reprocesar') }}" class="mb-3"
                                      onsubmit="this.querySelectorAll('button').forEach(function (b) { b.disabled = true; });">
                                    @csrf
                                    @foreach($resueltos as $fila)
                                        @foreach($fila['refs_lista'] as $ref)
                                            <input type="hidden" name="refs[]" value="{{ $ref }}">
                                        @endforeach
                                    @endforeach
                                    <button class="btn btn-warning btn-sm"
                                            @if(!$sp_disponible) disabled @endif>
                                        <i class="bi bi-signpost-split"></i>
                                        Reprocesar las {{ $ya_resolverian }} refs que ya resolverían
                                    </button>
                                    <span class="text-white-50 small ms-2">
                                        Ojo: el procedimiento suma en <code>rutas.veces_usada</code> en cada llamada.
                                    </span>
                                </form>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="thead-lupita">
                                        <tr>
                                            <th>Título</th>
                                            <th style="width: 70px;">Refs</th>
                                            <th>Ruta que quedaría</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($resueltos as $fila)
                                            <tr>
                                                <td class="text-light">
                                                    <code class="text-light">{{ $fila['titulo'] }}</code>
                                                    <span class="d-block text-white-50 small">
                                                        {{ implode(', ', array_slice($fila['refs_lista'], 0, 8)) }}@if(count($fila['refs_lista']) > 8) … @endif
                                                    </span>
                                                </td>
                                                <td class="text-light">{{ $fila['refs'] }}</td>
                                                <td><code class="text-success">{{ $fila['ruta_id'] }}</code></td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

@endsection

@section('scripts')
    <script>
        (function () {
            'use strict';

            var motivoSel = document.getElementById('filtro-motivo');
            var textoInput = document.getElementById('filtro-texto');
            var contador = document.getElementById('contador-titulos');
            var filas = Array.prototype.slice.call(
                document.querySelectorAll('#tabla-titulos tbody tr[data-motivo]')
            );

            function filtrar() {
                var motivo = motivoSel.value;
                var texto = textoInput.value.trim().toLowerCase();
                var visibles = 0;

                filas.forEach(function (fila) {
                    var okMotivo = !motivo || fila.getAttribute('data-motivo') === motivo;
                    var okTexto = !texto || fila.getAttribute('data-buscar').indexOf(texto) !== -1;
                    var mostrar = okMotivo && okTexto;
                    fila.style.display = mostrar ? '' : 'none';
                    if (mostrar) visibles++;
                });

                contador.textContent = visibles + ' de ' + filas.length + ' títulos';
            }

            if (filas.length > 0) {
                motivoSel.addEventListener('change', filtrar);
                textoInput.addEventListener('input', filtrar);
                filtrar();
            } else {
                contador.textContent = '0 títulos';
            }
        })();
    </script>
@endsection
