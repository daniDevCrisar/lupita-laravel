{{-- Veredicto del modelo para la llamada seleccionada (componente autónomo,
     se carga solo al escuchar el evento abrirMensajeTextbox de la vista). --}}
<div class="">
    <span class="fw-bold text-white-50 small"><i class="bi bi-robot"></i> Modelo:</span>

    @if(!$this->vapi_id)
        <span class="badge bg-secondary">selecciona una llamada</span>
    @elseif(!$this->puntuada)
        <span class="badge bg-secondary">sin puntuar</span>
        <a href="{{ route('lupita.scoring') }}" target="_blank" rel="noopener" class="small text-info">
            recalcula el rango <i class="bi bi-box-arrow-up-right"></i>
        </a>
    @else
        <span class="badge bg-{{ $this->color() }} fs-6">
            {{ number_format($this->puntaje, 0, ',', '.') }}%
        </span>
        <span class="text-{{ $this->color() }} fw-semibold small">
            veredicto: {{ $this->exitosa() ? 'ÉXITO' : 'FALLO' }}
        </span>
    @endif
</div>
