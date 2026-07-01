<?php

namespace App\Livewire\LogConductores;

use App\Database\DBConductores;
use App\Database\DBLlamadas;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Http\Request;

class TablaConductores extends Component
{
    use WithPagination;
    protected $paginationTheme = 'bootstrap';

    // Props que recibe del PADRE
    public $fecha_inicio = '';
    public $fecha_fin = '';
    public $llamada_tipo_id = '';
    public $conductor = '';
    public $trt = '';
    public $ordenar_por = '';
    public $orden = '';
    public $reporte = false;


    public function updating($property, $value)
    {
        if (in_array($property, ['fecha_inicio', 'fecha_fin', 'llamada_tipo_id', 'conductor', 'trt', 'ordenar_por', 'orden', 'reporte'])) {
            $this->resetPage();
        }
    }

    public function nuevoLog($logData = null)
    {
        $this->dispatch('nuevoLog',$logData);
    }

    public function limpiarFiltros()
    {
        $this->reset(['fecha_inicio', 'fecha_fin', 'llamada_tipo_id', 'conductor', 'trt', 'ordenar_por', 'orden', 'reporte']);
        $this->resetPage();
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
        $conductores = new DBConductores();
        $conductores::set_filtro($request);
        $conductores = $conductores::lista_principal();

        return view('livewire.log-conductores.tabla-conductores', compact('conductores', 'llamadas','request'));
    }
}
