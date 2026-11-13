<?php

use App\Database\DBPrediccion;
use App\Prediccion\Calibracion;
use App\Prediccion\Parametros;
use App\Prediccion\Servicio;
use Carbon\Carbon;
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

// Calibración del motor: corre la tabla de aciertos con varias combinaciones de
// parámetros sobre un tramo y elige la que mejor predice, comprobando después
// contra el tramo final (validación) que no se eligió por casualidad.
//
// El servidor no tiene consola: esto se corre en local (o por SSH con el mismo
// comando) y los valores elegidos se aplican con --aplicar, que los guarda en
// `prediccion_config` — la pantalla los lee de ahí sin tocar código.
Artisan::command(
    'prediccion:calibrar
     {--desde= : inicio del tramo de entrenamiento (Y-m-d)}
     {--hasta= : fin de todo el tramo (Y-m-d, por defecto hoy)}
     {--validar-desde= : inicio de la validación (por defecto los últimos 7 días)}
     {--validar-hasta= : fin de la validación (por defecto = --hasta)}
     {--limite=200 : filas por corrida}
     {--metrica=mae : mae | pct_1h | pct_3h | pct_ventana}
     {--n-minimo=20 : filas mínimas para que una corrida cuente}
     {--rejilla= : JSON con la rejilla, ej. {"spread_cadena":[0.2,0.3,0.5]}}
     {--aplicar : guarda los mejores valores en prediccion_config}',
    function () {
        $fecha = function (?string $valor, bool $fin = false): ?Carbon {
            $valor = trim((string) $valor);
            if ($valor === '') {
                return null;
            }

            try {
                $carbon = Carbon::parse($valor);
            } catch (\Throwable $e) {
                return null;
            }

            return $fin ? $carbon->endOfDay() : $carbon->startOfDay();
        };

        $hasta = $fecha($this->option('hasta'), true) ?? Carbon::now()->endOfDay();
        $desde = $fecha($this->option('desde')) ?? $hasta->copy()->subDays(29)->startOfDay();
        $valDesde = $fecha($this->option('validar-desde')) ?? $hasta->copy()->subDays(6)->startOfDay();
        $valHasta = $fecha($this->option('validar-hasta'), true) ?? $hasta->copy();
        $entHasta = $valDesde->copy()->subDay()->endOfDay();

        if ($desde->greaterThan($entHasta)) {
            $this->error('La ventana de entrenamiento quedó vacía: --desde ' . $desde->toDateString()
                . ' tiene que ser anterior a --validar-desde ' . $valDesde->toDateString() . '.');

            return 1;
        }
        if ($valDesde->greaterThan($valHasta)) {
            [$valDesde, $valHasta] = [$valHasta->copy()->startOfDay(), $valDesde->copy()->endOfDay()];
        }

        $metrica = (string) $this->option('metrica');
        if (!isset(Calibracion::METRICAS[$metrica])) {
            $this->error('Métrica desconocida: ' . $metrica . '. Usá: ' . implode(', ', array_keys(Calibracion::METRICAS)));

            return 1;
        }

        $limite = max(10, min(1000, (int) $this->option('limite')));
        $nMinimo = max(1, (int) $this->option('n-minimo'));

        $rejilla = Calibracion::REJILLA;
        $json = trim((string) $this->option('rejilla'));
        if ($json !== '') {
            $rejilla = json_decode($json, true);
            if (!is_array($rejilla)) {
                // comodidad para la consola: aceptar comillas simples
                $rejilla = json_decode(str_replace("'", '"', $json), true);
            }
            if (!is_array($rejilla) || $rejilla === []) {
                $this->error('--rejilla tiene que ser un JSON con listas, ej. {"spread_cadena":[0.2,0.3,0.5]} '
                    . '(con comillas dobles, o simples si la consola se las come)');

                return 1;
            }
        }

        $base = Parametros::cargar();

        // una clave que no existe en la configuración no movería nada: mejor decirlo
        $desconocidas = array_diff(array_keys($rejilla), array_keys((array) $base));
        if ($desconocidas !== []) {
            $this->error('Claves desconocidas en --rejilla: ' . implode(', ', $desconocidas)
                . '. Las que existen: ' . implode(', ', array_keys((array) $base)));

            return 1;
        }

        try {
            $combos = Calibracion::combinaciones($rejilla);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $filtrosEnt = ['desde' => $desde->toDateString(), 'hasta' => $entHasta->toDateString(), 'limite' => $limite];
        $filtrosVal = ['desde' => $valDesde->toDateString(), 'hasta' => $valHasta->toDateString(), 'limite' => $limite];

        $this->line('Entrenamiento: ' . $desde->toDateString() . ' → ' . $entHasta->toDateString()
            . ' · validación: ' . $valDesde->toDateString() . ' → ' . $valHasta->toDateString()
            . ' · ' . $limite . ' filas por corrida');
        $this->line('Métrica: ' . $metrica . ' (' . Calibracion::METRICAS[$metrica]['etiqueta'] . ') · '
            . count($combos) . ' combinaciones');
        $this->newLine();

        // referencia: los parámetros que están en uso ahora mismo
        $actualEnt = Servicio::backtest($filtrosEnt, $base);
        $actualVal = Servicio::backtest($filtrosVal, $base);

        $resultados = [];
        $saltados = [];
        $this->output->progressStart(count($combos));

        foreach ($combos as $valores) {
            $parametros = Calibracion::con($base, $valores);

            try {
                $ent = Servicio::backtest($filtrosEnt, $parametros);
                $val = Servicio::backtest($filtrosVal, $parametros);
            } catch (\Throwable $e) {
                $saltados[] = Calibracion::etiqueta($valores) . ': ' . $e->getMessage();
                $this->output->progressAdvance();

                continue;
            }

            $resultados[] = (object) [
                'valores' => $valores,
                'etiqueta' => Calibracion::etiqueta($valores),
                'entrenamiento' => $ent->resumen_motor,
                'validacion' => $val->resumen_motor,
            ];

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->newLine();

        foreach ($saltados as $aviso) {
            $this->warn('Se salteó ' . $aviso);
        }

        if ($resultados === []) {
            $this->error('No terminó ninguna corrida: revisá la base y el rango.');

            return 1;
        }

        $filas = [];
        foreach ($resultados as $resultado) {
            $filas[] = [$resultado, Calibracion::puntaje($resultado->validacion, $metrica)];
        }

        usort($filas, fn (array $a, array $b) => $a[1] <=> $b[1]);

        $numero = function ($valor): string {
            return $valor === null ? '—' : (string) (int) round((float) $valor);
        };

        $tabla = [];
        foreach (array_slice($filas, 0, 15) as $i => [$resultado, $puntaje]) {
            $ent = $resultado->entrenamiento;
            $val = $resultado->validacion;
            $tabla[] = [
                $i + 1,
                $resultado->etiqueta,
                $numero($ent->mae_min ?? null),
                (int) ($ent->n ?? 0),
                $numero($val->mae_min ?? null),
                (int) ($val->n ?? 0),
                $numero($val->pct_180 ?? null),
                $numero($val->pct_ventana ?? null),
                $puntaje === INF ? '—' : round($puntaje, 1),
            ];
        }

        $this->table(
            ['#', 'parámetros', 'MAE ent', 'n ent', 'MAE val', 'n val', '±3 h val', '% ventana val', 'puntaje'],
            $tabla
        );

        // referencia: los parámetros que están en uso ahora mismo (sólo las
        // claves que se están moviendo, no las 17 de la configuración)
        $etiquetaBase = [];
        foreach (array_keys($rejilla) as $clave) {
            $etiquetaBase[$clave] = $base->{$clave} ?? null;
        }

        $this->line('Ahora mismo (' . Calibracion::etiqueta($etiquetaBase) . '):');
        $this->line('  entrenamiento: MAE ' . $numero($actualEnt->resumen_motor->mae_min ?? null)
            . ' · ±3 h ' . $numero($actualEnt->resumen_motor->pct_180 ?? null) . '%'
            . ' · % ventana ' . $numero($actualEnt->resumen_motor->pct_ventana ?? null) . '%'
            . ' (n=' . (int) ($actualEnt->resumen_motor->n ?? 0) . ')');
        $this->line('  validación:    MAE ' . $numero($actualVal->resumen_motor->mae_min ?? null)
            . ' · ±3 h ' . $numero($actualVal->resumen_motor->pct_180 ?? null) . '%'
            . ' · % ventana ' . $numero($actualVal->resumen_motor->pct_ventana ?? null) . '%'
            . ' (n=' . (int) ($actualVal->resumen_motor->n ?? 0) . ')');

        $mejor = Calibracion::mejor($resultados, $metrica, true, $nMinimo);
        if ($mejor === null) {
            $this->warn('Ninguna corrida llegó a ' . $nMinimo . ' filas en la validación: no hay con qué elegir. '
                . 'Mirá la tabla de aciertos con ese rango.');

            return 1;
        }

        $this->newLine();
        $this->info('Mejor combinación: ' . $mejor->etiqueta);
        $this->line('  validación: MAE ' . $numero($mejor->validacion->mae_min ?? null)
            . ' · ±3 h ' . $numero($mejor->validacion->pct_180 ?? null) . '%'
            . ' · % ventana ' . $numero($mejor->validacion->pct_ventana ?? null) . '%'
            . ' (n=' . (int) ($mejor->validacion->n ?? 0) . ')');

        $puntajeActual = Calibracion::puntaje($actualVal->resumen_motor, $metrica);
        $puntajeMejor = Calibracion::puntaje($mejor->validacion, $metrica);
        if ($puntajeActual !== INF && $puntajeMejor !== INF) {
            $this->line('  contra los parámetros actuales: ' . round($puntajeMejor - $puntajeActual, 1)
                . ' puntos de "' . $metrica . '" (negativo = mejor)');
        }

        if ($this->option('aplicar')) {
            try {
                Parametros::guardar($mejor->valores);
                $this->info('Guardado en `prediccion_config`: ' . Calibracion::etiqueta($mejor->valores));
            } catch (\Throwable $e) {
                $this->error('No se pudo guardar en `prediccion_config` (¿existe la tabla?): ' . $e->getMessage());

                return 1;
            }
        } else {
            $this->comment('No se guardó nada. Repetí con --aplicar para dejarlo en `prediccion_config`.');
        }

        return 0;
    }
)->purpose('Busca los parámetros del motor que mejor predicen (entrenamiento + validación)');
