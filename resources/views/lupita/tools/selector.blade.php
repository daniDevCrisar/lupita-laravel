{{--
    Selector con búsqueda, reutilizable en cualquier pantalla.

    Por qué existe: en la app había tres formas distintas de elegir una ruta, un
    departamento o un conductor (`buscadorDeSelect` en tiempos/horario, los
    `data-combo` de tiempos/promedio y <select> pelados en las pantallas /ver y en
    referencias). Este partial es el único lugar donde se arma el marcado, y el
    comportamiento vive en `public/js/lupita-selectores.js`.

    Uso (simple, un valor):
        @include('lupita.tools.selector', [
            'nombre'  => 'ruta_id',
            'etiqueta'=> 'Ruta',
            'opciones'=> [['valor' => 'RUT_1', 'texto' => 'CARAL - CHICLAYO', 'extra' => 'RUT_1'], ...],
            'todas'   => 'Todas las rutas',
            'valor'   => request('ruta_id'),
            'ayuda'   => 'Filtra la vista',
        ])

    Uso (múltiple, varios valores como name[]):
        @include('lupita.tools.selector', [
            'nombre'  => 'ruta_id[]',
            'multiple'=> true,
            'opciones'=> ...,
            'valores' => $rutas_sel,
            'todas'   => 'Todas (' . count($opciones) . ')',
        ])

    Variables:
        nombre        name del input hidden (o del name[] en el múltiple)
        opciones      array de ['valor', 'texto', 'extra'?] — extra es la segunda línea
        valor         valor elegido (simple)
        valores       valores elegidos (múltiple)
        todas         etiqueta de la opción "todas / ninguna" (null = sin opción)
        etiqueta      texto del label (null = sin label)
        ayuda         texto chico debajo
        tamano        'sm' para form-control-sm / botón chico
        enviar_al_elegir  true = envía el formulario al elegir (como el conductor)
        obligatorio   marca el campo como requerido (sólo informativo en el label)
        id            id del input hidden (para JS que lo busque)
--}}
@php
    $multiple = (bool) ($multiple ?? false);
    $opciones = $opciones ?? [];
    $valor = (string) ($valor ?? request($nombre ?? ''));
    $valores = array_map('strval', $valores ?? []);
    $todas = $todas ?? null;
    $ayuda = $ayuda ?? null;
    $tamano = ($tamano ?? null) === 'sm';
    $enviar = (bool) ($enviar_al_elegir ?? false);
    $id = $id ?? ('sel_' . preg_replace('/[^a-z0-9]+/i', '_', (string) ($nombre ?? 'campo')));
    $enviarId = $tocadas ?? null;

    // texto que se muestra en el botón: la etiqueta de la opción elegida
    $elegida = null;
    foreach ($opciones as $opcion) {
        if ((string) ($opcion['valor'] ?? '') === $valor && $valor !== '') {
            $elegida = (string) ($opcion['texto'] ?? '');
            break;
        }
    }
    $textoBoton = $multiple
        ? ($valores === [] ? ($todas ?? 'Todas (' . count($opciones) . ')') : count($valores) . ' seleccionadas')
        : (($valor !== '' && $elegida !== null) ? $elegida : ($todas ?? 'Todos'));
@endphp

@if($etiqueta ?? null)
    <label class="form-label {{ $tamano ? 'small' : '' }} mb-0" for="{{ $id }}_buscar">
        {{ $etiqueta }}
        @if(!empty($obligatorio))<span class="text-danger">*</span>@endif
    </label>
@endif

<div class="dropdown"
     data-selector="{{ $multiple ? 'multiple' : 'simple' }}"
     @if($multiple) data-nombre="{{ $nombre }}" @endif
     @if($enviar) data-enviar="1" @endif
     @if($enviarId) data-tocadas="{{ $enviarId }}" @endif
     @if(!empty($abrir)) data-abrir="1" @endif>

    <button type="button"
            class="btn {{ $tamano ? 'btn-sm' : '' }} btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <span class="text-truncate" data-texto>{{ $textoBoton }}</span>
        <i class="bi bi-chevron-down ms-2 flex-shrink-0"></i>
    </button>

    <div class="dropdown-menu w-100 p-2">
        <input type="search" id="{{ $id }}_buscar" class="form-control form-control-sm mb-1"
               data-buscar placeholder="{{ $placeholder ?? 'Buscar...' }}" autocomplete="off">
        <div class="d-flex justify-content-between align-items-center mb-1 px-1">
            <span class="text-muted" style="font-size:.7rem" data-contador></span>
            @if($multiple)
                <span class="d-flex gap-1">
                    <button type="button" class="btn btn-link btn-sm p-0" style="font-size:.7rem"
                            data-accion="todas">Todas</button>
                    <span class="text-muted">·</span>
                    <button type="button" class="btn btn-link btn-sm p-0" style="font-size:.7rem"
                            data-accion="ninguna">Ninguna</button>
                </span>
            @endif
        </div>

        <div data-lista style="max-height: 260px; overflow-y: auto">
            @if($todas !== null && !$multiple)
                <button type="button" class="dropdown-item rounded text-wrap"
                        data-valor="" data-etiqueta="{{ $todas }}"
                        data-buscar="{{ mb_strtolower($todas) }}">
                    {{ $todas }}
                </button>
            @endif

            @foreach($opciones as $opcion)
                @php
                    $opcionValor = (string) ($opcion['valor'] ?? '');
                    $opcionTexto = (string) ($opcion['texto'] ?? $opcionValor);
                    $opcionExtra = (string) ($opcion['extra'] ?? '');
                    $buscable = mb_strtolower($opcionTexto . ' ' . $opcionExtra . ' ' . $opcionValor);
                @endphp

                @if($multiple)
                    <label class="dropdown-item rounded d-flex align-items-start gap-2 text-wrap" data-opcion>
                        <input type="checkbox" class="form-check-input mt-1" value="{{ $opcionValor }}"
                               data-buscar="{{ $buscable }}"
                               @checked(in_array($opcionValor, $valores, true))>
                        <span>
                            {{ $opcionTexto }}
                            @if($opcionExtra !== '')
                                <span class="d-block text-muted" style="font-size:.72rem">{{ $opcionExtra }}</span>
                            @endif
                        </span>
                    </label>
                @else
                    <button type="button" class="dropdown-item rounded text-wrap"
                            data-valor="{{ $opcionValor }}" data-etiqueta="{{ $opcionTexto }}"
                            data-buscar="{{ $buscable }}">
                        {{ $opcionTexto }}
                        @if($opcionExtra !== '')
                            <span class="d-block text-muted" style="font-size:.72rem">{{ $opcionExtra }}</span>
                        @endif
                    </button>
                @endif
            @endforeach
        </div>

        <div class="text-muted small text-center py-2" data-vacio hidden>Sin coincidencias</div>
    </div>

    @if($multiple)
        <div data-ocultos>
            @foreach($valores as $elegido)
                <input type="hidden" name="{{ $nombre }}" value="{{ $elegido }}">
            @endforeach
        </div>
    @else
        <input type="hidden" name="{{ $nombre }}" id="{{ $id }}" value="{{ $valor }}">
    @endif
</div>

@if($ayuda)
    <div class="form-text">{{ $ayuda }}</div>
@endif
