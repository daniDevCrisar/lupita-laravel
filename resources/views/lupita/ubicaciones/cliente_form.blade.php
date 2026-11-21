@extends('layouts.app')

@section('title', $modo === 'crear' ? 'Nuevo cliente' : 'Editar cliente')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">{{ $modo === 'crear' ? 'Nuevo cliente' : 'Editar cliente' }}</h1>
                @if($modo === 'editar')
                    <span class="badge bg-info text-dark">{{ $cliente->id }}</span>
                @endif
                <a href="{{ route('lupita.ubicaciones.clientes') }}" class="btn btn-outline-light btn-sm ms-auto">
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

    <form method="POST" action="{{ $accion }}">
        @csrf

        <div class="row mt-3">
            <div class="col-12 col-lg-7">
                <div class="card bg-dark text-light border-secondary">
                    <div class="card-header text-light"><i class="bi bi-person-vcard"></i> Datos del cliente</div>
                    <div class="card-body">

                        @if($modo === 'crear')
                            <div class="mb-3">
                                <label class="form-label text-light" for="id">
                                    ID <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control @error('id') is-invalid @enderror"
                                       id="id" name="id" maxlength="50" required
                                       value="{{ old('id', $cliente->id ?? '') }}"
                                       placeholder="Ej: CLI_AJEPER">
                                <div class="form-text text-white-50">
                                    Mayúsculas, números, guion y guion bajo. Es a lo que apuntan los locales y
                                    <b>no se puede cambiar después</b>.
                                </div>
                                @error('id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @else
                            <div class="mb-3">
                                <label class="form-label text-light d-block">ID</label>
                                <code class="text-warning">{{ $cliente->id }}</code>
                                <div class="form-text text-white-50">
                                    El ID no se edita: los locales apuntan a él. Para "renombrarlo" hay que dar
                                    de alta otro cliente.
                                </div>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label text-light" for="nombre">
                                Nombre <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('nombre') is-invalid @enderror"
                                   id="nombre" name="nombre" maxlength="100" required
                                   value="{{ old('nombre', $cliente->nombre ?? '') }}"
                                   placeholder="Ej: AJEPER">
                            @error('nombre')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="tipo">
                                Tipo <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('tipo') is-invalid @enderror"
                                   id="tipo" name="tipo" maxlength="50" required list="tipos-existentes"
                                   value="{{ old('tipo', $cliente->tipo ?? '') }}"
                                   placeholder="Ej: Distribuidor">
                            <datalist id="tipos-existentes">
                                @foreach($tipos as $tipo)
                                    <option value="{{ $tipo }}"></option>
                                @endforeach
                            </datalist>
                            <div class="form-text text-white-50">
                                Texto libre. Los tipos que ya existen salen como sugerencia.
                            </div>
                            @error('tipo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="palabra_clave">Palabra clave</label>
                            <input type="text" class="form-control @error('palabra_clave') is-invalid @enderror"
                                   id="palabra_clave" name="palabra_clave" maxlength="100"
                                   value="{{ old('palabra_clave', $cliente->palabra_clave ?? '') }}"
                                   placeholder="Ej: AJEPER">
                            <div class="form-text text-white-50">
                                La que aparece en el título del viaje. Separá variantes con <code>|</code>.
                                Ojo: no la uses en los locales; cada local tiene la suya.
                            </div>
                            @error('palabra_clave')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-0">
                            <label class="form-label text-light" for="descripcion">Descripción</label>
                            <textarea class="form-control @error('descripcion') is-invalid @enderror"
                                      id="descripcion" name="descripcion" rows="3" maxlength="1000"
                                      placeholder="Ej: Dueña de plantas de producción">{{ old('descripcion', $cliente->descripcion ?? '') }}</textarea>
                            @error('descripcion')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i>
                        {{ $modo === 'crear' ? 'Crear cliente' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('lupita.ubicaciones.clientes') }}" class="btn btn-outline-light">Cancelar</a>
                </div>
            </div>

            {{-- locales que cuelgan: solo en edicion --}}
            <div class="col-12 col-lg-5 mt-3 mt-lg-0">
                <div class="card bg-dark text-light border-secondary h-100">
                    <div class="card-header text-light">
                        <i class="bi bi-shop"></i> Locales de este cliente
                        <span class="badge bg-secondary">{{ count($locales) }}</span>
                    </div>
                    <div class="card-body">
                        @if(count($locales) === 0)
                            <p class="text-white-50 small mb-0">
                                No tiene locales. Se dan de alta desde
                                <a href="{{ route('lupita.locales.crear') }}" class="text-warning">Locales</a>.
                            </p>
                        @else
                            <ul class="list-unstyled small mb-2">
                                @foreach($locales as $local)
                                    <li>
                                        <a href="{{ route('lupita.locales.editar', $local) }}" class="text-info">
                                            {{ $local }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ route('lupita.locales', ['cliente' => $cliente->id]) }}"
                               class="btn btn-outline-light btn-sm">
                                <i class="bi bi-funnel"></i> Ver en el listado
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

@endsection
