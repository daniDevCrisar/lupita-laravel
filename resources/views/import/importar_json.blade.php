@extends('layouts.app')

@section('title', 'Lupita - Importar JSON')

@section('content')


<div class="container-fluid">
    <div class="row mb-3">
        <!-- IZQUIERDA -->
        <div class="col text-start">
        </div>

        <!-- DERECHA -->
        <div class="col text-end">
            <a href="{{ url('/importar/excel') }}" class="btn btn-success btn-sm">
                Siguiente <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </div>
    <!-- Header -->
    <header class="text-center mb-5">
        <h1 class="display-5 text-info mb-2">
            <i class="bi bi-filetype-json"></i> Importar JSON
        </h1>
        <p class="lead text-muted">Cargar los datos exportados del VAPI</p>


    </header>




    <div class="row justify-content-center">
        <div class="col-12" id="div_alertas">
            <div class="alert alert-success text-white d-none" id='alerta_exito'>
                <i class="bi bi-check-circle"></i>
                Archivo JSON cargado correctamente.
            </div>
            <div class="alert alert-danger text-white d-none" id='alerta_error'>
                <i class="bi bi-x-circle"></i>
                Error al procesar el archivo JSON.
            </div>

        </div>


        <div class="col-12">

            <!-- File Upload Section -->
            <div class="card shadow mb-4 bg-dark ">
                <div class="card-header bg-primary text-white">
                    <h2 class="h5 mb-0">
                        <i class="bi bi-upload"></i> Cargar Archivo JSON
                    </h2>
                </div>

                <div class="card-body text-light">

                    <div class="mb-3">
                        <label for="jsonFile" class="form-label">Selecciona un archivo JSON:</label>
                        <input class="form-control"
                               type="file"
                               id="jsonFile"
                               accept=".json,application/json">
                    </div>

                    <div class="d-flex flex-column align-items-center p-5 border rounded bg-dark upload-area">
                        <div class="text-center mb-3">
                            <i class="bi bi-file-earmark-text display-1 text-info"></i>
                        </div>

                        <p class="text-center mb-3 text-light">
                            Arrastra y suelta un archivo JSON aquí o haz clic en el botón de abajo
                        </p>

                        <label for="jsonFile" class="btn btn-primary">
                            <i class="bi bi-folder2-open"></i> Seleccionar Archivo
                        </label>

                        <p class="text-muted small mt-3">Formatos aceptados: .json</p>
                    </div>

                    <div class="mt-3">
                        <p class="text-muted">
                            <i class="bi bi-lightbulb"></i>
                            <small>Una vez cargado el archivo de VAPI, el sistema procesará la información y generará automáticamente un reporte en formato Excel con los datos relevantes de las llamadas.</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>



    </div>
</div>

@endsection

@section('scripts')
<script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
<script src="{{ asset('js/import/json/app.js') }}"></script>
<script src="{{ asset('js/import/json/procesar_transcripcion.js') }}"></script>
<script src="{{ asset('js/import/json/procesar_json.js') }}"></script>



@endsection
