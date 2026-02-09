// Variables globales
let fileInput;
let alerta_exito;
let alerta_error;

// Inicialización cuando el DOM está cargado
document.addEventListener('DOMContentLoaded', function() {
    // Referencias a elementos del DOM
    fileInput = document.getElementById('jsonFile'); //--------
    alerta_exito = document.getElementById('alerta_exito');
    alerta_error = document.getElementById('alerta_error');


    // Manejar selección de archivo
    fileInput.addEventListener('change', handleFileSelect);
    
    // Manejar arrastrar y soltar
    setupDragAndDrop();
});


// Manejar la selección de archivo
function handleFileSelect(e) {//------------
    const file = e.target.files[0];
    if (file) {
        loadFile(file);
    }
}

// Configurar arrastrar y soltar
function setupDragAndDrop() { //------------------
    const uploadArea = document.querySelector('.upload-area');
    
    // Prevenir comportamiento por defecto
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    // Efectos visuales al arrastrar
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, unhighlight, false);
    });
    
    // Manejar el archivo soltado
    uploadArea.addEventListener('drop', handleDrop, false);
    
    // Prevenir comportamientos por defecto
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Resaltar área de drop
    function highlight() {
        uploadArea.classList.add('drag-over');
    }
    
    // Quitar resaltado
    function unhighlight() {
        uploadArea.classList.remove('drag-over');
    }
    
    // Manejar archivo soltado
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const file = dt.files[0];
        
        if (file) {
            if (file.type === "application/json" || file.name.endsWith('.json')) {
                loadFile(file);
            } else {
                alert("Por favor, selecciona un archivo JSON válido.");
            }
        }
    }
}

// Cargar un archivo
function loadFile(file) {
    if (!isValidJSONFile(file)) {
        alert("Por favor, selecciona un archivo JSON válido.");
        return;
    }
    
    const reader = new FileReader();
    
    reader.onload = function(e) {
        try {
            
            const jsonData = JSON.parse(e.target.result);
            analisis = generar_excel_llamadas(jsonData);
            //mostrar alerta de éxito
            alerta_exito.classList.remove('d-none');
            alerta_error.classList.add('d-none');
            setTimeout(() => {
            alerta_exito.classList.add('d-none');
            }, 10000);
            //------------------

        } catch (error) {
            //mostrar alerta de error
            alerta_error.classList.remove('d-none');
            alerta_exito.classList.add('d-none');
            setTimeout(() => {
            alerta_error.classList.add('d-none');
            }, 10000);
            //------------------
        }
    };
    
    reader.readAsText(file);
}

// Verificar si el archivo es un JSON válido
function isValidJSONFile(file) {
    return file.type === "application/json" || file.name.endsWith('.json');
}

// Formatear tamaño de archivo
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}



function generarExcelDesdeArray(analisisArray) {
    // Verificar si SheetJS está cargado
    if (typeof XLSX === 'undefined') {
        console.error('SheetJS no está disponible. Incluye el CDN:');
        console.error('<script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>');
        alert('Error: La librería para Excel no está cargada');
        return false;
    }

    
    // Validar que el array no esté vacío
    if (analisisArray.length === 0) {
        console.error('Error: El array está vacío');
        alert('No hay datos para exportar');
        return false;
    }
    
    try {
        // Array para almacenar los datos procesados
        const datosProcesados = [];
        
        // Recorrer el array con forEach
        analisisArray.forEach((analisis, index) => {
            // Aquí puedes procesar o transformar cada objeto si es necesario
            const datoProcesado = {
                ...analisis
            };
            
            datosProcesados.push(datoProcesado);
            
            // Opcional: Log para depuración
            console.log(`Procesando análisis ${index + 1}:`, {
                id: analisis.id,
                type: analisis.type
            });
        });
        
        console.log(`Total de análisis procesados: ${datosProcesados.length}`);
        
        // Crear el libro de Excel
        const libro = XLSX.utils.book_new();
        
        // Convertir el array a hoja de cálculo
        const hoja = XLSX.utils.json_to_sheet(datosProcesados);
        
        // Agregar la hoja al libro
        XLSX.utils.book_append_sheet(libro, hoja, 'Análisis');
        
        // Generar nombre de archivo con fecha
        const fecha = new Date();
        const nombreArchivo = `analisis_${fecha.getFullYear()}-${(fecha.getMonth() + 1).toString().padStart(2, '0')}-${fecha.getDate().toString().padStart(2, '0')}_${fecha.getHours()}${fecha.getMinutes()}.xlsx`;
        
        // Descargar el archivo
        XLSX.writeFile(libro, nombreArchivo);
        
        console.log(`✅ Excel generado exitosamente: ${nombreArchivo}`);
        console.log(`📊 Registros exportados: ${datosProcesados.length}`);
        
        return true;
        
    } catch (error) {
        console.error('❌ Error al generar el Excel:', error);
        alert(`Error al generar el Excel: ${error.message}`);
        return false;
    }
}