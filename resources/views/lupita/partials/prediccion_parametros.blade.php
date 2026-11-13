@php
    /** @var \App\Prediccion\Parametros $p */
    $p = $catalogos->parametros;
    $etiquetas = [
        'pares_recientes_max' => ['Pares recientes por placa', 'con cuántos huecos recientes se calibra la vuelta'],
        'min_pares_ruta' => ['Mínimo por par de rutas', 'para llamarlo "medido"'],
        'min_pares_entidad' => ['Mínimo por placa', 'para llamarlo "parcial"'],
        'min_hitos_bin' => ['Hitos por franja', 'marcas mínimas para que una franja de 30 min no sea descanso'],
        'max_desplazamiento_min' => ['Desplazamiento máximo', 'minutos que puede moverse una predicción para salir de una franja muerta'],
        'traslado_defecto_min' => ['Traslado por defecto', 'minutos de regreso cuando no hay ruta medida'],
        'muerto_defecto_min' => ['Tiempo muerto por defecto', 'minutos de espera cuando no hay huecos medidos'],
        'espera_carga_defecto_min' => ['Espera en planta por defecto', 'minutos entre presenta e inicio de carga'],
    ];
@endphp

<div class="card mb-3">
    <div class="card-header bg-secondary text-white">
        <strong>Parámetros del motor</strong>
        <span class="small">— se guardan en <code>prediccion_config</code> y valen para todos</span>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('lupita.prediccion.parametros') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="ref" value="{{ $ref ?? '' }}">
            <input type="hidden" name="placa" value="{{ $placa ?? '' }}">
            <input type="hidden" name="ahora" value="{{ $ahora ?? '' }}">

            @foreach($parametros_editables as $clave)
                @php $info = $etiquetas[$clave] ?? [$clave, '']; @endphp
                <div class="col-md-3">
                    <label class="form-label small mb-0">{{ $info[0] }}</label>
                    <input type="number" min="0" class="form-control form-control-sm"
                           name="{{ $clave }}" value="{{ $p->{$clave} }}">
                    <div class="text-muted" style="font-size:.7rem">{{ $info[1] }}</div>
                </div>
            @endforeach

            <div class="col-md-3">
                <button class="btn btn-sm btn-outline-secondary w-100">
                    <i class="bi bi-save"></i> Guardar parámetros
                </button>
            </div>
        </form>

        <div class="small text-muted mt-2">
            Sin tabla <code>prediccion_config</code> (o sin permisos) la pantalla igual funciona con los valores de
            <code>config/prediccion.php</code>.
        </div>
    </div>
</div>
