function generar_excel_llamadas(json){
    var tabla='',analisis=[],archivo_excel=[];
    var vapi_ref,vapi_destino,vapi_origen,vapi_conductor,vapi_placa,vapi_mensajes,vapi_audio;
    var vapi_msj_conten,vapi_tlf,vapi_origen,vapi_fecha,vapi_v_prog=0,vapi_error_origen;
    var vapi_entro_llamada,vapi_conductor_no_contesta,vapi_conductor_cuelga,vapi_audio_duracion;

    const conversacion = new procesarTranscripcion();
    json.forEach((item, index) => {

        vapi_entro_llamada=item.analysis?.successEvaluation??''

        if (vapi_entro_llamada) vapi_entro_llamada= '1'
        else  vapi_entro_llamada= '0'

        //--------------obtener refencias-------------
        vapi_ref=
        item.assistantOverrides?.variableValues?.referencia??
        item.artifact?.variableValues?.referencia??
        item.artifact?.variables?.referencia??'';
        //-------------------------------------------
        vapi_destino=
        item.assistantOverrides?.variableValues?.destino??
        item.artifact?.variableValues?.destino??
        item.artifact?.variables?.destino??'';
        //--------------------------------------------
        vapi_origen=
        item.assistantOverrides?.variableValues?.origen??
        item.artifact?.variableValues?.origen??
        item.artifact?.variables?.origen??'';
        //---------fecha de compromiso-------------------
        vapi_fecha=item.assistantOverrides?.variableValues?.fecha_compromiso_carga??'';
        vapi_fecha=convertirFechaTextoAFecha(vapi_fecha);

        if (vapi_fecha=='') {
            vapi_fecha= item.analysis?.structuredData?.fecha_hora_compromiso_carga??'';
            vapi_fecha= vapi_fecha.replace("T", " ");
        }
        //-----------------------------------
        vapi_conductor=
        item.assistantOverrides?.variableValues?.driver_name??
        item.assistantOverrides?.variableValues?.nombre_transportista??
        item.artifact?.variableValues?.driver_name??
        item.artifact?.variables?.driver_name??'';


        //------------------------------------------------
        vapi_placa=
        item.assistantOverrides?.variableValues?.vehicle_plate??
        item.assistantOverrides?.variableValues?.placa??
        item.artifact?.variableValues?.vehicle_plate??
        item.artifact?.variables?.vehicle_plate??'';
        //----------------------------------------------
        vapi_mensajes=
        item.messages??
        item.artifact.messages??
        item.artifact.variables.messages??'';

        vapi_v_prog= 0;
        vapi_msj_conten=''
        if (vapi_mensajes){
            //listarPropiedadesSimples(vapi_mensajes)
            vapi_mensajes.forEach((msj , index)  => {
                //console.log('aqui')
                if (index >0){
                    try {
                        vapi_msj_conten += " //"+ msj.role + ': '+ msj.message;
                    }
                    catch (error) {console.log(msj.role)}
                }
            });
        }


        if (vapi_v_prog == 0){
            if (item.assistantId =="56f104ad-3e24-47dd-9cf8-1bd34bd95c81") vapi_v_prog= 1;
            else if (item.assistantId =="f6f40ed6-4cd0-4203-8631-b492f3b9e8d0") vapi_v_prog= 2;
            else if (item.assistantId =="3aa808fc-98b9-48fa-aa22-0f6465c47da2") vapi_v_prog= 3;
            else if (item.assistantId =="a6f9f813-a277-4b24-a84b-bc0bf016b625") vapi_v_prog= 5;
            else if (item.assistantId =="10ee0ccf-d688-4a61-b1f4-46ef7463325e") vapi_v_prog= 6;
        }


        vapi_audio=item.stereoRecordingUrl??'';
        vapi_tlf=item.customer.number??'';

        //eliminar caracteres no deseados
        if (vapi_placa) vapi_placa = vapi_placa.replaceAll(',', '');
        if (vapi_tlf) vapi_tlf= vapi_tlf.replaceAll('+', '');
        //--------------------------------
        const vapi_created_at=convertirSiEsFecha(formatearFechaISO(item.createdAt));
        //console.log(timestampJSaExcel(vapi_created_at));

        vapi_error_origen='';
        vapi_conductor_no_contesta='';
        vapi_conductor_cuelga='';
        if (item.endedReason=='twilio-failed-to-connect-call' || item.endedReason=='twilio-reported-customer-misdialed') vapi_error_origen=3;
        if (item.endedReason=='customer-did-not-answer') vapi_conductor_no_contesta=1;
        if (item.endedReason=='customer-ended-call') vapi_conductor_cuelga=1;


        vapi_audio_duracion= Math.round(Number(item.costs?.[0]?.minutes??0)*60);

        //analisar transcripcion----------
        conversacion.reiniciar()
        conversacion.procesar(vapi_msj_conten,vapi_audio_duracion,item.endedReason);

        //-------------------------------
        analisis = {
        id: item.id, //id en la plataforma de llamada
        type: item.type,
        created_at: vapi_created_at,
        created_at_excel: timestampJSaExcel(vapi_created_at) - (5/24), //restarle 5 horas
        llamada_tipo:vapi_v_prog,
        ref: vapi_ref,

        origen: vapi_origen,
        destino: vapi_destino,
        telefono: vapi_tlf,
        conductor: vapi_conductor,
        placa: vapi_placa,
        fecha_prometida: vapi_fecha,

        mensajes_conten: vapi_msj_conten,
        audio: vapi_audio,
        audio_duracion: vapi_audio_duracion,

        'exitosa_segun_ia': item.analysis?.successEvaluation??'false',
        entro_llamada: vapi_entro_llamada,
        razon_finalizacion: item.endedReason,
        razon_finalizacion_español: inglesAEspanol(item.endedReason),
        'transportista':'',
        'analisis_transcripcion': conversacion.analisis_transcripcion,
        'analisis_audio' : '',

        'conductor_confirma' : '',
        'buzon_de_voz': conversacion.buzon_de_voz,
        'conductor_contesta_pero_no_habla': conversacion.conductor_contesta_pero_no_habla,
        'conductor_no_escucha': '',
        'conductor_da_motivos' : '',
        'conductor_mala_señal': '',
        'confusion_en_llamada': '' ,
        'contesta_otra_persona': '',
        'numero_equivocado': '',
        'conversacion_fluida' :'',
        'llamada_interesante': '' ,

        'ia_se_confunde': '',
        'ia_no_escucha': '',
        'ia_cambio_de_datos': '',
        'ia_error_interpretacion': '', //cuando esta fuera de contexto
        'ia_dice_variable': '',
        'ia_mala_pronunciacion' :'',

        'conductor_cuelga' : vapi_conductor_cuelga,
        'conductor_no_contesta':vapi_conductor_no_contesta,
        'conductor_conducta_inapropiada': '',
        'error_tecnico_llamada': '',
        'error_audio' : '',
        'error_origen' : vapi_error_origen,
        'llamada_exitosa': '',
        };

        archivo_excel [index]=analisis
    });

    generarExcelDesdeArray(archivo_excel);
    return true;
}


function listarPropiedadesSimples(json) {
    console.log("📋 PROPIEDADES DEL JSON:");
    console.log("=" .repeat(40));

    for (let clave in json) {
        const tipo = Array.isArray(json[clave]) ? 'Array' : typeof json[clave];
        console.log(`• ${clave} (${tipo})`);
    }
}


function limitarTexto(texto, maxCaracteres = 200) {
    if (!texto) return "";
    if (texto.length <= maxCaracteres) return texto;
    return texto.substring(0, maxCaracteres) + "...";
}


function extraerHoraMinuto(texto) {
    // Busca patrones como: 19:00, 07:30, 14:45, etc.
    const regex = /(\d{1,2}):(\d{2})/;
    const match = texto.match(regex);

    if (match) {
        return `${match[1]}:${match[2]}`;
        //return {
            //hora: parseInt(match[1]),
            //minuto: parseInt(match[2]),
            //formato: `${match[1]}:${match[2]}`,
            //encontrado: true
        //};
    }

    return { encontrado: false };
}


function inglesAEspanol(textoIngles) {
    const diccionario = {
        // Mapeo exacto de los valores ingles → español
        "customer-ended-call": "Conductor finalizo llamada",
        "assistant-ended-call": "IA finalizo llamada",
        "customer-did-not-answer": "Conductor no contesto",
        "twilio-failed-to-connect-call": "Error de conexion de llamada",
        "twilio-reported-customer-misdialed": "Error tlf",
        "customer-busy": "Conductor ocupado",
    };

    return diccionario[textoIngles] || textoIngles;
}



// Función robusta para obtener el prompt de cualquier llamada
function obtenerPrompt(llamada) {
    // 1. Intentar desde messages principal
    if (llamada.messages && llamada.messages[0] && llamada.messages[0].message) {
        return llamada.messages[0].message;
    }

    // 2. Intentar desde artifact
    if (llamada.artifact && llamada.artifact.messages && llamada.artifact.messages[0]) {
        return llamada.artifact.messages[0].message;
    }

    // 3. Intentar desde messagesOpenAIFormatted
    if (llamada.artifact && llamada.artifact.messagesOpenAIFormatted) {
        const openAIMsgs = llamada.artifact.messagesOpenAIFormatted;
        const systemMsg = openAIMsgs.find(msg => msg.role === 'system');
        if (systemMsg) return systemMsg.content;
    }

    // 4. Si no hay prompt, devolver null o datos mínimos
    return null;
}

function formatearFechaISO(fechaISO) {
  const fecha = new Date(fechaISO);

  // Obtener partes de la fecha
  const año = fecha.getFullYear();
  const mes = String(fecha.getMonth() + 1).padStart(2, '0'); // Mes es 0-indexado
  const dia = String(fecha.getDate()).padStart(2, '0');
  const horas = String(fecha.getHours()).padStart(2, '0');
  const minutos = String(fecha.getMinutes()).padStart(2, '0');

  return `${año}-${mes}-${dia} ${horas}:${minutos}`;
}

function convertirFechaTextoAFecha(fechaTexto) {
  if (!fechaTexto) return fechaTexto;
  fecha_ano=true;
  try {
    // Verificar si contiene el año específico
    if (!fechaTexto.includes("dos mil veintiseis") &&
        !fechaTexto.includes("dos mil veintisiete") &&
        !fechaTexto.includes("dos mil veintiocho") )
        fecha_ano =false; // Devolver el mismo string si no encuentra el año

    if (!fecha_ano) return fechaTexto;
    // Reemplazar el año
    let fechaProcesada = fechaTexto.replace("de dos mil veintiseis", "2026");
    fechaProcesada = fechaProcesada.replace("de dos mil veintisiete", "2027");
    fechaProcesada = fechaProcesada.replace("de dos mil veintiocho", "2028");

    // Extraer día, mes y año
    const matchFecha = fechaProcesada.match(/(\d{1,2}) de (\w+) (\d{4})/);
    if (!matchFecha) return fechaTexto;

    const [, dia, mesTexto, año] = matchFecha;

    // Mapear mes español a número
    const meses = {
      enero: 1, febrero: 2, marzo: 3, abril: 4, mayo: 5, junio: 6,
      julio: 7, agosto: 8, septiembre: 9, octubre: 10, noviembre: 11, diciembre: 12
    };

    const mes = meses[mesTexto.toLowerCase()];
    if (!mes) return fechaTexto;

    // Extraer hora
    const matchHora = fechaProcesada.match(/(\d{1,2}):(\d{2}) de la (mañana|tarde|noche)/);
    if (!matchHora) return fechaTexto;

    let [, horas, minutos, periodo] = matchHora;
    let horaNum = parseInt(horas);

    // Ajustar hora según periodo
    if (periodo === "tarde" && horaNum < 12) horaNum += 12;
    if (periodo === "noche" && horaNum < 12) horaNum += 12;
    if (periodo === "mañana" && horaNum === 12) horaNum = 0; // 12:00 am = 00:00

    // Crear y formatear fecha
    const fecha = new Date(año, mes - 1, dia, horaNum, minutos);

    // Formatear a DD/MM/YYYY HH:mm
    const diaF = fecha.getDate().toString().padStart(2, '0');
    const mesF = (fecha.getMonth() + 1).toString().padStart(2, '0');
    const añoF = fecha.getFullYear();
    const horasF = fecha.getHours().toString().padStart(2, '0');
    const minutosF = fecha.getMinutes().toString().padStart(2, '0');

    return `${diaF}/${mesF}/${añoF} ${horasF}:${minutosF}`;
  } catch (error) {
    return fechaTexto; // Si hay algún error, devolver el string original
  }
}

function convertirSiEsFecha(fechaString) {
    try {
        if (typeof fechaString !== 'string') {
            return fechaString;
        }

        const str = fechaString.trim();
        if (!str) return str;

        // Verificar si es del formato específico YYYY-MM-DD HH:MM
        const formatoRegex = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/;
        const match = str.match(formatoRegex);

        if (!match) {
            return fechaString; // No es del formato esperado
        }

        // Extraer componentes y crear fecha manualmente
        const [_, anio, mes, dia, hora, minuto] = match.map(Number);

        // Validar rangos básicos
        if (mes < 1 || mes > 12 || dia < 1 || dia > 31 ||
            hora < 0 || hora > 23 || minuto < 0 || minuto > 59) {
            return fechaString;
        }

        // Crear fecha (meses son 0-11 en JavaScript)
        const fecha = new Date(anio, mes - 1, dia, hora, minuto, 0, 0);

        // Verificar si la fecha es válida (por ejemplo, 2026-02-30 sería inválido)
        if (isNaN(fecha.getTime())) {
            return fechaString;
        }

        // Devolver timestamp
        return fecha.getTime();

    } catch (error) {
        return fechaString;
    }
}


function timestampJSaExcel(timestamp) {
    try {
        const num = Number(timestamp);
        if (isNaN(num)) return timestamp;

        // Fórmula: (timestampJS / 86400000) + 25569
        // 86400000 = milisegundos en un día
        // 25569 = días de 1900-01-01 a 1970-01-01 en Excel
        return (num / 86400000) + 25569;

    } catch {
        return timestamp;
    }
}
