<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LlamadasController;
Route::get('/', function () {
    return view('welcome');
});




Route::get('/', [ImportController::class, 'index']);
Route::post('/procesar_excel', [ImportController::class, 'procesar_excel']);

Route::get('/procesar_excel/import/paso-1/{lote_id}',
    [ImportController::class, 'paso_1']
)->name('import.paso1');




Route::prefix('importar')->group(function () {

    Route::get('/json', [ImportController::class, 'procesar_json']);
    //Route::post('/json/procesar', [ImportController::class, 'jsonProcesar']);

    Route::get('/excel', [ImportController::class, 'cargar_excel']);
    Route::post('/excel/procesar', [ImportController::class, 'procesar_excel_llamadas']);

    Route::get('/excel/{lote_id}', [ImportController::class, 'mostrar_lote_importado'])->name('importar.excel.lote');
    Route::get('/excel/{lote_id}/procesar', [ImportController::class, 'procesar_importacion_de_lote'])->name('importar.excel.procesar');

});

Route::prefix('lupita')->group(function () {
    Route::get('/llamadas', [LlamadasController::class, 'listar_llamadas'])->name('lupita.llamadas');
    Route::get('/conductores', [LlamadasController::class, 'listar_conductores'])->name('lupita.conductores');
    Route::get('/transportistas', [LlamadasController::class, 'listar_trts'])->name('lupita.transportistas');
    Route::get('/reporte', [LlamadasController::class, 'reporte_todo']);
});


