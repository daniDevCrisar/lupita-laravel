<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet">


</head>
<body>

    <div class="container py-5 ">
        <div class="row d-flex justify-content-center align-items-center h-100">
            <div class="col-12 col-md-8 col-lg-6 col-xl-5">
                <div class="card bg-primary text-white" style="border-radius: 2rem;">
                    <div class="card-body p-5 text-center">

                        <div class="mb-md-5 mt-md-4 pb-5">
                            <div class="col-12">
                                <img src="{{ asset('images/logo.png') }}"
                                     alt="Logo"
                                     width="256"
                                     class="me-2 rounded">
                            </div>
                            <h3 class="fw-bold mb-2 text-uppercase">Lupita Login</h3>
                            <p class="text-white-50 mb-5">Ingresa tu usuario y contraseña!</p>

                            <form method="POST" action="{{ route('login') }}">
                            @csrf
                            <div data-mdb-input-init class="form-outline form-white mb-4">
                                <input id="name" type="text" class="form-control form-control-lg @error('email') is-invalid @enderror" name="name" value="{{ old('email') }}" required autocomplete="email" autofocus>

                                @error('name')
                                <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                                <label class="form-label" for="name">Usuario</label>
                            </div>

                            <div class="form-outline form-white mb-4">
                                <input id="password" type="password" class="form-control form-control-lg @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
                                @error('password')
                                <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                                <label class="form-label" for="password">Password</label>
                            </div>
                            <div class="d-flex justify-content-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="remember">
                                        Recuerdame
                                    </label>
                                </div>
                            </div>

                            @if (Route::has('password.request'))
                            <p>
                                <a class="btn btn-link" href="{{ route('password.request') }}">
                                    Olvidaste tu contraseña?
                                </a>
                            </p>
                            @endif

                            <button class="btn btn-outline-light btn-lg px-5" type="submit">Iniciar Sesion</button>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
