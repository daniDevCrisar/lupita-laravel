<!DOCTYPE html>
<html lang="es" x-data="callsApp()" x-init="init()" :data-bs-theme="darkMode ? 'dark' : 'light'">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Lista de Llamadas</title>
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }

        /* Forzar colores de fondo en el elemento raíz y body */
        html, body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        html[data-bs-theme="dark"],
        html[data-bs-theme="dark"] body {
            background-color: #1a1d20 !important;
            color: #dee2e6 !important;
        }

        html[data-bs-theme="light"],
        html[data-bs-theme="light"] body {
            background-color: #f8f9fa !important;
            color: #212529 !important;
        }

        .table-responsive { min-height: 400px; }
        .sticky-header th { position: sticky; top: 0; background: var(--bs-body-bg); z-index: 10; }
        .clock-style { font-family: 'Courier New', Courier, monospace; font-size: 1.4rem; font-weight: bold; line-height: 1; letter-spacing: 1px; }
        .text-dark-custom { color: #dee2e6 !important; }
        .text-light-custom { color: #212529 !important; }
    </style>
    <!-- JS -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
</head>
<body :class="darkMode ? 'text-light' : 'text-dark'" x-cloak>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col-12 col-md-5">
            <h2 class="mb-0"><i class="bi bi-telephone-outbound text-primary"></i> Registro de Llamadas</h2>
            <p class="text-muted small mb-0">Consulta en tiempo real (Auto: 30s)</p>
        </div>
        <div class="col-12 col-md-7 text-md-end d-flex align-items-center justify-content-md-end gap-3 mt-3 mt-md-0">
            <div x-show="cargando" class="spinner-border spinner-border-sm text-primary" role="status"></div>
            <span class="badge" :class="darkMode ? 'bg-dark border border-secondary' : 'bg-secondary'" x-text="filteredCalls.length + ' / ' + total + ' registros'"></span>

            <button @click="toggleDarkMode()" class="btn btn-sm border-0 fs-4" :title="darkMode ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'">
                <i class="bi" :class="darkMode ? 'bi-sun-fill text-warning' : 'bi-moon-stars-fill text-primary'"></i>
            </button>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4" :class="darkMode ? 'bg-dark-subtle text-light' : ''">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-uppercase">Fecha Inicio</label>
                    <input type="date" class="form-control" x-model="filtros.startdate" @change="fetchCalls()" :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-uppercase">Fecha Fin</label>
                    <input type="date" class="form-control" x-model="filtros.enddate" @change="fetchCalls()" :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-uppercase">Etapa Logística</label>
                    <select class="form-select" x-model="filtros.etapa_id" :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                        <option value="">Todas las etapas</option>
                        <template x-for="etapa in etapas" :key="etapa.id">
                            <option :value="etapa.id" x-text="(etapa.emoji || '') + ' ' + etapa.nombre"></option>
                        </template>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-uppercase">Origen</label>
                    <select class="form-select" x-model="filtros.origen" :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                        <option value="">Todos</option>
                        <option value="lima">Lima</option>
                        <option value="provincia">Provincia</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-uppercase">Destino</label>
                    <select class="form-select" x-model="filtros.destino" :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                        <option value="">Todos</option>
                        <option value="lima">Lima</option>
                        <option value="provincia">Provincia</option>
                    </select>
                </div>





                <div class="col-md-3">
                    <label class="form-label small fw-bold text-uppercase">Búsqueda rápida</label>
                    <input type="text" class="form-control" x-model="search" placeholder="Conductor, placa o ref..." :class="darkMode ? 'bg-dark text-light border-secondary' : ''">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn w-100" :class="darkMode ? 'btn-outline-light' : 'btn-outline-secondary'" @click="resetFilters()">
                        <i class="bi bi-arrow-counterclockwise"></i> Reiniciar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0" :class="darkMode ? 'bg-dark-subtle text-light' : ''">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" :class="darkMode ? 'table-dark' : ''">
                    <thead :class="darkMode ? 'table-dark' : 'table-light'" class="sticky-header">
                        <tr>
                            <th class="ps-3">Fecha</th>
                            <th>Conductor</th>
                            <th>Referencia</th>
                            <th>Etapa</th>
                            <th>Estado</th>
                            <th>Comentarios</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="cargando">
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="spinner-border text-primary mb-2" role="status"></div>
                                    <p class="text-muted">Obteniendo datos del servidor...</p>
                                </td>
                            </tr>
                        </template>

                        <template x-if="!cargando && filteredCalls.length === 0">
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-info-circle fs-2"></i>
                                    <p class="mt-2">No se encontraron registros coincidentes.</p>
                                </td>
                            </tr>
                        </template>

                        <template x-for="call in filteredCalls" :key="call.created_at + call.telefono">
                            <tr :class="{'table-success-light': call.llamada_exitosa, 'table-danger-light': !call.llamada_exitosa}">
                                <td class="ps-3">
                                    <div class="clock-style" :class="darkMode ? 'text-light' : 'text-dark'" x-text="call.solo_hora ? call.solo_hora.substring(0,5) : ''"></div>
                                    <div class="small text-muted mt-1" x-text="call.solo_fecha"></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary" x-text="call.conductor"></div>
                                    <div class="small mb-1" :class="darkMode ? 'text-dark-custom' : 'text-muted'">
                                        <i class="bi bi-truck"></i> <span class="badge fs-6" :class="darkMode ? 'bg-dark border border-secondary' : 'bg-light text-dark border'" x-text="call.placa"></span>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="bi bi-phone"></i>
                                        <span x-text="cleanPhone(call.telefono)"></span>
                                        <a :href="'https://wa.me/' + call.telefono + '?text=' + encodeURIComponent('buen dia ' + call.conductor)" target="_blank" class="text-success ms-1" title="Enviar WhatsApp">
                                            <i class="bi bi-whatsapp"></i>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <a :href="'https://efletexia.com/newmonit/viaje/card/' + call.ref" target="_blank" class="btn btn-sm p-0 border-0" :class="darkMode ? 'text-info' : 'text-primary'" title="Ver Historial / Card">
                                            <i class="bi bi-clock-history fs-5"></i>
                                        </a>
                                        <span class="badge fs-6" :class="darkMode ? 'bg-dark border border-secondary' : 'bg-light text-dark border'" x-text="call.ref"></span>
                                        <span class="fw-bold" x-text="call.ruta_id"></span>
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.75rem;" x-text="call.titulo_viaje"></div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill px-3 py-2"
                                          :class="'bg-' + (call.etapa_color || 'secondary')"
                                          style="border: 1px solid rgba(0,0,0,0.1);"
                                          x-text="(call.etapa_emoji || '') + ' ' + call.etapa_nombre"></span>
                                </td>
                                <td>
                                    <template x-if="call.llamada_exitosa">
                                        <span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> EXITOSA</span>
                                    </template>
                                    <template x-if="!call.llamada_exitosa">
                                        <span class="text-danger fw-bold"><i class="bi bi-x-circle-fill"></i> FALLIDA</span>
                                    </template>
                                </td>
                                <td class="small">
                                    <div x-show="call.analisis_audio" class="mb-1">
                                        <span class="text-primary fw-bold"></span><i class="bi bi-person-circle text-info"></i> <span x-text="call.analisis_audio"></span>
                                    </div>
                                    <div x-show="call.ia_result_comments_text">
                                        <span class="fw-bold" :class="darkMode ? 'text-dark-custom' : 'text-muted'"><i class="bi bi-robot text-info"></i></span> <span x-text="call.ia_result_comments_text" class="text-muted"></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .table-striped > tbody > tr:nth-of-type(odd) > * {
        --bs-table-accent-bg: rgba(0, 0, 0, 0.02);
    }

    .table-success-light { background-color: rgba(25, 135, 84, 0.05) !important; }
    .table-danger-light { background-color: rgba(220, 53, 69, 0.05) !important; }

    /* Ajustes específicos para dark mode */
    [data-bs-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * {
        --bs-table-accent-bg: rgba(255, 255, 255, 0.03);
    }
    [data-bs-theme="dark"] .table-success-light { background-color: rgba(25, 135, 84, 0.15) !important; }
    [data-bs-theme="dark"] .table-danger-light { background-color: rgba(220, 53, 69, 0.15) !important; }
    [data-bs-theme="dark"] .card { background-color: #2b3035 !important; }
    [data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select { background-color: #1a1d20 !important; color: #dee2e6 !important; border-color: #495057 !important; }
    [data-bs-theme="dark"] .form-control::placeholder { color: #6c757d !important; }
</style>

<script>
    function callsApp() {
        return {
            calls: [],
            etapas: @json($tipos_llamada ?? []),
            total: 0,
            cargando: false,
            search: '',
            darkMode: localStorage.getItem('darkMode') === 'true',
            filtros: {
                startdate: new Date().toISOString().split('T')[0],
                enddate: '',
                etapa_id: '',
                origen: '',
                destino: '',
            },

            init() {
                // Si no hay preferencia guardada, verificar el esquema de color del sistema
                if (localStorage.getItem('darkMode') === null) {
                    this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }

                this.fetchCalls();
                // Auto actualización cada 30 segundos
                setInterval(() => {
                    this.fetchCalls(true);
                }, 30000);
            },

            toggleDarkMode() {
                this.darkMode = !this.darkMode;
                localStorage.setItem('darkMode', this.darkMode);
            },

            async fetchCalls(quiet = false) {
                if (!quiet) this.cargando = true;
                try {
                    const queryParams = new URLSearchParams();
                    if (this.filtros.startdate) queryParams.append('startdate', this.filtros.startdate);
                    if (this.filtros.enddate) queryParams.append('enddate', this.filtros.enddate);

                    const response = await fetch(`{{route('end_point.llamadas.api')}}?${queryParams.toString()}`);
                    if (!response.ok) throw new Error('Error al conectar con el API');

                    const data = await response.json();
                    this.calls = data.calls || [];
                    this.total = data.total || 0;
                } catch (error) {
                    console.error('Fetch error:', error);
                    alert('No se pudieron cargar los datos de las llamadas.');
                } finally {
                    this.cargando = false;
                }
            },

            resetFilters() {
                this.filtros.startdate = new Date().toISOString().split('T')[0];
                this.filtros.enddate = '';
                this.filtros.etapa_id = '';
                this.filtros.origen = '';
                this.filtros.destino = '';
                this.search = '';
                this.fetchCalls();
            },

            get filteredCalls() {
                let filtered = this.calls;

                // Filtro de búsqueda rápida
                if (this.search) {
                    const s = this.search.toLowerCase();
                    filtered = filtered.filter(c => {
                        const conductor = String(c.conductor || '').toLowerCase();
                        const placa = String(c.placa || '').toLowerCase();
                        const ref = String(c.ref || '').toLowerCase();
                        const etapa = String(c.etapa_nombre || '').toLowerCase();

                        return conductor.includes(s) ||
                               placa.includes(s) ||
                               ref.includes(s) ||
                               etapa.includes(s);
                    });
                }

                // Filtro de Etapa Logística (Frontend)
                if (this.filtros.etapa_id) {
                    filtered = filtered.filter(c => String(c.etapa_id) === String(this.filtros.etapa_id));
                }

                const ubigeos_lima = ['15','07'];

                const checkLima = (valor, ubigeo) => {
                    let v = String(valor || '').trim().toLowerCase();
                    if (v === 'lima' || v === 'callao') return true;

                    let u = String(ubigeo || '').trim();
                    if (u !== '') {
                        return ubigeos_lima.includes(u.substring(0, 2));
                    }
                    return false;
                };

                if (this.filtros.origen) {
                    if (this.filtros.origen === 'lima') {
                        filtered = filtered.filter(c => checkLima(c.origen, c.ubigeo_origen));
                    } else if (this.filtros.origen === 'provincia') {
                        filtered = filtered.filter(c => {
                            let isLima = checkLima(c.origen, c.ubigeo_origen);
                            if (isLima) return false;

                            // Si no es Lima, verificar que tenga algún dato de origen
                            let v = String(c.origen || '').trim();
                            let u = String(c.ubigeo_origen || '').trim();
                            return v !== '' || u !== '';
                        });
                    }
                }

                if (this.filtros.destino) {
                    if (this.filtros.destino === 'lima') {
                        filtered = filtered.filter(c => checkLima(c.destino, c.ubigeo_destino));
                    } else if (this.filtros.destino === 'provincia') {
                        filtered = filtered.filter(c => {
                            let isLima = checkLima(c.destino, c.ubigeo_destino);
                            if (isLima) return false;

                            let v = String(c.destino || '').trim();
                            let u = String(c.ubigeo_destino || '').trim();
                            return v !== '' || u !== '';
                        });
                    }
                }

                return filtered;
            },



            cleanPhone(phone) {
                if (!phone) return '';
                phone = String(phone);
                if (phone.startsWith('51')) {
                    return phone.substring(2);
                }
                return phone;
            }
        }
    }
</script>

</body>
</html>
