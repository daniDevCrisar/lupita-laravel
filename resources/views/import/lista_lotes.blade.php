@extends('layouts.app')

@section('title', 'Lista de Lotes Importados')

@section('content')





<div class="row">
    <div class="col-12">
        <h1>Lista de Lotes importados</h1>
    </div>
</div>

<div class="row">

    <div class="col-12">{{ $lotes->links() }}</div>

    <div class="col-12">
        <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
            <table class="table table-bordered table-hover table-sm table-dark">
                <thead class="table-primary" style="position: sticky;top: 0;z-index: 2;">
                <tr>
                    <th>#</th>
                    <th>Id</th>
                    <th>Fecha</th>
                    <th>Archivo</th>
                    <th>Comentario</th>
                    <th>Usuario</th>
                </tr>
                </thead>
                <tbody>

                @foreach($lotes as $row)
                    <tr class="{{ $loop->odd ? 'table-secondary' : '' }}">
                        <td class="@if($row->procesado) table-success @endif">{{$loop->index+1}} </td>
                        <td>
                            <a href="{{ route('importar.excel.lote',[$row->lote_id]) }}" target="_blank">
                                {{$row->lote_id}} </a></td>
                        <td>{{$llamadas::format_fecha($row->created_at)}}</td>
                        <td>{{$row->nombre}}</td>
                        <td>{{$row->comentario}}</td>
                        <td>{{$row->user_nombres}}</td>
                    </tr>
                @endforeach


                </tbody>
            </table>

        </div>
    </div>
    <div class="col-12">{{ $lotes->links() }}</div>

</div>

@endsection

@section('scripts')
    <script>

    </script>
@endsection
