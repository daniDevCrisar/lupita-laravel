@extends('layouts.app')

@section('title', 'Palabras repetidas')

@section('content')

    <style>
        .ubic-accion form { display: inline; }
        .fila-descartada { opacity: .45; }
        .ubic-veredicto { white-space: nowrap; }
    </style>

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Palabras repetidas</h1>
                <span class="text-white-50 small">
                    qué palabras de los títulos sin ruta se repiten y qué conviene hacer con cada una
                </span>
                <div class="ms-auto d-flex gap-2">
                    <a href="{{ route('lupita.ubicaciones.palabras.exportar') }}" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-download"></i> Exportar CSV
                    </a>
                    <a href="{{ route('lupita.ubicaciones.titulos') }}" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-question-circle"></i> Títulos sin ruta
                    </a>
                </div>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                La columna <b>refs</b> es cuántas refs contienen esa frase, no un desglose que sume el total:
                un mismo título aporta varias frases (<code>MAQUILA AGUA</code>, <code>MAQUILA</code>, <code>AGUA</code>).
                El veredicto compara la frase contra el catálogo de <code>locales</code>.
            </p>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-4 text-light small">
                        <span><b class="text-warning">{{ number_format($refs, 0, ',', '.') }}</b> refs sin ruta</span>
                        <span><b class="text-warning">{{ number_format($titulos, 0, ',', '.') }}</b> títulos distintos</span>
                        <span><b class="text-warning">{{ number_format($total_frags, 0, ',', '.') }}</b> fragmentos sin resolver</span>
                        <span><b class="text-warning">{{ number_format(count($candidatos), 0, ',', '.') }}</b> frases candidatas</span>
                        <span><b class="text-info">{{ number_format($ya_resolverian, 0, ',', '.') }}</b> refs que ya resolverían</span>
                        @if($descartados > 0)
                            <span><b class="text-secondary">{{ $descartados }}</b> descartadas</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3 mb-0">{{ session('exito') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">{{ session('error') }}</div>
    @endif

    <ul class="nav nav-tabs mt-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-candidatos-boton" data-bs-toggle="tab"
                    data-bs-target="#tab-candidatos" type="button" role="tab">
                <i class="bi bi-list-check"></i> Palabras y frases
                <span class="badge bg-secondary">{{ count($candidatos) }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-fragmentos-boton" data-bs-toggle="tab"
                    data-bs-target="#tab-fragmentos" type="button" role="tab">
                <i class="bi bi-text-paragraph"></i> Fragmentos
                <span class="badge bg-secondary">{{ count($fragmentos) }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content bg-dark border border-top-0 border-secondary p-3">

        {{-- ================= CANDIDATOS ================= --}}
        <div class="tab-pane fade show active" id="tab-candidatos" role="tabpanel">
            <div class="row g-2 align-items-end mb-3">
                <div class="col-12 col-md-3">
                    <label class="form-label text-light small mb-1" for="filtro-veredicto">Veredicto</label>
                    <select class="form-select form-select-sm" id="filtro-veredicto">
                        <option value="">Todos</option>
                        <option value="nuevo">Local nuevo (falta registrarlo)</option>
                        <option value="familia">Varias sedes (MAKRO, CODISAL…)</option>
                        <option value="typo">Parecido a uno existente</option>
                        <option value="cubierto_parseo">El local existe (título cortado)</option>
                        <option value="generica">Genérica (no registrar)</option>
                        <option value="basura">Basura</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label text-light small mb-1" for="filtro-texto">Buscar</label>
                    <input type="text" class="form-control form-control-sm" id="filtro-texto"
                           placeholder="frase, local o título de ejemplo">
                </div>
                <div class="col-12 col-md-3">
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="ver-descartadas">
                        <label class="form-check-label text-light small" for="ver-descartadas">
                            Ver descartadas
                        </label>
                    </div>
                </div>
                <div class="col-12 col-md-2">
                    <span class="text-white-50 small" id="contador-candidatos"></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover align-middle" id="tabla-candidatos">
                    <thead class="thead-lupita">
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>Palabra / frase</th>
                        <th style="width: 65px;">Refs</th>
                        <th style="width: 70px;">Títulos</th>
                        <th style="width: 150px;">¿Local que lo cubre?</th>
                        <th style="width: 190px;">Veredicto</th>
                        <th style="width: 260px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($candidatos as $i => $c)
                        <tr data-veredicto="{{ $c['veredicto'] }}"
                            data-descartado="{{ $c['descartado'] ? '1' : '0' }}"
                            data-buscar="{{ mb_strtolower($c['frase'] . ' ' . ($c['local_id'] ?? '') . ' ' . $c['ejemplo_titulo']) }}"
                            class="{{ $c['descartado'] ? 'fila-descartada' : '' }}">
                            <td class="text-white-50 small">{{ $i + 1 }}</td>
                            <td>
                                <code class="text-warning">{{ $c['frase'] }}</code>
                                <span class="d-block text-white-50 small text-truncate" style="max-width: 460px;"
                                      title="{{ $c['ejemplo_titulo'] }}">
                                    ej: {{ $c['ejemplo_titulo'] }}
                                </span>
                            </td>
                            <td class="text-light">{{ $c['refs'] }}</td>
                            <td class="text-light">{{ $c['titulos'] }}</td>
                            <td class="small">
                                @if($c['local_id'])
                                    <code class="text-info">{{ $c['local_id'] }}</code>
                                @else
                                    <span class="text-white-50">—</span>
                                @endif
                            </td>
                            <td class="small ubic-veredicto">
                                @switch($c['veredicto'])
                                    @case('nuevo')
                                        <span class="badge bg-success">local nuevo</span>
                                        @break
                                    @case('familia')
                                        <span class="badge bg-primary">varias sedes</span>
                                        @break
                                    @case('typo')
                                        <span class="badge bg-warning text-dark">parecido</span>
                                        @break
                                    @case('cubierto_parseo')
                                        <span class="badge bg-info text-dark">el local existe</span>
                                        @break
                                    @case('generica')
                                        <span class="badge bg-secondary">genérica</span>
                                        @break
                                    @default
                                        <span class="badge bg-dark border border-secondary">basura</span>
                                @endswitch
                                @if($c['detalle'])
                                    <span class="d-block text-white-50" style="max-width: 190px;">{{ $c['detalle'] }}</span>
                                @endif
                            </td>
                            <td class="small ubic-accion">
                                @if(in_array($c['veredicto'], ['nuevo', 'familia'], true))
                                    <a href="{{ route('lupita.locales.crear', ['nombre' => $c['frase'], 'palabra_clave' => $c['frase']]) }}"
                                       class="btn btn-outline-success btn-sm" title="Dar de alta un local con esta palabra clave">
                                        <i class="bi bi-plus-circle"></i> Crear
                                    </a>
                                @endif

                                @if($c['local_id'])
                                    <a href="{{ route('lupita.locales.editar', $c['local_id']) }}"
                                       class="btn btn-outline-info btn-sm"
                                       title="Agregar esta frase como variante de la palabra clave">
                                        <i class="bi bi-pencil-square"></i> Variante
                                    </a>
                                @endif

                                <a href="{{ route('lupita.ubicaciones.titulos', ['fragmento' => $c['frase']]) }}"
                                   class="btn btn-outline-light btn-sm">
                                    <i class="bi bi-search"></i> Ver
                                </a>

                                @if($c['descartado'])
                                    <form method="POST" action="{{ route('lupita.ubicaciones.palabras.restaurar') }}">
                                        @csrf
                                        <input type="hidden" name="clave" value="{{ $c['clave'] }}">
                                        <button class="btn btn-outline-secondary btn-sm" title="Volver a mostrarla">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('lupita.ubicaciones.palabras.descartar') }}">
                                        @csrf
                                        <input type="hidden" name="clave" value="{{ $c['clave'] }}">
                                        <input type="hidden" name="texto" value="{{ $c['frase'] }}">
                                        <button class="btn btn-outline-danger btn-sm" title="No es un local: ocultarla">
                                            <i class="bi bi-x-circle"></i> Descartar
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-white-50 text-center py-4">
                                No hay frases candidatas: todas las refs sin ruta resuelven o están descartadas.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================= FRAGMENTOS ================= --}}
        <div class="tab-pane fade" id="tab-fragmentos" role="tabpanel">
            <p class="text-white-50 small">
                Los fragmentos completos que ningún local cubre, para leer el texto tal como llega.
                Un fragmento largo no sirve como palabra clave: esto es para entender el caso, no para crear.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="thead-lupita">
                    <tr>
                        <th>Fragmento</th>
                        <th style="width: 80px;">Lado</th>
                        <th style="width: 70px;">Refs</th>
                        <th style="width: 80px;">Títulos</th>
                        <th>Título de ejemplo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($fragmentos as $f)
                        <tr>
                            <td><code class="text-light">{{ $f['fragmento'] }}</code></td>
                            <td class="small text-white-50">{{ $f['lado'] }}</td>
                            <td class="text-light">{{ $f['refs'] }}</td>
                            <td class="text-light">{{ $f['titulos'] }}</td>
                            <td class="small text-white-50">{{ $f['ejemplo_titulo'] }}</td>
                        </tr>
                    @endforeach
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

            var veredictoSel = document.getElementById('filtro-veredicto');
            var textoInput = document.getElementById('filtro-texto');
            var verDescartadas = document.getElementById('ver-descartadas');
            var contador = document.getElementById('contador-candidatos');
            var filas = Array.prototype.slice.call(
                document.querySelectorAll('#tabla-candidatos tbody tr[data-veredicto]')
            );

            function filtrar() {
                var veredicto = veredictoSel.value;
                var texto = textoInput.value.trim().toLowerCase();
                var conDescartadas = verDescartadas.checked;
                var visibles = 0;

                filas.forEach(function (fila) {
                    var okVeredicto = !veredicto || fila.getAttribute('data-veredicto') === veredicto;
                    var okTexto = !texto || fila.getAttribute('data-buscar').indexOf(texto) !== -1;
                    var okDescarte = conDescartadas || fila.getAttribute('data-descartado') !== '1';
                    var mostrar = okVeredicto && okTexto && okDescarte;

                    fila.style.display = mostrar ? '' : 'none';
                    if (mostrar) visibles++;
                });

                contador.textContent = visibles + ' de ' + filas.length;
            }

            if (filas.length > 0) {
                veredictoSel.addEventListener('change', filtrar);
                textoInput.addEventListener('input', filtrar);
                verDescartadas.addEventListener('change', filtrar);
                filtrar();
            } else {
                contador.textContent = '0';
            }
        })();
    </script>
@endsection
