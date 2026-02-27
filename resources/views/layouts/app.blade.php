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
                    <a class="nav-link active" href="#">Inicio</a>
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
   


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="<?= $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']; ?>/js/app.js"></script>


@yield('scripts')
</body>
</html>
