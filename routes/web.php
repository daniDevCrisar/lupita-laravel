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




Route::prefix('importar')->group(function () {

    Route::get('/json', [ImportController::class, 'jsonView']);
    Route::post('/json/procesar', [ImportController::class, 'jsonProcesar']);

    Route::get('/excel', [ImportController::class, 'excelView']);
    Route::post('/excel/subir', [ImportController::class, 'procesar_excel']);

    Route::get('/excel/ia-prompt/{lote}', [ImportController::class, 'iaPrompt']);

    Route::get('/excel/paso-1/{lote}', [ImportController::class, 'paso1']);
    Route::get('/excel/paso-2/{lote}', [ImportController::class, 'paso2']);
    Route::get('/excel/paso-3/{lote}', [ImportController::class, 'paso3']);

    Route::post('/excel/finalizar/{lote}', [ImportController::class, 'finalizar']);
});
