<?php

use App\Http\Controllers\Api\LlamadasApiController;
use App\Http\Controllers\API\LlamadasJsonApiController;
use App\Http\Controllers\LogConductoresController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LlamadasController;
use App\Livewire\LogConductores\Padre as LogConductoresPadre;



Route::get('/', function () {
    return view('index');
});

Route::middleware(['auth'])->group(function () {
    Route::prefix('lupita')->group(function () {
        Route::get('/llamadas', [LlamadasController::class, 'listar_llamadas'])->name('lupita.llamadas');
        Route::get('/conductores', [LlamadasController::class, 'listar_conductores'])->name('lupita.conductores');

        Route::get('/conductores/log/nuevo', [LogConductoresController::class, 'nuevo'])->name('lupita.conductores.log.nuevo');

        Route::get('/conductores/log', LogConductoresPadre::class)->name('lupita.conductores.log');


        Route::get('/transportistas', [LlamadasController::class, 'listar_trts'])->name('lupita.transportistas');
        Route::get('/audio', [LlamadasController::class, 'procesar_audio'])->name('lupita.audio')->middleware('permission:etiquetar llamada');
        Route::patch('/audio/guardar', [LlamadasController::class, 'guardar_etiquetas'])->name('lupita.audio.guardar')->middleware('permission:etiquetar llamada');
        Route::get('/reporte', [LlamadasController::class, 'reporte_todo']);
    });

    Route::prefix('importar')->group(function () {
        Route::get('/json', [ImportController::class, 'procesar_json'])->name('importar.json');
        //Route::post('/json/procesar', [ImportController::class, 'jsonProcesar']);

        Route::get('/excel', [ImportController::class, 'cargar_excel'])->name('importar.excel')->middleware('permission:importar excel');
        Route::post('/excel/procesar', [ImportController::class, 'procesar_excel_llamadas'])->middleware('permission:importar excel');

        Route::get('/excel/lotes', [ImportController::class, 'lista_lotes'])->name('importar.excel.lotes')->middleware('permission:ver lista de lotes');
        Route::get('/excel/{lote_id}', [ImportController::class, 'mostrar_lote_importado'])->name('importar.excel.lote')->middleware('permission:ver lote');
        Route::get('/excel/{lote_id}/procesar', [ImportController::class, 'procesar_importacion_de_lote'])->name('importar.excel.procesar')->middleware('permission:ver lote');
        Route::delete('/excel/{lote_id}/eliminar', [ImportController::class, 'eliminar_lote'])->name('importar.excel.eliminar')
            ->middleware('role:admin')->middleware('permission:eliminar lote');
    });

});


Auth::routes();
Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');


Route::prefix('api')->group(function () {
    // Rutas públicas (sin autenticación)
    Route::get('/health', function () {
        return response()->json(['status' => 'OK']);
    });

    Route::get('/lote/{id}/detalle', [LlamadasApiController::class, 'devolver_lista_lote_refs']);
    Route::post('/lote/{id}/detalle/actualizar', [LlamadasApiController::class, 'actualizar_lote_detalle']);
    Route::get('/llamadas', [LlamadasJsonApiController::class, 'index']);
});

Route::get('/llamadas', [LlamadasController::class, 'calls_lista_llamadas']);
