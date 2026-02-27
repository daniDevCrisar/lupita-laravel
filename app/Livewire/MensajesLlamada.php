<?php

namespace App\Livewire;

use Livewire\Component;

class MensajesLlamada extends Component
{
    public $telefono;
    public $nombre;
    public $mensaje;
    public $showModal = false;

    // Sintaxis más simple para Livewire 3/4
    public function abrirMensaje($data)
    {
        $this->telefono = $data['telefono'];
        $this->nombre = $data['nombre'];
        $this->mensaje = $data['mensaje'];
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