<?php

namespace App\Livewire\LogConductores;

use App\Database\DBConductoresCasos;
use App\Database\DBLlamadas;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Bandeja extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(except: 'candidatos')]
    public $tab = 'candidatos';

    // Filtros (reflejados en la URL para poder compartir la vista)
    #[Url(except: '')]
    public $fecha_inicio = '';

    #[Url(except: '')]
    public $fecha_fin = '';

    #[Url(except: '')]
    public $llamada_tipo_id = '';

    #[Url(except: '')]
    public $conductor = '';

    #[Url(except: '')]
    public $tipo_caso = '';

    #[Url(except: '')]
    public $estado = '';

    public $por_pagina = 25;

    // Modal del caso
    public $modal = false;
    public $form = [];
    public $eventos = [];
    public $aviso = '';
    public $ver_evidencia = false;

    public function aplicar(): void
    {
        // "Sin caso abierto" solo existe en la bandeja de candidatos.
        if ($this->estado === 'SIN_CASO') {
            $this->tab = 'candidatos';
        }
        $this->resetPage();
        $this->aviso = '';
    }

    public function limpiar(): void
    {
        $this->reset(['fecha_inicio', 'fecha_fin', 'llamada_tipo_id', 'conductor', 'tipo_caso', 'estado']);
        $this->resetPage();
    }

    public function set_tab(string $tab): void
    {
        $this->tab = in_array($tab, ['candidatos', 'casos'], true) ? $tab : 'candidatos';
        $this->resetPage();
    }

    public function abrir(int $conductor_id): void
    {
        DBConductoresCasos::set_filtro(new Request($this->filtros()));

        $this->form = DBConductoresCasos::formulario($conductor_id);
        if (!$this->form) {
            $this->aviso = 'No se encontro informacion del conductor.';
            return;
        }
        $this->eventos = $this->form['id_log_conductor'] ? DBConductoresCasos::eventos((int) $this->form['id_log_conductor']) : [];
        $this->ver_evidencia = false;
        $this->modal = true;
    }

    public function editar(int $id_log_conductor): void
    {
        DBConductoresCasos::set_filtro(new Request($this->filtros()));
        $this->form = DBConductoresCasos::formulario_caso($id_log_conductor);
        if (!$this->form) {
            $this->aviso = 'El caso ya no existe.';
            return;
        }
        $this->eventos = DBConductoresCasos::eventos($id_log_conductor);
        $this->ver_evidencia = false;
        $this->modal = true;
    }

    public function cerrarModal(): void
    {
        $this->modal = false;
        $this->form = [];
        $this->eventos = [];
        $this->resetErrorBag();
    }

    public function reportar(int $id_log_conductor): void
    {
        DBConductoresCasos::cambiar_estado($id_log_conductor, 'REPORTADO', 'Marcado como reportado desde la bandeja');
        $this->aviso = 'Caso #' . $id_log_conductor . ' marcado como reportado.';
    }

    public function guardar(): void
    {
        $this->validate([
            'form.id_conductor' => 'required|numeric',
            'form.tipo_caso' => 'required|in:' . implode(',', array_keys(DBConductoresCasos::TIPOS)),
            'form.status' => 'required|in:' . implode(',', array_keys(DBConductoresCasos::ESTADOS)),
            'form.ubicacion' => 'required|in:LIMA,PROVINCIA',
            'form.analisis' => 'nullable|string|max:2000',
            'form.accion' => 'nullable|string|max:2000',
            'form.respuesta' => 'nullable|string|max:2000',
            'form.acuerdo' => 'nullable|string|max:1000',
            'form.fecha_reporte' => 'nullable|date',
            'form.fecha_compromiso' => 'nullable|date',
            'form.resultado' => 'nullable|in:' . implode(',', array_keys(DBConductoresCasos::RESULTADOS)),
        ], [], [
            'form.tipo_caso' => 'tipo de caso',
            'form.status' => 'estado',
            'form.analisis' => 'analisis',
            'form.accion' => 'accion',
            'form.respuesta' => 'respuesta',
            'form.resultado' => 'resultado',
        ]);

        $form = $this->form;

        if ($form['status'] === 'CERRADO' && empty($form['resultado'])) {
            $this->addError('form.resultado', 'Para cerrar el caso hay que indicar el resultado.');
            return;
        }
        if ($form['status'] === 'REPORTADO' && empty($form['fecha_reporte'])) {
            $form['fecha_reporte'] = now()->toDateString();
        }
        if ($form['fecha_reporte'] && $form['fecha_compromiso'] && $form['fecha_compromiso'] < $form['fecha_reporte']) {
            $this->addError('form.fecha_compromiso', 'El compromiso no puede ser anterior al reporte.');
            return;
        }

        $id = DBConductoresCasos::guardar($form);
        $this->modal = false;
        $this->form = [];
        $this->eventos = [];
        $this->aviso = 'Caso #' . $id . ' guardado.';
        $this->resetPage();
    }

    private function filtros(): array
    {
        return [
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'llamada_tipo_id' => $this->llamada_tipo_id,
            'conductor' => $this->conductor,
            'tipo_caso' => $this->tipo_caso,
            'estado' => $this->estado,
        ];
    }

    public function render()
    {
        if ($this->estado === 'SIN_CASO') {
            $this->tab = 'candidatos';
        }

        DBConductoresCasos::set_filtro(new Request($this->filtros()));

        $candidatos = DBConductoresCasos::candidatos();
        $casos = DBConductoresCasos::casos();
        $resumen = DBConductoresCasos::resumen($candidatos, $casos);

        $total = count($candidatos);
        $paginas = max(1, (int) ceil($total / $this->por_pagina));
        $pagina = min(max(1, $this->getPage()), $paginas);
        $items = array_slice($candidatos, ($pagina - 1) * $this->por_pagina, $this->por_pagina);

        $paginador = new LengthAwarePaginator($items, $total, $this->por_pagina, $pagina, [
            'path' => Paginator::resolveCurrentPath(),
        ]);

        $llamadas = new DBLlamadas();

        return view('livewire.log-conductores.bandeja', [
            'candidatos' => $paginador,
            'casos' => $casos,
            'resumen' => $resumen,
            'tipos_llamada' => $llamadas::$tipos_llamada,
            'tipos_caso' => DBConductoresCasos::TIPOS,
            'estados' => DBConductoresCasos::ESTADOS,
            'resultados' => DBConductoresCasos::RESULTADOS,
            'motivos' => DBConductoresCasos::MOTIVOS,
            'reglas' => [
                'min' => DBConductoresCasos::MIN_LLAMADAS,
                'min_contestadas' => DBConductoresCasos::MIN_CONTESTADAS,
                'rechazo' => DBConductoresCasos::UMBRAL_RECHAZO,
                'buzon' => DBConductoresCasos::UMBRAL_BUZON,
                'dudoso' => DBConductoresCasos::UMBRAL_DUDOSO,
                'score' => DBConductoresCasos::SCORE_MINIMO,
            ],
        ]);
    }
}
