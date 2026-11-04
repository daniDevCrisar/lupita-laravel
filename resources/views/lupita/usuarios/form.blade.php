@extends('layouts.app')

@section('title', $modo === 'crear' ? 'Nuevo usuario' : 'Editar usuario')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">{{ $modo === 'crear' ? 'Nuevo usuario' : 'Editar usuario' }}</h1>
                @if($modo === 'editar')
                    <span class="badge bg-info text-dark">id {{ $usuario['id'] }}</span>
                @endif
                <a href="{{ route('lupita.usuarios') }}" class="btn btn-outline-light btn-sm ms-auto">
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
            <div class="col-12 col-lg-6">
                <div class="card bg-dark text-light border-secondary">
                    <div class="card-header text-light">Datos de acceso</div>
                    <div class="card-body">

                        <div class="mb-3">
                            <label class="form-label text-light" for="name">Login <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" maxlength="255" required
                                   value="{{ old('name', $usuario['name'] ?? '') }}"
                                   placeholder="usuario con el que inicia sesion">
                            <div class="form-text text-white-50">Es el campo con el que se inicia sesion. No se puede repetir.</div>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="nombres">Nombres <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('nombres') is-invalid @enderror"
                                   id="nombres" name="nombres" maxlength="80" required
                                   value="{{ old('nombres', $usuario['nombres'] ?? '') }}"
                                   placeholder="Nombre completo del usuario">
                            @error('nombres')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="email">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" maxlength="255"
                                   value="{{ old('email', $usuario['email'] ?? '') }}">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-light" for="password">
                                Contraseña
                                @if($modo === 'crear')<span class="text-danger">*</span>@else
                                    <small class="text-white-50">(dejalo en blanco para no cambiarla)</small>
                                @endif
                            </label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" autocomplete="new-password"
                                   @if($modo === 'crear') required @endif>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-0">
                            <label class="form-label text-light" for="password_confirmation">Confirmar contraseña</label>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" autocomplete="new-password"
                                   @if($modo === 'crear') required @endif>
                        </div>

                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 mt-3 mt-lg-0">
                <div class="card bg-dark text-light border-secondary">
                    <div class="card-header text-light">Permisos y estado</div>
                    <div class="card-body">

                        <label class="form-label text-light d-block mb-2">Roles</label>
                        @forelse($roles as $rol)
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="roles[]"
                                       value="{{ $rol }}" id="rol_{{ $rol }}"
                                       @if(in_array($rol, old('roles', $roles_usuario))) checked @endif>
                                <label class="form-check-label text-light" for="rol_{{ $rol }}">
                                    {{ $rol }}
                                    @if(isset($roles_info[$rol]))
                                        <small class="text-white-50">
                                            — {{ count($roles_info[$rol]) }} permisos
                                        </small>
                                    @endif
                                </label>
                            </div>
                        @empty
                            <div class="text-white-50 small">No hay roles definidos.</div>
                        @endforelse
                        <div class="form-text text-white-50">
                            Sin rol el usuario entra al sistema pero no ve los modulos con permiso.
                        </div>

                        @if($modo === 'editar')
                            <hr class="border-secondary">

                            <label class="form-label text-light" for="activo">Estado</label>
                            @if($usuario['id'] === 1)
                                <select class="form-select" id="activo" name="activo" disabled>
                                    <option value="1" selected>Activo</option>
                                </select>
                                <div class="form-text text-white-50">
                                    El usuario administrador no se puede desactivar.
                                </div>
                            @else
                                <select class="form-select" id="activo" name="activo">
                                    <option value="1" @if(old('activo', (string) $usuario['activo']) === '1') selected @endif>
                                        Activo
                                    </option>
                                    <option value="0" @if(old('activo', (string) $usuario['activo']) === '0') selected @endif>
                                        Inactivo (no puede iniciar sesion)
                                    </option>
                                </select>
                            @endif
                        @endif

                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> {{ $modo === 'crear' ? 'Crear usuario' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('lupita.usuarios') }}" class="btn btn-outline-light">Cancelar</a>
                </div>
            </div>
        </div>
    </form>

@endsection
