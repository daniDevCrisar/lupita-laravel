<?php

namespace App\Http\Controllers;

use App\Database\DBUsuarios;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * UsuariosController
 *
 * Pantalla de administracion de usuarios (`/lupita/usuarios`):
 * listar, crear, modificar y desactivar.
 *
 * REGLA DE ACCESO: entra el usuario 1 (el administrador) o cualquiera con el
 * permiso `administrar usuarios`. El control vive en `guardar_acceso()` y se
 * aplica en todas las acciones.
 */
class UsuariosController extends Controller
{
    /**
     * Listado de usuarios con roles, permisos y estado.
     */
    public function index()
    {
        $this->guardar_acceso();

        return view('lupita.usuarios.index', [
            'usuarios' => DBUsuarios::listar(),
            'roles' => DBUsuarios::roles_existentes(),
        ]);
    }

    /**
     * Formulario de alta.
     */
    public function crear()
    {
        $this->guardar_acceso();

        return view('lupita.usuarios.form', [
            'modo' => 'crear',
            'usuario' => null,
            'accion' => route('lupita.usuarios.guardar'),
            'roles' => DBUsuarios::roles_existentes(),
            'roles_info' => DBUsuarios::roles_con_permisos(),
            'roles_usuario' => [],
        ]);
    }

    /**
     * Alta de usuario.
     */
    public function guardar(Request $request)
    {
        $this->guardar_acceso();

        $datos = $request->validate([
            'name' => 'required|string|max:255|unique:users,name',
            'nombres' => 'required|string|max:80',
            'email' => 'nullable|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:100',
            'password_confirmation' => 'required|same:password',
            'roles' => 'nullable|array',
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $id = DBUsuarios::crear([
            'name' => trim($datos['name']),
            'nombres' => trim($datos['nombres']),
            'email' => $datos['email'] ?? '',
            'password' => $datos['password'],
        ], $this->roles_pedidos($request));

        return redirect()
            ->route('lupita.usuarios')
            ->with('exito', 'Usuario "' . $datos['name'] . '" creado (id ' . $id . ').');
    }

    /**
     * Formulario de edicion.
     */
    public function editar(int $id)
    {
        $this->guardar_acceso();

        $usuario = DBUsuarios::buscar($id);
        if (!$usuario) {
            abort(404, 'El usuario no existe.');
        }

        return view('lupita.usuarios.form', [
            'modo' => 'editar',
            'usuario' => $usuario,
            'accion' => route('lupita.usuarios.actualizar', $usuario['id']),
            'roles' => DBUsuarios::roles_existentes(),
            'roles_info' => DBUsuarios::roles_con_permisos(),
            'roles_usuario' => $usuario['roles'],
        ]);
    }

    /**
     * Edicion de usuario.
     */
    public function actualizar(Request $request, int $id)
    {
        $this->guardar_acceso();

        $usuario = DBUsuarios::buscar($id);
        if (!$usuario) {
            abort(404, 'El usuario no existe.');
        }

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($id)],
            'nombres' => 'required|string|max:80',
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => 'nullable|string|min:8|max:100',
            'password_confirmation' => 'nullable|same:password',
            'activo' => 'nullable|in:0,1',
            'roles' => 'nullable|array',
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        // el administrador no se puede desactivar a si mismo (evita dejar el
        // sistema sin quien lo administre)
        $activo = $id === DBUsuarios::ADMIN_ID
            ? 1
            : (int) ($datos['activo'] ?? 1);

        DBUsuarios::actualizar($id, [
            'name' => trim($datos['name']),
            'nombres' => trim($datos['nombres']),
            'email' => $datos['email'] ?? '',
            'password' => $datos['password'] ?? '',
            'activo' => $activo,
        ], $this->roles_pedidos($request));

        return redirect()
            ->route('lupita.usuarios')
            ->with('exito', 'Usuario "' . $datos['name'] . '" actualizado.');
    }

    /**
     * Activa o desactiva un usuario (no borra: deja el historial intacto).
     */
    public function alternar_activo(int $id)
    {
        $this->guardar_acceso();

        if ($id === DBUsuarios::ADMIN_ID) {
            return redirect()
                ->route('lupita.usuarios')
                ->with('error', 'No se puede desactivar el usuario administrador.');
        }

        $estado = DBUsuarios::alternar_activo($id);
        if (!$estado) {
            return redirect()
                ->route('lupita.usuarios')
                ->with('error', 'El usuario no existe.');
        }

        return redirect()
            ->route('lupita.usuarios')
            ->with('exito', $estado['activo'] === 1
                ? 'Usuario reactivado.'
                : 'Usuario desactivado: ya no puede iniciar sesion.');
    }

    /**
     * Acceso a la pantalla de usuarios: el administrador (usuario 1) o
     * cualquiera con el permiso `administrar usuarios` (hoy, solo admin).
     */
    private function guardar_acceso(): void
    {
        $es_admin = (int) auth()->id() === DBUsuarios::ADMIN_ID;
        $tiene_permiso = (bool) auth()->user()?->can('administrar usuarios');

        if (!$es_admin && !$tiene_permiso) {
            abort(403, 'Solo el usuario administrador puede administrar los usuarios.');
        }
    }

    /**
     * Roles pedidos en el formulario (solo los que existen en la tabla roles).
     */
    private function roles_pedidos(Request $request): array
    {
        $pedidos = $request->input('roles', []);
        if (!is_array($pedidos)) {
            return [];
        }

        return array_values(array_intersect(
            array_map(fn($r) => mb_strtolower(trim((string) $r)), $pedidos),
            DBUsuarios::roles_existentes()
        ));
    }
}
