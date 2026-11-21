<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBClientes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ubicaciones\ClientesController
 *
 * Pantalla "Clientes" (/lupita/ubicaciones/clientes): el catalogo del que cuelgan los
 * locales. Listado, alta y edicion.
 *
 * REGLA DE ACCESO: es catalogo de apoyo, `role:admin` en la ruta.
 *
 * El id NO se edita (los locales apuntan a el) y no hay borrado: si un cliente tiene
 * locales, borrarlo los dejaria huerfanos.
 */
class ClientesController extends Controller
{
    public function index()
    {
        return view('lupita.ubicaciones.clientes', [
            'clientes' => DBClientes::listar(),
        ]);
    }

    public function crear()
    {
        return view('lupita.ubicaciones.cliente_form', [
            'modo'     => 'crear',
            'cliente'  => null,
            'accion'   => route('lupita.ubicaciones.clientes.guardar'),
            'tipos'    => DBClientes::tipos(),
            'locales'  => [],
        ]);
    }

    public function editar(string $id)
    {
        $cliente = DBClientes::buscar($id);
        if (!$cliente) {
            abort(404, 'El cliente no existe.');
        }

        return view('lupita.ubicaciones.cliente_form', [
            'modo'     => 'editar',
            'cliente'  => $cliente,
            'accion'   => route('lupita.ubicaciones.clientes.actualizar', $cliente->id),
            'tipos'    => DBClientes::tipos(),
            'locales'  => DBClientes::localesDe($cliente->id),
        ]);
    }

    public function guardar(Request $request)
    {
        $this->normalizar($request);

        $datos = $request->validate($this->reglas(), $this->mensajes(), $this->atributos());

        DBClientes::crear($datos);

        return redirect()
            ->route('lupita.ubicaciones.clientes')
            ->with('exito', 'Cliente "' . $datos['nombre'] . '" creado (id ' . $datos['id'] . ').');
    }

    public function actualizar(Request $request, string $id)
    {
        $cliente = DBClientes::buscar($id);
        if (!$cliente) {
            abort(404, 'El cliente no existe.');
        }

        $this->normalizar($request);

        $datos = $request->validate($this->reglas($cliente->id), $this->mensajes(), $this->atributos());

        DBClientes::actualizar($cliente->id, $datos);

        return redirect()
            ->route('lupita.ubicaciones.clientes')
            ->with('exito', 'Cliente "' . $datos['nombre'] . '" actualizado.');
    }

    /**
     * El id se guarda en mayusculas y sin espacios (ej: CLI_AJEPER).
     */
    private function normalizar(Request $request): void
    {
        $request->merge([
            'id'            => strtoupper(trim((string) $request->input('id'))),
            'nombre'        => trim((string) $request->input('nombre')),
            'tipo'          => trim((string) $request->input('tipo')),
            'descripcion'   => trim((string) $request->input('descripcion')),
            'palabra_clave' => trim((string) $request->input('palabra_clave')),
        ]);
    }

    /**
     * Al editar, el id no se valida: no se puede cambiar.
     */
    private function reglas(?string $id_existente = null): array
    {
        $reglas = [
            'nombre'        => ['required', 'string', 'max:100'],
            'tipo'          => ['required', 'string', 'max:50'],
            'descripcion'   => ['nullable', 'string', 'max:1000'],
            'palabra_clave' => ['nullable', 'string', 'max:100'],
        ];

        if ($id_existente === null) {
            $reglas = [
                'id' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', 'unique:clientes,id'],
            ] + $reglas;
        }

        return $reglas;
    }

    private function mensajes(): array
    {
        return [
            'id.required'       => 'El ID del cliente es obligatorio.',
            'id.max'            => 'El ID no puede pasar de 50 caracteres.',
            'id.regex'          => 'El ID solo admite letras, números, guion y guion bajo (ej: CLI_AJEPER).',
            'id.unique'         => 'Ya existe un cliente con ese ID.',
            'nombre.required'   => 'El nombre del cliente es obligatorio.',
            'nombre.max'        => 'El nombre no puede pasar de 100 caracteres.',
            'tipo.required'     => 'El tipo de cliente es obligatorio.',
            'tipo.max'          => 'El tipo no puede pasar de 50 caracteres.',
            'descripcion.max'   => 'La descripción no puede pasar de 1000 caracteres.',
            'palabra_clave.max' => 'La palabra clave no puede pasar de 100 caracteres.',
        ];
    }

    private function atributos(): array
    {
        return [
            'id'            => 'ID',
            'nombre'        => 'nombre',
            'tipo'          => 'tipo',
            'descripcion'   => 'descripción',
            'palabra_clave' => 'palabra clave',
        ];
    }
}
