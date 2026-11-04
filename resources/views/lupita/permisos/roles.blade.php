@extends('layouts.app')

@section('title', 'Permisos por rol')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Permisos por rol</h1>
                <span class="text-white-50 small">marca a que modulos entra cada rol</span>
                <a href="{{ route('lupita.usuarios') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-arrow-left"></i> Volver a usuarios
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Cada pantalla del panel exige un permiso, asi que las casillas de aqui deciden
                que ve cada rol. Los permisos <b>sensibles</b> (eliminar lote, fusionar conductores
                y administrar usuarios) deberian quedar solo en el rol <b>admin</b>: si el rol del
                administrador pierde <b>administrar usuarios</b>, nadie podria volver a entrar a esta
                pantalla.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3">{{ session('exito') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger mt-3">{{ session('error') }}</div>
    @endif

    @forelse($roles as $rol)
        @php
            $es_protegido = in_array($rol['name'], $roles_protegidos, true);
            $slug = str_replace(' ', '_', strtolower($rol['name']));
        @endphp

        <div class="card bg-dark text-light border-secondary mt-3">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 text-light">
                <i class="bi bi-shield-lock"></i>
                <span class="fw-semibold">{{ $rol['name'] }}</span>
                <span class="badge bg-primary">{{ count($rol['permisos']) }} de {{ $total_permisos }} permisos</span>
                @if($es_protegido)
                    <span class="badge bg-info text-dark" title="Es el rol del usuario administrador: no puede quedarse sin el permiso de administrar usuarios">
                        protegido
                    </span>
                @endif
            </div>

            <form method="POST" action="{{ route('lupita.permisos.guardar') }}">
                @csrf
                <input type="hidden" name="rol" value="{{ $rol['name'] }}">

                <div class="card-body">
                    @foreach($grupos as $grupo => $permisos_del_grupo)
                        @php
                            $marcados = count(array_intersect($permisos_del_grupo, $rol['permisos']));
                            $slug_grupo = str_replace(' ', '_', strtolower($grupo));
                        @endphp

                        <div class="mb-4" data-grupo="{{ $slug_grupo }}">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="text-light fw-semibold">{{ $grupo }}</span>
                                <span class="text-white-50 small">{{ $marcados }} de {{ count($permisos_del_grupo) }}</span>
                                <div class="ms-auto btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-light" data-marcar="1">
                                        <i class="bi bi-check-all"></i> Todos
                                    </button>
                                    <button type="button" class="btn btn-outline-light" data-limpiar="1">
                                        <i class="bi bi-x-circle"></i> Ninguno
                                    </button>
                                </div>
                            </div>

                            <div class="row g-2">
                                @foreach($permisos_del_grupo as $permiso)
                                    @php
                                        $id_casilla = 'perm_' . $slug . '_' . $slug_grupo . '_' . $loop->index;
                                        $chequeado = in_array($permiso, $rol['permisos'], true);
                                        // el permiso de administrar usuarios no se puede quitar
                                        // al rol protegido: se manda siempre por hidden
                                        $bloqueado = $es_protegido
                                            && $permiso === \App\Database\DBPermisos::ADMINISTRAR_USUARIOS;
                                    @endphp

                                    <div class="col-12 col-md-6 col-xl-4">
                                        <div class="form-check">
                                            @if($bloqueado)
                                                <input type="hidden" name="permisos[]" value="{{ $permiso }}">
                                            @endif
                                            <input class="form-check-input" type="checkbox" name="permisos[]"
                                                   value="{{ $permiso }}" id="{{ $id_casilla }}"
                                                   @if($chequeado) checked @endif
                                                   @if($bloqueado) disabled @endif>
                                            <label class="form-check-label text-light" for="{{ $id_casilla }}">
                                                {{ $permiso }}
                                                @if($bloqueado)
                                                    <i class="bi bi-lock-fill text-white-50"
                                                       title="Este permiso no se puede quitar al rol del administrador"></i>
                                                @endif
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="card-footer d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save"></i> Guardar permisos de {{ $rol['name'] }}
                    </button>
                    <a href="{{ route('lupita.permisos') }}" class="btn btn-outline-light btn-sm">Descartar cambios</a>
                </div>
            </form>
        </div>
    @empty
        <div class="alert alert-warning mt-3">
            No hay roles definidos. Corre el seeder <code>PermisosPanelSeeder</code> para crearlos.
        </div>
    @endforelse

@endsection

@section('scripts')
<script>
    (function () {
        // "Todos" / "Ninguno" por grupo de permisos
        document.querySelectorAll('[data-grupo]').forEach(function (grupo) {
            const casillas = grupo.querySelectorAll('input[type="checkbox"][name="permisos[]"]');
            const marcar = grupo.querySelector('[data-marcar]');
            const limpiar = grupo.querySelector('[data-limpiar]');

            if (marcar) {
                marcar.addEventListener('click', function () {
                    casillas.forEach(function (c) {
                        if (!c.disabled) c.checked = true;
                    });
                });
            }

            if (limpiar) {
                limpiar.addEventListener('click', function () {
                    casillas.forEach(function (c) {
                        if (!c.disabled) c.checked = false;
                    });
                });
            }
        });

        // avisa si el rol deja de tener permisos antes de guardar
        document.querySelectorAll('form[action*="permisos"]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const marcadas = form.querySelectorAll('input[type="checkbox"][name="permisos[]"]:checked').length;
                if (marcadas === 0) {
                    e.preventDefault();
                    if (confirm('Este rol se quedaria sin ningun permiso: no podria entrar a ningun modulo. ¿Guardar igual?')) {
                        form.submit();
                    }
                }
            });
        });
    })();
</script>
@endsection
