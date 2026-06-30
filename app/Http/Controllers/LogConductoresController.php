<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LogConductoresController extends Controller
{
    public function nuevo(){
        return view('lupita.log_conductor_nuevo');
    }
}
