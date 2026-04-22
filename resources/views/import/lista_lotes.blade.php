@extends('layouts.app')

@section('title', 'Lista de Lotes Importados')

@section('content')

    <div class="container py-5">

        <!-- Título -->
        <div class="text-center mb-5">
            <h1 style="color: #e0e0e0;">
                <i class="bi bi-trophy-fill me-2" style="color: #FFD700;"></i>
                Llamada Relevante
                <i class="bi bi-trophy-fill ms-2" style="color: #FFD700;"></i>
            </h1>
            <p style="color: #888;">Escala de clasificación · 3 niveles</p>
        </div>

        <div class="row g-4 justify-content-center">

            <!-- ========================================== -->
            <!-- NIVEL 1 - BRONCE -->
            <!-- ========================================== -->
            <div class="col-md-4">
                <div class="card h-100 border-0" style="background: #1a1a1a; border-radius: 16px; border-top: 4px solid #CD7F32;">
                    <div class="card-body text-center p-4">
                        <!-- Medalla -->
                        <div class="mb-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: linear-gradient(135deg, #CD7F32, #8B5A2B); box-shadow: 0 0 20px rgba(205, 127, 50, 0.5);">
                                <i class="bi bi-trophy-fill fs-1" style="color: #FFE4C4;"></i>
                            </div>
                        </div>

                        <!-- Título -->
                        <h3 style="color: #CD7F32; font-weight: bold; letter-spacing: 1px;">BRONCE III</h3>
                        <p style="color: #aaa; font-size: 14px; margin-top: -5px;">Rango Inicial</p>

                        <!-- Línea decorativa -->
                        <div style="width: 50px; height: 2px; background: #CD7F32; margin: 15px auto;"></div>

                        <!-- Nombre -->
                        <h4 style="color: #fff; font-size: 1.3rem;">Llamada Informativa</h4>

                        <!-- Puntaje -->
                        <div class="mt-3">
                            <span style="background: #CD7F32; color: #1a1a1a; font-weight: bold; padding: 6px 20px; border-radius: 30px; font-size: 1.2rem;">
                                +15 pts
                            </span>
                        </div>

                        <!-- Descripción -->
                        <p class="mt-3 small" style="color: #777;">
                            <i class="bi bi-chat-dots-fill me-1"></i>
                            Información básica confirmada
                        </p>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- NIVEL 2 - PLATA -->
            <!-- ========================================== -->
            <div class="col-md-4">
                <div class="card h-100 border-0" style="background: #1a1a1a; border-radius: 16px; border-top: 4px solid #C0C0C0;">
                    <div class="card-body text-center p-4">
                        <!-- Medalla -->
                        <div class="mb-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: linear-gradient(135deg, #C0C0C0, #808080); box-shadow: 0 0 20px rgba(192, 192, 192, 0.5);">
                                <i class="bi bi-trophy-fill fs-1" style="color: #F0F0F0;"></i>
                            </div>
                        </div>

                        <!-- Título -->
                        <h3 style="color: #C0C0C0; font-weight: bold; letter-spacing: 1px;">PLATA II</h3>
                        <p style="color: #aaa; font-size: 14px; margin-top: -5px;">Rango Intermedio</p>

                        <!-- Línea decorativa -->
                        <div style="width: 50px; height: 2px; background: #C0C0C0; margin: 15px auto;"></div>

                        <!-- Nombre -->
                        <h4 style="color: #fff; font-size: 1.3rem;">Llamada Valiosa</h4>

                        <!-- Puntaje -->
                        <div class="mt-3">
                            <span style="background: #C0C0C0; color: #1a1a1a; font-weight: bold; padding: 6px 20px; border-radius: 30px; font-size: 1.2rem;">
                                +30 pts
                            </span>
                        </div>

                        <!-- Descripción -->
                        <p class="mt-3 small" style="color: #777;">
                            <i class="bi bi-chat-dots-fill me-1"></i>
                            Información detallada confirmada
                        </p>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- NIVEL 3 - ORO -->
            <!-- ========================================== -->
            <div class="col-md-3">
                <div class="card  border-0" style="background: #1a1a1a; border-radius: 16px; border-top: 4px solid #FFD700;">
                    <div class="card-body text-center p-4">
                        <!-- Medalla -->
                        <div class="mb-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: linear-gradient(135deg, #FFD700, #DAA520); box-shadow: 0 0 25px rgba(255, 215, 0, 0.6);">
                                <i class="bi bi-chat-left-text-fill fs-1" style="color: #FFF8DC;"></i>
                            </div>
                        </div>

                        <!-- Título -->
                        <h3 style="color: #FFD700; font-weight: bold; letter-spacing: 1px;">Da motivos</h3>
                        <p style="color: #aaa; font-size: 14px; margin-top: -5px;">Rango Élite</p>
                    </div>
                </div>
            </div>

        </div>


        <div class="row g-2 justify-content-center">

            <!-- BRONCE -->
            <div class="col-auto">
                <div class="card border-0" style="background: #1a1a1a; border-radius: 12px; border-top: 3px solid #CD7F32; width: 160px;">
                    <div class="card-body text-center p-2">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-1" style="width: 45px; height: 45px; background: linear-gradient(135deg, #CD7F32, #8B5A2B);">
                            <i class="bi bi-chat-text-fill fs-5" style="color: #FFF8DC;"></i>
                        </div>
                        <h6 style="color: #CD7F32; font-weight: bold; font-size: 0.75rem; margin: 0;">Bronce</h6>
                        <p style="color: #aaa; font-size: 0.6rem; margin: 0;">+15 pts</p>
                    </div>
                </div>
            </div>

            <!-- PLATA -->
            <div class="col-auto">
                <div class="card border-0" style="background: #1a1a1a; border-radius: 12px; border-top: 3px solid #C0C0C0; width: 160px;">
                    <div class="card-body text-center p-2">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-1" style="width: 45px; height: 45px; background: linear-gradient(135deg, #C0C0C0, #808080);">
                            <i class="bi bi-chat-dots-fill fs-5" style="color: #1a1a1a;"></i>
                        </div>
                        <h6 style="color: #C0C0C0; font-weight: bold; font-size: 0.75rem; margin: 0;">Plata</h6>
                        <p style="color: #aaa; font-size: 0.6rem; margin: 0;">+30 pts</p>
                    </div>
                </div>
            </div>

            <!-- ORO -->
            <div class="col-auto">
                <div class="card border-0" style="background: #1a1a1a; border-radius: 12px; border-top: 3px solid #FFD700; width: 160px;">
                    <div class="card-body text-center p-2">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-1" style="width: 45px; height: 45px; background: linear-gradient(135deg, #FFD700, #DAA520); box-shadow: 0 0 10px rgba(255, 215, 0, 0.5);">
                            <i class="bi bi-chat-quote-fill fs-5" style="color: #1a1a1a;"></i>
                        </div>
                        <h6 style="color: #FFD700; font-weight: bold; font-size: 0.75rem; margin: 0;">Oro</h6>
                        <p style="color: #aaa; font-size: 0.6rem; margin: 0;">+50 pts</p>
                    </div>
                </div>
            </div>

        </div>



        <!-- ========================================== -->
        <!-- SECCIÓN: llamada_interesante -->
        <!-- ========================================== -->
        <div class="text-center mb-3">
            <small style="color: #666;">llamada_interesante</small>
        </div>
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #CD7F32, #8B5A2B);">
                    <i class="bi bi-star-fill text-white fs-6"></i>
                </div>
                <div style="color: #CD7F32; font-size: 0.6rem;">+15</div>
            </div>
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #C0C0C0, #808080);">
                    <i class="bi bi-star-fill text-dark fs-6"></i>
                </div>
                <div style="color: #C0C0C0; font-size: 0.6rem;">+30</div>
            </div>
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #FFD700, #DAA520);">
                    <i class="bi bi-star-fill text-dark fs-6"></i>
                </div>
                <div style="color: #FFD700; font-size: 0.6rem;">+50</div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECCIÓN: buzon_de_voz -->
        <!-- ========================================== -->
        <div class="text-center mb-3">
            <small style="color: #666;">buzon_de_voz</small>
        </div>
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #CD7F32, #8B5A2B);">
                    <i class="bi bi-voicemail text-white fs-6"></i>
                </div>
                <div style="color: #CD7F32; font-size: 0.6rem;">-5</div>
            </div>
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #C0C0C0, #808080);">
                    <i class="bi bi-voicemail text-dark fs-6"></i>
                </div>
                <div style="color: #C0C0C0; font-size: 0.6rem;">-10</div>
            </div>
            <div class="text-center">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 35px; height: 35px; background: linear-gradient(135deg, #FFD700, #DAA520);">
                    <i class="bi bi-voicemail text-dark fs-6"></i>
                </div>
                <div style="color: #FFD700; font-size: 0.6rem;">-20</div>
            </div>
        </div>



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
