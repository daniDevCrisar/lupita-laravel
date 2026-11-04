<?php

namespace App\Http\Controllers\Auth;

use App\Database\DBLlamadaEtiquetar;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }
    public function username()
    {
        return 'name';
    }

    /**
     * Solo pueden entrar los usuarios activos: los desactivados desde la
     * pantalla de usuarios (users.activo = 0) no pasan la validacion.
     *
     * OJO: el metodo `credentials` viene del trait AuthenticatesUsers, no de
     * la clase padre, asi que no se puede llamar con `parent::`.
     */
    protected function credentials(Request $request)
    {
        return array_merge(
            $request->only($this->username(), 'password'),
            ['activo' => 1]
        );
    }

    //-----------------GENERAR TROFEOS PARA CADA CHOFER--------------
    protected function authenticated(Request $request, $user){
        // Verificar si ya se ejecutó hoy
//        DBLlamadaEtiquetar::generar_trofeos();
        $clave_cache = 'procesamiento_diario_' . now()->format('Y-m-d');

        if (!Cache::has($clave_cache)) {
            // Ejecutar en el primer login del dia
            DBLlamadaEtiquetar::generar_trofeos();
            Cache::put($clave_cache, true, now()->endOfDay());

        }

        return redirect()->intended($this->redirectPath());
    }


}
