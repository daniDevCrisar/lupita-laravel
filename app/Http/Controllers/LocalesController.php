<?php

namespace App\Http\Controllers;

use App\Database\DBLocales;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LocalesController
 *
 * Catalogo de locales/plantas (`/lupita/locales`): listado, alta y edicion.
 *
 * REGLA DE ACCESO: es un catalogo de apoyo (lo usan tiempos, plantas y
 * reportes), asi que queda reservado al rol `admin`. El control vive en las
 * rutas (`middleware('role:admin')`), no en el controlador.
 *
 * El ID del local no se edita: es la clave con la que los viajes apuntan al
 * local (`rutas.origen_id` / `rutas.destino_id`). Para "renombrarlo" hay que
 * dar de alta otro local.
 */
class LocalesController extends Controller
{
    /**
     * Listado de locales con cliente y ubicacion resueltos.
     */
    public function index()
    {
        return view('lupita.locales.index', [
            'locales'  => DBLocales::listar(),
            'clientes' => DBLocales::clientes(),
        ]);
    }

    /**
     * Formulario de alta.
     */
    public function crear()
    {
        return view('lupita.locales.form', [
            'modo'     => 'crear',
            'local'    => null,
            'accion'   => route('lupita.locales.guardar'),
            'clientes' => DBLocales::clientes(),
            'ubigeo'   => DBLocales::ubigeo(),
        ]);
    }

    /**
     * Formulario de edicion.
     */
    public function editar(string $id)
    {
        $local = DBLocales::buscar($id);
        if (!$local) {
            abort(404, 'El local no existe.');
        }

        return view('lupita.locales.form', [
            'modo'     => 'editar',
            'local'    => $local,
            'accion'   => route('lupita.locales.actualizar', $local->id),
            'clientes' => DBLocales::clientes(),
            'ubigeo'   => DBLocales::ubigeo(),
        ]);
    }

    /**
     * Alta del local.
     */
    public function guardar(Request $request)
    {
        $this->normalizar($request);

        $datos = $request->validate(
            $this->reglas(),
            $this->mensajes(),
            $this->atributos()
        );

        DBLocales::crear($datos);

        return redirect()
            ->route('lupita.locales')
            ->with('exito', 'Local "' . $datos['nombre'] . '" creado (id ' . $datos['id'] . ').');
    }

    /**
     * Edicion del local (sin tocar el id).
     */
    public function actualizar(Request $request, string $id)
    {
        $local = DBLocales::buscar($id);
        if (!$local) {
            abort(404, 'El local no existe.');
        }

        $this->normalizar($request);

        $datos = $request->validate(
            $this->reglas($local->id),
            $this->mensajes(),
            $this->atributos()
        );

        DBLocales::actualizar($local->id, $datos);

        return redirect()
            ->route('lupita.locales')
            ->with('exito', 'Local "' . $datos['nombre'] . '" actualizado.');
    }

    /**
     * Se normaliza antes de validar: el id del local va en mayusculas y sin
     * espacios (ej: LOC_BABEL_MAXO) y `unique` compara el valor final.
     */
    private function normalizar(Request $request): void
    {
        $request->merge([
            'id'              => strtoupper(trim((string) $request->input('id'))),
            'cliente_id'      => trim((string) $request->input('cliente_id')),
            'nombre'          => trim((string) $request->input('nombre')),
            'palabra_clave'   => trim((string) $request->input('palabra_clave')),
            'distrito_ubigeo' => trim((string) $request->input('distrito_ubigeo')),
        ]);
    }

    /**
     * Reglas de validacion. Al editar se omite el id (no se puede cambiar) y
     * por eso tampoco se valida.
     */
    private function reglas(?string $id_existente = null): array
    {
        $reglas = [
            'cliente_id'      => ['required', 'string', 'max:50', Rule::exists('clientes', 'id')],
            'nombre'          => ['required', 'string', 'max:100'],
            'palabra_clave'   => ['required', 'string', 'max:100'],
            'distrito_ubigeo' => ['nullable', 'string', 'size:6', Rule::exists('ubigeo_distritos', 'ubigeo')],
            'activo'          => ['nullable', 'in:0,1'],
        ];

        if ($id_existente === null) {
            $reglas = [
                'id' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', 'unique:locales,id'],
            ] + $reglas;
        }

        return $reglas;
    }

    /**
     * Mensajes de validacion en espanol.
     */
    private function mensajes(): array
    {
        return [
            'id.required'             => 'El ID del local es obligatorio.',
            'id.max'                  => 'El ID no puede pasar de 50 caracteres.',
            'id.regex'                => 'El ID solo admite letras, numeros, guion y guion bajo (ej: LOC_BABEL_MAXO).',
            'id.unique'               => 'Ya existe un local con ese ID.',
            'cliente_id.required'     => 'Selecciona el cliente al que pertenece el local.',
            'cliente_id.exists'       => 'El cliente elegido no existe.',
            'nombre.required'         => 'El nombre del local es obligatorio.',
            'nombre.max'              => 'El nombre no puede pasar de 100 caracteres.',
            'palabra_clave.required'  => 'La palabra clave es obligatoria.',
            'palabra_clave.max'       => 'La palabra clave no puede pasar de 100 caracteres.',
            'distrito_ubigeo.size'    => 'El ubigeo del distrito debe tener 6 digitos.',
            'distrito_ubigeo.exists'  => 'El distrito elegido no existe en el ubigeo.',
            'activo.in'               => 'El estado solo puede ser activo o inactivo.',
        ];
    }

    /**
     * Nombres legibles de los campos en los mensajes.
     */
    private function atributos(): array
    {
        return [
            'id'              => 'ID',
            'cliente_id'      => 'cliente',
            'nombre'          => 'nombre',
            'palabra_clave'   => 'palabra clave',
            'distrito_ubigeo' => 'distrito',
            'activo'          => 'estado',
        ];
    }
}
