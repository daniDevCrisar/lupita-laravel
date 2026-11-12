<?php

use App\Http\Controllers\Api\ActualizarRefsController;
use App\Http\Controllers\Api\LlamadasApiController;
use App\Http\Controllers\Api\LlamadasJsonApiController;
use App\Http\Controllers\Api\ReferenciasApiController;
use App\Http\Controllers\Api\TiemposApiController;
use App\Http\Controllers\Api\TiemposPromedioApiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ConductoresDuplicadosController;
use App\Http\Controllers\ComportamientoController;
use App\Http\Controllers\LocalesController;
use App\Http\Controllers\LogConductoresController;
use App\Http\Controllers\VapiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LlamadasController;
use App\Http\Controllers\ReportesController;
use App\Http\Controllers\ReferenciasController;
use App\Http\Controllers\RefsController;
use App\Http\Controllers\TiemposRutaController;
use App\Http\Controllers\TiemposApiVerController;
use App\Http\Controllers\PalabrasController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\TiemposHorarioController;
use App\Http\Controllers\TiemposPromedioController;
use App\Http\Controllers\TiemposPromedioVerController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\VerificarReferenciasController;
use App\Livewire\LogConductores\Bandeja as LogConductoresBandeja;
use App\Livewire\LogConductores\Padre as LogConductoresPadre;



Route::get('/', function () {
    return view('index');
});

//--- MAPA DE PERMISOS DEL PANEL ---
// Cada modulo del panel tiene su permiso (Spatie). Las rutas que no son API
// cuelgan del grupo `auth` y exigen el permiso de su modulo:
//   ver llamadas | ver conductores | ver transportistas | ver referencias
//   ver tiempos   | ver reportes   | ver scoring        | ver lote
//   ver lista de lotes | ver api | compilar promedios | importar excel
//   importar json | importar vapi | etiquetar llamada | editar palabras clave
//   eliminar lote | fusionar conductores | administrar usuarios  (solo admin)
// REGLA: los API son PUBLICOS. Lo que vive en app/Http/Controllers/Api y en el
// grupo /api no lleva `auth` ni middleware `permission`; las pantallas que los
// acompanian (RefsController, TiemposApiVerController) exigen solo el permiso
// de lectura `ver api` (grupo API del menu).
Route::middleware(['auth'])->group(function () {
    Route::prefix('lupita')->group(function () {
        Route::get('/llamadas', [LlamadasController::class, 'listar_llamadas'])->name('lupita.llamadas')
            ->middleware('permission:ver llamadas');
        Route::get('/conductores', [LlamadasController::class, 'listar_conductores'])->name('lupita.conductores')
            ->middleware('permission:ver conductores');

        //--- Fusion de conductores duplicados (mismo nombre) ---
        // lectura: la pantalla solo agrupa y cuenta
        Route::get('/conductores/duplicados', [ConductoresDuplicadosController::class, 'index'])->name('lupita.conductores.duplicados')
            ->middleware('permission:gestionar duplicados conductores');
        // escritura: reapunta llamadas, referencias, telefonos y log al id elegido
        Route::post('/conductores/duplicados/fusionar', [ConductoresDuplicadosController::class, 'fusionar'])->name('lupita.conductores.duplicados.fusionar')
            ->middleware('role:admin')->middleware('permission:fusionar conductores');

        Route::get('/conductores/log/nuevo', [LogConductoresController::class, 'nuevo'])->name('lupita.conductores.log.nuevo')
            ->middleware('permission:ver conductores');

        Route::get('/conductores/log', LogConductoresPadre::class)->name('lupita.conductores.log')
            ->middleware('permission:ver conductores');

        Route::get('/conductores/casos', LogConductoresBandeja::class)->name('lupita.conductores.casos')
            ->middleware('permission:gestionar casos conductores');


        Route::get('/transportistas', [LlamadasController::class, 'listar_trts'])->name('lupita.transportistas')
            ->middleware('permission:ver transportistas');
        Route::get('/referencias', [ReferenciasController::class, 'index'])->name('lupita.referencias')
            ->middleware('permission:ver referencias');
        Route::get('/referencias/mapa', [ReferenciasController::class, 'mapa'])->name('lupita.referencias.mapa')
            ->middleware('permission:ver referencias');

        //--- Verificacion masiva: pegar una lista de refs y ver cuales no existen
        // o existen sin fin de descargue. La lista viaja por sesion (POST -> GET),
        // asi 5k refs no tienen que caber en la URL.
        Route::get('/referencias/verificar', [VerificarReferenciasController::class, 'index'])->name('lupita.referencias.verificar')
            ->middleware('permission:ver referencias');
        Route::post('/referencias/verificar', [VerificarReferenciasController::class, 'cargar'])->name('lupita.referencias.verificar.cargar')
            ->middleware('permission:ver referencias');
        Route::get('/referencias/verificar/exportar', [VerificarReferenciasController::class, 'exportar'])->name('lupita.referencias.verificar.exportar')
            ->middleware('permission:ver referencias');
        Route::get('/tiempos', [TiemposRutaController::class, 'index'])->name('lupita.tiempos')
            ->middleware('permission:ver tiempos');

        //--- Comportamiento por placa / conductor (habitos, puntualidad y tiempos) ---
        Route::get('/comportamiento', [ComportamientoController::class, 'index'])->name('lupita.comportamiento')
            ->middleware('permission:ver tiempos');
        Route::get('/comportamiento/ficha', [ComportamientoController::class, 'ficha'])->name('lupita.comportamiento.ficha')
            ->middleware('permission:ver tiempos');
        Route::get('/palabras', [PalabrasController::class, 'index'])->name('lupita.palabras')
            ->middleware('permission:ver scoring');

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
        // los dos parametros de la sugerencia de analisis (fuerza minima y techo
        // de notas): configuran la sugerencia, nunca el puntaje
        Route::patch('/scoring/sugerencia', [ScoringController::class, 'guardar_sugerencia'])->name('lupita.scoring.sugerencia.guardar')
            ->middleware('permission:editar palabras clave');

        //--- Pantallas del modulo de API (grupo API del menu) ---
        Route::get('/refs/actualizar', [RefsController::class, 'seleccion'])->name('lupita.refs.seleccion')
            ->middleware('permission:ver api');
        Route::get('/refs/docs', [RefsController::class, 'documentacion'])->name('lupita.refs.docs')
            ->middleware('permission:ver api');
        Route::get('/refs/ruta-id', [RefsController::class, 'documentacion_ruta_id'])->name('lupita.refs.ruta_id')
            ->middleware('permission:ver api');

        //--- Permisos por rol (que modulos ve cada rol) ---
        Route::get('/permisos', [PermisosController::class, 'index'])->name('lupita.permisos')
            ->middleware('permission:administrar usuarios');
        Route::post('/permisos', [PermisosController::class, 'guardar'])->name('lupita.permisos.guardar')
            ->middleware('permission:administrar usuarios');

        //--- Administracion de usuarios (solo el usuario 1: el administrador) ---
        Route::get('/usuarios', [UsuariosController::class, 'index'])->name('lupita.usuarios')
            ->middleware('permission:administrar usuarios');
        Route::get('/usuarios/nuevo', [UsuariosController::class, 'crear'])->name('lupita.usuarios.crear')
            ->middleware('permission:administrar usuarios');
        Route::post('/usuarios', [UsuariosController::class, 'guardar'])->name('lupita.usuarios.guardar')
            ->middleware('permission:administrar usuarios');
        Route::get('/usuarios/{id}/editar', [UsuariosController::class, 'editar'])->name('lupita.usuarios.editar')
            ->whereNumber('id')->middleware('permission:administrar usuarios');
        Route::post('/usuarios/{id}', [UsuariosController::class, 'actualizar'])->name('lupita.usuarios.actualizar')
            ->whereNumber('id')->middleware('permission:administrar usuarios');
        Route::post('/usuarios/{id}/estado', [UsuariosController::class, 'alternar_activo'])->name('lupita.usuarios.estado')
            ->whereNumber('id')->middleware('permission:administrar usuarios');

        //--- Catalogo de locales / plantas (listado y alta) ---
        // es un catalogo de apoyo de tiempos, plantas y reportes: solo admin
        Route::get('/locales', [LocalesController::class, 'index'])->name('lupita.locales')
            ->middleware('role:admin');
        Route::get('/locales/nuevo', [LocalesController::class, 'crear'])->name('lupita.locales.crear')
            ->middleware('role:admin');
        Route::post('/locales', [LocalesController::class, 'guardar'])->name('lupita.locales.guardar')
            ->middleware('role:admin');
        // el id viaja por la URL: puede traer espacios (ej: LOC_PTE PIEDRA)
        Route::get('/locales/{id}/editar', [LocalesController::class, 'editar'])->name('lupita.locales.editar')
            ->middleware('role:admin');
        Route::post('/locales/{id}', [LocalesController::class, 'actualizar'])->name('lupita.locales.actualizar')
            ->middleware('role:admin');

        Route::get('/tiempos/horario', [TiemposHorarioController::class, 'index'])->name('lupita.tiempos.horario')
            ->middleware('permission:ver tiempos');

        //--- Compilacion de promedios de ruta (inicio_ruta -> llegada destino) por tope en minutos ---
        // lectura: previsualiza cuanto se pierde con el tope elegido
        Route::get('/tiempos/promedio', [TiemposPromedioController::class, 'index'])->name('lupita.tiempos.promedio')
            ->middleware('permission:ver tiempos');
        // escritura: guarda los promedios por partes (todas, una ruta o solo las que faltan)
        Route::post('/tiempos/promedio/compilar', [TiemposPromedioController::class, 'compilar'])->name('lupita.tiempos.promedio.compilar')
            ->middleware('permission:compilar promedios');
        // escritura: compila una sola ruta con el tope propio escrito en la tabla
        Route::post('/tiempos/promedio/ruta', [TiemposPromedioController::class, 'compilar_ruta'])->name('lupita.tiempos.promedio.ruta')
            ->middleware('permission:compilar promedios');

        //--- Promedios por dia de la semana y hora de inicio de ruta (matriz ruta x dia x hora) ---
        // lectura: previsualiza cuantos viajes arrancan en cada celda y cuantos pasan el tope
        Route::get('/tiempos/promedio/horario', [TiemposPromedioController::class, 'horario'])->name('lupita.tiempos.promedio.horario')
            ->middleware('permission:ver tiempos');
        // escritura: recompila solo las celdas dia/hora, sin tocar los promedios de ruta
        Route::post('/tiempos/promedio/horario/compilar', [TiemposPromedioController::class, 'compilar_horario'])->name('lupita.tiempos.promedio.horario.compilar')
            ->middleware('permission:compilar promedios');

        //--- Promedios de espera por PLANTA (tabla aparte: tiempos_planta_promedio) ---
        // lectura: espera fuera y dentro de cada planta, fusionando carga (E2/E3) y descarga (E5/E6)
        Route::get('/tiempos/promedio/plantas', [TiemposPromedioController::class, 'plantas'])->name('lupita.tiempos.promedio.plantas')
            ->middleware('permission:ver tiempos');
        // escritura: guarda la tabla de plantas con el tope recomendado (≤18 % descartado), uno global o uno por planta
        Route::post('/tiempos/promedio/plantas/compilar', [TiemposPromedioController::class, 'compilar_plantas'])->name('lupita.tiempos.promedio.plantas.compilar')
            ->middleware('permission:compilar promedios');
        // escritura: borra el promedio guardado solo de las plantas marcadas
        Route::post('/tiempos/promedio/plantas/eliminar', [TiemposPromedioController::class, 'eliminar_plantas'])->name('lupita.tiempos.promedio.plantas.eliminar')
            ->middleware('permission:compilar promedios');

        //--- Consulta de lo ya compilado (solo lectura, consume /api/tiempos/promedio) ---
        Route::get('/tiempos/promedio/ver', [TiemposPromedioVerController::class, 'rutas'])->name('lupita.tiempos.promedio.ver')
            ->middleware('permission:ver tiempos');
        Route::get('/tiempos/promedio/horario/ver', [TiemposPromedioVerController::class, 'horario'])->name('lupita.tiempos.promedio.horario.ver')
            ->middleware('permission:ver tiempos');

        Route::get('/tiempos/api', [TiemposApiVerController::class, 'index'])->name('lupita.tiempos.api')
            ->middleware('permission:ver api');
        Route::get('/audio', [LlamadasController::class, 'procesar_audio'])->name('lupita.audio')->middleware('permission:etiquetar llamada');
        Route::patch('/audio/guardar', [LlamadasController::class, 'guardar_etiquetas'])->name('lupita.audio.guardar')->middleware('permission:etiquetar llamada');
        Route::get('/reporte', [LlamadasController::class, 'reporte_todo'])->name('lupita.reporte')
            ->middleware('permission:ver reportes');
        Route::get('/reporte/portada', [LlamadasController::class, 'reporte_portada'])->name('lupita.reporte.portada')
            ->middleware('permission:ver reportes');
        Route::get('/reporte/compacta', [LlamadasController::class, 'reporte_compacta'])->name('lupita.reporte.compacta')
            ->middleware('permission:ver reportes');
        Route::get('/reporte/seleccion', [LlamadasController::class, 'reporte_seleccion'])->name('lupita.reporte.seleccion')
            ->middleware('permission:ver reportes');
        Route::get('/reporte/ejecutivo', [LlamadasController::class, 'reporte_ejecutivo'])->name('lupita.reporte.ejecutivo')
            ->middleware('permission:ver reportes');

        //--- Reportes V2: dashboard interactivo + reportes email-safe (dia y rango) ---
        Route::get('/reportes/dashboard', [ReportesController::class, 'dashboard'])->name('lupita.reportes.dashboard')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/dashboard/html', [ReportesController::class, 'dashboard_html'])->name('lupita.reportes.dashboard.html')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/seleccion', [ReportesController::class, 'seleccion'])->name('lupita.reportes.seleccion')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/reporte', [ReportesController::class, 'reporte'])->name('lupita.reportes.reporte')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/reporte/html', [ReportesController::class, 'reporte_html'])->name('lupita.reportes.reporte.html')
            ->middleware('permission:ver reportes');
        //--- Progreso por semana y por mes (formato reporte clasico, metricas del dashboard) ---
        Route::get('/reportes/progreso', [ReportesController::class, 'progreso'])->name('lupita.reportes.progreso')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/progreso/html', [ReportesController::class, 'progreso_html'])->name('lupita.reportes.progreso.html')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/dia', [ReportesController::class, 'dia'])->name('lupita.reportes.dia')
            ->middleware('permission:ver reportes');
        Route::get('/reportes/rango', [ReportesController::class, 'rango'])->name('lupita.reportes.rango')
            ->middleware('permission:ver reportes');
    });

    Route::prefix('importar')->group(function () {
        Route::get('/json', [ImportController::class, 'procesar_json'])->name('importar.json')
            ->middleware('permission:importar json');
        //Route::post('/json/procesar', [ImportController::class, 'jsonProcesar']);

        Route::get('/excel', [ImportController::class, 'cargar_excel'])->name('importar.excel')->middleware('permission:importar excel');
        Route::post('/excel/procesar', [ImportController::class, 'procesar_excel_llamadas'])->middleware('permission:importar excel');

        Route::get('/excel/lotes', [ImportController::class, 'lista_lotes'])->name('importar.excel.lotes')->middleware('permission:ver lista de lotes');
        Route::get('/excel/{lote_id}', [ImportController::class, 'mostrar_lote_importado'])->name('importar.excel.lote')->middleware('permission:ver lote');
        Route::get('/excel/{lote_id}/procesar', [ImportController::class, 'procesar_importacion_de_lote'])->name('importar.excel.procesar')->middleware('permission:ver lote');
        Route::delete('/excel/{lote_id}/eliminar', [ImportController::class, 'eliminar_lote'])->name('importar.excel.eliminar')
            ->middleware('role:admin')->middleware('permission:eliminar lote');

        //vicodeado solo vapi correos de dtgroup
        Route::get('/vapi', [VapiController::class, 'index'])->name('vapi.index')
            ->middleware('permission:importar vapi');

        // Procesa el link, descarga, extrae el JSON y lo devuelve
        Route::post('/vapi/procesar', [VapiController::class, 'procesarZip'])->name('vapi.procesar')
            ->middleware('permission:importar vapi');

    });

    // Rutas de soporte: solo el administrador
    Route::get('/test-c', [HomeController::class, 'test_nombres'])->middleware('permission:administrar usuarios');
    Route::get('/test-identificar-error-nombres', [ImportController::class, 'nombres_verificar_error'])
        ->middleware('permission:administrar usuarios');

});


Auth::routes();
Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home')
    ->middleware(['auth', 'permission:ver llamadas']);


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

    //--- Promedios YA compilados (tiempos_ruta_promedio y tiempos_ruta_horario, en JSON) ---
    Route::get('/tiempos/promedio', [TiemposPromedioApiController::class, 'por_ruta'])->name('api.tiempos.promedio');
    Route::get('/tiempos/promedio/horario', [TiemposPromedioApiController::class, 'horario'])->name('api.tiempos.promedio.horario');
    Route::get('/tiempos/promedio/plantas', [TiemposPromedioApiController::class, 'plantas'])->name('api.tiempos.promedio.plantas');
});

Route::get('/llamadas', [LlamadasController::class, 'calls_lista_llamadas']);

Route::middleware(['auth', 'permission:administrar usuarios'])->get('/mail-config', function () {
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
