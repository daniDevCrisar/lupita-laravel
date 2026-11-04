@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Usuarios</h1>
                <span class="text-white-50 small">alta, edicion y desactivacion · solo el usuario administrador</span>
                <a href="{{ route('lupita.usuarios.crear') }}" class="btn btn-primary btn-sm ms-auto">
                    <i class="bi bi-person-plus"></i> Nuevo usuario
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Un usuario desactivado no puede iniciar sesion: se le pone <code class="text-warning">activo = 0</code>
                y conserva todo su historial. El login es el campo <b>Login</b>; <b>Nombres</b> es el nombre completo
                que muestra el sistema.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3">{{ session('exito') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger mt-3">{{ session('error') }}</div>
    @endif

    <div class="row mt-2">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm align-middle">
                    <thead class="thead-lupita">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Login</th>
                        <th>Nombres</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Permisos</th>
                        <th>Estado</th>
                        <th>Alta</th>
                        <th class="text-end" style="width: 220px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($usuarios as $u)
                        <tr>
                            <td class="text-white-50">{{ $u['id'] }}</td>
                            <td class="text-light">
                                {{ $u['name'] }}
                                @if($u['id'] === 1)
                                    <span class="badge bg-info text-dark">administrador</span>
                                @endif
                            </td>
                            <td class="text-light">{{ $u['nombres'] !== '' ? $u['nombres'] : '—' }}</td>
                            <td class="text-light">{{ $u['email'] !== '' ? $u['email'] : '—' }}</td>
                            <td>
                                @forelse($u['roles'] as $rol)
                                    <span class="badge bg-primary">{{ $rol }}</span>
                                @empty
                                    <span class="text-white-50 small">sin roles</span>
                                @endforelse
                            </td>
                            <td class="text-white-50 small">
                                @if(count($u['permisos']) > 0)
                                    <span title="{{ implode(', ', $u['permisos']) }}">{{ count($u['permisos']) }} permisos</span>
                                @else
                                    <span>—</span>
                                @endif
                            </td>
                            <td>
                                @if($u['activo'] === 1)
                                    <span class="badge bg-success">Activo</span>
                                @else
                                    <span class="badge bg-danger">Inactivo</span>
                                @endif
                            </td>
                            <td class="text-white-50 small">{{ $u['created_at'] }}</td>
                            <td class="text-end">
                                <a href="{{ route('lupita.usuarios.editar', $u['id']) }}"
                                   class="btn btn-outline-info btn-sm">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                                @if($u['id'] === 1)
                                    <button type="button" class="btn btn-outline-light btn-sm" disabled
                                            title="El usuario administrador no se puede desactivar">
                                        <i class="bi bi-lock"></i> Estado
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('lupita.usuarios.estado', $u['id']) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('{{ $u['activo'] === 1 ? 'Desactivar' : 'Reactivar' }} al usuario {{ $u['name'] }}?');">
                                        @csrf
                                        <button type="submit"
                                                class="btn btn-outline-{{ $u['activo'] === 1 ? 'danger' : 'success' }} btn-sm">
                                            <i class="bi bi-{{ $u['activo'] === 1 ? 'slash-circle' : 'check-circle' }}"></i>
                                            {{ $u['activo'] === 1 ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-white-50 py-4">No hay usuarios registrados.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
