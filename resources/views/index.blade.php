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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- CSS propio opcional -->
    {{-- <link href="{{ asset('css/app.css') }}" rel="stylesheet"> --}}
    @yield('heads')
</head>

<body class="" style="">
<!-- NAVBAR -->
<div class="p-5">
    <div class="container">

        <div class="row">
            <div class="col-12">
                <div class="card text-dark-emphasis p-3  d-flex justify-content-center align-items-center h-100" style="border-radius: 2rem;background:#F6F2F0;">
                    <div class="col-12  m-1 text-center" >
                        <img src="{{ asset('images/banner.png') }}" class="logo" alt="Lupita" style="width: 65vw;">
                    </div>
                    <div class="col-12 text-center">
                        <a href="{{route('login')}}" class="btn btn-warning">Iniciar Sesión</a>
                    </div>
                </div>

            </div>


        </div>
    </div>

</div>



@yield('scripts')
</body>
</html>
