
<div class="border border-secondary rounded p-3 bg-black bg-opacity-25 overflow-auto small"
    style="max-height: 380px; height: 380px">

    @forelse($mensajes??[] as $item)

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
    @empty
    @endforelse

</div>
