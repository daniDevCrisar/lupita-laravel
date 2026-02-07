@extends('layouts.app')

@section('title', 'Inicio')

@section('content')
    <h1 class="mb-3">Hola Mundo</h1>
    <p>Panel logístico AI</p>
    <h1>{{ $titulo }}</h1>
    <p>Bienvenido {{ $usuario }}</p>
<div class="row justify-content-center">
    <div class="col-md-6">

        <div class="card shadow-sm">
            <div class="card-header">
                Importar Archivo Excel
            </div>

            <div class="card-body">

                <form action="/procesar_excel" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Seleccionar archivo</label>
                        <input 
                            type="file" 
                            name="excel" 
                            class="form-control"
                            accept=".xls,.xlsx,.csv"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        Importar
                    </button>

                </form>

            </div>
        </div>

    </div>
</div>

    

@endsection
