<div>
    @if($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Mensaje de {{ $nombre }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p><i class="bi bi-telephone"></i> {{ $telefono }}</p>
                        <p>{{ $mensaje }}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="cerrar">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>