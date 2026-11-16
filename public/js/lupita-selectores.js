/*
 * Selectores de Lupita: un solo componente de búsqueda reutilizable.
 *
 * Reemplaza las tres implementaciones que había (el `buscadorDeSelect` de
 * tiempos/horario, los `data-combo` de tiempos/promedio y los <select> pelados de
 * las pantallas /ver y de referencias).
 *
 * Se usa desde el partial `resources/views/lupita/tools/selector.blade.php` y no
 * necesita nada del servidor: recibe las opciones ya pintadas en el HTML y las
 * filtra en el navegador.
 *
 *   <div data-selector="simple">   -> un valor; el elegido viaja en un input hidden
 *   <div data-selector="multiple"> -> varios valores; viajan como name[]
 *
 * Atributos que entiende `simple`:
 *   data-enviar="1"   envía el formulario al elegir (como hacía el selector de
 *                     conductor en tiempos/horario)
 *   data-abrir="1"    deja el desplegable abierto (para múltiples campos seguidos)
 *
 * Expone `window.LupitaSelectores.init(raiz)` para volver a enganchar después de
 * pintar HTML nuevo (no hay que llamarlo si la página ya cargó: se engancha solo).
 */
(function () {
    'use strict';

    /** Texto comparable: minúsculas y sin tildes. */
    function normalizar(texto) {
        return String(texto == null ? '' : texto)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    /** Cierra el desplegable de Bootstrap de un botón, si está abierto. */
    function cerrar(boton) {
        if (!boton || !window.bootstrap || !window.bootstrap.Dropdown) {
            return;
        }
        var instancia = window.bootstrap.Dropdown.getInstance(boton);
        if (instancia) {
            instancia.hide();
        }
    }

    /**
     * Selector de UN valor.
     *
     * No usamos <select> porque el buscador nativo del navegador no filtra por
     * texto en todos lados y no se puede estilar. Todo el estado vive en el input
     * hidden, así que el formulario de la pantalla sigue funcionando igual que con
     * un <select> (mismo `name`, mismo `.value`).
     */
    function iniciarSimple(contenedor) {
        if (contenedor.dataset.listo === '1') {
            return;
        }
        contenedor.dataset.listo = '1';

        var boton = contenedor.querySelector('[data-bs-toggle="dropdown"]');
        var buscar = contenedor.querySelector('[data-buscar]');
        var lista = contenedor.querySelector('[data-lista]');
        var vacio = contenedor.querySelector('[data-vacio]');
        var texto = contenedor.querySelector('[data-texto]');
        var oculto = contenedor.querySelector('input[type="hidden"]');
        var contador = contenedor.querySelector('[data-contador]');

        if (!boton || !lista || !oculto) {
            return;
        }

        var opciones = Array.prototype.slice.call(lista.querySelectorAll('[data-valor]'));

        function marcar(valor) {
            opciones.forEach(function (opcion) {
                opcion.classList.toggle('active', opcion.dataset.valor === valor);
            });
        }

        function filtrar() {
            var consulta = normalizar(buscar ? buscar.value : '');
            var visibles = 0;

            opciones.forEach(function (opcion) {
                var textoOpcion = normalizar(opcion.dataset.buscar || opcion.textContent);
                var visible = consulta === '' || textoOpcion.indexOf(consulta) !== -1;
                opcion.hidden = !visible;
                if (visible) {
                    visibles++;
                }
            });

            if (vacio) {
                vacio.hidden = visibles > 0;
            }
            if (contador) {
                contador.textContent = consulta === ''
                    ? opciones.length + ' opciones'
                    : visibles + ' de ' + opciones.length + ' opciones';
            }
        }

        function elegir(valor, etiqueta) {
            oculto.value = valor;
            if (texto && etiqueta !== undefined) {
                texto.textContent = etiqueta;
            }
            marcar(valor);

            // los formularios que escuchan `change` sobre el campo siguen andando
            oculto.dispatchEvent(new Event('change', { bubbles: true }));

            if (contenedor.dataset.enviar === '1') {
                var formulario = contenedor.closest('form');
                if (formulario) {
                    if (typeof formulario.requestSubmit === 'function') {
                        formulario.requestSubmit();
                    } else {
                        formulario.submit();
                    }
                }

                return;
            }

            if (contenedor.dataset.abrir !== '1') {
                cerrar(boton);
            }
        }

        opciones.forEach(function (opcion) {
            opcion.addEventListener('click', function (evento) {
                evento.preventDefault();
                elegir(opcion.dataset.valor, opcion.dataset.etiqueta || opcion.textContent.trim());
            });
        });

        if (buscar) {
            buscar.addEventListener('input', filtrar);
            buscar.addEventListener('keydown', function (evento) {
                if (evento.key === 'Escape') {
                    buscar.value = '';
                    filtrar();
                }
            });
        }

        // al abrir: la lista arranca sin filtro y con el foco en el buscador
        if (boton) {
            boton.addEventListener('shown.bs.dropdown', function () {
                if (buscar) {
                    buscar.value = '';
                    filtrar();
                    buscar.focus();
                }
            });
        }

        marcar(oculto.value);
        filtrar();

        // un `form.reset()` (botón "Limpiar") devuelve el input hidden a su valor
        // inicial, pero no el texto del botón: hay que volver a pintarlo.
        var formulario = contenedor.closest('form');
        if (formulario && texto) {
            var textoInicial = texto.textContent;
            formulario.addEventListener('reset', function () {
                window.setTimeout(function () {
                    marcar(oculto.value);
                    var elegido = opciones.filter(function (opcion) {
                        return opcion.dataset.valor === oculto.value;
                    })[0];
                    texto.textContent = elegido
                        ? (elegido.dataset.etiqueta || elegido.textContent.trim())
                        : textoInicial;
                    filtrar();
                }, 0);
            });
        }
    }

    /**
     * Selector de VARIOS valores (las rutas de tiempos/horario).
     *
     * Cada opción marcada se refleja en un input hidden `name[]` dentro de
     * `[data-ocultos]`, y el texto del botón resume la selección. Los botones
     * "todas" y "ninguna" viven dentro de la lista con `data-accion`.
     */
    function iniciarMultiple(contenedor) {
        if (contenedor.dataset.listo === '1') {
            return;
        }
        contenedor.dataset.listo = '1';

        var boton = contenedor.querySelector('[data-bs-toggle="dropdown"]');
        var buscar = contenedor.querySelector('[data-buscar]');
        var lista = contenedor.querySelector('[data-lista]');
        var vacio = contenedor.querySelector('[data-vacio]');
        var texto = contenedor.querySelector('[data-texto]');
        var contador = contenedor.querySelector('[data-contador]');
        var ocultos = contenedor.querySelector('[data-ocultos]');
        var nombre = contenedor.dataset.nombre || 'valor[]';

        if (!boton || !lista || !ocultos) {
            return;
        }

        var casillas = Array.prototype.slice.call(lista.querySelectorAll('input[type="checkbox"]'));

        function elegidas() {
            return casillas.filter(function (casilla) { return casilla.checked; });
        }

        function sincronizar() {
            var marcadas = elegidas();

            ocultos.innerHTML = '';
            marcadas.forEach(function (casilla) {
                var campo = document.createElement('input');
                campo.type = 'hidden';
                campo.name = nombre;
                campo.value = casilla.value;
                ocultos.appendChild(campo);
            });

            if (texto) {
                if (marcadas.length === 0) {
                    texto.textContent = contenedor.dataset.todas || 'Todas (' + casillas.length + ')';
                } else if (marcadas.length === 1) {
                    texto.textContent = '1 seleccionada';
                } else {
                    texto.textContent = marcadas.length + ' seleccionadas';
                }
            }

            if (contador) {
                contador.textContent = marcadas.length + ' de ' + casillas.length + ' marcadas';
            }

            marcarTocadas(marcadas.length > 0);
        }

        /** Avisa a otros selectores (el de rutas del conductor) que hubo cambios. */
        function marcarTocadas(hay) {
            var campo = document.getElementById(contenedor.dataset.tocadas || '');
            if (campo) {
                campo.value = hay ? '1' : '';
            }
        }

        function filtrar() {
            var consulta = normalizar(buscar ? buscar.value : '');
            var visibles = 0;

            casillas.forEach(function (casilla) {
                var fila = casilla.closest('[data-opcion]') || casilla.parentElement;
                var textoFila = normalizar(casilla.dataset.buscar || fila.textContent);
                var visible = consulta === '' || textoFila.indexOf(consulta) !== -1;
                fila.hidden = !visible;
                if (visible) {
                    visibles++;
                }
            });

            if (vacio) {
                vacio.hidden = visibles > 0;
            }
            if (contador) {
                contador.textContent = elegidas().length + ' de ' + casillas.length + ' marcadas';
            }
        }

        casillas.forEach(function (casilla) {
            casilla.addEventListener('change', sincronizar);
        });

        Array.prototype.slice.call(lista.querySelectorAll('[data-accion]')).forEach(function (accion) {
            accion.addEventListener('click', function (evento) {
                evento.preventDefault();
                var marcarTodas = accion.dataset.accion === 'todas';
                casillas.forEach(function (casilla) {
                    var fila = casilla.closest('[data-opcion]') || casilla.parentElement;
                    if (fila.hidden) {
                        return;     // "todas" respeta el filtro de búsqueda
                    }
                    casilla.checked = marcarTodas;
                });
                sincronizar();
            });
        });

        if (buscar) {
            buscar.addEventListener('input', filtrar);
        }
        if (boton) {
            boton.addEventListener('shown.bs.dropdown', function () {
                if (buscar) {
                    buscar.value = '';
                    filtrar();
                    buscar.focus();
                }
            });
        }

        sincronizar();
        filtrar();

        // `form.reset()` devuelve las casillas a su estado inicial: hay que
        // recalcular los inputs hidden y el texto del botón.
        var formulario = contenedor.closest('form');
        if (formulario) {
            formulario.addEventListener('reset', function () {
                window.setTimeout(function () {
                    casillas.forEach(function (casilla) {
                        casilla.checked = casilla.defaultChecked;
                    });
                    sincronizar();
                    filtrar();
                }, 0);
            });
        }
    }

    function init(raiz) {
        var ambito = raiz || document;
        Array.prototype.slice.call(ambito.querySelectorAll('[data-selector="simple"]')).forEach(iniciarSimple);
        Array.prototype.slice.call(ambito.querySelectorAll('[data-selector="multiple"]')).forEach(iniciarMultiple);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    window.LupitaSelectores = { init: init, normalizar: normalizar };
})();
