<?php

namespace App\Livewire\LogConductores;

use App\Database\DBConductores;
use App\Database\DBConductoresLog;
use App\Database\DBLlamadas;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Http\Request;

class ListaLogs extends Component
{
    // Props que recibe del PADRE
    public $fecha_inicio = '';
    public $fecha_fin = '';
    public $llamada_tipo_id = '';
    public $conductor = '';
    public $trt = '';
    public $ordenar_por = '';
    public $orden = '';
    public $reporte = false;

    protected $listeners = ['updateRenderLogs' => 'updateRender'];

    public function editarLog($logData = null)
    {
        $this->dispatch('nuevoLog',$logData);
    }

    public function updateRender()
    {
        $this->render();
    }

    public function render()
    {
        // Construir request con los filtros actuales
        $request = new Request([
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'llamada_tipo_id' => $this->llamada_tipo_id,
            'conductor' => $this->conductor,
            'trt' => $this->trt,
            'ordenar_por' => $this->ordenar_por,
            'orden' => $this->orden,
            'reporte' => $this->reporte,
        ]);

        $llamadas = new DBLlamadas();
        $clog = new DBConductoresLog();
        $clog::set_filtro($request);
        $clog = $clog::allLogs();

        return view('livewire.log-conductores.tabla-logs-conductores', compact('clog', 'llamadas','request'));
    }
}



