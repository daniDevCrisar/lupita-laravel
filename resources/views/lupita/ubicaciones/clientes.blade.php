@extends('layouts.app')

@section('title', 'Clientes')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Clientes</h1>
                <span class="text-white-50 small">catálogo del que cuelgan los locales</span>
                <div class="ms-auto d-flex gap-2">
                    <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-shop"></i> Locales
                    </a>
                    <a href="{{ route('lupita.ubicaciones.clientes.crear') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> Nuevo cliente
                    </a>
                </div>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Cada local pertenece a un cliente (<code>locales.cliente_id</code>). El <b>ID</b> no se puede
                cambiar: es a lo que apuntan los locales. No hay borrado: un cliente con locales los dejaría
                huérfanos.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3 mb-0">{{ session('exito') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">{{ session('error') }}</div>
    @endif

    <div class="row mt-3 g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label text-light small mb-1" for="filtro-texto">Buscar</label>
            <input type="text" class="form-control form-control-sm" id="filtro-texto"
                   placeholder="ID, nombre, tipo o palabra clave">
        </div>
        <div class="col-12 col-lg-8">
            <span class="text-white-50 small" id="contador-clientes"></span>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm align-middle" id="tabla-clientes">
                    <thead class="thead-lupita">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Palabra clave</th>
                        <th style="width: 110px;">Locales</th>
                        <th style="width: 100px;" class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($clientes as $cliente)
                        <tr data-buscar="{{ mb_strtolower($cliente->id . ' ' . $cliente->nombre . ' ' . $cliente->tipo . ' ' . ($cliente->palabra_clave ?? '') . ' ' . ($cliente->descripcion ?? '')) }}">
                            <td><code class="text-warning">{{ $cliente->id }}</code></td>
                            <td class="text-light">
                                {{ $cliente->nombre }}
                                @if($cliente->descripcion)
                                    <span class="d-block text-white-50 small">{{ $cliente->descripcion }}</span>
                                @endif
                            </td>
                            <td class="text-white-50 small">{{ $cliente->tipo }}</td>
                            <td>
                                @if($cliente->palabra_clave)
                                    @foreach(explode('|', $cliente->palabra_clave) as $palabra)
                                        <span class="badge bg-secondary">{{ trim($palabra) }}</span>
                                    @endforeach
                                @else
                                    <span class="text-white-50">—</span>
                                @endif
                            </td>
                            <td>
                                @if((int) $cliente->locales_count > 0)
                                    <a href="{{ route('lupita.locales', ['cliente' => $cliente->id]) }}"
                                       class="badge bg-info text-dark text-decoration-none"
                                       title="Ver los locales de {{ $cliente->id }}">
                                        {{ $cliente->locales_count }} local(es)
                                    </a>
                                @else
                                    <span class="badge bg-dark border border-secondary text-white-50">sin locales</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('lupita.ubicaciones.clientes.editar', $cliente->id) }}"
                                   class="btn btn-outline-light btn-sm" title="Editar {{ $cliente->id }}">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-white-50 text-center py-4">
                                Todavía no hay clientes cargados.
                                <a href="{{ route('lupita.ubicaciones.clientes.crear') }}" class="text-warning">
                                    Crear el primero
                                </a>.
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

            var texto = document.getElementById('filtro-texto');
            var contador = document.getElementById('contador-clientes');
            var filas = Array.prototype.slice.call(
                document.querySelectorAll('#tabla-clientes tbody tr[data-buscar]')
            );

            function filtrar() {
                var buscado = texto.value.trim().toLowerCase();
                var visibles = 0;

                filas.forEach(function (fila) {
                    var mostrar = !buscado || fila.getAttribute('data-buscar').indexOf(buscado) !== -1;
                    fila.style.display = mostrar ? '' : 'none';
                    if (mostrar) visibles++;
                });

                contador.textContent = visibles + ' de ' + filas.length + ' clientes';
            }

            if (filas.length > 0) {
                texto.addEventListener('input', filtrar);
                filtrar();
            } else {
                contador.textContent = '0 clientes';
            }
        })();
    </script>
@endsection
