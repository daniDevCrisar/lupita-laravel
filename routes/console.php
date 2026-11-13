<?php

use App\Database\DBPrediccion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Qué datos hay realmente para predecir la hora de presenta para carga: sin
// estos números la tabla de aciertos no se puede interpretar (y el servidor,
// que no tiene consola, los lee con este mismo comando por SSH).
Artisan::command('prediccion:diagnostico {--dias=120}', function () {
    $dias = max(1, (int) $this->option('dias'));

    try {
        $datos = DBPrediccion::diagnostico($dias);
    } catch (\Throwable $e) {
        $this->error('Sin base de datos: ' . $e->getMessage());

        return 0;
    }

    $this->line(DBPrediccion::texto_diagnostico($datos, $dias));

    return 0;
})->purpose('Mide qué datos hay para predecir la hora de presenta para carga');
