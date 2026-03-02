<div>
    @if($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <p class="modal-title">
                        <i class="bi bi-telephone"></i> {{ $telefono }} <br>
                        <i class="bi bi-person"></i> {{ $nombre }}</p>

                        <button type="button" class="btn-close" wire:click="cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <!-- CONTENEDOR CHAT!!!!!! -->
                        <div class="border border-secondary rounded p-3 bg-black bg-opacity-25 overflow-auto"
                            style="max-height: 350px;">

                            @foreach($mensajes as $item)

                                @if($item->tipo == 'USER')

                                    <!-- MENSAJE CONDUCTOR (IZQUIERDA) -->
                                    <div class="d-flex justify-content-start mb-3">
                                        <div class="bg-success text-white p-2 rounded"
                                            style="max-width: 75%;">
                                            {{ $item->mensaje }}
                                        </div>
                                    </div>

                                @else

                                    <!-- MENSAJE IA (DERECHA) -->
                                    <div class="d-flex justify-content-end mb-3">
                                        <div class="bg-primary text-white p-2 rounded"
                                            style="max-width: 75%;">
                                            {{ $item->mensaje }}
                                        </div>
                                    </div>

                                @endif

                            @endforeach

                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="cerrar">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>