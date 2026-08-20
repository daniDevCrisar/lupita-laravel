<?php

use App\Http\Controllers\Api\LlamadasApiController;
use App\Http\Controllers\Api\LlamadasJsonApiController;
use App\Http\Controllers\HomeController;
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
        Route::get('/reporte', [LlamadasController::class, 'reporte_todo'])->name('lupita.reporte');
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

    Route::get('/test-c', [HomeController::class, 'test_nombres']);

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
    Route::get('/llamadas', [LlamadasJsonApiController::class, 'index'])->name('end_point.llamadas.api');
});

Route::get('/llamadas', [LlamadasController::class, 'calls_lista_llamadas']);

Route::get('/mail-config', function () {
    echo "<h1>🔧 Configuración de correo</h1>";
    echo "<pre style='background:#1a1a2e;color:#00ff88;padding:15px;border-radius:8px;'>";
    echo "MAIL_MAILER: " . config('mail.default') . "\n";
    echo "MAIL_HOST: " . config('mail.mailers.smtp.host') . "\n";
    echo "MAIL_PORT: " . config('mail.mailers.smtp.port') . "\n";
    echo "MAIL_USERNAME: " . config('mail.mailers.smtp.username') . "\n";
    echo "MAIL_PASSWORD: " . (config('mail.mailers.smtp.password') ? '******** (configurada)' : '⚠️ VACÍA') . "\n";
    echo "MAIL_ENCRYPTION: " . config('mail.mailers.smtp.encryption') . "\n";
    echo "MAIL_FROM_ADDRESS: " . config('mail.from.address') . "\n";
    echo "MAIL_FROM_NAME: " . config('mail.from.name') . "\n";
    echo "</pre>";

    // Verificar si está usando hello@example.com
    if (config('mail.from.address') === 'hello@example.com') {
        echo "⚠️ <strong>El remitente es hello@example.com - NO está usando tu configuración</strong><br>";
        echo "👉 Elimina <code>bootstrap/cache/config.php</code> manualmente\n";
    } else {
        echo "✅ <strong>Configuración correcta!</strong>\n";
    }
});


Route::get('/livewire/discover', function() {
    try {
        // ✅ Usar Artisan directamente (Laravel ya está cargado)
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('optimize:clear');

        return response()->json([
            'success' => true,
            'message' => '✅ Caché limpiado y Livewire descubierto'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => '❌ Error: ' . $e->getMessage()
        ]);
    }
});
