{{--
    Layout de REPORTES EN PANTALLA (no el tema oscuro del CRM).
    Es un documento autononimo con Bootstrap 5.3.2 CLARO, identico al head que
    usa reporte/todo/todo1.blade.php, para que el lenguaje visual del reporte
    (lienzo #f4f6f9, tarjetas blancas, cabeceras azul oscuro) se aplique puro,
    sin que el tema darkly del CRM pise los form-controls, badges y tablas.

    No lleva navbar ni footer comercial: es el lienzo del reporte a pantalla
    completa, igual que todo1. Los botones de accion y filtros del propio
    reporte viven en la vista (seccion content), no aqui.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Reporte VAPI') · Lupita CRM</title>

    <!-- Bootstrap 5 claro (mismo que todo1, no darkly) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
    </style>

    @yield('heads')
</head>
<body>
<div class="container py-4">
    @yield('content')
</div>

<!-- Bootstrap JS (los <select> y tooltips del formulario de filtros lo necesitan) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

@yield('scripts')
</body>
</html>
