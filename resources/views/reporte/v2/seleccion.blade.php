@extends('layouts.app')

@section('title', 'Generar reporte VAPI')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">

            <div class="card bg-dark border-secondary shadow-sm mt-4">
                <div class="card-body p-4">

                    <h1 class="h3 fw-bold mb-1 text-light">
                        <i class="bi bi-file-earmark-bar-graph text-info me-2"></i>Generar reporte VAPI
                    </h1>
                    <p class="text-secondary mb-4">
                        Elegí el periodo y generá el reporte listo para pegar en el correo,
                        o abrí el dashboard interactivo con los mismos datos.
                    </p>

                    <form method="GET" action="{{ route('lupita.reportes.rango') }}" id="form_reporte">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fecha_inicio" class="form-label fw-bold">Desde</label>
                                <input type="date" id="fecha_inicio" name="fecha_inicio"
                                       value="{{ $fecha_inicio }}" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_fin" class="form-label fw-bold">Hasta</label>
                                <input type="date" id="fecha_fin" name="fecha_fin"
                                       value="{{ $fecha_fin }}" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="llamada_tipo_id" class="form-label fw-bold">Tipo de llamada</label>
                                <select id="llamada_tipo_id" name="llamada_tipo_id" class="form-select">
                                    <option value="">Todos los tipos</option>
                                    @foreach($catalogo_tipos as $tipo)
                                        <option value="{{ $tipo->id }}">{{ $tipo->emoji }} {{ $tipo->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="trt" class="form-label fw-bold">Transportista</label>
                                <select id="trt" name="trt" class="form-select">
                                    <option value="">Todos</option>
                                    @foreach($catalogo_trts as $trt)
                                        <option value="{{ $trt->id }}">{{ $trt->nombres }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <span class="form-label fw-bold d-block mb-2">Atajos de periodo</span>
                            <div class="d-flex gap-2 flex-wrap" id="atajos">
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="hoy">Hoy</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="ayer">Ayer</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="semana">Últimos 7 días</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="mes">Mes actual</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="mes_anterior">Mes anterior</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-periodo="30">Últimos 30 días</button>
                            </div>
                            <div class="form-text">
                                Los atajos solo completan las fechas; después elegí qué salida querés.
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mt-4">
                            <button type="submit" class="btn btn-primary fw-bold py-2 px-4">
                                <i class="bi bi-envelope-paper"></i> Reporte para correo
                            </button>
                            <button type="submit" class="btn btn-success fw-bold py-2 px-4"
                                    formaction="{{ route('lupita.reportes.reporte') }}">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reporte en pantalla
                            </button>
                            <button type="submit" class="btn btn-outline-light fw-bold py-2 px-4"
                                    formaction="{{ route('lupita.reportes.dashboard') }}">
                                <i class="bi bi-speedometer2"></i> Abrir dashboard
                            </button>
                            @if($fecha_inicio === $fecha_fin)
                                <a href="{{ route('lupita.reportes.dia', ['fecha_inicio' => $fecha_inicio]) }}"
                                   class="btn btn-outline-light py-2">
                                    <i class="bi bi-calendar-day"></i> Versión de un día
                                </a>
                            @endif
                        </div>
                    </form>

                    <hr class="border-secondary my-4">

                    <p class="text-secondary small mb-0">
                        <i class="bi bi-info-circle"></i>
                        El reporte de correo usa tablas y estilos inline (email-safe) y trae los mismos KPIs
                        del reporte histórico más tres hallazgos: costo por confirmación, hora dorada y fuga en buzón.
                    </p>
                </div>
            </div>

        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            const ini = document.getElementById('fecha_inicio');
            const fin = document.getElementById('fecha_fin');

            const iso = (d) => d.toISOString().slice(0, 10);

            const calcular = (tipo) => {
                const hoy = new Date();
                hoy.setHours(12, 0, 0, 0);

                switch (tipo) {
                    case 'hoy':
                        return [hoy, hoy];
                    case 'ayer': {
                        const a = new Date(hoy);
                        a.setDate(a.getDate() - 1);
                        return [a, a];
                    }
                    case 'semana': {
                        const d = new Date(hoy);
                        d.setDate(d.getDate() - 6);
                        return [d, hoy];
                    }
                    case '30': {
                        const d = new Date(hoy);
                        d.setDate(d.getDate() - 29);
                        return [d, hoy];
                    }
                    case 'mes':
                        return [new Date(hoy.getFullYear(), hoy.getMonth(), 1), hoy];
                    case 'mes_anterior':
                        return [
                            new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1),
                            new Date(hoy.getFullYear(), hoy.getMonth(), 0)
                        ];
                }
            };

            document.querySelectorAll('#atajos [data-periodo]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const [desde, hasta] = calcular(btn.dataset.periodo);
                    ini.value = iso(desde);
                    fin.value = iso(hasta);
                });
            });
        })();
    </script>
@endsection
