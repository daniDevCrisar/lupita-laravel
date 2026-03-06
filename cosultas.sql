 -- ------------------------------------------------------------
 -- CONSULTA LISTA DE CONDUCTORES--------------------------------
 -- ------------------------------------------------------------
SELECT
    a.conductor_id,
    b.nombres AS conductor,
    COUNT(*) AS total,
    SUM(a.llamada_exitosa=1) AS exitosas,
    SUM(a.llamada_exitosa=0) AS fallidas,
    ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
    SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

    error_desconocido ,error_ia,error_red,error_sistema,

    SUM(a.buzon_de_voz * (a.llamada_exitosa = 0))  AS buzon_de_voz,
    SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0)) AS conductor_contesta_pero_no_habla,
    SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0)) AS conductor_no_escucha,
    SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0)) AS conductor_mala_senal,
    SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0)) AS confusion_en_llamada,
    SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0)) AS contesta_otra_persona,
    SUM(a.numero_equivocado * (a.llamada_exitosa = 0)) AS numero_equivocado,
    SUM(a.conductor_cuelga * (a.llamada_exitosa = 0)) AS conductor_cuelga,
    SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0)) AS conductor_no_contesta,
    SUM(a.conductor_confirma * (a.llamada_exitosa = 0))  AS confirmacion_parcial,
    SUM(a.conductor_conducta_inapropiada * (a.llamada_exitosa = 0))  AS conductor_conducta_inapropiada,

    SUM(a.conductor_confirma * (a.llamada_exitosa = 1))  AS conductor_confirma,
    SUM(a.conductor_da_motivos * (a.llamada_exitosa = 1)) AS conductor_da_motivos,
    SUM(a.conversacion_fluida * (a.llamada_exitosa = 1)) AS conversacion_fluida,
    SUM(a.llamada_interesante * (a.llamada_exitosa = 1)) AS llamada_interesante
FROM llamadas a
         INNER JOIN conductores b ON b.id = a.conductor_id
         LEFT JOIN (SELECT conductor_id ,
                           sum(error_origen=-1) as error_desconocido ,
                           sum(error_origen=1) as error_ia,
                           sum(error_origen=2) as error_red,
                           sum(error_origen=3) as error_sistema
                    from llamadas where error_origen!=0 GROUP BY conductor_id) c
                   on c.conductor_id = a.conductor_id
WHERE a.error_origen = 0
GROUP BY a.conductor_id, a.trt_id
ORDER BY diferencia DESC , exitosas asc
    limit 30;
 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------
