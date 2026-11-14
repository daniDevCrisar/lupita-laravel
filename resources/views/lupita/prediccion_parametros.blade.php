@extends('layouts.app')

@section('title', 'Parámetros del motor de predicción')

@section('content')
    @php
        $hoy = fn ($valor) => number_format((int) $valor);
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-sliders"></i> Parámetros del motor de predicción</h1>
            <p class="text-muted small mb-1">
                Todo lo que cambia <strong>cómo predice</strong> el motor de la hora de presentación para carga. Lo que
                se toca acá vale para <strong>todos</strong> (calculadora, API y tabla de aciertos) y se guarda en la
                tabla <code>prediccion_config</code>; si la tabla no existe, la pantalla igual funciona con los valores
                de <code>config/prediccion.php</code>.
            </p>
            <p class="text-muted small mb-0">
                <a href="{{ route('lupita.prediccion.presenta') }}">&larr; Calculadora</a> ·
                <a href="{{ route('lupita.prediccion.aciertos') }}">Tabla de aciertos</a> ·
                <a href="{{ route('lupita.prediccion.geoapify') }}">Uso de Geoapify</a>
            </p>
        </div>
    </div>

    {{-- ============ CÓMO SE USA ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>Cómo se usa</strong></div>
        <div class="card-body">
            <ol class="mb-2">
                <li class="mb-1">
                    <strong>Mirá los valores en uso</strong> (abajo, en "Parámetros que se editan a mano" y en la
                    tarjeta de cada calibración). Los ocho de la primera fila se cambian escribiendo el número y
                    apretando <em>Guardar parámetros</em>.
                </li>
                <li class="mb-1">
                    <strong>Calibrá lo que no se pone a mano.</strong> Hay dos cosas que no se eligen a ojo porque
                    dependen de tus datos:
                    <ul class="mb-1 mt-1">
                        <li>
                            <code>cruce_pares_k</code>: cuántos viajes propios necesita una placa para pesar más que
                            el promedio de la flota. El botón <strong>Calibrar</strong> prueba
                            {{ implode(' · ', $rejilla_cruce) }} sobre la tabla de aciertos (entrena con los últimos 30
                            días y valida con los últimos 7) y recomienda el que mejor mide en la validación.
                        </li>
                        <li>
                            <strong>Ventana rápido/tardado</strong>: cuánto abrir antes y después de la hora
                            publicada para que la hora real caiga adentro. El botón <strong>Medir</strong> la calcula
                            con el error real del motor (el p25–p75 del hueco cubre sólo ~35 %).
                        </li>
                    </ul>
                </li>
                <li class="mb-1">
                    <strong>Aplicá.</strong> La recomendación <em>no</em> cambia nada hasta que apretás
                    <em>Aplicar</em> al lado. Recién ahí se guarda en <code>prediccion_config</code> y el motor la usa
                    en la próxima predicción.
                </li>
                <li class="mb-0">
                    <strong>Comprobá.</strong> Volvé a la <a href="{{ route('lupita.prediccion.aciertos') }}">tabla de
                    aciertos</a> con el mismo rango y compará MAE, ±3 h y % de la ventana antes y después. Si empeora,
                    volvé al valor anterior (no hay historial: anotá el número que estaba).
                </li>
            </ol>
            <div class="alert alert-warning py-2 mb-0 small">
                <strong>Ojo:</strong> calibrar con pocas filas es ruido. La tabla de aciertos evalúa las más recientes:
                subí el límite a <strong>300-1000</strong> antes de creerle a un botón. Con 100 filas la validación
                queda con 10-20 y cualquier valor "gana" por suerte.
            </div>
        </div>
    </div>

    {{-- ============ EDITABLES + CALIBRACIÓN (partial compartido) ============ --}}
    @include('lupita.partials.prediccion_parametros')

    {{-- ============ QUÉ HACE CADA UNO ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-info text-dark"><strong>Qué mueve cada parámetro</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light">
                        <tr>
                            <th>Parámetro</th>
                            <th>Qué mueve</th>
                            <th class="text-end">En uso</th>
                            <th>Cómo se cambia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $detalle = [
                                'pares_recientes_max' => ['Pares recientes por placa', 'con cuántos huecos recientes se calibra la vuelta; con 0 usa toda la historia'],
                                'min_pares_ruta' => ['Mínimo por par de rutas', 'pares que hacen falta para llamar "medido" al hueco'],
                                'min_pares_entidad' => ['Mínimo por placa', 'pares que hacen falta para "parcial" y para calibrar por chofer (C3)'],
                                'min_hitos_bin' => ['Hitos por franja', 'marcas mínimas para que una franja de 30 min no sea descanso'],
                                'max_desplazamiento_min' => ['Desplazamiento máximo', 'minutos que puede moverse una predicción para salir de una franja muerta'],
                                'traslado_defecto_min' => ['Traslado por defecto', 'minutos de regreso cuando no hay ruta medida'],
                                'muerto_defecto_min' => ['Tiempo muerto por defecto', 'minutos de espera cuando no hay huecos medidos'],
                                'espera_carga_defecto_min' => ['Espera en planta por defecto', 'minutos entre presenta e inicio de carga sin compilado'],
                            ];
                        @endphp
                        @foreach($parametros_editables as $clave)
                            <tr>
                                <td><code>{{ $clave }}</code><div class="text-muted" style="font-size:.75rem">{{ $detalle[$clave][0] ?? '' }}</div></td>
                                <td class="small text-muted">{{ $detalle[$clave][1] ?? '' }}</td>
                                <td class="text-end fw-bold">{{ $hoy($en_uso->{$clave} ?? 0) }}</td>
                                <td class="small">A mano</td>
                            </tr>
                        @endforeach

                        @foreach($calibrables as $clave)
                            <tr class="table-info">
                                <td><code>{{ $clave }}</code></td>
                                <td class="small text-muted">
                                    @switch($clave)
                                        @case('cruce_pares_k') Peso de la placa contra el compilado en el VIAJE (w = n/(n+k)) @break
                                        @case('ventana_residuos') Usar la ventana medida con los residuos (1) o el p25–p75 del hueco (0) @break
                                        @case('ventana_adelanto_min') Minutos que se le restan a la hora publicada para el "rápido" @break
                                        @case('ventana_atraso_min') Minutos que se le suman para el "tardado" @break
                                        @case('ventana_adelanto_cadena') Lo mismo, cuando no hay pares medidos (evidencia cadena) @break
                                        @case('ventana_atraso_cadena') Idem atraso, sin pares medidos @break
                                        @default —
                                    @endswitch
                                </td>
                                <td class="text-end fw-bold">{{ $hoy($en_uso->{$clave} ?? 0) }}</td>
                                <td class="small">Con los botones de arriba</td>
                            </tr>
                        @endforeach

                        @foreach($fijos as $fila)
                            <tr>
                                <td><code>{{ $fila['clave'] }}</code></td>
                                <td class="small text-muted">{{ $fila['texto'] }}</td>
                                <td class="text-end fw-bold">{{ $fila['valor'] ? 'sí' : 'no' }}</td>
                                <td class="small text-muted">Sólo por consola / <code>prediccion_config</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============ AVISOS ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white"><strong>Antes de apretar Guardar o Aplicar</strong></div>
        <div class="card-body">
            <ul class="mb-0 small">
                <li>Los valores valen para <strong>todos</strong>: cambian la calculadora, el API y la tabla de aciertos al mismo tiempo.</li>
                <li>Toman efecto en la <strong>próxima</strong> predicción (no hay que reiniciar nada).</li>
                <li>No hay historial de cambios ni botón de deshacer: <strong>anotá el valor anterior</strong> antes de aplicar.</li>
                <li>Cambiar parámetros <strong>no</strong> cambia los datos: si el MAE es malo por hitos incompletos, refs sin ruta o huecos de varios días, ningún parámetro lo arregla. Para eso está
                    <code>php artisan prediccion:diagnostico</code>.</li>
                <li>La calibración y la medición de la ventana usan la tabla de aciertos con Geoapify apagado: no gastan API.</li>
            </ul>
        </div>
    </div>
@endsection
