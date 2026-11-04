@extends('layouts.app')

@section('title', 'Acceso restringido')

@section('content')

    <div class="row mt-4">
        <div class="col-12">
            <div class="card bg-dark text-light border-secondary">
                <div class="card-body text-center py-5">
                    <h1 class="text-light">
                        <i class="bi bi-shield-lock"></i> Acceso restringido
                    </h1>
                    <p class="text-white-50 mb-3">{{ $exception?->getMessage() ?: 'No tienes permisos para ver esta pantalla.' }}</p>
                    <a href="{{ url('/') }}" class="btn btn-primary">
                        <i class="bi bi-house"></i> Volver al inicio
                    </a>
                </div>
            </div>
        </div>
    </div>

@endsection
