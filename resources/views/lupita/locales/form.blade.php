@extends('layouts.app')

@section('title', $modo === 'crear' ? 'Nuevo local' : 'Editar local')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">{{ $modo === 'crear' ? 'Nuevo local' : 'Editar local' }}</h1>
                @if($modo === 'editar')
                    <span class="badge bg-info text-dark">{{ $local->id }}</span>
                @endif
                <span class="text-white-50 small">
                    catálogo de locales / plantas · el ID y la palabra clave identifican el local en el título del viaje
                </span>
                <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-arrow-left"></i> Volver al listado
                </a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger mt-3">
            <h5 class="alert-heading mb-1"><i class="bi bi-exclamation-triangle"></i> Revisa los datos</h5>
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $accion }}" id="form-local">
        @csrf

        <div class="row mt-3">

            {{-- ================= DATOS DEL LOCAL ================= --}}
            <div class="col-12 col-lg-7">
                <div class="card bg-dark text-light border-secondary h-100">
                    <div class="card-header text-light">
                        <i class="bi bi-shop"></i> Datos del local
                    </div>
                    <div class="card-body">

                        <div class="mb-3">
                            <label class="form-label text-light" for="cliente_id">
                                Cliente <span class="text-danger">*</span>
                            </label>
                            <select class="form-select @error('cliente_id') is-invalid @enderror"
                                    id="cliente_id" name="cliente_id" required>
                                <option value="">— Selecciona un cliente —</option>
                                @foreach($clientes as $cliente)
                                    <option value="{{ $cliente->id }}"
                                        @if(old('cliente_id', $local->cliente_id ?? '') === $cliente->id) selected @endif>
                                        {{ $cliente->nombre }} ({{ $cliente->id }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text text-white-50">
                                El local cuelga de un cliente de la tabla <code class="text-warning">clientes</code>.
                            </div>
                            @error('cliente_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="nombre">
                                Nombre del local <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('nombre') is-invalid @enderror"
                                   id="nombre" name="nombre" maxlength="100" required
                                   value="{{ old('nombre', $local->nombre ?? '') }}"
                                   placeholder="Ej: PLANTA CARAL">
                            <div class="form-text text-white-50">
                                Nombre comercial con el que se muestra en tiempos, plantas y reportes.
                            </div>
                            @error('nombre')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="id">
                                ID del local
                                @if($modo === 'crear')<span class="text-danger">*</span>@endif
                            </label>

                            @if($modo === 'crear')
                                <div class="input-group">
                                    <input type="text" class="form-control @error('id') is-invalid @enderror"
                                           id="id" name="id" maxlength="50" required
                                           pattern="[A-Za-z0-9_\-]+"
                                           title="Solo letras, números, guion y guion bajo"
                                           value="{{ old('id') }}"
                                           placeholder="LOC_BABEL_MAXO"
                                           autocomplete="off">
                                    <button type="button" class="btn btn-outline-light" id="generar-id"
                                            title="Armar el ID con el cliente y el nombre">
                                        <i class="bi bi-magic"></i> Generar
                                    </button>
                                    @error('id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="form-text text-white-50">
                                    Clave primaria del local, en mayúsculas y sin espacios.
                                    Se propone solo a partir del cliente y del nombre; se puede corregir a mano.
                                </div>
                            @else
                                <input type="text" class="form-control" id="id" name="id"
                                       value="{{ $local->id }}" disabled>
                                <div class="form-text text-white-50">
                                    El ID no se puede cambiar: los viajes ya apuntan a él con este código.
                                    Si necesitas otro, crea un local nuevo.
                                </div>
                            @endif
                        </div>

                        <div class="mb-0">
                            <label class="form-label text-light" for="palabra_clave">
                                Palabra clave <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('palabra_clave') is-invalid @enderror"
                                   id="palabra_clave" name="palabra_clave" maxlength="100" required
                                   value="{{ old('palabra_clave', $local->palabra_clave ?? '') }}"
                                   placeholder="Ej: CARAL">
                            <div class="form-text text-white-50">
                                Palabra que aparece en el título del viaje. Admite varias alternativas
                                separadas con <code class="text-warning">|</code>, por ejemplo
                                <code class="text-warning">MAXO|BABEL MAXO</code>.
                            </div>
                            @error('palabra_clave')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>
                </div>
            </div>

            {{-- ================= UBICACION Y ESTADO ================= --}}
            <div class="col-12 col-lg-5 mt-3 mt-lg-0">

                <div class="card bg-dark text-light border-secondary">
                    <div class="card-header text-light">
                        <i class="bi bi-geo-alt"></i> Ubicación
                    </div>
                    <div class="card-body">

                        <div class="mb-3">
                            <label class="form-label text-light" for="departamento">Departamento</label>
                            <select class="form-select" id="departamento">
                                <option value="">— Selecciona un departamento —</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="provincia">Provincia</label>
                            <select class="form-select" id="provincia" disabled>
                                <option value="">— Primero el departamento —</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="distrito">Distrito</label>
                            <select class="form-select @error('distrito_ubigeo') is-invalid @enderror"
                                    id="distrito" disabled>
                                <option value="">— Primero la provincia —</option>
                            </select>
                            <input type="hidden" id="distrito_ubigeo" name="distrito_ubigeo"
                                   value="{{ old('distrito_ubigeo', $local->distrito_ubigeo ?? '') }}">
                            @error('distrito_ubigeo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex align-items-center gap-2 small">
                            <span class="text-white-50">Código ubigeo:</span>
                            <span class="badge bg-secondary" id="ubigeo-codigo">
                                {{ old('distrito_ubigeo', $local->distrito_ubigeo ?? '') ?: 'sin distrito' }}
                            </span>
                            <span class="text-white-50">(6 dígitos, opcional)</span>
                        </div>

                    </div>
                </div>

                <div class="card bg-dark text-light border-secondary mt-3">
                    <div class="card-header text-light">
                        <i class="bi bi-toggle-on"></i> Estado
                    </div>
                    <div class="card-body">
                        <label class="form-label text-light" for="activo">Activo</label>
                        <select class="form-select @error('activo') is-invalid @enderror"
                                id="activo" name="activo">
                            <option value="1" @if((string) old('activo', (string) ($local->activo ?? 1)) === '1') selected @endif>
                                Activo (aparece en el catálogo)
                            </option>
                            <option value="0" @if((string) old('activo', (string) ($local->activo ?? 1)) === '0') selected @endif>
                                Inactivo (se conserva, pero no se ofrece)
                            </option>
                        </select>
                        <div class="form-text text-white-50">
                            Un local inactivo no se borra: queda en el historial de viajes y promedios.
                        </div>
                        @error('activo')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i>
                        {{ $modo === 'crear' ? 'Crear local' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light">Cancelar</a>
                </div>

            </div>
        </div>
    </form>

@endsection

@section('scripts')
    <script>
        (function () {
            'use strict';

            // Arbol departamento > provincia > distrito (solo los 6 dígitos del
            // distrito viajan al servidor en `distrito_ubigeo`).
            var UBIGEO = @json($ubigeo);

            var depSel = document.getElementById('departamento');
            var provSel = document.getElementById('provincia');
            var distSel = document.getElementById('distrito');
            var ubigeoInput = document.getElementById('distrito_ubigeo');
            var ubigeoBadge = document.getElementById('ubigeo-codigo');
            var clienteSel = document.getElementById('cliente_id');
            var nombreInput = document.getElementById('nombre');
            var idInput = document.getElementById('id');
            var btnGenerar = document.getElementById('generar-id');

            // ---------------------------------------------------------------
            // Utilidades de texto
            // ---------------------------------------------------------------
            function slug(texto) {
                return (texto || '')
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .toUpperCase()
                    .replace(/[^A-Z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '');
            }

            function opcion(valor, texto) {
                var o = document.createElement('option');
                o.value = valor;
                o.textContent = texto;
                return o;
            }

            function buscarDepartamento(id) {
                for (var i = 0; i < UBIGEO.length; i++) {
                    if (String(UBIGEO[i].id) === String(id)) return UBIGEO[i];
                }
                return null;
            }

            function buscarProvincia(dep, id) {
                if (!dep) return null;
                for (var i = 0; i < dep.provincias.length; i++) {
                    if (String(dep.provincias[i].id) === String(id)) return dep.provincias[i];
                }
                return null;
            }

            // ---------------------------------------------------------------
            // Cascada de ubigeo
            // ---------------------------------------------------------------
            function llenarProvincias(seleccionada) {
                var dep = buscarDepartamento(depSel.value);
                provSel.innerHTML = '';
                distSel.innerHTML = '';
                distSel.disabled = true;

                if (!dep) {
                    provSel.appendChild(opcion('', '— Primero el departamento —'));
                    provSel.disabled = true;
                    distSel.appendChild(opcion('', '— Primero la provincia —'));
                    actualizarCodigo();
                    return;
                }

                provSel.appendChild(opcion('', '— Selecciona una provincia —'));
                dep.provincias.forEach(function (p) {
                    provSel.appendChild(opcion(p.id, p.nombre));
                });
                provSel.disabled = false;

                if (seleccionada) {
                    provSel.value = seleccionada;
                } else {
                    provSel.value = '';
                    distSel.appendChild(opcion('', '— Primero la provincia —'));
                    distSel.disabled = true;
                    actualizarCodigo();
                }
            }

            function llenarDistritos(seleccionado) {
                var dep = buscarDepartamento(depSel.value);
                var prov = buscarProvincia(dep, provSel.value);
                distSel.innerHTML = '';

                if (!prov) {
                    distSel.appendChild(opcion('', '— Primero la provincia —'));
                    distSel.disabled = true;
                    actualizarCodigo();
                    return;
                }

                distSel.appendChild(opcion('', '— Selecciona un distrito —'));
                prov.distritos.forEach(function (d) {
                    distSel.appendChild(opcion(d.ubigeo, d.nombre));
                });
                distSel.disabled = false;

                if (seleccionado) {
                    distSel.value = seleccionado;
                } else {
                    distSel.value = '';
                }
                actualizarCodigo();
            }

            function actualizarCodigo() {
                ubigeoInput.value = distSel.value || '';
                ubigeoBadge.textContent = ubigeoInput.value || 'sin distrito';
            }

            depSel.addEventListener('change', function () {
                llenarProvincias('');
            });
            provSel.addEventListener('change', function () {
                llenarDistritos('');
            });
            distSel.addEventListener('change', actualizarCodigo);

            // ---------------------------------------------------------------
            // ID sugerido (solo al crear): LOC_<CLIENTE>_<NOMBRE> sin repetir
            // el cliente si el nombre ya lo trae (cliente BABEL + "BABEL MAXO")
            // ---------------------------------------------------------------
            function idSugerido() {
                var cliente = slug((clienteSel.value || '').replace(/^CLI_/, ''));
                var nombre = slug(nombreInput.value);

                if (!cliente && !nombre) return '';
                if (!nombre) return 'LOC_' + cliente;
                if (!cliente) return 'LOC_' + nombre;
                if (nombre === cliente || nombre.indexOf(cliente + '_') === 0) {
                    return 'LOC_' + nombre;
                }
                return 'LOC_' + cliente + '_' + nombre;
            }

            if (btnGenerar) {
                // si el usuario escribe en el ID, deja de pisarse solo
                var idTocado = idInput.value.trim() !== '';

                var autoId = function () {
                    if (idTocado) return;
                    idInput.value = idSugerido();
                };

                idInput.addEventListener('input', function () {
                    idTocado = idInput.value.trim() !== '';
                });
                clienteSel.addEventListener('change', autoId);
                nombreInput.addEventListener('input', autoId);

                btnGenerar.addEventListener('click', function () {
                    idInput.value = idSugerido();
                    idTocado = true;
                    idInput.focus();
                });
            }

            // ---------------------------------------------------------------
            // Arranque: se rearma la cascada con el ubigeo guardado (edicion) o
            // con el que venía en old() si el POST falló
            // ---------------------------------------------------------------
            (function arrancar() {
                UBIGEO.forEach(function (dep) {
                    depSel.appendChild(opcion(dep.id, dep.nombre));
                });

                var inicial = ubigeoInput.value;
                if (!inicial) {
                    provSel.appendChild(opcion('', '— Primero el departamento —'));
                    distSel.appendChild(opcion('', '— Primero la provincia —'));
                    return;
                }

                for (var i = 0; i < UBIGEO.length; i++) {
                    var dep = UBIGEO[i];
                    for (var j = 0; j < dep.provincias.length; j++) {
                        var prov = dep.provincias[j];
                        for (var k = 0; k < prov.distritos.length; k++) {
                            if (prov.distritos[k].ubigeo === inicial) {
                                depSel.value = dep.id;
                                llenarProvincias(prov.id);
                                llenarDistritos(inicial);
                                return;
                            }
                        }
                    }
                }
            })();
        })();
    </script>
@endsection
