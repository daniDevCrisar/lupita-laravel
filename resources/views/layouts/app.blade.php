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

    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

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

                <li class="nav-item">
                    <a class="nav-link active" href="{{route('lupita.llamadas')}}">Llamadas</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="{{route('lupita.conductores')}}">Conductores</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="{{route('lupita.transportistas')}}">Transportistas</a>
                </li>

                <!-- 🔽 MENÚ DESPLEGABLE -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       role="button"
                       data-bs-toggle="dropdown"
                       aria-expanded="false">
                        Gestión
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#">Llamadas</a></li>
                        <li><a class="dropdown-item" href="#">Clientes</a></li>
                        <li><a class="dropdown-item" href="#">Usuarios</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#">Cerrar sesión</a></li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#">Contacto</a>
                </li>

            </ul>
        </div>

    </div>
</nav>

<div class="container-fluid">
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
                    <li class="mb-2"><a href="{{route('lupita.llamadas')}}" class="text-white text-decoration-none">Llamadas</a></li>
                    <li class="mb-2"><a href="{{route('lupita.conductores')}}" class="text-white text-decoration-none">Conductores</a></li>
                    <li class="mb-2"><a href="{{route('lupita.transportistas')}}" class="text-white text-decoration-none">Transportistas</a></li>
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

<script src="<?= $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']; ?>/js/app.js"></script>


@yield('scripts')
</body>
</html>
