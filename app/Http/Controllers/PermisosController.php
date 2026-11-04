<?php

namespace App\Http\Controllers;

use App\Database\DBPermisos;
use App\Database\DBUsuarios;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PermisosController
 *
 * Pantalla de permisos por rol (`/lupita/permisos`): decide que modulos del
 * panel ve cada rol (admin / editor / invitado).
 *
 * REGLA DE ACCESO: entra el usuario 1 (el administrador) o cualquiera con el
 * permiso `administrar usuarios`, igual que la pantalla de usuarios.
 *
 * SEGURIDAD: el rol del administrador siempre conserva el permiso
 * `administrar usuarios`. Si se quitara, el usuario 1 se quedaria sin acceso
 * ni a esta pantalla ni a la de usuarios, y el sistema quedaria sin nadie que
 * administre los permisos.
 */
class PermisosController extends Controller
{
    /**
     * Listado de roles con sus permisos, agrupados por tipo de accion.
     */
    public function index()
    {
        $this->guardar_acceso();

        return view('lupita.permisos.roles', [
            'grupos' => DBPermisos::grupos(),
            'roles' => DBPermisos::roles(),
            'total_permisos' => count(DBPermisos::todos()),
            'roles_protegidos' => $this->roles_protegidos(),
        ]);
    }

    /**
     * Guarda los permisos marcados de un rol.
     */
    public function guardar(Request $request)
    {
        $this->guardar_acceso();

        $datos = $request->validate([
            'rol' => ['required', 'string', Rule::exists('roles', 'name')],
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $rol = $datos['rol'];
        $pedidos = $datos['permisos'] ?? [];

        // el rol del administrador no puede perder `administrar usuarios`
        if (in_array($rol, $this->roles_protegidos(), true)
            && !in_array(DBPermisos::ADMINISTRAR_USUARIOS, $pedidos, true)) {
            $pedidos[] = DBPermisos::ADMINISTRAR_USUARIOS;
        }

        $total = DBPermisos::guardar_rol($rol, $pedidos);

        return redirect()
            ->route('lupita.permisos')
            ->with('exito', 'Rol "' . $rol . '" actualizado: ' . $total . ' permisos.');
    }

    /**
     * Acceso a la pantalla de permisos: el administrador (usuario 1) o
     * cualquiera con el permiso `administrar usuarios`.
     */
    private function guardar_acceso(): void
    {
        $es_admin = (int) auth()->id() === DBUsuarios::ADMIN_ID;
        $tiene_permiso = (bool) auth()->user()?->can('administrar usuarios');

        if (!$es_admin && !$tiene_permiso) {
            abort(403, 'Solo el usuario administrador puede modificar los permisos por rol.');
        }
    }

    /**
     * Roles del usuario administrador: esos roles no pueden quedarse sin el
     * permiso `administrar usuarios`.
     */
    private function roles_protegidos(): array
    {
        $admin = User::find(DBUsuarios::ADMIN_ID);

        return $admin ? $admin->getRoleNames()->toArray() : [];
    }
}
