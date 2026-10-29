@extends('layouts.app')

@section('title', 'Importar desde Vapi')

@section('content')
    <div class="container-fluid">
        <header class="text-center mb-5 mt-3">
            <h1 class="display-5 text-info mb-2">
                <i class="bi bi-cloud-download"></i> Importar desde Vapi
            </h1>
            <p class="lead text-muted">Obtén los archivos directamente de la nube y envíalos a procesar</p>
        </header>

        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">

                <!-- Mensajes de Alerta -->
                <div class="alert alert-success text-white d-none" id='alerta_exito'>
                    <i class="bi bi-check-circle"></i> ¡Excel generado y enviado a Laravel correctamente! Redirigiendo...
                </div>
                <div class="alert alert-danger text-white d-none" id='alerta_error'>
                    <i class="bi bi-x-circle"></i> <span id="texto_error"></span>
                </div>

                <!-- Tarjeta Principal -->
                <div class="card shadow mb-4 bg-dark border-secondary">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">
                            <i class="bi bi-link-45deg"></i> Enlaces recientes de Vapi
                        </h2>
                        <span class="badge bg-light text-dark">Últimos 5</span>
                    </div>

                    <div class="card-body text-light">
                        @if(empty($correos))
                            <div class="text-center p-5 text-muted">
                                <i class="bi bi-inbox display-1"></i>
                                <p class="mt-3">No se pudieron cargar los correos desde Google API.</p>
                            </div>
                        @else
                            @foreach($correos as $index => $correo)
                                @php $n = $index + 1; @endphp
                                <div class="card bg-dark border-secondary mb-3">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="card-title text-info mb-0">
                                                <i class="bi bi-file-earmark-zip"></i> vapi_{{ $n }}
                                            </h6>
                                            <small class="text-muted">{{ str_replace('T', ' ', $correo['fecha']) }}</small>
                                        </div>

                                        <button class="btn btn-success w-100 btn-procesar" data-url="{{ $correo['enlace'] }}">
                                            <i class="bi bi-magic"></i> Procesar y Enviar a Laravel
                                        </button>

                                        <div class="mt-2 text-center text-warning small d-none status-text">
                                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                            Procesando...
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <!-- SheetJS -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>

    <!-- Tus scripts originales -->
    <script src="{{ asset('js/import/json/app.js') }}"></script>
    <script src="{{ asset('js/import/json/procesar_transcripcion.js') }}"></script>
    <script src="{{ asset('js/import/json/procesar_json.js') }}"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.btn-procesar').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const url = this.getAttribute('data-url');
                    const statusText = this.nextElementSibling;
                    const alertaExito = document.getElementById('alerta_exito');
                    const alertaError = document.getElementById('alerta_error');
                    const textoError = document.getElementById('texto_error');

                    alertaExito.classList.add('d-none');
                    alertaError.classList.add('d-none');
                    this.disabled = true;
                    this.innerHTML = '<i class="bi bi-hourglass-split"></i> Procesando...';
                    statusText.classList.remove('d-none');
                    statusText.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Descargando JSON...';

                    try {
                        // 1. Pedir el JSON al nuevo controlador
                        const response = await fetch('{{ route('vapi.procesar') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ url: url })
                        });

                        const data = await response.json();
                        if (data.error) throw new Error(data.error);

                        statusText.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generando Excel...';

                        // 2. Interceptar SheetJS
                        const originalWriteFile = XLSX.writeFile;

                        // Creamos la promesa que capturará el archivo
                        const excelPromise = new Promise((resolve) => {
                            XLSX.writeFile = function(wb, filename) {
                                // Convertimos a binario y resolvemos la promesa
                                const excelData = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
                                resolve(excelData);
                            };
                        });

                        // Ejecutamos tu función original. En lugar de descargar, se disparará la promesa.
                        if (typeof generar_excel_llamadas === 'function') {
                            generar_excel_llamadas(data.data);
                        } else {
                            throw new Error('La función generar_excel_llamadas no está definida.');
                        }

                        // Esperamos a que se capture el archivo
                        const excelBinario = await excelPromise;

                        // Restauramos SheetJS
                        XLSX.writeFile = originalWriteFile;

                        statusText.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando a Laravel...';

                        // 3. Enviar a tu controlador original
                        const blob = new Blob([excelBinario], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                        const formData = new FormData();
                        formData.append('excel', blob, 'vapi_export.xlsx');
                        formData.append('txt_llamadas', 'VAPI');
                        formData.append('txt_ref', '');
                        formData.append('txt_ref_1', '');

                        const responseExcel = await fetch('{{ url('/importar/excel/procesar') }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: formData
                        });

                        // 4. Redirigir al lote
                        if (responseExcel.ok || responseExcel.redirected) {
                            alertaExito.classList.remove('d-none');
                            setTimeout(() => {
                                window.location.href = responseExcel.url;
                            }, 1500);
                        } else {
                            throw new Error("Error al procesar el Excel en el servidor.");
                        }

                    } catch (error) {
                        textoError.textContent = error.message;
                        alertaError.classList.remove('d-none');
                        window.scrollTo(0, 0);
                        if (typeof originalWriteFile !== 'undefined') XLSX.writeFile = originalWriteFile;
                    } finally {
                        this.disabled = false;
                        this.innerHTML = '<i class="bi bi-magic"></i> Procesar y Enviar a Laravel';
                        statusText.classList.add('d-none');
                    }
                });
            });
        });
    </script>
@endsection
