@extends('layouts.app')

@section('title', 'Generar Reporte VAPI')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-body p-4">

                    <h1 class="h3 fw-bold mb-1 text-light">
                        <i class="bi bi-telephone-fill text-info me-2"></i>Generar Reporte VAPI
                    </h1>
                    <p class="text-secondary mb-4">Elegí el día y el tipo de reporte ejecutivo.</p>

                    <form method="get" action="{{ route('lupita.reporte.ejecutivo') }}">

                        <div class="mb-3">
                            <label for="fecha_inicio" class="form-label fw-bold">Día a analizar</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio"
                                   value="{{ $fecha_str }}" class="form-control">
                        </div>

                        <div class="mb-2">
                            <label for="tipo" class="form-label fw-bold">Tipo de reporte</label>
                            <select id="tipo" name="tipo" class="form-select">
                                <option value="compacta_ultra">Compacta – Ultra (gerencia, lo esencial)</option>
                                <option value="compacta_medio">Compacta – Media (+ etapa logística y Top 5 rutas)</option>
                                <option value="completa">Portada completa (KPIs por tipo, errores y mapa)</option>
                            </select>
                            <div class="form-text">
                                Ultra = resumen global + tabla por tipo. Media = agrega contexto operativo.
                                Completa = reporte extendido para pegar en correo.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold mt-4 py-2">
                            Generar reporte <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>

                    <p class="text-secondary text-center small mt-4 mb-0">
                        Los reportes se abren en una vista apta para copiar y pegar en correo.
                    </p>

                </div>
            </div>

        </div>
    </div>
@endsection
