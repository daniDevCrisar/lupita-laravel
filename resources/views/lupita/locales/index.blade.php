@extends('layouts.app')

@section('title', 'Locales')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Locales</h1>
                <span class="text-white-50 small">
                    catálogo de locales / plantas · lo usan tiempos, plantas y reportes
                </span>
                <a href="{{ route('lupita.locales.crear') }}" class="btn btn-primary btn-sm ms-auto">
                    <i class="bi bi-plus-circle"></i> Nuevo local
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                El <b>ID</b> es la clave con la que los viajes apuntan al local, la
                <b>palabra clave</b> es la que se busca en el título del viaje y el
                <b>distrito</b> sale del ubigeo de 6 dígitos.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3">{{ session('exito') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger mt-3">{{ session('error') }}</div>
    @endif

    <div class="row mt-3">
        <div class="col-12 col-md-6 col-lg-4">
            <label class="form-label text-light small mb-1" for="filtro-cliente">Cliente</label>
            <select class="form-select form-select-sm" id="filtro-cliente">
                <option value="">Todos los clientes</option>
                @foreach($clientes as $cliente)
                    <option value="{{ $cliente->id }}">
                        {{ $cliente->nombre }} ({{ $cliente->id }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-6 col-lg-4 mt-2 mt-md-0">
            <label class="form-label text-light small mb-1" for="filtro-texto">Buscar</label>
            <input type="text" class="form-control form-control-sm" id="filtro-texto"
                   placeholder="ID, nombre, palabra clave o distrito">
        </div>
        <div class="col-12 col-lg-4 d-flex align-items-end mt-2 mt-lg-0">
            <span class="text-white-50 small" id="contador-locales"></span>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm align-middle" id="tabla-locales">
                    <thead class="thead-lupita">
                    <tr>
                        <th>ID</th>
                        <th>Cliente</th>
                        <th>Nombre</th>
                        <th>Palabra clave</th>
                        <th>Distrito</th>
                        <th style="width: 110px;">Estado</th>
                        <th style="width: 90px;" class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($locales as $local)
                        <tr data-cliente="{{ $local->cliente_id }}"
                            data-buscar="{{ mb_strtolower($local->id . ' ' . $local->cliente_id . ' ' . $local->nombre . ' ' . $local->palabra_clave . ' ' . ($local->distrito ?? '') . ' ' . ($local->provincia ?? '') . ' ' . ($local->departamento ?? '') . ' ' . ($local->distrito_ubigeo ?? '')) }}">
                            <td class="text-light"><code class="text-warning">{{ $local->id }}</code></td>
                            <td class="text-light">{{ $local->cliente_id }}</td>
                            <td class="text-light">{{ $local->nombre }}</td>
                            <td>
                                @foreach(explode('|', $local->palabra_clave) as $palabra)
                                    <span class="badge bg-secondary">{{ trim($palabra) }}</span>
                                @endforeach
                            </td>
                            <td class="text-white-50 small">
                                @if($local->distrito)
                                    {{ $local->distrito }}
                                    <span class="d-block">
                                        {{ $local->provincia }} · {{ $local->departamento }}
                                        <span class="text-warning">({{ $local->distrito_ubigeo }})</span>
                                    </span>
                                @elseif($local->distrito_ubigeo)
                                    <span class="text-warning">ubigeo {{ $local->distrito_ubigeo }} no encontrado</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if((int) $local->activo === 1)
                                    <span class="badge bg-success">Activo</span>
                                @else
                                    <span class="badge bg-danger">Inactivo</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('lupita.locales.editar', $local->id) }}"
                                   class="btn btn-outline-light btn-sm" title="Editar {{ $local->id }}">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-white-50 text-center py-4">
                                Todavía no hay locales cargados.
                                <a href="{{ route('lupita.locales.crear') }}" class="text-warning">Crear el primero</a>.
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

            var clienteSel = document.getElementById('filtro-cliente');
            var textoInput = document.getElementById('filtro-texto');
            var contador = document.getElementById('contador-locales');
            var filas = Array.prototype.slice.call(
                document.querySelectorAll('#tabla-locales tbody tr[data-cliente]')
            );

            function filtrar() {
                var cliente = clienteSel.value;
                var texto = textoInput.value.trim().toLowerCase();
                var visibles = 0;

                filas.forEach(function (fila) {
                    var okCliente = !cliente || fila.getAttribute('data-cliente') === cliente;
                    var okTexto = !texto || fila.getAttribute('data-buscar').indexOf(texto) !== -1;
                    var mostrar = okCliente && okTexto;
                    fila.style.display = mostrar ? '' : 'none';
                    if (mostrar) visibles++;
                });

                contador.textContent = visibles + ' de ' + filas.length + ' locales';
            }

            if (filas.length > 0) {
                clienteSel.addEventListener('change', filtrar);
                textoInput.addEventListener('input', filtrar);
                filtrar();
            } else {
                contador.textContent = '0 locales';
            }
        })();
    </script>
@endsection
