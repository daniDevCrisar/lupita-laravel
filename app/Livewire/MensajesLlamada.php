<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;


class MensajesLlamada extends Component
{
    public $telefono;
    public $nombre;
    public $vapi_id;
    public $mensajes;
    public $showModal = false;
    // Sintaxis más simple para Livewire 3/4
    #[On('abrirMensaje')] // <--- 2. ESTO ES OBLIGATORIO
    public function abrirMensaje($telefono, $nombre, $vapi_id)
    {
        $this->telefono = $telefono;
        $this->nombre = $nombre;
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
        return view('livewire.mensajes-llamada');
    }
}
