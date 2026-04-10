<script>
    function etiquetas_actualizar_label() {
        let seleccionadas = [];
        document.querySelectorAll('input[name="etiquetas[]"]:checked').forEach(cb => {
            let label = document.querySelector(`label[for="${cb.id}"]`);
            if (label) seleccionadas.push(label.innerText);
        });
        let operador = document.querySelector(('input[name="e_operador"]:checked')).value;
        let header = document.getElementById('acordeon_head');

        let txt_operador = 'Todas';
        if (operador == '1')
            txt_operador = 'Al menos una';

        if (seleccionadas.length)
            header.innerHTML = `${txt_operador}: ${seleccionadas.length} seleccionada${seleccionadas.length !== 1 ? 's' : ''}`;
        else
            header.innerHTML = txt_operador + ': sin seleccion';
    }

    // Eventos
    document.querySelectorAll('input[name="etiquetas[]"]').forEach(cb => {
        cb.addEventListener('change', etiquetas_actualizar_label);
    });
    document.querySelectorAll('input[name="e_operador"]').forEach(rd => {
        rd.addEventListener('change', etiquetas_actualizar_label);
    });

    // Ejecutar al inicio
    etiquetas_actualizar_label();
</script>
