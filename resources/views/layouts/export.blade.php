{{--
    Layout de EXPORTACION: se usa cuando una vista se descarga como .html.
    Adopta el estilo claro del reporte classico (todo1): Bootstrap 5 sin
    tema, lienzo #f4f6f9 y Segoe UI. Sin navbar, sin footer comercial y sin
    dependencias locales: el archivo descargado se abre en cualquier equipo
    con internet y se ve igual que un reporte todo1.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reporte VAPI') · Lupita CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    {{-- los reportes usan los iconos Font Awesome del formato classico (todo1) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* lienzo claro estilo todo1 */
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #212529;
        }

        .card { border-color: #dee2e6; }

        /* cabeceras azul de la app, identicas en pantalla y descarga */
        .table > thead.thead-lupita > tr > th,
        .table > thead.thead-lupita > th,
        .thead-lupita > tr > th,
        .thead-lupita > th {
            background-color: var(--bs-primary) !important;
            color: #fff !important;
            border-bottom: 0;
            vertical-align: middle;
        }

        @media print {
            .no-print { display: none !important; }
            .card, .table-responsive { break-inside: avoid; }
        }
    </style>

    {{-- los mismos estilos personalizados del reporte classico (todo1) --}}
    @include('reporte.partials.estilos-todo1')

    @yield('heads')
</head>
<body>
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
        <div class="d-flex align-items-center gap-2">
            <img src="{{ asset('images/logo.png') }}" alt="Lupita CRM" width="44" class="rounded">
            <div>
                <div class="fw-bold">Lupita CRM · Reporte VAPI</div>
                <small class="text-muted">Copia independiente generada el {{ now()->format('d/m/Y H:i') }}</small>
            </div>
        </div>
        <small class="text-muted">Criterio de éxito: llamada_exitosa = 1</small>
    </div>

    @yield('content')

    <footer class="text-muted small border-top mt-4 pt-3">
        Lupita CRM · Análisis de audio de llamadas IA — documento generado automáticamente.
    </footer>
</div>

@yield('scripts')
</body>
</html>
