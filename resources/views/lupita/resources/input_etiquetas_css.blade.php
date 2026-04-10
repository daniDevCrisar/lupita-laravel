<style>
    /* CSS compacto */

    .acordeon-mini .accordion-button{padding:6px 10px!important;font-size:1rem!important;}
    .acordeon-mini .accordion-body{padding:8px 10px!important;}
    .acordeon-mini .accordion-item{margin-bottom:4px;border-radius:5px;}
    .acordeon-mini .accordion-button::after{background-size:.75rem;width:.75rem;height:.75rem;}

    /* Grid de botones */
    .grid-botones{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;}

    /* Ocultar checkbox original */
    .btn-checkbox{display:none;}

    /* Estilo de botón usando clases de Bootstrap */
    .btn-opcion{
    display:block;
    text-align:center;
        padding:6px 8px;
        font-size:.7rem;
        font-weight:500;
        cursor:pointer;
        transition:all 0.15s;
    }

    /* Checkbox chequeado -> botón primary activo */
    .btn-checkbox:checked + .btn-opcion{
    background-color:var(--bs-primary);
        border-color:var(--bs-primary);
        color:white;
    }

    .btn-mini{padding:3px 8px;font-size:.65rem;border-radius:3px;}
</style>
