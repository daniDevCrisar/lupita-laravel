@extends('layouts.app')

@section('title', 'Fusionar locales')

@section('content')

    <div class="row mt-3">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="text-light mb-0">Fusionar locales</h1>
                <span class="text-white-50 small">dos locales que son el mismo lugar</span>
                <a href="{{ route('lupita.locales') }}" class="btn btn-outline-light btn-sm ms-auto">
                    <i class="bi bi-shop"></i> Locales
                </a>
            </div>
            <p class="text-white-50 small mt-2 mb-0">
                Los pares de abajo salen de <b>compartir una palabra clave</b>: son los que hacen ambiguo el
                match del título (gana el primero del alfabeto y el otro queda sin uso). Fusionar
                <b>no borra</b> el local que se va: lo deja inactivo y le pasa todo lo suyo al que sobrevive.
            </p>
        </div>
    </div>

    @if(session('exito'))
        <div class="alert alert-success mt-3 mb-0">{{ session('exito') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mt-3 mb-0">{{ session('error') }}</div>
    @endif

    {{-- ================= DETECTADOS ================= --}}
    <div class="row mt-4">
        <div class="col-12">
            <h5 class="text-light">
                Detectados por palabra clave compartida
                <span class="badge bg-secondary">{{ count($pares) }}</span>
            </h5>

            @if(count($pares) === 0)
                <p class="text-white-50 small">No hay locales que compartan palabra clave.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="thead-lupita">
                        <tr>
                            <th style="width: 170px;">Palabra clave</th>
                            <th>Local</th>
                            <th style="width: 80px;">Refs</th>
                            <th style="width: 80px;">Rutas</th>
                            <th style="width: 110px;">Estado</th>
                            <th style="width: 200px;">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($pares as $i => $par)
                            @foreach(['a', 'b'] as $lado)
                                @php($l = $par[$lado])
                                <tr>
                                    @if($lado === 'a')
                                        <td rowspan="2"><code class="text-warning">{{ $par['variante'] }}</code></td>
                                    @endif
                                    <td class="small">
                                        <span class="text-light">{{ $l['nombre'] }}</span>
                                        <span class="d-block text-white-50">
                                            <code>{{ $l['id'] }}</code> · {{ $l['cliente_id'] }}
                                            @if($l['lat'] !== null)
                                                · <i class="bi bi-geo-alt"></i> con coordenadas
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-light small">{{ $l['refs'] }}</td>
                                    <td class="text-light small">{{ $l['rutas'] }}</td>
                                    <td class="small">
                                        @if($l['activo'] === 1)
                                            <span class="badge bg-success">activo</span>
                                        @else
                                            <span class="badge bg-secondary">inactivo</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if($lado === 'a')
                                            <a class="btn btn-outline-warning btn-sm"
                                               href="{{ route('lupita.ubicaciones.fusion', [
                                                   'sobrevive' => $par['b']['id'],
                                                   'se_va' => $par['a']['id'],
                                               ]) }}">
                                                Simular: gana {{ $par['b']['id'] }}
                                            </a>
                                        @else
                                            <a class="btn btn-outline-warning btn-sm"
                                               href="{{ route('lupita.ubicaciones.fusion', [
                                                   'sobrevive' => $par['a']['id'],
                                                   'se_va' => $par['b']['id'],
                                               ]) }}">
                                                Simular: gana {{ $par['a']['id'] }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-white-50 small mb-0">
                    Por defecto se propone que sobreviva el que más referencias tiene: mirá la columna
                    <b>Refs</b> antes de elegir.
                </p>
            @endif
        </div>
    </div>

    {{-- ================= A MANO ================= --}}
    <div class="row mt-4">
        <div class="col-12">
            <h5 class="text-light">O elegí dos locales a mano</h5>
            <form method="GET" action="{{ route('lupita.ubicaciones.fusion') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label text-light small mb-1" for="sobrevive">Sobrevive</label>
                    <select class="form-select form-select-sm" id="sobrevive" name="sobrevive" required>
                        <option value="">— Elegí —</option>
                        @foreach($locales as $local)
                            <option value="{{ $local->id }}" @selected($sobrevive === $local->id)>
                                {{ $local->nombre }} ({{ $local->id }}){{ (int) $local->activo === 0 ? ' — inactivo' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label text-light small mb-1" for="se_va">Se fusiona (queda inactivo)</label>
                    <select class="form-select form-select-sm" id="se_va" name="se_va" required>
                        <option value="">— Elegí —</option>
                        @foreach($locales as $local)
                            <option value="{{ $local->id }}" @selected($se_va === $local->id)>
                                {{ $local->nombre }} ({{ $local->id }}){{ (int) $local->activo === 0 ? ' — inactivo' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button class="btn btn-warning btn-sm w-100" type="submit">
                        <i class="bi bi-search"></i> Simular
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ================= PLAN ================= --}}
    @if($plan !== null)
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-{{ $plan['ok'] ? 'warning' : 'danger' }} bg-dark">
                    <div class="card-header text-light">
                        <i class="bi bi-clipboard-check"></i> Simulación
                        @if($plan['ok'])
                            <span class="badge bg-warning text-dark">no se escribió nada todavía</span>
                        @endif
                    </div>
                    <div class="card-body">

                        @if(!$plan['ok'])
                            <div class="alert alert-danger mb-0">
                                <b>No se puede fusionar:</b> {{ $plan['error'] }}
                            </div>
                        @else
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="border border-success rounded p-2">
                                        <span class="text-success small d-block">Sobrevive</span>
                                        <span class="text-light">{{ $plan['sobrevive']['nombre'] }}</span>
                                        <span class="d-block text-white-50 small">
                                            <code>{{ $plan['sobrevive']['id'] }}</code> ·
                                            {{ $plan['sobrevive']['refs'] }} refs ·
                                            {{ $plan['sobrevive']['rutas'] }} rutas
                                        </span>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="border border-secondary rounded p-2">
                                        <span class="text-white-50 small d-block">Se fusiona y queda inactivo</span>
                                        <span class="text-light">{{ $plan['se_va']['nombre'] }}</span>
                                        <span class="d-block text-white-50 small">
                                            <code>{{ $plan['se_va']['id'] }}</code> ·
                                            {{ $plan['se_va']['refs'] }} refs ·
                                            {{ $plan['se_va']['rutas'] }} rutas
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <table class="table table-sm table-bordered mt-3 mb-0">
                                <tbody>
                                <tr>
                                    <th style="width: 260px;">Palabra clave resultante</th>
                                    <td>
                                        <code class="text-warning">{{ $plan['palabra_clave_resultante'] }}</code>
                                        @if($plan['palabra_clave_recortada'])
                                            <span class="badge bg-danger ms-2">recortada a 100</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Coordenadas</th>
                                    <td class="small">
                                        @switch($plan['coordenadas'])
                                            @case('conserva') se conservan las del que sobrevive @break
                                            @case('hereda') se heredan las del que se va @break
                                            @default ninguno de los dos tiene @break
                                        @endswitch
                                    </td>
                                </tr>
                                <tr>
                                    <th>Rutas a repuntar</th>
                                    <td class="small">
                                        {{ $plan['rutas_repuntadas'] }}
                                        @if(count($plan['rutas_colapsadas']) > 0)
                                            <span class="text-warning">
                                                ({{ count($plan['rutas_colapsadas']) }} colapsan en una ruta que ya existe: se suman las veces usadas)
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Referencias que se mueven</th>
                                    <td class="small">{{ $plan['referencias'] }}</td>
                                </tr>
                                <tr>
                                    <th>Promedios compilados</th>
                                    <td class="small">
                                        {{ $plan['tiempos_ruta_promedio'] }} de ruta ·
                                        {{ $plan['tiempos_ruta_horario'] }} de horario
                                    </td>
                                </tr>
                                @if($plan['rutas_self'] !== [])
                                    <tr>
                                        <th class="text-warning">Rutas origen = destino</th>
                                        <td class="small text-warning">
                                            {{ implode(', ', $plan['rutas_self']) }}
                                            <span class="d-block text-white-50">se dejan como están</span>
                                        </td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>

                            @foreach($plan['avisos'] as $aviso)
                                <div class="alert alert-warning mt-3 mb-0 py-2 small">{{ $aviso }}</div>
                            @endforeach

                            <form method="POST" action="{{ route('lupita.ubicaciones.fusion.aplicar') }}" class="mt-3"
                                  onsubmit="this.querySelectorAll('button').forEach(function (b) { b.disabled = true; });">
                                @csrf
                                <input type="hidden" name="sobrevive" value="{{ $plan['sobrevive']['id'] }}">
                                <input type="hidden" name="se_va" value="{{ $plan['se_va']['id'] }}">
                                <button class="btn btn-danger" type="submit">
                                    <i class="bi bi-arrow-left-right"></i>
                                    Fusionar {{ $plan['se_va']['id'] }} en {{ $plan['sobrevive']['id'] }}
                                </button>
                                <a href="{{ route('lupita.ubicaciones.fusion') }}" class="btn btn-outline-light">
                                    Cancelar
                                </a>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

@endsection
