<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Database\DBLlamadas;

class LlamadasController extends Controller
{
    public static function listar_llamadas(Request $request){
        $llamadas= new DBLlamadas();
        $llamadas::listar_principal(30);

        return view('lupita.lista_llamadas', [
            'llamadas' => $llamadas
        ]);
    }
}
