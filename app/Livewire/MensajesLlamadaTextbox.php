<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;


class MensajesLlamadaTextbox extends Component
{
    public $telefono;
    public $nombre;
    public $vapi_id;
    public $mensajes;
    public $showModal = false;
    // Sintaxis más simple para Livewire 3/4
    #[On('abrirMensajeTextbox')] // <--- 2. ESTO ES OBLIGATORIO
    public function abrirMensajeTextbox($vapi_id)
    {
        $this->vapi_id = $vapi_id;

        $this->showModal = true;

        $this->mensajes= DB::select('SELECT * FROM mensajes where vapi_id=? order by orden;', [$this->vapi_id]);
    }

    public function cerrar()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.mensajes-llamada-textbox');
    }
}
