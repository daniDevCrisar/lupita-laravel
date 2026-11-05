@extends('layouts.app')

@section('title', 'Verificar referencias')

@section('content')

    {{-- ===================== ENCABEZADO ===================== --}}
    <div class="row">
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1><i class="bi bi-check2-square"></i> Verificar lista de referencias</h1>
                <p class="text-white-50 mb-0">
                    Pegue una lista de refs (hasta {{ number_format($maxRefs) }}) y la pantalla devuelve
                    <strong class="text-danger">solo las refs que no existen</strong> y
                    <strong class="text-warning">las que existen sin fin de descargue</strong>;
                    de las cerradas se muestra únicamente el total. No escribe nada: solo lee.
                </p>
            </div>
            <a href="{{ route('lupita.referencias') }}" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left"></i> Consulta de referencias
            </a>
        </div>
    </div>

    {{-- ===================== FORMULARIO ===================== --}}
    <div class="row mt-3">
        <div class="col-12">
            <form method="POST" action="{{ route('lupita.referencias.verificar.cargar') }}">
                @csrf
                <fieldset class="border p-3 rounded">
                    <legend class="float-none w-auto px-2 fs-6">Lista de refs</legend>
                    <div class="row g-3">
                        <div class="col-12">
                            <textarea id="refs" name="refs" rows="9" class="form-control font-monospace"
                                      style="font-size: 12px;"
                                      placeholder="1154501&#10;1154503&#10;1154510">{{ old('refs', $meta['texto'] ?? '') }}</textarea>
                            <small class="text-white-50">
                                Separadas por coma, espacio, tabulador o una por línea. Las repetidas se
                                cuentan una vez y el tope es {{ number_format($maxRefs) }} refs.
                            </small>
                            @error('refs')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                            @if(session('error'))
                                <div class="alert alert-warning py-2 mt-2 mb-0">
                                    <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
                                </div>
                            @endif
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="archivo">O cargue un .txt / .csv</label>
                            <input type="file" id="archivo" accept=".txt,.csv" class="form-control">
                            <small class="text-white-50">
                                Se lee en el navegador y se pega en el cuadro de arriba; no se sube nada.
                            </small>
                        </div>
                        <div class="col-md-7 d-flex align-items-end">
                            <div class="form-check">
                                {{-- 0 oculto + 1 del checkbox: asi el POST siempre trae el valor --}}
                                <input type="hidden" name="primera_columna" value="0">
                                <input class="form-check-input" type="checkbox" value="1" id="primera_columna"
                                       name="primera_columna" {{ !empty($meta['primera_columna']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="primera_columna">
                                    <i class="bi bi-table"></i> Usar solo la primera columna de cada línea
                                    (al pegar desde Excel)
                                </label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Verificar
                            </button>
                            @if($datos)
                                <a href="{{ route('lupita.referencias.verificar', ['limpiar' => 1]) }}"
                                   class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Limpiar lista
                                </a>
                            @endif
                        </div>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>

    @if($datos)
        @php
            $kpis = $datos['kpis'];
            $noExisten = $datos['no_existen'];
            $sinFin = $datos['sin_fin'];
        @endphp

        {{-- ===================== LO QUE SE DESCARTO AL PEGAR ===================== --}}
        @if(!empty($meta['duplicadas']) || !empty($meta['ignorados_total']) || !empty($meta['truncadas']))
            <div class="alert alert-secondary py-2 mt-3">
                <div class="small text-light">
                    <i class="bi bi-info-circle"></i>
                    Al leer la lista:
                    @if(!empty($meta['duplicadas']))
                        <span class="badge bg-dark border border-secondary ms-1">{{ number_format($meta['duplicadas']) }} repetidas</span>
                    @endif
                    @if(!empty($meta['ignorados_total']))
                        <span class="badge bg-dark border border-secondary ms-1">{{ number_format($meta['ignorados_total']) }} ignoradas (no tienen forma de ref)</span>
                    @endif
                    @if(!empty($meta['truncadas']))
                        <span class="badge bg-dark border border-warning ms-1">{{ number_format($meta['truncadas']) }} sobrantes del tope</span>
                    @endif
                </div>
                @if(!empty($meta['ignorados']))
                    <details class="mt-1">
                        <summary class="small text-white-50" style="cursor:pointer;">
                            Ver los tokens ignorados (primeros {{ count($meta['ignorados']) }})
                        </summary>
                        <div class="small text-white-50 mt-1">{{ implode(' · ', $meta['ignorados']) }}</div>
                    </details>
                @endif
            </div>
        @endif

        {{-- ===================== TOTALES ===================== --}}
        <div class="row g-3 mt-1 mb-3">
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="text-uppercase small text-white-50">Refs verificadas</div>
                        <div class="fs-2 fw-bold text-light">{{ number_format($kpis->total) }}</div>
                        <small class="text-white-50">únicas de la lista</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="text-uppercase small text-white-50">No existen</div>
                        <div class="fs-2 fw-bold text-danger">{{ number_format($kpis->no_existen) }}</div>
                        <small class="text-white-50">no están en referencias</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="text-uppercase small text-white-50">Sin fin de descargue</div>
                        <div class="fs-2 fw-bold text-warning">{{ number_format($kpis->sin_fin) }}</div>
                        <small class="text-white-50">ciclo abierto</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="text-uppercase small text-white-50">Cerradas</div>
                        <div class="fs-2 fw-bold text-success">{{ number_format($kpis->cerradas) }}</div>
                        <small class="text-white-50">con fin de descargue</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== COPIAR / EXPORTAR ===================== --}}
        <div class="row g-2 mb-3">
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-danger" data-copiar="no_existen">
                    <i class="bi bi-clipboard"></i> Copiar no existen ({{ number_format(count($noExisten)) }})
                </button>
                <button type="button" class="btn btn-sm btn-outline-warning" data-copiar="sin_fin">
                    <i class="bi bi-clipboard"></i> Copiar sin fin de descargue ({{ number_format(count($sinFin)) }})
                </button>
                <button type="button" class="btn btn-sm btn-outline-light" data-copiar="fallas">
                    <i class="bi bi-clipboard"></i> Copiar todas las fallas ({{ number_format(count($noExisten) + count($sinFin)) }})
                </button>
                <a href="{{ route('lupita.referencias.verificar.exportar') }}" class="btn btn-sm btn-success">
                    <i class="bi bi-file-earmark-excel"></i> Descargar Excel (fallas)
                </a>
            </div>
        </div>

        {{-- ===================== LISTAS DE REFS ===================== --}}
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="text-danger">
                            <i class="bi bi-x-circle"></i> No existen
                            <span class="badge bg-dark border border-danger text-danger">{{ number_format(count($noExisten)) }}</span>
                        </span>
                        @if(!empty($noExisten))
                            <button type="button" class="btn btn-sm btn-outline-danger" data-copiar="no_existen">
                                <i class="bi bi-clipboard"></i> Copiar
                            </button>
                        @endif
                    </div>
                    <div class="card-body" style="max-height: 620px; overflow-y: auto;">
                        @if(empty($noExisten))
                            <p class="text-white-50 mb-0">
                                <i class="bi bi-check2-circle text-success"></i>
                                Todas las refs de la lista existen en referencias.
                            </p>
                        @else
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($noExisten as $ref)
                                    <span class="badge bg-dark border border-danger text-danger font-monospace">{{ $ref }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="text-warning">
                            <i class="bi bi-hourglass-split"></i> Sin fin de descargue
                            <span class="badge bg-dark border border-warning text-warning">{{ number_format(count($sinFin)) }}</span>
                        </span>
                        @if(!empty($sinFin))
                            <button type="button" class="btn btn-sm btn-outline-warning" data-copiar="sin_fin">
                                <i class="bi bi-clipboard"></i> Copiar
                            </button>
                        @endif
                    </div>
                    <div class="card-body" style="max-height: 620px; overflow-y: auto;">
                        @if(empty($sinFin))
                            <p class="text-white-50 mb-0">
                                <i class="bi bi-check2-circle text-success"></i>
                                Ninguna ref de las que existen tiene el ciclo abierto.
                            </p>
                        @else
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($sinFin as $ref)
                                    <span class="badge bg-dark border border-warning text-warning font-monospace">{{ $ref }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('scripts')
    @php
        // @json solo acepta una expresion simple: las listas se arman antes
        $paraCopiar = [
            'no_existen' => $datos['no_existen'] ?? [],
            'sin_fin'    => $datos['sin_fin'] ?? [],
            'fallas'     => array_merge($datos['no_existen'] ?? [], $datos['sin_fin'] ?? []),
        ];
    @endphp
    <script>
        (function () {
            // ---- leer un .txt / .csv en el navegador y dejarlo en el textarea ----
            const archivo = document.getElementById('archivo');
            const area = document.getElementById('refs');
            if (archivo && area) {
                archivo.addEventListener('change', function () {
                    const file = archivo.files && archivo.files[0];
                    if (!file) return;

                    const lector = new FileReader();
                    lector.onload = function () {
                        area.value = String(lector.result || '');
                        area.focus();
                    };
                    lector.readAsText(file, 'utf-8');
                });
            }

            // ---- copiar listas de refs (una por linea) ----
            const listas = @json($paraCopiar);

            const avisar = (btn) => {
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                setTimeout(() => { btn.innerHTML = original; }, 1500);
            };

            // respaldo para navegadores sin clipboard API (http sin TLS)
            const copiarClasico = (texto, btn) => {
                const ta = document.createElement('textarea');
                ta.value = texto;
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); avisar(btn); } catch (e) {}
                document.body.removeChild(ta);
            };

            document.querySelectorAll('[data-copiar]').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const refs = listas[btn.dataset.copiar] || [];
                    if (!refs.length) return;

                    const texto = refs.join('\n');
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(texto).then(() => avisar(btn)).catch(() => copiarClasico(texto, btn));
                    } else {
                        copiarClasico(texto, btn);
                    }
                });
            });
        })();
    </script>
@endsection
