{{--
    Estilos personalizados del reporte clasico (reporte/todo/todo1.blade.php),
    compartidos por las descargas .html (layouts.export). Son los mismos
    valores que todo1: tarjetas redondeadas con sombra, degradados de estado,
    cabeceras azul oscuro, celdas de mapa de calor y badges.
--}}
<style>
    .report-card {
        border-radius: 20px;
        border: none;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        transition: all 0.2s ease;
    }

    .report-card:hover {
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        transform: translateY(-3px);
    }

    .bg-exito {
        background: linear-gradient(145deg, #e6f7e6, #c8e6c9);
        border-left: 6px solid #2e7d32;
    }

    .bg-fallo {
        background: linear-gradient(145deg, #ffebee, #ffcdd2);
        border-left: 6px solid #c62828;
    }

    .bg-advertencia {
        background: linear-gradient(145deg, #fff8e1, #ffecb3);
        border-left: 6px solid #ff8f00;
    }

    .estrella {
        color: #ffc107;
        font-size: 1.1rem;
    }

    .peligro {
        color: #d32f2f;
        font-size: 1.2rem;
    }

    .tabla-conductores th {
        background-color: #2c3e50;
        color: white;
        font-weight: 500;
    }

    .badge-exito {
        background-color: #2e7d32;
        color: white;
        font-size: 0.8rem;
    }

    .badge-fallo {
        background-color: #c62828;
        color: white;
    }

    .badge-critico {
        background-color: #b71c1c;
        color: white;
        animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
        0% {
            opacity: 1;
        }
        50% {
            opacity: 0.85;
        }
        100% {
            opacity: 1;
        }
    }

    .footer-note {
        font-size: 0.85rem;
        color: #5f6368;
        border-top: 1px dashed #ccc;
    }

    .table-hover tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }

    .tooltip-custom {
        border-bottom: 1px dotted #007bff;
        cursor: help;
    }

    .heatmap-cell {
        text-align: center;
        font-size: 0.85rem;
        padding: 8px;
        border-radius: 5px;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .heatmap-cell:hover {
        transform: scale(1.2);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
</style>
