<?php

use App\Http\Controllers\Api\ActualizarRefsController;
use App\Http\Controllers\Api\LlamadasApiController;
use App\Http\Controllers\Api\LlamadasJsonApiController;
use App\Http\Controllers\Api\ReferenciasApiController;
use App\Http\Controllers\Api\TiemposApiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogConductoresController;
use App\Http\Controllers\VapiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LlamadasController;
use App\Http\Controllers\ReportesController;
use App\Http\Controllers\ReferenciasController;
use App\Http\Controllers\TiemposRutaController;
use App\Http\Controllers\PalabrasController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\TiemposHorarioController;
use App\Livewire\LogConductores\Bandeja as LogConductoresBandeja;
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

        Route::get('/conductores/casos', LogConductoresBandeja::class)->name('lupita.conductores.casos');


        Route::get('/transportistas', [LlamadasController::class, 'listar_trts'])->name('lupita.transportistas');
        Route::get('/referencias', [ReferenciasController::class, 'index'])->name('lupita.referencias');
        Route::get('/referencias/mapa', [ReferenciasController::class, 'mapa'])->name('lupita.referencias.mapa');
        Route::get('/tiempos', [TiemposRutaController::class, 'index'])->name('lupita.tiempos');
        Route::get('/palabras', [PalabrasController::class, 'index'])->name('lupita.palabras');

        //--- Scoring de exito por palabras (pesos por tipo de llamada) ---
        // lectura: la evaluacion y su configuracion comparten el permiso de ver
        Route::get('/scoring', [ScoringController::class, 'index'])->name('lupita.scoring')
            ->middleware('permission:ver scoring');
        Route::get('/scoring/pesos', [ScoringController::class, 'pesos'])->name('lupita.scoring.pesos')
            ->middleware('permission:ver scoring');
        Route::get('/scoring/detalle', [ScoringController::class, 'detalle'])->name('lupita.scoring.detalle')
            ->middleware('permission:ver scoring');
        Route::get('/scoring/exportar', [ScoringController::class, 'exportar'])->name('lupita.scoring.exportar')
            ->middleware('permission:ver scoring');
        Route::get('/scoring/ayuda', [ScoringController::class, 'ayuda'])->name('lupita.scoring.ayuda')
            ->middleware('permission:ver scoring');
        Route::get('/scoring/llamadas', [ScoringController::class, 'llamadas'])->name('lupita.scoring.llamadas')
            ->middleware('permission:ver scoring');
        // el calculo escribe scoring_llamadas (nunca `llamadas`), asi que va del
        // lado de lectura: no reetiqueta nada, solo persiste el puntaje
        Route::post('/scoring/calcular', [ScoringController::class, 'calcular'])->name('lupita.scoring.calcular')
            ->middleware('permission:ver scoring');
        // escritura: la tabla de pesos es vocabulario controlado del usuario
        Route::patch('/scoring/pesos', [ScoringController::class, 'guardar_pesos'])->name('lupita.scoring.pesos.guardar')
            ->middleware('permission:editar palabras clave');
        Route::post('/scoring/pesos/sugerir', [ScoringController::class, 'sugerir_pesos'])->name('lupita.scoring.pesos.sugerir')
            ->middleware('permission:editar palabras clave');
        Route::post('/scoring/pesos/aplicar', [ScoringController::class, 'aplicar_sugeridos'])->name('lupita.scoring.pesos.aplicar')
            ->middleware('permission:editar palabras clave');
        Route::post('/scoring/pesos/copiar', [ScoringController::class, 'copiar_pesos'])->name('lupita.scoring.pesos.copiar')
            ->middleware('permission:editar palabras clave');

        Route::get('/refs/actualizar', [ActualizarRefsController::class, 'seleccion'])->name('lupita.refs.seleccion');
        Route::get('/refs/docs', [ActualizarRefsController::class, 'documentacion'])->name('lupita.refs.docs');

        Route::get('/tiempos/horario', [TiemposHorarioController::class, 'index'])->name('lupita.tiempos.horario');
        Route::get('/tiempos/api', [TiemposApiController::class, 'probar'])->name('lupita.tiempos.api');
        Route::get('/audio', [LlamadasController::class, 'procesar_audio'])->name('lupita.audio')->middleware('permission:etiquetar llamada');
        Route::patch('/audio/guardar', [LlamadasController::class, 'guardar_etiquetas'])->name('lupita.audio.guardar')->middleware('permission:etiquetar llamada');
        Route::get('/reporte', [LlamadasController::class, 'reporte_todo'])->name('lupita.reporte');
        Route::get('/reporte/portada', [LlamadasController::class, 'reporte_portada'])->name('lupita.reporte.portada');
        Route::get('/reporte/compacta', [LlamadasController::class, 'reporte_compacta'])->name('lupita.reporte.compacta');
        Route::get('/reporte/seleccion', [LlamadasController::class, 'reporte_seleccion'])->name('lupita.reporte.seleccion');
        Route::get('/reporte/ejecutivo', [LlamadasController::class, 'reporte_ejecutivo'])->name('lupita.reporte.ejecutivo');

        //--- Reportes V2: dashboard interactivo + reportes email-safe (dia y rango) ---
        Route::get('/reportes/dashboard', [ReportesController::class, 'dashboard'])->name('lupita.reportes.dashboard');
        Route::get('/reportes/dashboard/html', [ReportesController::class, 'dashboard_html'])->name('lupita.reportes.dashboard.html');
        Route::get('/reportes/seleccion', [ReportesController::class, 'seleccion'])->name('lupita.reportes.seleccion');
        Route::get('/reportes/reporte', [ReportesController::class, 'reporte'])->name('lupita.reportes.reporte');
        Route::get('/reportes/reporte/html', [ReportesController::class, 'reporte_html'])->name('lupita.reportes.reporte.html');
        Route::get('/reportes/dia', [ReportesController::class, 'dia'])->name('lupita.reportes.dia');
        Route::get('/reportes/rango', [ReportesController::class, 'rango'])->name('lupita.reportes.rango');
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

        //vicodeado solo vapi correos de dtgroup
        Route::get('/vapi', [VapiController::class, 'index'])->name('vapi.index');

        // Procesa el link, descarga, extrae el JSON y lo devuelve
        Route::post('/vapi/procesar', [VapiController::class, 'procesarZip'])->name('vapi.procesar');

    });

    Route::get('/test-c', [HomeController::class, 'test_nombres']);
    Route::get('/test-identificar-error-nombres', [ImportController::class, 'nombres_verificar_error']);

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

    //--- Actualizacion directa de referencias (tceflexia generalMilestonesRoute) ---
    Route::get('/refs', [ActualizarRefsController::class, 'devolver_refs_por_lotes']);
    Route::post('/refs/actualizar', [ActualizarRefsController::class, 'actualizar_refs']);
    Route::get('/llamadas', [LlamadasJsonApiController::class, 'index'])->name('end_point.llamadas.api');

    // Consultar las llamadas de una referencia (viaje) o de una lista de refs.
    Route::get('/referencias/{ref}/llamadas', [ReferenciasApiController::class, 'llamadasPorRef'])->name('api.referencias.llamadas');
    Route::match(['get', 'post'], '/referencias/llamadas', [ReferenciasApiController::class, 'llamadasPorRefs'])->name('api.referencias.llamadas.batch');

    //--- Tiempos promedio por ruta (misma logica que /lupita/tiempos, en JSON) ---
    Route::get('/tiempos/ruta', [TiemposApiController::class, 'por_ruta'])->name('api.tiempos.ruta');
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

        // 🔑 Refrescar caché de permisos/roles de Spatie (spatie.permission.cache)
        // Se hace por dos vías: el comando oficial (si existe) y el olvido directo,
        // para que funcione aunque la versión instalada no tenga `permission:cache-reset`.
        try {
            \Illuminate\Support\Facades\Artisan::call('permission:cache-reset');
        } catch (\Throwable $e) {
            // la versión de Spatie puede no traer el comando: se ignora y se usa el olvido directo
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => '✅ Caché limpiado, permisos refrescados y Livewire descubierto'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => '❌ Error: ' . $e->getMessage()
        ]);
    }
});

Route::get('/tabulator_table_ejm', [HomeController::class, 'tabulator_table_ejm']);
