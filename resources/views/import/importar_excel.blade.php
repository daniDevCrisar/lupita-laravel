@extends('layouts.app')

@section('title', 'Inicio')

@section('content')
<div class="container-fluid">

    <div class="row justify-content-center">
        <div class="row mb-3">
            <!-- IZQUIERDA -->
            <div class="col text-start">
                <a href="{{ url('/importar/json') }}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Procesar JSON
                </a>
            </div>
     

            <!-- DERECHA -->
            <div class="col text-end">
                <a href="#" onclick="document.getElementById('frm_enviar_excel').submit(); return false;" class="btn btn-success btn-sm">
                    Siguiente <i class="bi bi-arrow-right"></i>
                </a>
            </div>

        </div>

        <div class="col-12 col-lg-6">
            <header class="text-center mb-5 ">
                <h1 class="display-5 text-success mb-2">
                    <i class="bi bi-filetype-xls"></i> Importar EXCEL
                </h1>
                <p class="lead text-muted">Cargar registros de llamadas y sus referencias asociadas.</p>


            </header>
            
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h2 class="h5 mb-0">
                        <i class="bi bi-upload"></i>
                        Cargar Archivo Excel
                    </h2>
                </div>

                <div class="card-body">

                    <form action="{{ url('/importar/excel/procesar') }}" method="POST" enctype="multipart/form-data" id="frm_enviar_excel">
                        @csrf

                        <div class="row g-3">

                            <!-- ARCHIVO -->
                            <div class="col-12">
                                <label for="excel" class="form-label fw-semibold">
                                    Seleccionar archivo:
                                </label>
                                <input 
                                    type="file" 
                                    id="excel"
                                    name="excel" 
                                    class="form-control"
                                    accept=".xls,.xlsx,.csv"
                                    required
                                >
                            </div>
                            <fieldset class="col-12 border rounded p-3">
                                <legend class="float-none w-auto px-2 fs-6 fw-semibold">
                                    Hojas:
                                </legend>
                                <div class="row">
                                   <!-- LLAMADAS -->
                                    <div class="col-12 col-md-6">
                                        <label for="txt_llamadas" class="form-label fw-semibold">
                                            Llamadas:
                                        </label>
                                        <input 
                                            type="text"
                                            id="txt_llamadas"
                                            name="txt_llamadas"
                                            class="form-control"
                                            value="FILTRADO"
                                            required
                                        >
                                    </div>

                                    <!-- REFERENCIAS DE COMPROMISO -->
                                    <div class="col-12 col-md-6">
                                        <label for="txt_ref_1" class="form-label fw-semibold">
                                            Referencias de compromiso:
                                        </label>
                                        <input 
                                            type="text"
                                            id="txt_ref_1"
                                            name="txt_ref_1"
                                            class="form-control"
                                            value="TRT_1"
                                            required
                                        >
                                    </div>

                                    <!-- REFERENCIAS -->
                                    <div class="col-12 col-md-6">
                                        <label for="txt_ref" class="form-label fw-semibold">
                                            Referencias:
                                        </label>
                                        <input 
                                            type="text"
                                            id="txt_ref"
                                            name="txt_ref"
                                            class="form-control"
                                            value="TRT"
                                            required
                                        >
                                    </div>

                                </div>
 
                            </fieldset>

                            <!-- BOTÓN -->
                            <div class="col-12 pt-2">
                                <button type="submit" class="btn btn-success w-100 fw-semibold">
                                    Importar Archivo
                                </button>
                            </div>

                        </div>

                    </form>

                </div>

                
            </div>

        </div>



    </div>

</div>



    

@endsection
