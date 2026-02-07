<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;

Route::get('/', function () {
    return view('welcome');
});




Route::get('/', [ImportController::class, 'index']);
Route::post('/procesar_excel', [ImportController::class, 'procesar_excel']);

Route::get('/procesar_excel/import/paso-1/{lote_id}', 
    [ImportController::class, 'paso_1']
)->name('import.paso1');

