@extends('layouts.app')

@section('title', 'Lote Importado LOG')

@section('content')

    <div class='col-12 py-3'>
        <div class="card bg-dark text-light border-0 p-3">
            <div class="d-flex align-items-center justify-content-between">

                <div class="d-flex align-items-center">
                    <div>
                        <h5 class="mb-1">Log</h5>
                        <small class="d-block p-2 text-white">
                            {!! $log !!}
                        </small>
                    </div>
                </div>

            </div>
        </div>
    </div>



@endsection

@section('scripts')
@endsection
