@php
    // Aviso compartido: locales cuya palabra clave tiene una variante vacia ("|" de mas)
    // y por lo tanto coinciden con CUALQUIER titulo. Se usa en las pantallas donde se
    // edita el catalogo y donde se ve la ruta que quedaria.
    $peligrosas = $peligrosas ?? [];
@endphp

@if(count($peligrosas) > 0)
    <div class="alert alert-danger mt-3">
        <h6 class="alert-heading mb-1">
            <i class="bi bi-exclamation-octagon"></i>
            Palabra clave peligrosa: {{ count($peligrosas) }} local(es) coinciden con cualquier título
        </h6>
        <p class="small mb-2">
            La palabra clave tiene un <code>|</code> de más, así que el patrón del procedimiento queda
            <code>(A|B|)</code>: la última alternativa es <b>vacía</b> y matchea cualquier texto. Ese local
            se lleva todas las refs que no resolvieron antes, y como gana el primero del alfabeto, ensucia
            las rutas en silencio.
        </p>
        <ul class="small mb-2">
            @foreach($peligrosas as $p)
                <li>
                    <code class="text-warning">{{ $p['id'] }}</code>:
                    <code>{{ $p['palabra_clave'] }}</code>
                    <a href="{{ route('lupita.locales.editar', $p['id']) }}" class="text-info">
                        corregir <i class="bi bi-pencil"></i>
                    </a>
                </li>
            @endforeach
        </ul>
        <p class="small mb-0">
            Sacá el <code>|</code> final (o los que sobren) y guardá. Los locales que ya tienen ruta
            asignada por este motivo hay que reprocesarlos después.
        </p>
    </div>
@endif
