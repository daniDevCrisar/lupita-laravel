class procesarTranscripcion{
    conversacion={};

    analisis_transcripcion='';
    buzon_de_voz='';
    conductor_contesta_pero_no_habla=''

    reiniciar(){
        this.conversacion={};
        this.analisis_transcripcion='';
        this.buzon_de_voz='';
        this.conductor_contesta_pero_no_habla='';
    }
    procesar(texto,duracion,razonf){
        if (!this.obtener_conversacion(texto)) return false

        if (this.conversacion.user.total <=0) {
            if (razonf==='assistant-ended-call'){
                this.conductor_contesta_pero_no_habla=1;
                this.analisis_transcripcion='NO HABLA';
            }
            if ( razonf==='customer-ended-call'){
                if (duracion <20 ) this.analisis_transcripcion='CUELGA';
                else{
                    this.conductor_contesta_pero_no_habla=1;
                    this.analisis_transcripcion='NO HABLA';
                }
            }
            return true
        }

        //texto solo del conductor--------------
        let user_txt;
        this.conversacion.user.msjs.forEach(function (msjs) {
            user_txt+= msjs[1] + ' ';
        })

        if(this.es_buzon(user_txt)){
            this.buzon_de_voz= 1;
            this.analisis_transcripcion='BUZON';
            return true
        }

        if (this.sintaxis(user_txt)){
            return true;
        }


    }

    obtener_conversacion(texto){
        if (texto.trim()==='') return false
        texto=this.normalizar(texto);

        let m_bot=[],m_user=[],conversacion=[];
        let mensajes=texto.split('//');
        let msj;
        let count=0;
        mensajes.forEach(function (msjs,index){
            msjs=msjs.trim();
            if (msjs!==''){
                msj= msjs.split(':');
                msj[1]= msj[1].trim();
                if ((msj[0] ==='USER' || msj[0] ==='BOT') && msj[1]!=='' ){
                    conversacion.push([count,msj[0],msj[1]]);
                    if (msj[0] ==='USER') m_user.push([count,msj[1]]);
                    else  m_bot.push([count,msj[1]]);
                    count++;
                }
            }
        });

        if (!count) return false
        this.conversacion= {
            total: count,
            msjs : conversacion,
            bot: {
                total: m_bot.length,
                msjs: m_bot
            },
            user: {
                total: m_user.length,
                msjs: m_user
            }
        }
        return true
    }

    normalizar(texto) {
        return texto
            .trim()
            .toUpperCase() // USER / BOT consistente
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/\s+/g, " ")
            .replace(/[?,¿]/g, "")//ERROR?----------------------
    }

    es_buzon(texto) {
        if (!texto || texto.trim() === '') {
            return false;
        }

        // Frases FUERTES
        const fuertes = [
            'BUZON DE VOZ',
            'DEJE SU MENSAJE',
            'DESPUES DEL TONO',
            'GRABE SU MENSAJE',
            'CASILLA DE VOZ',
            'VOICE MAIL',
            'VOICEMAIL',
            'TIEMPO LIMITE',
            'CON EL BUZON',
        ];

        for (let f of fuertes) {
            if (texto.includes(f)) {
                return true;
            }
        }

        // Frases MEDIAS
        const medias = [
            'NO ESTA DISPONIBLE',
            'NO SE ENCUENTRA DISPONIBLE',
            'NO PUEDE ATENDER',
            'NO PUEDE CONTESTAR',
            'NO PUEDE RESPONDER',
            'INTENTE MAS TARDE',
            'NO SE PUEDE COMPLETAR SU LLAMADA',
            'EL NUMERO AL QUE LLAMA',
            'NUMERO DE TELEFONO',
            'NO ESTA DISPONIBLE',
        ];

        // Operadores
        const operadores = [
            'MOVISTAR',
            'CLARO',
            'TELCEL',
            'ENTEL',
            'TIGO',
            'PERSONAL',
            'BITEL',
            'WIN',
            'DIGITEL'
        ];

        // Refuerzo
        const refuerzo = [
            'TONO',
            'MENSAJE',
            'BUZON',
            'CASILLA',
            'GRABADO',
            'GRABE',
            'VOZ',
            'DESPUES',
            'COMUNICADO',
        ];


        let score = 0;

        medias.forEach(m => {
            if (texto.includes(m)) score++;
        });

        operadores.forEach(op => {
            if (texto.includes(op)) score++;
        });

        refuerzo.forEach(r => {
            if (texto.includes(r)) score += 0.7;
        });

        return score >= 2;
    }

    sintaxis(texto){
        const cola_medias = [
            'CARROS ADELANTE',
            'CARRO ADELANTE',
            'MONTON DE CARRO',
            'ARTO CARRO',
            'BASTANTES CARRO',
            'BASTANTE CARRO',
            'HACIENDO COLA',
            'HAY COLA',
            'EN LA COLA',
            'COLA DE CARRO',
            'MUCHO CARRO',
            'MUCHOS CARRO',
            'VARIOS CARRO',
            'DEMASIADOS CARRO',
            'DEMASIADO CARRO',

            'UNIDADES ESPERANDO',
            'BASTANTES UNIDADES',
            'VARIAS UNIDADES',
            'DEMASIADAS UNIDADES',
            'DEMASIADA UNIDAD'
        ];

        const llaman_medias= [
            'NO ME LLAMA',
            'VAN A LLAMAR',
            'NO LLAMA',
            'QUE ME LLAME',
            'LA LLAMADA',
        ];

        const espera_medias =[
            'A LA ESPERA',
            'ESTAMOS ESPERANDO',
            'ESTAMOS EN ESPERA',
            'SIGO ESPERANDO',
            'ESTOY ESPERANDO',
            'EN LA ESPERA',
            'ACABO DE LLEGAR',
            'ESPERANDO PARA',
            'MI TURNO',
        ];

        const descarga_medias =[
            'YA DESCARG',
            'YA DESCARGARON',
            'EL DESCARGUE',

            'ESTOY DESCARGANDO',
            'ESTAN DESCARGANDO',
            'ESTABA DESCARGANDO',

            'VOY A DESCARGAR',
            'VOY DESCARGAR',
            'VAN DESCARGAR',
            'VAN A DESCARGAR',
            'DE DESCARGA',
            'PARA DESCARGA',

            'ME DESCARG',// 'ME DESCARGARON'
            'LA DESCARG',// 'LA DESCARGARON' descarga
        ];

        const carga_medias =[
            'YA CARGUE',
            'YA CARGARON',
            'LA CARGUE',

            'ESTOY CARGANDO',
            'ESTAN CARGANDO',
            'ESTABA CARGANDO',

            'VOY A CARGAR',
            'VOY CARGAR',
            'VAN CARGAR',
            'VAN A CARGAR',
            'DE CARGA',
            'PARA CARGA',
            'ME CARGAN',
            'LA CARGA',

            'ME CARGARON',
            'LA CARGARON',
        ];

        const entrar_medias= [
            'ESTOY ENTRANDO',
            'AHORITA ENTRO',
            'PARA ENTRAR',
            'POR ENTRAR',
            'YA ENTRO',
            'YA ENTRE',
            'AHORA ENTRO',
            'AHORITA ENTRO',
            'DE ENTRAR',
            'INGRESADO',
            'YA INGRESE',
        ];
        const salir_medias= [
            'ESTOY SALIENDO',
            'AHORITA SALGO',
            'PARA SALIR',
            'POR SALIR',
            'YA SALGO',
            'YA SALI',
            'AHORA SALGO',
            'AHORITA SALGO',
            'DE SALIR',
            'EN RUTA'
        ]

        const refuerzo =[
            'TOLDANDO',
            'TOLDEANDO',
            'TOLDEAR',
            'TOLDERA',
            'ENCARPANDO',
            'ENCARPAR',
            'ENCARPE',
            'RAMPA',
            'BALANSA',
            'BALANZA',
            'AMARRARON',
            'MONTACARGA',

            'DOCUMENTO',
            'GUIA',
            'MANTEO',
            'ESTIBA',
            'DESPACHARON',
            'PRODUCTO',
            'SISTEMA',

            'REGISTRE',
            'DEMORA',

            'ESTOY ADENTRO',
            'NO SALGO'
        ];

        const parcial=[
            ' SI ','CORRECT','AFIRMA','CONFIRMA', 'ASI ES'
        ]


        let score = 0;

        cola_medias.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= 'hay cola '
                score++;
            }
        });

        llaman_medias.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= 'van a llamar '
                score++;
            }
        });

        espera_medias.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= 'esperando '
                score++;
            }
        });

        carga_medias.forEach(m => {
            if (texto.includes(m)) {
                if (this.analisis_transcripcion) this.analisis_transcripcion+= '- ';
                this.analisis_transcripcion+= 'carga '
                score++;
            }
        });

        descarga_medias.forEach(m => {
            if (texto.includes(m)) {
                if (this.analisis_transcripcion) this.analisis_transcripcion+= '- ';
                this.analisis_transcripcion+= 'descarga'
                score++;
            }
        });


        entrar_medias.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= '+ ' + m.toLowerCase();
                score+=1;
            }
        });

        salir_medias.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= '+ ' + m.toLowerCase();
                score+=1;
            }
        });

        refuerzo.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= '+ ' + m.toLowerCase();
                score+=1;
            }
        });
        parcial.forEach(m => {
            if (texto.includes(m)) {
                this.analisis_transcripcion+= '+ ';
                score+=0.5;
            }
        });

        return score >= 3;
    }



}
