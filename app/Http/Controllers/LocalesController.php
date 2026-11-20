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
     *
     * Acepta `nombre` y `palabra_clave` por query para que /lupita/ubicaciones
     * pueda mandar un candidato ya prellenado ("Crear local" desde el ranking de
     * palabras repetidas). Se trunca a 100, que es el largo de la columna.
     */
    public function crear(Request $request)
    {
        return view('lupita.locales.form', [
            'modo'     => 'crear',
            'local'    => (object) [
                'id'              => '',
                'cliente_id'      => '',
                'nombre'          => mb_substr($this->texto_query($request, 'nombre'), 0, 100),
                'palabra_clave'   => mb_substr($this->texto_query($request, 'palabra_clave'), 0, 100),
                'distrito_ubigeo' => '',
                'activo'          => 1,
            ],
            'accion'   => route('lupita.locales.guardar'),
            'clientes' => DBLocales::clientes(),
            'ubigeo'   => DBLocales::ubigeo(),
        ]);
    }

    /**
     * Un parametro de query como texto seguro.
     *
     * `(string) $request->query(...)` revienta con "Array to string conversion" si
     * el parametro llega como arreglo (`?nombre[]=x`).
     */
    private function texto_query(Request $request, string $clave): string
    {
        $valor = $request->query($clave, '');

        return is_string($valor) ? trim($valor) : '';
    }

    /**
     * Formulario de edicion.
     *
     * Acepta `variante` por query: es lo que manda el boton "Variante" de
     * /lupita/ubicaciones/palabras, que agrega la frase candidata al final de
     * `palabra_clave` (separada por `|`) para que el usuario solo confirme.
     */
    public function editar(Request $request, string $id)
    {
        $local = DBLocales::buscar($id);
        if (!$local) {
            abort(404, 'El local no existe.');
        }

        $variante = $this->texto_query($request, 'variante');
        if ($variante !== '') {
            $actual = trim((string) $local->palabra_clave);
            $variantes = array_filter(array_map('trim', explode('|', $actual)));
            if (!in_array($variante, $variantes, true)) {
                $local->palabra_clave = mb_substr($actual . ($actual !== '' ? '|' : '') . $variante, 0, 100);
            }
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
            // coordenadas: se aceptan con coma decimal ("-11,1063") porque asi se
            // copian de Google Maps en es-CL/ES
            'lat'             => str_replace(',', '.', trim((string) $request->input('lat'))),
            'lng'             => str_replace(',', '.', trim((string) $request->input('lng'))),
            'geo_texto'       => trim((string) $request->input('geo_texto')),
            'geo_fuente'      => trim((string) $request->input('geo_fuente')),
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
            // o las dos coordenadas o ninguna (required_with en cada lado)
            'lat'             => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'             => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'geo_texto'       => ['nullable', 'string', 'max:200'],
            'geo_fuente'      => ['nullable', 'in:maps,manual'],
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
            'lat.numeric'             => 'La latitud tiene que ser un numero.',
            'lat.between'             => 'La latitud tiene que estar entre -90 y 90.',
            'lat.required_with'       => 'Si cargas la longitud, falta la latitud.',
            'lng.numeric'             => 'La longitud tiene que ser un numero.',
            'lng.between'             => 'La longitud tiene que estar entre -180 y 180.',
            'lng.required_with'       => 'Si cargas la latitud, falta la longitud.',
            'geo_fuente.in'           => 'El origen de las coordenadas no es valido.',
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
