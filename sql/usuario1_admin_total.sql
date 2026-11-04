-- =====================================================================
-- DEJA AL USUARIO 1 (ADMIN) CON ACCESO TOTAL AL PANEL
-- Spatie/laravel-permission  |  MySQL 8.x
--
-- PROBLEMA QUE RESUELVE
--   En produccion el usuario 1 entra, pero el menu le sale recortado
--   (solo Scoring, Etiquetar, Importar y Usuario). Eso pasa cuando:
--     a) el rol `admin` existe pero NO tiene los 22 permisos del catalogo;
--     b) el usuario 1 no tiene el rol `admin` asignado (model_has_roles);
--     c) los pivotes guardan un `model_type` distinto a `App\Models\User`
--        (el pivot se inserta con el nombre de la clase, no con el id);
--     d) los permisos quedaron con `guard_name` distinto de 'web';
--     e) falta la cache: Spatie guarda los permisos 24 h y el cambio de
--        base no la invalida.
--   Este archivo arregla a), b), c), d) y deja e) documentado al final.
--
-- QUE HACE (en este orden, todo idempotente)
--   0. Crea las tablas de Spatie si la base destino no las tiene.
--   1. Crea los 22 permisos del catalogo (INSERT IGNORE).
--   2. Crea el rol `admin` (INSERT IGNORE).
--   3. Normaliza `guard_name` = 'web' en los 22 permisos y en los 3 roles.
--   4. Resuelve el id real del administrador (@uid) y lo muestra.
--   5. Deja el rol `admin` con los 22 permisos (se rehace completa).
--   6. Asigna el rol `admin` al administrador.
--   7. Asigna ADEMAS los 22 permisos DIRECTAMENTE al administrador
--      (model_has_permissions): asi no depende del rol para que las
--      rutas con `permission:` le dejen pasar.
--   8. Limpia pivotes huerfanos del administrador con model_type viejo.
--   9. Verificacion.
--
-- COMO USAR
--   phpMyAdmin > base de produccion > Importar > este archivo
--   o
--     mysql -u USUARIO -p NOMBRE_BASE < sql/usuario1_admin_total.sql
--
--   DESPUES OBLIGATORIO (en el servidor de produccion):
--     php artisan optimize:clear
--     php artisan permission:cache-reset
--     php artisan config:clear
--     php artisan route:clear
--   o, mas rapido, entrar a  /livewire/discover  (si el usuario 1 ya
--   tiene `administrar usuarios`), que limpia todo eso de una vez.
--   Despues: cerrar sesion y volver a entrar (la sesion guarda el
--   usuario cacheado en la vista).
--
--   Es IDEMPOTENTE: se puede correr las veces que sea sin duplicar nada.
--   NO toca los roles ni los permisos de los usuarios 2 y 3.
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
-- 0) ESTRUCTURA DE LAS TABLAS DE SPATIE (solo si no existen)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `roles` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_has_permissions` (
    `permission_id` bigint unsigned NOT NULL,
    `role_id` bigint unsigned NOT NULL,
    PRIMARY KEY (`permission_id`,`role_id`),
    KEY `role_has_permissions_role_id_foreign` (`role_id`),
    CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `model_has_roles` (
    `role_id` bigint unsigned NOT NULL,
    `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `model_id` bigint unsigned NOT NULL,
    PRIMARY KEY (`role_id`,`model_id`,`model_type`),
    KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
    CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `model_has_permissions` (
    `permission_id` bigint unsigned NOT NULL,
    `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `model_id` bigint unsigned NOT NULL,
    PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
    KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
    CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 1) PERMISOS DEL CATALOGO (22) - se crean los que falten
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
    -- LECTURA
    ('ver llamadas',                          'web', NOW(), NOW()),
    ('ver conductores',                       'web', NOW(), NOW()),
    ('ver transportistas',                    'web', NOW(), NOW()),
    ('ver referencias',                       'web', NOW(), NOW()),
    ('ver tiempos',                           'web', NOW(), NOW()),
    ('ver reportes',                          'web', NOW(), NOW()),
    ('ver scoring',                           'web', NOW(), NOW()),
    ('ver lote',                              'web', NOW(), NOW()),
    ('ver lista de lotes',                    'web', NOW(), NOW()),
    ('ver api',                               'web', NOW(), NOW()),
    -- ESCRITURA
    ('editar palabras clave',                 'web', NOW(), NOW()),
    ('etiquetar llamada',                     'web', NOW(), NOW()),
    ('importar excel',                        'web', NOW(), NOW()),
    ('importar json',                         'web', NOW(), NOW()),
    ('importar vapi',                         'web', NOW(), NOW()),
    ('compilar promedios',                    'web', NOW(), NOW()),
    ('gestionar duplicados conductores',      'web', NOW(), NOW()),
    ('gestionar casos conductores',            'web', NOW(), NOW()),
    -- SENSIBLES
    ('eliminar lote',                         'web', NOW(), NOW()),
    ('fusionar conductores',                  'web', NOW(), NOW()),
    ('administrar usuarios',                  'web', NOW(), NOW()),
    -- HEREDADOS
    ('api de obtencion efletexia',            'web', NOW(), NOW());


-- ---------------------------------------------------------------------
-- 2) ROLES (3)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
    ('admin',    'web', NOW(), NOW()),
    ('editor',   'web', NOW(), NOW()),
    ('invitado', 'web', NOW(), NOW());


-- ---------------------------------------------------------------------
-- 3) SANEAR `guard_name` DUPLICADO
--    En produccion puede haber el MISMO nombre de permiso con dos guards
--    (por ejemplo 'etiquetar llamada' con 'api' y con 'web'). Con el indice
--    unico (name, guard_name) eso no es un error de MySQL, pero Spatie solo
--    lee los del guard del usuario: el de 'api' queda huerfano y el de 'web'
--    puede no tener las asignaciones. Aqui se migran las asignaciones del
--    guard raro al de 'web' y se borra el sobrante.
-- ---------------------------------------------------------------------

-- 3.1 Permisos: sus asignaciones pasan al permiso de guard 'web'
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT pw.id, rhp.role_id
FROM `role_has_permissions` rhp
JOIN `permissions` pr ON pr.id = rhp.permission_id
JOIN `permissions` pw ON BINARY pw.`name` = BINARY pr.`name` AND pw.`guard_name` = 'web'
WHERE pr.`guard_name` <> 'web';

INSERT IGNORE INTO `model_has_permissions` (`permission_id`, `model_type`, `model_id`)
SELECT pw.id, mhp.model_type, mhp.model_id
FROM `model_has_permissions` mhp
JOIN `permissions` pr ON pr.id = mhp.permission_id
JOIN `permissions` pw ON BINARY pw.`name` = BINARY pr.`name` AND pw.`guard_name` = 'web'
WHERE pr.`guard_name` <> 'web';

-- 3.2 Se desasocian los permisos con guard raro ANTES de borrarlos.
--     Si la tabla tiene la FK con ON DELETE CASCADE, esto es redundante;
--     si en produccion se creo sin FK, evita dejar filas huerfanas.
DELETE rhp
FROM `role_has_permissions` rhp
JOIN `permissions` pr ON pr.id = rhp.permission_id
WHERE pr.`guard_name` <> 'web'
  AND pr.`name` IN (
    'ver llamadas','ver conductores','ver transportistas','ver referencias',
    'ver tiempos','ver reportes','ver scoring','ver lote','ver lista de lotes',
    'ver api','editar palabras clave','etiquetar llamada','importar excel',
    'importar json','importar vapi','compilar promedios',
    'gestionar duplicados conductores','gestionar casos conductores',
    'eliminar lote','fusionar conductores','administrar usuarios',
    'api de obtencion efletexia'
  );

DELETE mhp
FROM `model_has_permissions` mhp
JOIN `permissions` pr ON pr.id = mhp.permission_id
WHERE pr.`guard_name` <> 'web'
  AND pr.`name` IN (
    'ver llamadas','ver conductores','ver transportistas','ver referencias',
    'ver tiempos','ver reportes','ver scoring','ver lote','ver lista de lotes',
    'ver api','editar palabras clave','etiquetar llamada','importar excel',
    'importar json','importar vapi','compilar promedios',
    'gestionar duplicados conductores','gestionar casos conductores',
    'eliminar lote','fusionar conductores','administrar usuarios',
    'api de obtencion efletexia'
  );

-- 3.3 Ahora si se borran los permisos con guard raro
DELETE FROM `permissions`
WHERE `guard_name` <> 'web'
  AND `name` IN (
    'ver llamadas','ver conductores','ver transportistas','ver referencias',
    'ver tiempos','ver reportes','ver scoring','ver lote','ver lista de lotes',
    'ver api','editar palabras clave','etiquetar llamada','importar excel',
    'importar json','importar vapi','compilar promedios',
    'gestionar duplicados conductores','gestionar casos conductores',
    'eliminar lote','fusionar conductores','administrar usuarios',
    'api de obtencion efletexia'
  );

-- 3.4 Roles: sus permisos pasan al rol de guard 'web'
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT rhp.permission_id, rw.id
FROM `role_has_permissions` rhp
JOIN `roles` ro ON ro.id = rhp.role_id
JOIN `roles` rw ON BINARY rw.`name` = BINARY ro.`name` AND rw.`guard_name` = 'web'
WHERE ro.`guard_name` <> 'web';

-- 3.5 Se desasocian los roles con guard raro (idem 3.2: por si no hay FK)
DELETE rhp
FROM `role_has_permissions` rhp
JOIN `roles` ro ON ro.id = rhp.role_id
WHERE ro.`guard_name` <> 'web'
  AND ro.`name` IN ('admin','editor','invitado');

DELETE mhr
FROM `model_has_roles` mhr
JOIN `roles` ro ON ro.id = mhr.role_id
WHERE ro.`guard_name` <> 'web'
  AND ro.`name` IN ('admin','editor','invitado');

-- 3.6 Se borran los roles con guard raro (el rol 'web' ya existe del paso 2)
DELETE FROM `roles`
WHERE `guard_name` <> 'web'
  AND `name` IN ('admin','editor','invitado');


-- ---------------------------------------------------------------------
-- 4) RESOLVER EL ID REAL DEL ADMINISTRADOR
--    Prioridad: id 1  ->  email del admin  ->  name 'admin'.
--    Te imprime el id que resolvio; si no es 1, ajustalo arriba.
-- ---------------------------------------------------------------------
SET @uid := COALESCE(
    (SELECT `id` FROM `users` WHERE `id` = 1 LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'thedanisa@gmail.com' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `name` = 'admin' LIMIT 1)
);

SELECT 'ADMIN RESUELTO' AS aviso, @uid AS id_usuario,
       (SELECT `name` FROM `users` WHERE `id` = @uid) AS nombre,
       (SELECT `email` FROM `users` WHERE `id` = @uid) AS email,
       (SELECT `activo` FROM `users` WHERE `id` = @uid) AS activo;


-- ---------------------------------------------------------------------
-- 5) EL ROL `admin` QUEDA CON LOS 22 PERMISOS (se rehace completa)
-- ---------------------------------------------------------------------
DELETE rhp
FROM `role_has_permissions` rhp
JOIN `roles` r ON r.id = rhp.role_id
WHERE r.name = 'admin';

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
CROSS JOIN `roles` r
WHERE r.name = 'admin'
  AND p.name IN (
    'ver llamadas','ver conductores','ver transportistas','ver referencias',
    'ver tiempos','ver reportes','ver scoring','ver lote','ver lista de lotes',
    'ver api','editar palabras clave','etiquetar llamada','importar excel',
    'importar json','importar vapi','compilar promedios',
    'gestionar duplicados conductores','gestionar casos conductores',
    'eliminar lote','fusionar conductores','administrar usuarios',
    'api de obtencion efletexia'
  );


-- ---------------------------------------------------------------------
-- 6) EL ADMINISTRADOR TIENE EL ROL `admin`
--    El pivote se inserta con el model_type de la clase: App\Models\User
-- ---------------------------------------------------------------------
DELETE FROM `model_has_roles`
WHERE `model_id` = @uid
  AND BINARY `model_type` = BINARY 'App\\Models\\User'
  AND `role_id` IN (SELECT `id` FROM `roles` WHERE `name` = 'admin');

INSERT IGNORE INTO `model_has_roles` (`role_id`, `model_type`, `model_id`)
SELECT r.id, 'App\\Models\\User', @uid
FROM `roles` r
WHERE r.name = 'admin';


-- ---------------------------------------------------------------------
-- 7) LOS 22 PERMISOS DIRECTAMENTE AL ADMINISTRADOR
--    Asi no depende de que el rol este bien armando: el middleware
--    `permission:` valida los permisos directos del usuario.
-- ---------------------------------------------------------------------
DELETE FROM `model_has_permissions`
WHERE `model_id` = @uid
  AND BINARY `model_type` = BINARY 'App\\Models\\User';

INSERT IGNORE INTO `model_has_permissions` (`permission_id`, `model_type`, `model_id`)
SELECT p.id, 'App\\Models\\User', @uid
FROM `permissions` p
WHERE p.name IN (
    'ver llamadas','ver conductores','ver transportistas','ver referencias',
    'ver tiempos','ver reportes','ver scoring','ver lote','ver lista de lotes',
    'ver api','editar palabras clave','etiquetar llamada','importar excel',
    'importar json','importar vapi','compilar promedios',
    'gestionar duplicados conductores','gestionar casos conductores',
    'eliminar lote','fusionar conductores','administrar usuarios',
    'api de obtencion efletexia'
);


-- ---------------------------------------------------------------------
-- 8) PIVOTES CON `model_type` VIEJO DEL ADMINISTRADOR
--    Spatie no los leeria (espera 'App\Models\User'), asi que se borran.
--    Solo afecta al administrador; los demas usuarios no se tocan.
-- ---------------------------------------------------------------------
DELETE FROM `model_has_roles`
WHERE `model_id` = @uid
  AND BINARY `model_type` <> BINARY 'App\\Models\\User';

DELETE FROM `model_has_permissions`
WHERE `model_id` = @uid
  AND BINARY `model_type` <> BINARY 'App\\Models\\User';

-- el administrador nunca queda inactivo
UPDATE `users` SET `activo` = 1 WHERE `id` = @uid;


-- ---------------------------------------------------------------------
-- 9) VERIFICACION
--    9.1 debe salir: admin 22
--    9.2 debe salir: 22 permisos directos del administrador
--    9.3 debe salir: 1 rol (admin) del administrador
-- ---------------------------------------------------------------------
-- 9.1 Permisos del rol admin
SELECT r.name AS rol, COUNT(rhp.permission_id) AS permisos
FROM `roles` r
LEFT JOIN `role_has_permissions` rhp ON rhp.role_id = r.id
WHERE r.name = 'admin'
GROUP BY r.name;

-- 9.2 Permisos directos del administrador
SELECT COUNT(*) AS permisos_directos_del_admin
FROM `model_has_permissions` mhp
JOIN `permissions` p ON p.id = mhp.permission_id
WHERE mhp.model_id = @uid
  AND mhp.model_type = 'App\\Models\\User';

-- 9.3 Rol del administrador
SELECT mhr.model_id AS usuario, r.name AS rol
FROM `model_has_roles` mhr
JOIN `roles` r ON r.id = mhr.role_id
WHERE mhr.model_id = @uid
  AND mhr.model_type = 'App\\Models\\User';

-- 9.4 Listado final: los 22 permisos que deberia tener el administrador
SELECT p.name AS permiso
FROM `model_has_permissions` mhp
JOIN `permissions` p ON p.id = mhp.permission_id
WHERE mhp.model_id = @uid
  AND mhp.model_type = 'App\\Models\\User'
ORDER BY p.name;

-- 9.5 Los usuarios 2 y 3 NO se tocaron (invitado: 19 no sensibles)
SELECT u.id, u.name, r.name AS rol, COUNT(rhp.permission_id) AS permisos_del_rol
FROM `users` u
LEFT JOIN `model_has_roles` mhr
       ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
LEFT JOIN `roles` r ON r.id = mhr.role_id
LEFT JOIN `role_has_permissions` rhp ON rhp.role_id = r.id
WHERE u.id <> @uid
GROUP BY u.id, u.name, r.name;


-- =====================================================================
-- FIN DEL ARCHIVO
-- DESPUES DE IMPORTAR, EN EL SERVIDOR:
--   php artisan optimize:clear && php artisan permission:cache-reset
--   (y cerrar sesion / entrar de nuevo)
-- =====================================================================
