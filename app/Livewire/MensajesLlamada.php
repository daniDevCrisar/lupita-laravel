<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;


class MensajesLlamada extends Component
{
    public $telefono;
    public $nombre;
    public $mensaje;
    public $showModal = false;

    // Sintaxis más simple para Livewire 3/4
    #[On('abrirMensaje')] // <--- 2. ESTO ES OBLIGATORIO
    public function abrirMensaje($telefono, $nombre, $mensaje)
    {
        $this->telefono = $telefono;
        $this->nombre = $nombre;
        $this->mensaje = $mensaje;
        $this->showModal = true;
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