-- =====================================================================
-- Pruebas funcionales del esquema (se ejecutan en una transaccion y se revierten)
-- Uso: psql -v ON_ERROR_STOP=1 -f 02_pruebas_esquema.sql
-- =====================================================================
BEGIN;

CREATE FUNCTION pg_temp.falla(p_sql text, p_frag text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    EXECUTE p_sql;
    RAISE EXCEPTION 'SE_ESPERABA_ERROR "%" pero tuvo exito: %', p_frag, p_sql;
EXCEPTION WHEN OTHERS THEN
    IF SQLERRM LIKE 'SE_ESPERABA_ERROR%' THEN RAISE; END IF;
    IF position(p_frag IN SQLERRM) = 0 THEN
        RAISE EXCEPTION 'Error distinto del esperado. Esperado "%" y se obtuvo "%"', p_frag, SQLERRM;
    END IF;
END $$;

CREATE FUNCTION pg_temp.verifica(p_cond boolean, p_msg text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF NOT COALESCE(p_cond, false) THEN RAISE EXCEPTION 'FALLO: %', p_msg; END IF;
    RAISE NOTICE 'OK: %', p_msg;
END $$;

DO $prueba$
DECLARE
    u_ur bigint; u_cj bigint; u_ca bigint; u_ct bigint; u_ac bigint; u_x bigint;
    v_p bigint; v_p2 bigint; v_p3 bigint; v_tmp bigint; v_d2 bigint; v_ver2 bigint; v_it1 bigint; v_it2 bigint;
    v_pub1 bigint; v_pub2 bigint; v_d bigint; v_arch bigint; v_ver bigint; v_rev bigint; v_obs bigint;
    v_prov bigint[]; v_of bigint[]; v_oc bigint; v_ent bigint; i int;
    f_prep bigint; f_pre bigint; f_con bigint;
    v_tp bigint; v_mac bigint; v_ini bigint; v_doc_tipo bigint; v_est_oferta bigint; v_td bigint;
BEGIN
    SELECT id INTO f_prep FROM fases WHERE codigo='PREPARATORIA';
    SELECT id INTO f_pre  FROM fases WHERE codigo='PRECONTRACTUAL';
    SELECT id INTO f_con  FROM fases WHERE codigo='CONTRACTUAL';

    -- Usuarios y roles
    INSERT INTO users (name,email) VALUES ('Unidad Req','ur@t.ec') RETURNING id INTO u_ur;
    INSERT INTO users (name,email) VALUES ('Jefe Compras','cj@t.ec') RETURNING id INTO u_cj;
    INSERT INTO users (name,email) VALUES ('Analista','ca@t.ec') RETURNING id INTO u_ca;
    INSERT INTO users (name,email) VALUES ('Comision','ct@t.ec') RETURNING id INTO u_ct;
    INSERT INTO users (name,email) VALUES ('Admin Contrato','ac@t.ec') RETURNING id INTO u_ac;
    INSERT INTO users (name,email) VALUES ('Sin Rol','x@t.ec') RETURNING id INTO u_x;
    INSERT INTO usuario_rol SELECT u, r.id FROM (VALUES (u_ur,'UNIDAD_REQUIRENTE'),(u_cj,'COMPRAS_JEFE'),
        (u_ca,'COMPRAS_ANALISTA'),(u_ct,'COMISION_TECNICA'),(u_ac,'ADMIN_CONTRATO')) m(u,rol)
        JOIN roles r ON r.codigo = m.rol;

    SELECT id INTO v_tp  FROM tipos_proceso LIMIT 1;
    SELECT id INTO v_mac FROM estados_macro WHERE codigo='PREPARATORIA';
    SELECT id INTO v_ini FROM estados_proceso WHERE codigo='INICIO';

    -- 1. Crear proceso: genera historial inicial
    INSERT INTO procesos (tipo_proceso_id, anio, objeto, estado_macro_id, estado_proceso_id, creado_por)
    VALUES (v_tp, 2026, 'Adquisicion de canulas', v_mac, v_ini, u_ur) RETURNING id INTO v_p;
    PERFORM pg_temp.verifica((SELECT count(*) FROM historial_estados_proceso WHERE proceso_id=v_p)=1,
        'crear proceso registra historial inicial');
    INSERT INTO proceso_items (proceso_id,numero_linea,cum,descripcion,cantidad) VALUES
        (v_p,1,'12-700-001','Canula adulto',100000) RETURNING id INTO v_it1;
    INSERT INTO proceso_items (proceso_id,numero_linea,cum,descripcion,cantidad) VALUES
        (v_p,2,'12-700-003','Canula neonatal',20000) RETURNING id INTO v_it2;

    -- 2. El estado no se puede cambiar por UPDATE directo
    PERFORM pg_temp.falla(format('UPDATE procesos SET estado_proceso_id=(SELECT id FROM estados_proceso WHERE codigo=%L) WHERE id=%s','PUBLICADO',v_p),
        'solo cambia mediante una transicion');
    PERFORM pg_temp.verifica(true,'UPDATE directo de estado bloqueado');

    -- 3. Autorizacion: permiso, fase y asignacion
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'enviar_a_revision',u_x),'no tiene el permiso');
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'enviar_a_revision',u_ur),'no esta asignado');
    INSERT INTO asignaciones_proceso (proceso_id,user_id,rol_id,fase_id,asignado_por)
        SELECT v_p,m.u,r.id,m.f,u_cj FROM (VALUES
            (u_ur,'UNIDAD_REQUIRENTE',f_prep),(u_cj,'COMPRAS_JEFE',f_prep),(u_ca,'COMPRAS_ANALISTA',f_prep),
            (u_ct,'COMISION_TECNICA',f_pre),(u_ac,'ADMIN_CONTRATO',f_con)) m(u,rol,f)
        JOIN roles r ON r.codigo=m.rol;
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'publicar',u_ca),'no esta permitida');
    PERFORM aplicar_transicion_proceso(v_p,'enviar_a_revision',u_ur);
    PERFORM pg_temp.verifica((SELECT ep.codigo FROM procesos p JOIN estados_proceso ep ON ep.id=p.estado_proceso_id WHERE p.id=v_p)='EN_REVISION',
        'enviar_a_revision -> EN_REVISION con permiso y asignacion');

    -- 4. Ciclo documental: version, revision, observacion, correccion
    SELECT id INTO v_doc_tipo FROM tipos_documento WHERE codigo='EXPEDIENTE_PREPARATORIO';
    INSERT INTO documentos (proceso_id,tipo_documento_id,estado_documento_id,titulo,creado_por)
        VALUES (v_p,v_doc_tipo,(SELECT id FROM estados_documento WHERE codigo='BORRADOR'),'Expediente',u_ur) RETURNING id INTO v_d;
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_documento(%s,%L,%s)',v_d,'enviar_a_revision',u_ur),'ninguna version');
    INSERT INTO archivos (disco,ruta,nombre_original,nombre_interno,extension,mime,tamano_bytes,hash_sha256,user_id)
        VALUES ('documentos','1/a.pdf','exp.pdf','uuid-1.pdf','pdf','application/pdf',10,repeat('a',64),u_ur) RETURNING id INTO v_arch;
    INSERT INTO documento_versiones (documento_id,numero_version,archivo_id,user_id) VALUES (v_d,0,v_arch,u_ur) RETURNING id INTO v_ver;
    PERFORM pg_temp.verifica((SELECT numero_version FROM documento_versiones WHERE id=v_ver)=1
        AND (SELECT version_actual_id FROM documentos WHERE id=v_d)=v_ver, 'version 1 numerada y marcada como actual');
    PERFORM aplicar_transicion_documento(v_d,'enviar_a_revision',u_ur);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_documento(%s,%L,%s)',v_d,'iniciar_revision',u_ca),'no esta asignado');
    INSERT INTO asignaciones_documento (documento_id,user_id,tarea,asignado_por) VALUES (v_d,u_ca,'revisar',u_cj);
    INSERT INTO asignaciones_documento (documento_id,user_id,tarea,asignado_por) VALUES (v_d,u_ur,'corregir',u_cj);
    PERFORM aplicar_transicion_documento(v_d,'iniciar_revision',u_ca);
    INSERT INTO revisiones (documento_version_id,numero_revision,revisor_id,resultado) VALUES (v_ver,0,u_ca,'con_observaciones') RETURNING id INTO v_rev;
    INSERT INTO observaciones (revision_id,tipo,texto,user_id) VALUES (v_rev,'observacion','Falta firma',u_ca) RETURNING id INTO v_obs;
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_documento(%s,%L,%s)',v_d,'registrar_observaciones',u_ca),'exige un comentario');
    PERFORM aplicar_transicion_documento(v_d,'registrar_observaciones',u_ca,'Falta firma');
    PERFORM aplicar_transicion_proceso(v_p,'solicitar_correcciones',u_ca,'Ver observaciones');
    PERFORM aplicar_transicion_documento(v_d,'iniciar_correccion',u_ur);
    INSERT INTO archivos (disco,ruta,nombre_original,nombre_interno,extension,mime,tamano_bytes,hash_sha256,user_id)
        VALUES ('documentos','1/b.pdf','exp2.pdf','uuid-2.pdf','pdf','application/pdf',12,repeat('b',64),u_ur) RETURNING id INTO v_arch;
    INSERT INTO documento_versiones (documento_id,numero_version,archivo_id,user_id) VALUES (v_d,0,v_arch,u_ur) RETURNING id INTO v_ver;
    PERFORM pg_temp.verifica((SELECT numero_version FROM documento_versiones WHERE id=v_ver)=2,
        'la correccion crea la version 2 sin sobrescribir la 1');
    PERFORM aplicar_transicion_documento(v_d,'reenviar',u_ur);
    PERFORM aplicar_transicion_proceso(v_p,'reenviar_a_revision',u_ur);
    PERFORM aplicar_transicion_documento(v_d,'iniciar_revision',u_ca);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'validar_documentacion',u_ca),'observaciones pendientes');
    INSERT INTO correcciones (observacion_id,documento_version_id,user_id,descripcion) VALUES (v_obs,v_ver,u_ur,'Se agrego la firma');
    INSERT INTO revisiones (documento_version_id,numero_revision,revisor_id,resultado) VALUES (v_ver,0,u_ca,'aprobada');
    INSERT INTO aprobaciones (documento_version_id,user_id) VALUES (v_ver,u_ca);
    PERFORM aplicar_transicion_documento(v_d,'aprobar',u_ca);
    PERFORM aplicar_transicion_proceso(v_p,'validar_documentacion',u_ca);
    PERFORM pg_temp.verifica((SELECT count(*) FROM v_historial_revisiones WHERE documento_id=v_d)=2
        AND (SELECT count(*) FROM v_historial_correcciones WHERE documento_id=v_d)=1,
        'historial de revisiones y correcciones consultable');

    -- 5. Tablas historicas inmutables
    PERFORM pg_temp.falla(format('UPDATE documento_versiones SET comentario=%L WHERE id=%s','x',v_ver),'es historica');
    PERFORM pg_temp.falla('DELETE FROM historial_estados_proceso','es historica');
    PERFORM pg_temp.verifica(true,'tablas historicas no admiten UPDATE ni DELETE');

    -- La version actual debe pertenecer al mismo documento
    SET CONSTRAINTS ALL IMMEDIATE;
    INSERT INTO documentos (proceso_id,tipo_documento_id,estado_documento_id,titulo,creado_por)
        VALUES (v_p,v_doc_tipo,(SELECT id FROM estados_documento WHERE codigo='BORRADOR'),'Otro',u_ur) RETURNING id INTO v_d2;
    INSERT INTO archivos (disco,ruta,nombre_original,nombre_interno,extension,mime,tamano_bytes,hash_sha256,user_id)
        VALUES ('documentos','1/c.pdf','otro.pdf','uuid-3.pdf','pdf','application/pdf',5,repeat('c',64),u_ur) RETURNING id INTO v_arch;
    INSERT INTO documento_versiones (documento_id,numero_version,archivo_id,user_id) VALUES (v_d2,0,v_arch,u_ur) RETURNING id INTO v_ver2;
    PERFORM pg_temp.falla(format('UPDATE documentos SET version_actual_id=%s WHERE id=%s',v_ver2,v_d),'documentos_version_actual_fk');
    PERFORM pg_temp.verifica(true,'version actual de otro documento rechazada');

    -- 6. Publicaciones y ofertas
    PERFORM aplicar_transicion_proceso(v_p,'autorizar_publicacion',u_cj);
    PERFORM pg_temp.verifica((SELECT em.codigo FROM procesos p JOIN estados_macro em ON em.id=p.estado_macro_id WHERE p.id=v_p)='PRECONTRACTUAL',
        'autorizar_publicacion cambia el macro a PRECONTRACTUAL');
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'publicar',u_ca),'No existe una publicacion');
    INSERT INTO publicaciones (proceso_id,secuencia,codigo_nc,fecha_publicacion,fecha_limite)
        VALUES (v_p,1,'NC-1798194964001-2026-00001',now(),now()+interval '2 days') RETURNING id INTO v_pub1;
    PERFORM aplicar_transicion_proceso(v_p,'publicar',u_ca);
    PERFORM pg_temp.falla(format('INSERT INTO publicaciones (proceso_id,secuencia,codigo_nc,fecha_publicacion,fecha_limite) VALUES (%s,3,%L,now(),now())',v_p,'NC-X'),
        'numero maximo de publicaciones');
    FOR i IN 1..3 LOOP
        INSERT INTO proveedores (razon_social) VALUES ('Proveedor '||i) RETURNING id INTO v_tmp; v_prov[i] := v_tmp;
    END LOOP;
    SELECT id INTO v_est_oferta FROM estados_oferta WHERE codigo='RECIBIDA';
    INSERT INTO ofertas (proceso_id,publicacion_id,proveedor_id,estado_oferta_id,creado_por)
        VALUES (v_p,v_pub1,v_prov[1],v_est_oferta,u_ca) RETURNING id INTO v_tmp; v_of[1] := v_tmp;
    INSERT INTO oferta_items (proceso_id,oferta_id,proceso_item_id,precio_unitario) VALUES (v_p,v_of[1],v_it1,0.55),(v_p,v_of[1],v_it2,0.55);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'enviar_a_comision',u_cj),'No se cumple el numero de ofertas');
    PERFORM aplicar_transicion_proceso(v_p,'republicar',u_ca);
    INSERT INTO publicaciones (proceso_id,secuencia,codigo_nc,fecha_publicacion,fecha_limite)
        VALUES (v_p,2,'NC-1798194964001-2026-00002',now(),now()+interval '1 day') RETURNING id INTO v_pub2;
    INSERT INTO ofertas (proceso_id,publicacion_id,proveedor_id,estado_oferta_id,creado_por)
        VALUES (v_p,v_pub2,v_prov[2],v_est_oferta,u_ca) RETURNING id INTO v_tmp; v_of[2] := v_tmp;
    INSERT INTO oferta_items (proceso_id,oferta_id,proceso_item_id,precio_unitario) VALUES (v_p,v_of[2],v_it1,0.35),(v_p,v_of[2],v_it2,0.35);
    INSERT INTO ofertas (proceso_id,publicacion_id,proveedor_id,estado_oferta_id,creado_por)
        VALUES (v_p,v_pub2,v_prov[3],v_est_oferta,u_ca) RETURNING id INTO v_tmp; v_of[3] := v_tmp;
    INSERT INTO oferta_items (proceso_id,oferta_id,proceso_item_id,precio_unitario) VALUES (v_p,v_of[3],v_it1,0.34),(v_p,v_of[3],v_it2,0.35);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'republicar',u_ca),'Ya se alcanzo');
    PERFORM pg_temp.falla(format('INSERT INTO oferta_items (proceso_id,oferta_id,proceso_item_id,precio_unitario) VALUES (%s,%s,%s,1)',v_p,v_of[1],v_it1),'unique');
    PERFORM aplicar_transicion_proceso(v_p,'enviar_a_comision',u_cj);
    PERFORM pg_temp.verifica((SELECT total FROM v_ofertas_totales WHERE oferta_id=v_of[3])=41000.00
        AND (SELECT ofertas FROM v_procesos WHERE proceso_id=v_p)=3,'totales de ofertas calculados y 3 ofertas contadas');

    -- 7. Designacion y seleccion
    PERFORM pg_temp.verifica(tipo_designacion_sugerido(66000)=(SELECT id FROM tipos_designacion WHERE codigo='DELEGADO')
        AND tipo_designacion_sugerido(150000)=(SELECT id FROM tipos_designacion WHERE codigo='COMISION')
        AND tipo_designacion_sugerido(600000)=(SELECT id FROM tipos_designacion WHERE codigo='COMISION_FINANCIERO_JURIDICO'),
        'tipo de designacion sugerido segun umbrales (equivale a la formula del Excel)');
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'iniciar_comision',u_ca),'No existe una designacion');
    INSERT INTO designaciones (proceso_id,tipo_designacion_id,monto_referencia)
        VALUES (v_p,tipo_designacion_sugerido(66000),66000) RETURNING id INTO v_td;
    INSERT INTO documentos (proceso_id,tipo_documento_id,estado_documento_id,titulo,numero_documento,designacion_id,creado_por)
        VALUES (v_p,(SELECT id FROM tipos_documento WHERE codigo='MEMORANDO_DESIGNACION'),
                (SELECT id FROM estados_documento WHERE codigo='BORRADOR'),'Memo designacion','AIL E.P.-SGG-2026-0044-M',v_td,u_ca);
    PERFORM aplicar_transicion_proceso(v_p,'iniciar_comision',u_ca);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'aprobar_seleccion',u_ct),'No existe una oferta seleccionada');
    PERFORM aplicar_transicion_oferta(v_of[3],'iniciar_evaluacion',u_ct);
    PERFORM aplicar_transicion_oferta(v_of[3],'marcar_seleccionable',u_ct);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_oferta(%s,%L,%s)',v_of[3],'seleccionar',u_ca),'no tiene el permiso');
    PERFORM aplicar_transicion_oferta(v_of[3],'seleccionar',u_ct);
    PERFORM aplicar_transicion_proceso(v_p,'aprobar_seleccion',u_ct);

    -- 8. Orden de compra, entregas y cierre
    PERFORM aplicar_transicion_proceso(v_p,'generar_expediente',u_cj);
    PERFORM aplicar_transicion_proceso(v_p,'generar_orden_compra',u_ca);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p,'iniciar_administracion',u_ca),'No existe una orden de compra');
    INSERT INTO ordenes_compra (proceso_id,oferta_id,proveedor_id,fecha_orden,administrador_nombre,administrador_user_id)
        VALUES (v_p,v_of[3],v_prov[3],now(),'Admin Contrato',u_ac) RETURNING id INTO v_oc;
    PERFORM aplicar_transicion_proceso(v_p,'iniciar_administracion',u_ca);
    INSERT INTO entregas (proceso_id,orden_compra_id,numero_entrega,fecha_entrega,responsable_id)
        VALUES (v_p,v_oc,1,current_date,u_ac) RETURNING id INTO v_ent;
    INSERT INTO entrega_items (proceso_id,entrega_id,proceso_item_id,cantidad) VALUES (v_p,v_ent,v_it1,60000);
    PERFORM aplicar_transicion_proceso(v_p,'cerrar',u_ac);
    PERFORM pg_temp.verifica((SELECT em.codigo||'/'||ep.codigo FROM procesos p JOIN estados_macro em ON em.id=p.estado_macro_id
        JOIN estados_proceso ep ON ep.id=p.estado_proceso_id WHERE p.id=v_p)='FINALIZADO/CIERRE',
        'flujo completo hasta FINALIZADO/CIERRE');
    PERFORM pg_temp.verifica((SELECT count(*) FROM historial_estados_proceso WHERE proceso_id=v_p)=15,
        'historial del proceso con 15 movimientos');

    -- 9. Devolucion y reapertura (el analista decide segun el memorando)
    INSERT INTO procesos (tipo_proceso_id,anio,objeto,estado_macro_id,estado_proceso_id,creado_por)
        VALUES (v_tp,2026,'Proceso devuelto',v_mac,v_ini,u_ur) RETURNING id INTO v_p2;
    INSERT INTO asignaciones_proceso (proceso_id,user_id,rol_id,fase_id,asignado_por)
        VALUES (v_p2,u_ca,(SELECT id FROM roles WHERE codigo='COMPRAS_ANALISTA'),f_pre,u_cj);
    PERFORM set_config('app.transicion_en_curso','procesos',true);   -- solo para preparar el escenario
    UPDATE procesos SET estado_proceso_id=(SELECT id FROM estados_proceso WHERE codigo='PUBLICADO'),
                        estado_macro_id=(SELECT id FROM estados_macro WHERE codigo='PRECONTRACTUAL') WHERE id=v_p2;
    PERFORM set_config('app.transicion_en_curso','',true);
    INSERT INTO publicaciones (proceso_id,secuencia,codigo_nc,fecha_publicacion,fecha_limite)
        VALUES (v_p2,1,'NC-REAB',now(),now()),(v_p2,2,'NC-REAB',now(),now());
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s,%L)',v_p2,'devolver_expediente',u_ca,'Sin ofertas'),'Debe registrar la devolucion');
    INSERT INTO devoluciones (proceso_id,motivo,reapertura_permitida,user_id) VALUES (v_p2,'Sin ofertas',true,u_ca);
    PERFORM aplicar_transicion_proceso(v_p2,'devolver_expediente',u_ca,'Memorando de devolucion');
    PERFORM aplicar_transicion_proceso(v_p2,'reabrir',u_ca,'Se reabre segun memorando');
    PERFORM pg_temp.verifica((SELECT ciclo FROM procesos WHERE id=v_p2)=2
        AND (SELECT ep.codigo FROM procesos p JOIN estados_proceso ep ON ep.id=p.estado_proceso_id WHERE p.id=v_p2)='INICIO',
        'reabrir vuelve a INICIO e incrementa el ciclo');
    INSERT INTO publicaciones (proceso_id,secuencia,codigo_nc,fecha_publicacion,fecha_limite)
        VALUES (v_p2,1,'NC-REAB',now(),now());
    PERFORM pg_temp.verifica((SELECT ciclo FROM publicaciones WHERE proceso_id=v_p2 ORDER BY id DESC LIMIT 1)=2,
        'nuevo ciclo permite otra publicacion secuencia 1 con el mismo NC');

    -- devolucion que no autoriza reapertura: solo se puede cerrar
    INSERT INTO procesos (tipo_proceso_id,anio,objeto,estado_macro_id,estado_proceso_id,creado_por)
        VALUES (v_tp,2026,'Sin reapertura',v_mac,v_ini,u_ur) RETURNING id INTO v_p3;
    INSERT INTO asignaciones_proceso (proceso_id,user_id,rol_id,fase_id,asignado_por)
        VALUES (v_p3,u_ca,(SELECT id FROM roles WHERE codigo='COMPRAS_ANALISTA'),f_pre,u_cj);
    PERFORM set_config('app.transicion_en_curso','procesos',true);
    UPDATE procesos SET estado_proceso_id=(SELECT id FROM estados_proceso WHERE codigo='PARA_REAPERTURA'),
                        estado_macro_id=(SELECT id FROM estados_macro WHERE codigo='PRECONTRACTUAL') WHERE id=v_p3;
    PERFORM set_config('app.transicion_en_curso','',true);
    INSERT INTO devoluciones (proceso_id,motivo,reapertura_permitida,user_id) VALUES (v_p3,'Desierto',false,u_ca);
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s,%L)',v_p3,'reabrir',u_ca,'x'),'no autoriza la reapertura');
    PERFORM aplicar_transicion_proceso(v_p3,'cerrar_sin_reapertura',u_ca,'Memorando sin reapertura');
    PERFORM pg_temp.verifica(true,'devolucion sin reapertura solo permite cerrar');

    -- 10. Documentos requeridos configurables por transicion
    INSERT INTO procesos (tipo_proceso_id,anio,objeto,estado_macro_id,estado_proceso_id,creado_por)
        VALUES (v_tp,2026,'Con requisitos',v_mac,v_ini,u_ur) RETURNING id INTO v_p3;
    INSERT INTO asignaciones_proceso (proceso_id,user_id,rol_id,fase_id,asignado_por)
        VALUES (v_p3,u_ur,(SELECT id FROM roles WHERE codigo='UNIDAD_REQUIRENTE'),f_prep,u_cj);
    INSERT INTO transicion_proceso_requisitos (transicion_id,tipo_documento_id,estado_documento_minimo_id)
        SELECT t.id,(SELECT id FROM tipos_documento WHERE codigo='INFORME_NECESIDAD'),(SELECT id FROM estados_documento WHERE codigo='BORRADOR')
          FROM transiciones_proceso t WHERE t.codigo='enviar_a_revision' AND t.estado_origen_id=v_ini;
    PERFORM pg_temp.falla(format('SELECT aplicar_transicion_proceso(%s,%L,%s)',v_p3,'enviar_a_revision',u_ur),'Faltan documentos requeridos: Informe de necesidad');
    INSERT INTO documentos (proceso_id,tipo_documento_id,estado_documento_id,titulo,creado_por)
        VALUES (v_p3,(SELECT id FROM tipos_documento WHERE codigo='INFORME_NECESIDAD'),
                (SELECT id FROM estados_documento WHERE codigo='BORRADOR'),'Informe',u_ur);
    PERFORM aplicar_transicion_proceso(v_p3,'enviar_a_revision',u_ur);
    PERFORM pg_temp.verifica(true,'documento requerido bloquea y luego habilita la transicion');

    -- 11. Todas las vistas se pueden consultar
    PERFORM pg_temp.verifica((SELECT count(*) FROM v_matriz_contratacion WHERE proceso_id=v_p)=2
        AND (SELECT pub2_nc FROM v_matriz_contratacion WHERE proceso_id=v_p AND numero_linea=1)='NC-1798194964001-2026-00002'
        AND (SELECT mejor_proveedor FROM v_matriz_contratacion WHERE proceso_id=v_p AND numero_linea=1)='Proveedor 3',
        'v_matriz_contratacion devuelve una fila por linea con publicaciones y mejor oferta');
    PERFORM pg_temp.verifica((SELECT count(*) FROM v_tiempos_revision)>=1
        AND (SELECT count(*) FROM v_documentos_aprobados)>=1
        AND (SELECT count(*) FROM v_ofertas_por_proveedor)=3,'vistas documentales y de proveedores operativas');
END $prueba$;

-- Consulta de todas las vistas (falla si alguna esta mal definida)
DO $$
DECLARE v text;
BEGIN
    FOR v IN SELECT table_name FROM information_schema.views WHERE table_schema='public' LOOP
        EXECUTE format('SELECT * FROM %I LIMIT 1', v);
    END LOOP;
    RAISE NOTICE 'OK: todas las vistas son consultables';
END $$;
ROLLBACK;
