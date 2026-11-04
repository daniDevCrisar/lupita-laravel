@extends('layouts.app')

@section('title', 'Actualizar Referencias por Lote')

@section('content')

    @php
        // link de tceflexia: la pagina recibe los lote_id separados por coma
        $link_base = 'https://tceflexia.com/generalMilestonesRoute?api=lupita_lotes&lotes=';
    @endphp

    <div class="row mt-3">
        <div class="col-12">
            <h1 class="text-light">Actualizar Referencias <small class="text-secondary fs-6">puente con tceflexia</small></h1>
            <p class="text-light">
                Selecciona los lotes del rango y ábrelos en tceflexia: la página pedirá
                <span class="badge bg-info text-dark">GET /api/refs?lotes=...</span>
                las refs únicas pendientes (sin fin_descargue) y devolverá la data fresca con
                <span class="badge bg-info text-dark">POST /api/refs/actualizar</span>
                que se escribe directo en <code class="text-warning">referencias</code>.
                <a href="{{ route('lupita.refs.docs') }}" class="text-info">Ver documentación del API <i class="bi bi-journal-code"></i></a>
            </p>
        </div>
    </div>

    {{-- ================= FILTROS POR RANGO ================= --}}
    <div class="row">
        <div class="col-12">
            <form method="GET" action="{{ route('lupita.refs.seleccion') }}" class="d-flex align-items-end gap-2 mb-3">
                <div>
                    <label class="form-label text-light mb-0 small">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ $fecha_inicio }}">
                </div>
                <div>
                    <label class="form-label text-light mb-0 small">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $fecha_fin }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Buscar lotes</button>
                <span class="text-light small ms-2">Por fecha de importación del lote · máx. 300 lotes</span>
            </form>
        </div>
    </div>

    {{-- ================= LISTA DE LOTES ================= --}}
    <div class="row">
        <div class="col-12">
            @if(count($lotes) === 0)
                <div class="alert alert-warning">No hay lotes procesados en ese rango de fechas.</div>
            @else
                {{-- ========== SELECCION RAPIDA ========== --}}
                <div class="d-flex align-items-center gap-2 mb-2">
                    <button type="button" id="btn_sel_todo" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-check2-all"></i> Seleccionar todo
                    </button>
                    <button type="button" id="btn_sel_nada" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x2"></i> Ninguno
                    </button>
                    <span class="text-secondary small ms-1">Solo lotes procesados dentro del rango (máx. 300)</span>
                </div>
                <form id="form_lotes">
                    <div class="table-responsive" style="max-height: 640px; overflow-y: auto;">
                        <table class="table table-bordered table-hover table-sm table-dark">
                            <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                            <tr>
                                <th style="width: 36px;"><input type="checkbox" id="chk_todos" class="form-check-input"></th>
                                <th>#</th>
                                <th>Lote</th>
                                <th>Fecha</th>
                                <th>Archivo</th>
                                <th>Usuario</th>
                                <th class="text-end">Refs</th>
                                <th class="text-end">Pendientes</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($lotes as $row)
                                <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                                    <td><input type="checkbox" name="lote_sel" value="{{ $row->lote_id }}" class="form-check-input chk_lote"></td>
                                    <td>{{ $loop->index + 1 }}</td>
                                    <td>
                                        @can('ver lote')
                                        <a href="{{ route('importar.excel.lote', [$row->lote_id]) }}" target="_blank" class="text-info">{{ $row->lote_id }}</a>
                                        @else
                                        {{ $row->lote_id }}
                                        @endcan
                                    </td>
                                    <td>{{ $row->created_at }}</td>
                                    <td>{{ $row->nombre }}</td>
                                    <td>{{ $row->user_nombres }}</td>
                                    <td class="text-end">{{ $row->refs }}</td>
                                    <td class="text-end">
                                        @if($row->pendientes > 0)
                                            <span class="badge bg-warning text-dark">{{ $row->pendientes }}</span>
                                        @else
                                            <span class="badge bg-success">0</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>

                {{-- ========== GENERADOR DEL LINK ========== --}}
                <div class="card bg-dark text-light border-secondary mt-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="text-light">Lotes seleccionados: <b id="contador_sel" class="text-warning">0</b></span>
                            <button type="button" id="btn_abrir" class="btn btn-success btn-sm ms-auto">
                                <i class="bi bi-box-arrow-up-right"></i> Abrir en tceflexia
                            </button>
                            <button type="button" id="btn_copiar" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-clipboard"></i> Copiar link
                            </button>
                        </div>
                        <input type="text" id="link_tceflexia" class="form-control mt-2 bg-black text-light small" readonly
                               value="{{ $link_base }}">
                        <div class="form-text text-secondary mt-1">
                            tceflexia consultará las refs pendientes de esos lotes y enviará la actualización
                            directa a <code>referencias</code> con referencias_de_lotes = true.
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        const LINK_BASE = '{{ $link_base }}';

        function refs_seleccionados() {
            return [...document.querySelectorAll('.chk_lote:checked')].map(c => c.value);
        }

        function refrescar_link() {
            const sel = refs_seleccionados();
            document.getElementById('contador_sel').textContent = sel.length;
            document.getElementById('link_tceflexia').value = LINK_BASE + sel.join(',');
        }

        document.getElementById('chk_todos')?.addEventListener('change', function () {
            document.querySelectorAll('.chk_lote').forEach(c => c.checked = this.checked);
            refrescar_link();
        });
        document.querySelectorAll('.chk_lote').forEach(c => c.addEventListener('change', refrescar_link));

        // botones de seleccion rapida: marcar/desmarcar todos y sincronizar la cabecera
        function marcar_todos(val) {
            document.querySelectorAll('.chk_lote').forEach(c => c.checked = val);
            const cabecera = document.getElementById('chk_todos');
            if (cabecera) cabecera.checked = val;
            refrescar_link();
        }
        document.getElementById('btn_sel_todo').addEventListener('click', () => marcar_todos(true));
        document.getElementById('btn_sel_nada').addEventListener('click', () => marcar_todos(false));

        document.getElementById('btn_abrir').addEventListener('click', () => {
            const sel = refs_seleccionados();
            if (!sel.length) { alert('Selecciona al menos un lote'); return; }
            window.open(LINK_BASE + sel.join(','), '_blank');
        });

        document.getElementById('btn_copiar').addEventListener('click', () => {
            const input = document.getElementById('link_tceflexia');
            if (!refs_seleccionados().length) { alert('Selecciona al menos un lote'); return; }
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                document.getElementById('btn_copiar').innerHTML = '<i class="bi bi-check2"></i> Copiado';
                setTimeout(() => {
                    document.getElementById('btn_copiar').innerHTML = '<i class="bi bi-clipboard"></i> Copiar link';
                }, 1500);
            });
        });
    </script>
@endsection
