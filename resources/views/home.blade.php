@extends('layouts.app')

@section('content')
<div class="col-12 ">
    <div class="col-12 text-center">
        @if (session('status'))
            <div class="alert alert-success" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <h1>Iniciaste Sesion</h1>


            <a href="{{ route('logout') }}"
               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                <h3>Cerrar sesión</h3>
            </a>

            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                @csrf
            </form>
    </div>

</div>
@endsection
