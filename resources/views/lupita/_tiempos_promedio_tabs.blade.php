{{-- Pestañas del módulo de promedios: rutas (recorrido + espera por ruta) y plantas (espera por planta) --}}
@php($pestana = $tab ?? 'rutas')
<ul class="nav nav-tabs mt-3">
    <li class="nav-item">
        <a class="nav-link {{ $pestana === 'rutas' ? 'active' : '' }}" href="{{ route('lupita.tiempos.promedio') }}">
            <i class="bi bi-signpost-split"></i> Rutas
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $pestana === 'plantas' ? 'active' : '' }}" href="{{ route('lupita.tiempos.promedio.plantas') }}">
            <i class="bi bi-building"></i> Plantas
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('lupita.tiempos.promedio.horario') }}">
            <i class="bi bi-clock-history"></i> Día y hora
        </a>
    </li>
    <li class="nav-item ms-auto">
        <a class="nav-link {{ $pestana === 'ver' ? 'active' : '' }}" href="{{ route('lupita.tiempos.promedio.ver') }}">
            Ver compilados <i class="bi bi-arrow-right"></i>
        </a>
    </li>
</ul>
