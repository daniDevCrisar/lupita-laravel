{{--
    Layout de EXPORTACION: se usa cuando una vista se descarga como .html.
    Es una copia autonoma del tema (Bootstrap darkly por CDN + las mismas
    reglas de public/css/app.css) sin navbar, sin footer comercial y sin
    dependencias locales: el archivo descargado se abre en cualquier equipo
    con internet y se ve igual que en el CRM.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reporte VAPI') · Lupita CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body { background-color: #222; }

        /* mismas reglas que public/css/app.css: darkly dejaria el th oscuro */
        .table > thead.thead-lupita > tr > th,
        .table > thead.thead-lupita > th,
        .thead-lupita > tr > th,
        .thead-lupita > th {
            --bs-table-color: #fff;
            --bs-table-bg: var(--bs-primary);
            --bs-table-hover-color: #fff;
            background-color: var(--bs-primary) !important;
            color: #fff !important;
            border-bottom: 0;
            vertical-align: middle;
        }

        .table {
            --bs-table-color: #fff;
            --bs-table-hover-color: #fff;
        }

        /* al imprimir el tema oscuro gasta tinta y se lee mal */
        @media print {
            body { background: #fff !important; color: #000 !important; }
            .no-print { display: none !important; }
            .card, .table-responsive { break-inside: avoid; }
            .card { background: #fff !important; border-color: #ccc !important; }
            .text-white, .text-light, .text-white-50, .text-body-secondary { color: #000 !important; }
            .table { --bs-table-color: #000; }
        }
    </style>

    @yield('heads')
</head>
<body>
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom border-secondary">
        <div class="d-flex align-items-center gap-2">
            <img src="{{ asset('images/logo.png') }}" alt="Lupita CRM" width="44" class="rounded">
            <div>
                <div class="fw-bold">Lupita CRM · Reporte VAPI</div>
                <small class="text-white-50">Copia independiente generada el {{ now()->format('d/m/Y H:i') }}</small>
            </div>
        </div>
        <small class="text-white-50">Criterio de éxito: llamada_exitosa = 1</small>
    </div>

    @yield('content')

    <footer class="text-white-50 small border-top border-secondary mt-4 pt-3">
        Lupita CRM · Análisis de audio de llamadas IA — documento generado automáticamente.
    </footer>
</div>

@yield('scripts')
</body>
</html>
