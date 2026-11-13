<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Sistema')</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <!--
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    -->
{{--    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">--}}
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet">
{{--    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/journal/bootstrap.min.css" rel="stylesheet">--}}

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="{{ asset('css/app.css') . '?v=' . (@filemtime(public_path('css/app.css')) ?: time()) }}">
    <!-- CSS propio opcional -->
    {{-- <link href="{{ asset('css/app.css') }}" rel="stylesheet"> --}}
    @yield('heads')
</head>

<body>
<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary p-3 mb-3">
    <div class="container-fluid">

        <!-- Logo + Nombre -->
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="{{ asset('images/logo.png') }}"
                 alt="Logo"
                 width="128"
                 class="me-2 rounded">
            <div>
                <h1 class="h3 mb-0 fw-bold">Lupita CRM</h1>
                <small>Analisis de audio de llamadas IA</small>
            </div>
        </a>

        <!-- Botón hamburguesa -->
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Opciones -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">

                {{-- Cada link del menu se muestra solo si el usuario tiene el
                     permiso del modulo: el servidor igual lo exige con el
                     middleware `permission:` de cada ruta. --}}
                @can('ver llamadas')
                <li class="nav-item">
                    <a class="nav-link" href="{{route('lupita.llamadas')}}">Llamadas</a>
                </li>
                @endcan

                @if(auth()->user()->canAny(['ver conductores', 'gestionar duplicados conductores']))
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Conductores
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        @can('ver conductores')
                        <li><a class="dropdown-item" href="{{route('lupita.conductores')}}"><i class="bi bi-list-ul"></i> Lista</a></li>
                        @endcan
                        @can('gestionar duplicados conductores')
                        <li><a class="dropdown-item" href="{{route('lupita.conductores.duplicados')}}"><i class="bi bi-people"></i> Duplicados</a></li>
                        @endcan
                    </ul>
                </li>
                @endif

                @can('gestionar casos conductores')
                <li class="nav-item">
                    <a class="nav-link" href="{{route('lupita.conductores.casos')}}">Casos</a>
                </li>
                @endcan

                @can('ver transportistas')
                <li class="nav-item">
                    <a class="nav-link" href="{{route('lupita.transportistas')}}">Transportistas</a>
                </li>
                @endcan

                @can('ver referencias')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Referencias
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{route('lupita.referencias')}}"><i class="bi bi-list-ul"></i> Consulta</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.referencias.verificar')}}"><i class="bi bi-check2-square"></i> Verificar lista</a></li>
                    </ul>
                </li>
                @endcan
                @if(auth()->user()->canAny(['ver tiempos', 'compilar promedios']))
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Tiempos
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @can('ver tiempos')
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos')}}"><i class="bi bi-stopwatch"></i> Tiempos de ruta</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.horario')}}"><i class="bi bi-clock-history"></i> Horarios</a></li>
                        @endcan
                        @can('compilar promedios')
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.promedio')}}"><i class="bi bi-calculator"></i> Compilar promedios</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.promedio.horario')}}"><i class="bi bi-calendar3"></i> Compilar horarios</a></li>
                        @endcan
                        @can('ver tiempos')
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.promedio.ver')}}"><i class="bi bi-table"></i> Promedios compilados</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.promedio.horario.ver')}}"><i class="bi bi-grid-3x3-gap"></i> Horarios compilados</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.comportamiento')}}"><i class="bi bi-person-lines-fill"></i> Comportamiento</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.prediccion.presenta')}}"><i class="bi bi-clock-history"></i> Predecir carga</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.prediccion.aciertos')}}"><i class="bi bi-bullseye"></i> Aciertos de la predicción</a></li>
                        @endcan
                    </ul>
                </li>
                @endif
                {{-- el modulo de scoring solo se muestra si el usuario trae el permiso
                     de lectura: `can()` devuelve false antes que el seeder corra, y no
                     revienta como `hasPermissionTo` --}}
                @can('ver scoring')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#"
                           role="button"
                           data-bs-toggle="dropdown"
                           aria-expanded="false">
                            Scoring
                        </a>

                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{route('lupita.scoring')}}">Modelo vs humano</a></li>
                            <li><a class="dropdown-item" href="{{route('lupita.palabras')}}"><i class="bi bi-key"></i> Palabras</a></li>
                            <li><a class="dropdown-item" href="{{route('lupita.scoring.pesos')}}">Palabras y pesos</a></li>
                            <li><a class="dropdown-item" href="{{route('lupita.scoring.llamadas')}}"><i class="bi bi-list-ul"></i> Llamadas del rango</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{route('lupita.scoring.ayuda')}}"><i class="bi bi-question-circle"></i> Cómo funciona</a></li>
                        </ul>
                    </li>
                @endcan
                @can('etiquetar llamada')
                <li class="nav-item">
                    <a class="nav-link" href="{{route('lupita.audio')}}">Etiquetar</a>
                </li>
                @endcan
                <!-- 🔽 MENÚ DESPLEGABLE -->
                @if(auth()->user()->canAny(['importar json', 'importar excel', 'importar vapi', 'ver lista de lotes']))
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Importar
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        @can('importar json')
                        <li><a class="dropdown-item" href="{{route('importar.json')}}">JSON</a></li>
                        @endcan
                        @can('importar excel')
                        <li><a class="dropdown-item" href="{{route('importar.excel')}}">Excel</a></li>
                        @endcan
                        @can('importar vapi')
                        <li><a class="dropdown-item" href="{{route('vapi.index')}}">Por correo Vapi</a></li>
                        @endcan
                        @can('ver lista de lotes')
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item"  href="{{route('importar.excel.lotes')}}">Lista de Lotes</a></li>
                        @endcan
                    </ul>
                </li>
                @endif

                {{-- Grupo API: se abre con el permiso de lectura `ver api` --}}
                @can('ver api')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        API
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{route('lupita.refs.seleccion')}}"><i class="bi bi-arrow-repeat"></i> Actualizar Refs</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.refs.docs')}}"><i class="bi bi-journal-code"></i> Docs API Refs</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.refs.ruta_id')}}"><i class="bi bi-signpost-split"></i> Docs ruta_id de viaje</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="{{route('lupita.tiempos.api')}}"><i class="bi bi-plug"></i> Probar API Tiempos</a></li>
                    </ul>
                </li>
                @endcan

                @can('ver reportes')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Reportes
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{route('lupita.reportes.dashboard')}}"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.reportes.reporte')}}"><i class="bi bi-file-earmark-bar-graph"></i> Reporte en pantalla</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.reportes.seleccion')}}"><i class="bi bi-sliders"></i> Generar reporte</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="{{route('lupita.reportes.dia')}}"><i class="bi bi-calendar-day"></i> Un día (correo)</a></li>
                        <li><a class="dropdown-item" href="{{route('lupita.reportes.rango')}}"><i class="bi bi-calendar-range"></i> Por rango (correo)</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="{{route('lupita.reporte.seleccion')}}">Para correo (histórico)</a></li>
                    </ul>
                </li>
                @endcan

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Usuario
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        @can('administrar usuarios')
                            {{-- la administracion de usuarios es solo del administrador (usuario 1) --}}
                            <li>
                                <a href="{{ route('lupita.usuarios') }}" class="dropdown-item">
                                    <i class="bi bi-people-fill"></i> Usuarios
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('lupita.permisos') }}" class="dropdown-item">
                                    <i class="bi bi-shield-lock"></i> Permisos por rol
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        @endcan
                        @role('admin')
                            {{-- catalogo de locales/plantas: solo el rol admin --}}
                            <li>
                                <a href="{{ route('lupita.locales') }}" class="dropdown-item">
                                    <i class="bi bi-shop"></i> Locales
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        @endrole
                        <li>
                            <span class="dropdown-item-text text-light">
                                {{ auth()->user()->name }}@if(auth()->user()->nombres)<small class="text-white-50"> · {{ auth()->user()->nombres }}</small>@endif
                            </span>
                        </li>
                        <li>
                            <a href="{{ route('logout') }}" class="dropdown-item"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                Cerrar sesión
                            </a>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                                @csrf
                            </form>
                        </li>
                    </ul>
                </li>

            </ul>
        </div>

    </div>
</nav>

<div class="container-fluid">
{{-- SOLO PARA LIVEWIRE   --}}
    {{ $slot??'' }}

    @yield('content')
</div>

<!-- Footer -->
<footer class="bg-primary text-white mt-5">
    <div class="container-fluid p-4">
        <div class="row">
            <!-- Sección izquierda: Logo + Descripción -->
            <div class="col-md-4 mb-4 mb-md-0">
                <div class="d-flex align-items-center mb-3">
                    <img src="{{ asset('images/logo.png') }}"
                         alt="Logo"
                         width="64"
                         class="me-2 rounded">
                    <div>
                        <h5 class="fw-bold mb-0">Lupita CRM</h5>
                        <small>Análisis de audio de llamadas IA</small>
                    </div>
                </div>
                <p class="small">
                    Etiquetado de llamadas del VAPI.
                </p>
            </div>

            <!-- Sección central: Enlaces rápidos -->
            <div class="col-md-4 mb-4 mb-md-0">
                <h5 class="fw-bold mb-3">Enlaces rápidos</h5>
                <ul class="list-unstyled">
                    <li class="mb-2">
                        @can('ver llamadas')
                        <a href="{{route('lupita.llamadas')}}" class="text-white text-decoration-none">Llamadas</a>
                        @endcan
                    </li>
                    <li class="mb-2">
                        @can('ver conductores')
                        <a href="{{route('lupita.conductores')}}" class="text-white text-decoration-none">Conductores</a>
                        @endcan
                    </li>
                    <li class="mb-2">
                        @can('ver transportistas')
                        <a href="{{route('lupita.transportistas')}}" class="text-white text-decoration-none">Transportistas</a>
                        @endcan
                    </li>
                    <li class="mb-2"><a href="#" class="text-white text-decoration-none">Contacto</a></li>
                </ul>
            </div>

            <!-- Sección derecha: Contacto y Redes -->
            <div class="col-md-4">
                <h5 class="fw-bold mb-3">Contacto</h5>
                <ul class="list-unstyled">
                    <li class="mb-2">
                        <i class="bi bi-envelope-fill me-2"></i>
                        thedanisa@gmail.com
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-telephone-fill me-2"></i>
                        +51 944 659 175
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-geo-alt-fill me-2"></i>
                        Lima, Peru
                    </li>
                </ul>

                <!-- Iconos redes sociales -->
                <div class="mt-3">
                    <a href="#" class="text-white me-3"><i class="bi bi-facebook fs-5"></i></a>
                    <a href="#" class="text-white me-3"><i class="bi bi-twitter-x fs-5"></i></a>
                    <a href="#" class="text-white me-3"><i class="bi bi-linkedin fs-5"></i></a>
                    <a href="#" class="text-white"><i class="bi bi-instagram fs-5"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Barra inferior copyright -->
    <div class="bg-primary border-top border-white border-opacity-25 py-3">
        <div class="container-fluid text-center small">
            © {{ date('Y') }} Lupita CRM - Análisis de audio de llamadas IA. Todos los derechos reservados.
        </div>
    </div>
</footer>

<!-- Asegúrate de tener Bootstrap Icons para los iconos sociales -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="{{ asset('js/app.js') }}"></script>


@yield('scripts')
</body>
</html>
