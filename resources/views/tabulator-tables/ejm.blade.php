@extends('layouts.app')

@section('heads')
    @vite(['resources/js/app.js'])
<style>
    /* =========================================================================
       TABULATOR MIDNIGHT — Colores semánticos de Bootstrap 5.3
       Usa primary, secondary, success, info, warning, danger, light, dark
       ========================================================================= */

    .tabulator {
        background-color: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-white) !important;
    }

    /* ---------- HEADER ---------- */
    .tabulator .tabulator-header {
        background-color: var(--bs-secondary) !important;
        border-bottom: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-header .tabulator-col {
        background-color: var(--bs-primary) !important;
        border-right: 1px solid var(--bs-secondary) !important;
        color: var(--bs-white) !important;
    }

    .tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title .tabulator-title-editor {
        background-color: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-header .tabulator-col .tabulator-header-filter input,
    .tabulator .tabulator-header .tabulator-col .tabulator-header-filter select {
        background-color: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-header .tabulator-col .tabulator-header-filter input:focus,
    .tabulator .tabulator-header .tabulator-col .tabulator-header-filter select:focus {
        border-color: var(--bs-primary) !important;
        box-shadow: 0 0 0 0.15rem rgba(var(--bs-primary-rgb), 0.25) !important;
    }

    /* Flechas de ordenación */
    .tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-sorter .tabulator-arrow {
        border-bottom-color: var(--bs-light) !important;
    }
    .tabulator .tabulator-header .tabulator-col.tabulator-sortable[aria-sort=ascending] .tabulator-col-content .tabulator-col-sorter .tabulator-arrow {
        border-bottom-color: var(--bs-primary) !important;
        border-top: none !important;
    }
    .tabulator .tabulator-header .tabulator-col.tabulator-sortable[aria-sort=descending] .tabulator-col-content .tabulator-col-sorter .tabulator-arrow {
        border-top-color: var(--bs-primary) !important;
        border-bottom: none !important;
    }

    /* Hover en columnas ordenables */
    @media (hover: hover) and (pointer: fine) {
        .tabulator .tabulator-header .tabulator-col.tabulator-sortable.tabulator-col-sorter-element:hover {
            background-color: var(--bs-light) !important;
        }
    }

    /* ---------- CÁLCULOS ---------- */
    .tabulator .tabulator-header .tabulator-calcs-holder,
    .tabulator .tabulator-header .tabulator-calcs-holder .tabulator-row {
        background-color: var(--bs-dark) !important;
    }
    .tabulator .tabulator-footer .tabulator-calcs-holder,
    .tabulator .tabulator-footer .tabulator-calcs-holder .tabulator-row {
        background-color: var(--bs-dark) !important;
    }

    /* ---------- TABLA / BODY ---------- */
    .tabulator .tabulator-tableholder .tabulator-table {
        background-color: var(--bs-dark) !important;
        color: var(--bs-white) !important;
    }

    .tabulator .tabulator-tableholder .tabulator-placeholder .tabulator-placeholder-contents {
        color: var(--bs-white) !important;
    }

    /* ---------- FILAS ---------- */
    .tabulator-row {
        background-color: var(--bs-dark) !important;
        color: var(--bs-white) !important;
        border-bottom: 1px solid var(--bs-secondary) !important;
    }

    .tabulator-row.tabulator-row-even {
        background-color: var(--bs-secondary) !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator-row.tabulator-selectable:hover {
            background-color: var(--bs-primary) !important;
        }
    }

    .tabulator-row.tabulator-selected {
        background-color: rgba(var(--bs-primary-rgb), 0.5) !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator-row.tabulator-selected:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.9) !important;
        }
    }

    /* ---------- CELDAS ---------- */
    .tabulator-row .tabulator-cell {
        border-right: 1px solid var(--bs-secondary) !important;
        color: var(--bs-white) !important;
    }

    .tabulator-row .tabulator-cell.tabulator-row-header {
        background-color: var(--bs-secondary) !important;
        border-bottom: 1px solid var(--bs-secondary) !important;
        border-right: 1px solid var(--bs-secondary) !important;
    }

    /* Editores inline */
    .tabulator-row .tabulator-cell.tabulator-editing {
        border: 1px solid var(--bs-primary) !important;
    }
    .tabulator-row .tabulator-cell.tabulator-editing input,
    .tabulator-row .tabulator-cell.tabulator-editing select {
        background: var(--bs-dark) !important;
        color: var(--bs-light) !important;
    }

    /* ---------- FOOTER / PAGINACIÓN ---------- */
    .tabulator .tabulator-footer {
        background-color: var(--bs-secondary) !important;
        border-top: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-footer .tabulator-page-counter,
    .tabulator .tabulator-footer .tabulator-paginator label {
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-footer .tabulator-page-size {
        background-color: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
        border-radius: var(--bs-border-radius-sm) !important;
    }

    .tabulator .tabulator-footer .tabulator-page {
        background-color: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-white) !important;
        border-radius: var(--bs-border-radius-sm) !important;
        font-family: inherit !important;
        font-size: inherit !important;
        font-weight: inherit !important;
    }

    .tabulator .tabulator-footer .tabulator-page.active {
        background-color: var(--bs-primary) !important;
        border-color: var(--bs-primary) !important;
        color: var(--bs-light) !important;
    }

    .tabulator .tabulator-footer .tabulator-page:disabled {
        opacity: 0.5 !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator .tabulator-footer .tabulator-page:not(disabled):hover {
            background-color: var(--bs-secondary) !important;
            color: var(--bs-light) !important;
        }
    }

    /* ---------- SPREADSHEET TABS ---------- */
    .tabulator .tabulator-footer .tabulator-spreadsheet-tabs .tabulator-spreadsheet-tab {
        background: var(--bs-secondary) !important;
        border-color: var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }
    .tabulator .tabulator-footer .tabulator-spreadsheet-tabs .tabulator-spreadsheet-tab.tabulator-spreadsheet-tab-active {
        background: var(--bs-primary) !important;
        color: var(--bs-light) !important;
    }

    /* ---------- POPUPS / MENÚS / EDIT LISTS ---------- */
    .tabulator-popup-container {
        background: var(--bs-dark) !important;
        border: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5) !important;
    }

    .tabulator-menu .tabulator-menu-item {
        color: var(--bs-light) !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator-menu .tabulator-menu-item:not(.tabulator-menu-item-disabled):hover {
            background: var(--bs-secondary) !important;
        }
    }

    .tabulator-menu .tabulator-menu-separator {
        border-top: 1px solid var(--bs-secondary) !important;
    }

    .tabulator-edit-list {
        background: var(--bs-dark) !important;
        color: var(--bs-light) !important;
    }

    .tabulator-edit-list .tabulator-edit-list-item {
        color: var(--bs-light) !important;
    }

    .tabulator-edit-list .tabulator-edit-list-item.active {
        background: var(--bs-primary) !important;
        color: var(--bs-light) !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator-edit-list .tabulator-edit-list-item:hover {
            background: var(--bs-secondary) !important;
            color: var(--bs-light) !important;
        }
    }

    .tabulator-edit-list .tabulator-edit-list-placeholder,
    .tabulator-edit-list .tabulator-edit-list-group {
        color: var(--bs-light) !important;
    }

    .tabulator-edit-list .tabulator-edit-list-group {
        border-bottom: 1px solid var(--bs-secondary) !important;
    }

    /* ---------- TOGGLE ---------- */
    .tabulator-toggle {
        background: var(--bs-secondary) !important;
        border-color: var(--bs-secondary) !important;
    }
    .tabulator-toggle.tabulator-toggle-on {
        background: var(--bs-primary) !important;
    }
    .tabulator-toggle .tabulator-toggle-switch {
        background: var(--bs-dark) !important;
        border-color: var(--bs-secondary) !important;
    }

    /* ---------- GROUPS ---------- */
    .tabulator-row.tabulator-group {
        background: var(--bs-secondary) !important;
        border-bottom: 1px solid var(--bs-secondary) !important;
        border-top: 1px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }
    .tabulator-row.tabulator-group span {
        color: var(--bs-light) !important;
    }

    @media (hover: hover) and (pointer: fine) {
        .tabulator-row.tabulator-group:hover {
            background-color: var(--bs-dark) !important;
        }
    }

    /* ---------- TREE / DATA TREE ---------- */
    .tabulator-row .tabulator-cell .tabulator-data-tree-control {
        background: rgba(var(--bs-light-rgb), 0.1) !important;
        border: 1px solid var(--bs-secondary) !important;
    }
    .tabulator-row .tabulator-cell .tabulator-data-tree-control .tabulator-data-tree-control-collapse:after,
    .tabulator-row .tabulator-cell .tabulator-data-tree-control .tabulator-data-tree-control-expand,
    .tabulator-row .tabulator-cell .tabulator-data-tree-control .tabulator-data-tree-control-expand:after {
        background: var(--bs-light) !important;
    }

    .tabulator-row .tabulator-cell .tabulator-data-tree-branch {
        border-bottom: 2px solid var(--bs-secondary) !important;
        border-left: 2px solid var(--bs-secondary) !important;
    }

    /* ---------- ALERT ---------- */
    .tabulator .tabulator-alert {
        background: rgba(0, 0, 0, 0.6) !important;
    }
    .tabulator .tabulator-alert .tabulator-alert-msg {
        background: var(--bs-dark) !important;
        color: var(--bs-light) !important;
    }
    .tabulator .tabulator-alert .tabulator-alert-msg.tabulator-alert-state-msg {
        border: 4px solid var(--bs-secondary) !important;
        color: var(--bs-light) !important;
    }
    .tabulator .tabulator-alert .tabulator-alert-msg.tabulator-alert-state-error {
        border: 4px solid var(--bs-danger) !important;
        color: var(--bs-danger) !important;
    }

    /* ---------- SCROLLBARS ---------- */
    .tabulator .tabulator-tableholder::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }
    .tabulator .tabulator-tableholder::-webkit-scrollbar-track {
        background: var(--bs-secondary);
    }
    .tabulator .tabulator-tableholder::-webkit-scrollbar-thumb {
        background: var(--bs-dark);
        border-radius: 5px;
    }
    .tabulator .tabulator-tableholder::-webkit-scrollbar-thumb:hover {
        background: var(--bs-primary);
    }

    /* ---------- TOOLTIP ---------- */
    .tabulator-tooltip {
        background: var(--bs-dark) !important;
        color: var(--bs-light) !important;
        border: 1px solid var(--bs-secondary) !important;
    }
</style>

@endsection

@section('content')


    <div class="container-fluid">

        {{-- BOTONES --}}
        <div class="d-flex flex-wrap gap-2 mb-3">

            <button class="btn btn-primary" id="btnAgregar">
                <i class="bi bi-plus-lg"></i>
                Agregar
            </button>

            <button class="btn btn-success" id="btnEditar">
                <i class="bi bi-pencil"></i>
                Editar seleccionado
            </button>

            <button class="btn btn-danger" id="btnEliminar">
                <i class="bi bi-trash"></i>
                Eliminar seleccionado
            </button>

            <button class="btn btn-secondary" id="btnLimpiarFiltros">
                <i class="bi bi-funnel"></i>
                Limpiar filtros
            </button>

            <button class="btn btn-info" id="btnMostrarColumnas">
                <i class="bi bi-layout-three-columns"></i>
                Columnas
            </button>

            <button class="btn btn-outline-success" id="btnExcel">
                <i class="bi bi-file-earmark-excel"></i>
                Excel
            </button>

            <button class="btn btn-outline-danger" id="btnPDF">
                <i class="bi bi-file-earmark-pdf"></i>
                PDF
            </button>

        </div>

        {{-- BÚSQUEDA GLOBAL --}}
        <div class="row mb-3">

            <div class="col-md-4">

                <div class="input-group">

                <span class="input-group-text">
                    <i class="bi bi-search"></i>
                </span>

                    <input
                        type="text"
                        id="buscar"
                        class="form-control"
                        placeholder="Buscar en toda la tabla..."
                    >

                </div>

            </div>

        </div>

        {{-- TABLA --}}
        <div id="tablaLlamadas"></div>

    </div>


    {{-- MODAL --}}
    <div class="modal fade" id="modalRegistro" tabindex="-1">

        <div class="modal-dialog">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title" id="modalTitulo">
                        Registro
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden" id="registroId">

                    <div class="mb-3">
                        <label class="form-label">
                            Conductor
                        </label>

                        <input
                            type="text"
                            id="registroConductor"
                            class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Placa
                        </label>

                        <input
                            type="text"
                            id="registroPlaca"
                            class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Transportista
                        </label>

                        <input
                            type="text"
                            id="registroTransportista"
                            class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Estado
                        </label>

                        <select
                            id="registroEstado"
                            class="form-select">

                            <option value="Confirmado">
                                Confirmado
                            </option>

                            <option value="En ruta">
                                En ruta
                            </option>

                            <option value="Pendiente">
                                Pendiente
                            </option>

                            <option value="Error">
                                Error
                            </option>

                        </select>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        id="btnGuardar">
                        Guardar
                    </button>

                </div>

            </div>

        </div>

    </div>


@endsection


@section('scripts')

    <script>

        document.addEventListener('DOMContentLoaded', () => {

            const elemento = document.querySelector('#tablaLlamadas');

            if (!elemento) {
                return;
            }


            /*
            |--------------------------------------------------------------------------
            | DATOS DE PRUEBA
            |--------------------------------------------------------------------------
            */

            const datos = [

                {
                    id: 1,
                    fecha: "2026-09-14 08:15",
                    conductor: "Juan Pérez",
                    placa: "ABC-123",
                    transportista: "Transportes Lima",
                    estado: "Confirmado",
                    duracion: 125,
                    telefono: "999111222"
                },

                {
                    id: 2,
                    fecha: "2026-09-14 09:20",
                    conductor: "Carlos López",
                    placa: "XYZ-456",
                    transportista: "Crisar",
                    estado: "Pendiente",
                    duracion: 85,
                    telefono: "988222333"
                },

                {
                    id: 3,
                    fecha: "2026-09-14 10:05",
                    conductor: "Pedro García",
                    placa: "DEF-789",
                    transportista: "Transportes Norte",
                    estado: "En ruta",
                    duracion: 210,
                    telefono: "977333444"
                },

                {
                    id: 4,
                    fecha: "2026-09-14 10:40",
                    conductor: "Luis Torres",
                    placa: "GHI-321",
                    transportista: "Crisar",
                    estado: "Error",
                    duracion: 32,
                    telefono: "966444555"
                },

                {
                    id: 5,
                    fecha: "2026-09-14 11:10",
                    conductor: "Miguel Sánchez",
                    placa: "JKL-654",
                    transportista: "Transportes Lima",
                    estado: "Confirmado",
                    duracion: 145,
                    telefono: "955555666"
                },

                {
                    id: 6,
                    fecha: "2026-09-14 11:35",
                    conductor: "José Ramírez",
                    placa: "MNO-987",
                    transportista: "Crisar",
                    estado: "En ruta",
                    duracion: 175,
                    telefono: "944666777"
                },

                {
                    id: 7,
                    fecha: "2026-09-14 12:00",
                    conductor: "Roberto Díaz",
                    placa: "PQR-111",
                    transportista: "Transportes Sur",
                    estado: "Pendiente",
                    duracion: 64,
                    telefono: "933777888"
                },

                {
                    id: 8,
                    fecha: "2026-09-14 12:25",
                    conductor: "Fernando Ruiz",
                    placa: "STU-222",
                    transportista: "Crisar",
                    estado: "Confirmado",
                    duracion: 190,
                    telefono: "922888999"
                }

            ];


            /*
            |--------------------------------------------------------------------------
            | TABULATOR
            |--------------------------------------------------------------------------
            */

            const tabla = new Tabulator(elemento, {
                theme: "midnight",

                height: "550px",

                data: datos,

                layout: "fitColumns",

                responsiveLayout: "hide",

                movableColumns: true,

                selectableRows: true,

                pagination: true,

                paginationSize: 5,

                paginationSizeSelector: [
                    5,
                    10,
                    20,
                    50
                ],

                placeholder: "No hay registros",

                columns: [

                    {
                        title: "ID",
                        field: "id",
                        sorter: "number",
                        width: 80,
                        headerFilter: "input"
                    },

                    {
                        title: "Fecha",
                        field: "fecha",
                        sorter: "datetime",
                        headerFilter: "input"
                    },

                    {
                        title: "Conductor",
                        field: "conductor",
                        sorter: "string",
                        headerFilter: "input"
                    },

                    {
                        title: "Placa",
                        field: "placa",
                        sorter: "string",
                        headerFilter: "input"
                    },

                    {
                        title: "Transportista",
                        field: "transportista",
                        sorter: "string",
                        headerFilter: "input"
                    },

                    {
                        title: "Estado",
                        field: "estado",

                        headerFilter: "select",

                        headerFilterParams: {
                            values: {
                                "": "Todos",
                                "Confirmado": "Confirmado",
                                "En ruta": "En ruta",
                                "Pendiente": "Pendiente",
                                "Error": "Error"
                            }
                        },

                        formatter: function(cell) {

                            const estado = cell.getValue();

                            let clase = "secondary";
                            let icono = "circle";

                            if (estado === "Confirmado") {
                                clase = "success";
                                icono = "check-circle";
                            }

                            if (estado === "En ruta") {
                                clase = "primary";
                                icono = "truck";
                            }

                            if (estado === "Pendiente") {
                                clase = "warning text-dark";
                                icono = "clock";
                            }

                            if (estado === "Error") {
                                clase = "danger";
                                icono = "x-circle";
                            }

                            return `
                        <span class="badge bg-${clase}">
                            <i class="bi bi-${icono}"></i>
                            ${estado}
                        </span>
                    `;
                        }

                    },

                    {
                        title: "Duración",
                        field: "duracion",
                        sorter: "number",

                        formatter: function(cell) {

                            const segundos = cell.getValue();

                            const minutos = Math.floor(segundos / 60);
                            const resto = segundos % 60;

                            return `
                        <i class="bi bi-clock"></i>
                        ${minutos}m ${resto}s
                    `;
                        },

                        headerFilter: "number"
                    },

                    {
                        title: "Teléfono",
                        field: "telefono",
                        visible: false
                    },

                    {
                        title: "Acciones",

                        hozAlign: "center",

                        headerSort: false,

                        formatter: function() {

                            return `
                        <button
                            class="btn btn-sm btn-outline-primary btn-editar"
                            title="Editar">

                            <i class="bi bi-pencil"></i>

                        </button>

                        <button
                            class="btn btn-sm btn-outline-danger btn-eliminar"
                            title="Eliminar">

                            <i class="bi bi-trash"></i>

                        </button>
                    `;
                        },

                        cellClick: function(e, cell) {

                            const fila = cell.getRow();

                            const data = fila.getData();

                            if (e.target.closest(".btn-editar")) {

                                editarRegistro(data);

                            }

                            if (e.target.closest(".btn-eliminar")) {

                                eliminarRegistro(fila);

                            }

                        }

                    }

                ]

            });


            /*
            |--------------------------------------------------------------------------
            | BÚSQUEDA GLOBAL
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#buscar")
                .addEventListener("keyup", function() {

                    const valor = this.value.toLowerCase();

                    tabla.setFilter(function(data) {

                        return Object
                            .values(data)
                            .some(valorCampo =>

                                String(valorCampo)
                                    .toLowerCase()
                                    .includes(valor)

                            );

                    });

                });


            /*
            |--------------------------------------------------------------------------
            | LIMPIAR FILTROS
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnLimpiarFiltros")
                .addEventListener("click", function() {

                    tabla.clearFilter();

                    document
                        .querySelector("#buscar")
                        .value = "";

                });


            /*
            |--------------------------------------------------------------------------
            | AGREGAR
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnAgregar")
                .addEventListener("click", function() {

                    document.querySelector("#modalTitulo").innerText =
                        "Nuevo registro";

                    document.querySelector("#registroId").value = "";

                    document.querySelector("#registroConductor").value = "";

                    document.querySelector("#registroPlaca").value = "";

                    document.querySelector("#registroTransportista").value = "";

                    document.querySelector("#registroEstado").value =
                        "Pendiente";


                    const modal = new bootstrap.Modal(
                        document.querySelector("#modalRegistro")
                    );

                    modal.show();

                });


            /*
            |--------------------------------------------------------------------------
            | EDITAR SELECCIONADO
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnEditar")
                .addEventListener("click", function() {

                    const seleccionados = tabla.getSelectedData();

                    if (seleccionados.length !== 1) {

                        alert("Selecciona exactamente un registro.");

                        return;
                    }

                    editarRegistro(seleccionados[0]);

                });


            /*
            |--------------------------------------------------------------------------
            | ELIMINAR SELECCIONADO
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnEliminar")
                .addEventListener("click", function() {

                    const filas = tabla.getSelectedRows();

                    if (filas.length === 0) {

                        alert("Selecciona al menos un registro.");

                        return;
                    }

                    if (!confirm(
                        `¿Eliminar ${filas.length} registro(s)?`
                    )) {
                        return;
                    }

                    filas.forEach(fila => {

                        fila.delete();

                    });

                });


            /*
            |--------------------------------------------------------------------------
            | GUARDAR
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnGuardar")
                .addEventListener("click", function() {

                    const id =
                        document.querySelector("#registroId").value;

                    const nuevoRegistro = {

                        id: id
                            ? Number(id)
                            : Date.now(),

                        conductor:
                        document.querySelector("#registroConductor").value,

                        placa:
                        document.querySelector("#registroPlaca").value,

                        transportista:
                        document.querySelector("#registroTransportista").value,

                        estado:
                        document.querySelector("#registroEstado").value,

                        fecha:
                            new Date()
                                .toISOString()
                                .slice(0, 16)
                                .replace("T", " "),

                        duracion: 0,

                        telefono: ""

                    };


                    if (id) {

                        const fila = tabla.getRow(Number(id));

                        if (fila) {

                            fila.update(nuevoRegistro);

                        }

                    } else {

                        tabla.addRow(
                            nuevoRegistro,
                            true
                        );

                    }


                    bootstrap.Modal
                        .getInstance(
                            document.querySelector("#modalRegistro")
                        )
                        .hide();

                });


            /*
            |--------------------------------------------------------------------------
            | EDITAR REGISTRO
            |--------------------------------------------------------------------------
            */

            window.editarRegistro = function(data) {

                document.querySelector("#modalTitulo").innerText =
                    "Editar registro";

                document.querySelector("#registroId").value =
                    data.id;

                document.querySelector("#registroConductor").value =
                    data.conductor;

                document.querySelector("#registroPlaca").value =
                    data.placa;

                document.querySelector("#registroTransportista").value =
                    data.transportista;

                document.querySelector("#registroEstado").value =
                    data.estado;


                const modal = new bootstrap.Modal(
                    document.querySelector("#modalRegistro")
                );

                modal.show();

            };


            /*
            |--------------------------------------------------------------------------
            | ELIMINAR FILA
            |--------------------------------------------------------------------------
            */

            window.eliminarRegistro = function(fila) {

                const data = fila.getData();

                if (!confirm(
                    `¿Eliminar a ${data.conductor}?`
                )) {
                    return;
                }

                fila.delete();

            };


            /*
            |--------------------------------------------------------------------------
            | COLUMNAS
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnMostrarColumnas")
                .addEventListener("click", function() {

                    tabla.getColumns().forEach(columna => {

                        const field = columna.getField();

                        if (!field) {
                            return;
                        }

                        const visible =
                            columna.isVisible();

                        columna.toggle();

                    });

                });


            /*
            |--------------------------------------------------------------------------
            | EXPORTAR EXCEL
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnExcel")
                .addEventListener("click", function() {

                    tabla.download(
                        "xlsx",
                        "llamadas.xlsx",
                        {
                            sheetName: "Llamadas"
                        }
                    );

                });


            /*
            |--------------------------------------------------------------------------
            | EXPORTAR PDF
            |--------------------------------------------------------------------------
            */

            document
                .querySelector("#btnPDF")
                .addEventListener("click", function() {

                    tabla.download(
                        "pdf",
                        "llamadas.pdf"
                    );

                });


        });

    </script>

@endsection
