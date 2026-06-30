
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-left: 5px solid #dc3545;">
            <div class="d-flex align-items-center">
                <div class="me-3" style="font-size: 2rem;">
                    ⚠️
                </div>
                <div>
                    <h5 class="alert-heading mb-1">Error de Validación</h5>
                    <p class="mb-0">{{ $message ?? 'Los parámetros de búsqueda no son válidos.' }}</p>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
