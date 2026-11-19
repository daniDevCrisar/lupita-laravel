{{--
    Diff de una importación de configuración (antes / después).

    Lo usan las dos mitades de la tarjeta "Exportar / importar": la vista previa
    (`accion=ver`, no guarda nada) y el resultado después de aplicar. Es la única
    forma de ver qué se pisó: la base no guarda historial de la configuración.

    Recibe:
      $cambios  array<clave, array{antes: mixed, despues: mixed}>
--}}
@php
    $cambios = $cambios ?? [];
@endphp

@if($cambios === [])
    <div class="small text-muted">
        Ninguna clave cambia: el archivo dice lo mismo que está en uso.
    </div>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.8rem">
            <thead class="table-light">
            <tr>
                <th>clave</th>
                <th class="text-end">en uso</th>
                <th class="text-end">en el archivo</th>
            </tr>
            </thead>
            <tbody>
            @foreach($cambios as $clave => $cambio)
                <tr>
                    <td><code>{{ $clave }}</code></td>
                    <td class="text-end text-muted">
                        {{ $cambio['antes'] === null ? '—' : $cambio['antes'] }}
                    </td>
                    <td class="text-end fw-bold">{{ $cambio['despues'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
