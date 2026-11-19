{{--
    Valores EN USO de una calibración, en una lista compacta.

    Cada calibración de la pantalla de parámetros escribe claves en
    `prediccion_config` sin pasar por un formulario: esto es lo único que muestra
    qué está gobernando hoy (y si se apartó del valor de fábrica).

    Recibe:
      $filas  salida de App\Prediccion\Parametros::en_uso()
      $titulo texto del encabezado (por defecto "En uso ahora")
--}}
@php
    $filas = $filas ?? [];
    $titulo = $titulo ?? 'En uso ahora';
@endphp

<div class="alert alert-secondary py-2 px-2 mb-0">
    <div class="small fw-semibold mb-1">{{ $titulo }}</div>

    @if($filas === [])
        <div class="small text-muted">sin valores que mostrar</div>
    @else
        <ul class="list-unstyled mb-0" style="font-size:.78rem">
            @foreach($filas as $fila)
                <li class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                    <code>{{ $fila['clave'] }}</code>
                    <span>
                        <strong>{{ $fila['texto'] }}</strong>
                        @if($fila['cambiado'])
                            <span class="text-muted">· fábrica {{ $fila['fabrica_texto'] }}</span>
                            <span class="badge bg-warning text-dark">cambiado</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
