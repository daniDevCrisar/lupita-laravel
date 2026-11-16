@extends('layouts.app')

@section('title', 'Tools · Selector con búsqueda')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h1><i class="bi bi-tools"></i> Tools · Selector con búsqueda</h1>
            <p class="text-muted small mb-1">
                Una sola pieza para elegir una ruta, un departamento, un conductor o cualquier cosa con nombre, en
                <strong>todas</strong> las pantallas. Antes había tres implementaciones distintas y por eso cada
                pantalla se comportaba diferente.
            </p>
            <p class="text-muted small mb-0">
                Marcado: <code>resources/views/lupita/tools/selector.blade.php</code> ·
                Comportamiento: <code>public/js/lupita-selectores.js</code>
            </p>
        </div>
    </div>

    <div class="alert alert-info py-2 small">
        <strong>Qué resuelve:</strong> buscar escribiendo (sin tildes y en cualquier parte del texto, no sólo al
        principio), mostrar el código abajo del nombre, contar cuántas opciones hay, y dejar el valor en un
        <code>input hidden</code> con el mismo <code>name</code> que usa el formulario. Los formularios que ya existen
        no cambian: sólo cambia cómo se elige.
    </div>

    {{-- ============ DEMO: uno simple ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <strong>1. Un solo valor</strong>
            <span class="small">— <code>name="ruta_id"</code>, como en tiempos/promedio y las pantallas /ver</span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.tools.selectores') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    @include('lupita.tools.selector', [
                        'nombre' => 'ruta_id',
                        'id' => 'demo_ruta',
                        'etiqueta' => 'Ruta',
                        'opciones' => $rutas,
                        'todas' => 'Todas las rutas',
                        'valor' => $ejemplo_valor,
                        'ayuda' => 'Escribí para filtrar; la opción elegida queda en el campo.',
                    ])
                </div>
                <div class="col-md-4">
                    @include('lupita.tools.selector', [
                        'nombre' => 'dep_origen',
                        'id' => 'demo_dep',
                        'etiqueta' => 'Departamento de origen',
                        'opciones' => $departamentos,
                        'todas' => 'Todos los orígenes',
                        'valor' => (string) request('dep_origen', ''),
                        'ayuda' => 'Acá el nombre va solo, sin el código.',
                    ])
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-search"></i> Probar</button>
                    <a href="{{ route('lupita.tools.selectores') }}" class="btn btn-secondary">Limpiar</a>
                </div>
            </form>

            @if(request('ruta_id') || request('dep_origen'))
                <div class="alert alert-success py-2 mt-3 mb-0 small">
                    Llegó al servidor:
                    <code>ruta_id={{ request('ruta_id') }}</code>
                    <code>dep_origen={{ request('dep_origen') }}</code>
                </div>
            @endif

            <pre class="bg-dark text-light p-2 mt-3 mb-0 small rounded">@verbatim@include('lupita.tools.selector', [
    'nombre'   => 'ruta_id',
    'etiqueta' => 'Ruta',
    'opciones' => $rutas,      // [['valor' => 'RUT_1', 'texto' => 'CARAL - CHICLAYO', 'extra' => 'RUT_1']]
    'todas'    => 'Todas las rutas',
    'valor'    => request('ruta_id'),
    'ayuda'    => 'Escribí para filtrar',
])@endverbatim</pre>
        </div>
    </div>

    {{-- ============ DEMO: varios valores ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <strong>2. Varios valores</strong>
            <span class="small">— <code>name="ruta_ids[]"</code>, como el filtro de rutas de tiempos/horario</span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.tools.selectores') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    @include('lupita.tools.selector', [
                        'nombre' => 'ruta_ids[]',
                        'id' => 'demo_rutas',
                        'multiple' => true,
                        'etiqueta' => 'Rutas',
                        'opciones' => $rutas,
                        'valores' => $ejemplo_valores,
                        'todas' => 'Todas (' . count($rutas) . ')',
                        'ayuda' => '"Todas" y "Ninguna" respetan lo que esté filtrado.',
                    ])
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">
                        Ninguna marcada = no se manda el campo (el servidor lo interpreta como "todas").
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-search"></i> Probar</button>
                    <a href="{{ route('lupita.tools.selectores') }}" class="btn btn-secondary">Limpiar</a>
                </div>
            </form>

            @if($ejemplo_valores !== [])
                <div class="alert alert-success py-2 mt-3 mb-0 small">
                    Llegaron {{ count($ejemplo_valores) }} rutas: <code>{{ implode(', ', $ejemplo_valores) }}</code>
                </div>
            @endif

            <pre class="bg-dark text-light p-2 mt-3 mb-0 small rounded">@verbatim@include('lupita.tools.selector', [
    'nombre'   => 'ruta_id[]',
    'multiple' => true,
    'etiqueta' => 'Rutas',
    'opciones' => $rutas,
    'valores'  => $rutas_sel,
    'todas'    => 'Todas (' . count($rutas) . ')',
])@endverbatim</pre>
        </div>
    </div>

    {{-- ============ DEMO: envía solo ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <strong>3. Envía el formulario al elegir</strong>
            <span class="small">— para filtros que recargan solos, como el conductor de tiempos/horario</span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('lupita.tools.selectores') }}" class="row g-2 align-items-end">
                <input type="hidden" name="ruta_id" value="{{ $ejemplo_valor }}">
                <div class="col-md-4">
                    @include('lupita.tools.selector', [
                        'nombre' => 'dep_origen',
                        'id' => 'demo_envia',
                        'etiqueta' => 'Departamento (envía al elegir)',
                        'opciones' => $departamentos,
                        'todas' => 'Todos',
                        'valor' => (string) request('dep_origen', ''),
                        'enviar_al_elegir' => true,
                        'ayuda' => 'Elegí una opción y el formulario se envía solo.',
                    ])
                </div>
                <div class="col-md-8">
                    <div class="text-muted small">
                        Se activa con <code>'enviar_al_elegir' =&gt; true</code>. El selector dispara un
                        <code>change</code> sobre el campo antes de enviar, así que cualquier JS que escuche ese
                        evento sigue funcionando.
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ============ Dónde se usa ============ --}}
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>Dónde está aplicado</strong></div>
        <div class="card-body">
            <ul class="mb-2 small">
                <li><strong>tiempos/promedio</strong> — ruta + departamento de origen + departamento de destino (acá
                    se unificó el combo propio que tenía con <code>data-combo</code>).</li>
                <li><strong>tiempos/promedio/ver</strong> y <strong>tiempos/promedio/horario/ver</strong> — la ruta,
                    que antes era un <code>&lt;select&gt;</code> sin búsqueda.</li>
                <li><strong>referencias</strong> — la ruta, que antes era un texto libre.</li>
                <li><strong>tiempos/horario</strong> — conductor, origen, destino y el selector múltiple de rutas.</li>
            </ul>
            <div class="alert alert-warning py-2 small mb-0">
                <strong>Para agregarlo en otra pantalla:</strong> cargá el JS una vez en la vista
                (<code>&lt;script src="{{ asset('js/lupita-selectores.js') }}"&gt;&lt;/script&gt;</code> dentro de
                <code>&#64;section('scripts')</code>) y sumá el <code>&#64;include</code>. Si la pantalla pinta HTML
                nuevo con JS, después llamá <code>LupitaSelectores.init()</code>.
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/lupita-selectores.js') }}"></script>
@endsection
