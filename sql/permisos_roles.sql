-- =====================================================================
-- MATRIZ DE PERMISOS Y ROLES DEL PANEL  (spatie/laravel-permission)
-- Base: lupita_bd (MySQL 8.4)     Generado: 2026-11-04
--
-- QUÉ CAMBIA RESPECTO A LA MATRIZ ANTERIOR
--   1. Se eliminan los 3 permisos de API:
--        ver documentacion api | ver api tiempos | actualizar referencias api
--      Los endpoints JSON de /api/* son PÚBLICOS y no llevan permiso; las
--      pantallas que los acompañan usan ahora el permiso de lectura `ver api`.
--   2. Grupo API del menú -> permiso de LECTURA `ver api`.
--   3. Dos permisos nuevos de ESCRITURA para conductores:
--        gestionar duplicados conductores  (/lupita/conductores/duplicados)
--        gestionar casos conductores      (/lupita/conductores/casos)
--
-- CATÁLOGO ACTUAL (22 permisos)
--   LECTURA (10): ver llamadas | ver conductores | ver transportistas |
--                  ver referencias | ver tiempos | ver reportes |
--                  ver scoring | ver lote | ver lista de lotes | ver api
--   ESCRITURA (8): editar palabras clave | etiquetar llamada | importar excel |
--                  importar json | importar vapi | compilar promedios |
--                  gestionar duplicados conductores | gestionar casos conductores
--   SENSIBLES (3): eliminar lote | fusionar conductores | administrar usuarios
--   HEREDADOS (1): api de obtencion efletexia
--
-- MATRIZ DE ROLES
--   admin    = los 22 permisos
--   editor   = los 19 no sensibles
--   invitado = los 19 no sensibles
--
-- USUARIOS INCLUIDOS (sección 5)
--   1 | admin     | Pedro Daniel Sotelo Aguirre | thedanisa@gmail.com
--   2 | dani      | (sin nombre de pila)         | a@b.com
--   3 | monitoreo | MONITO                       | llamadas.ia.crisar@gmail.com
--   Los 3 van activos y con las contraseñas actuales (hash bcrypt).
--
-- CÓMO USAR
--   phpMyAdmin > Importar > este archivo, o
--     mysql -u root -p lupita_bd < sql/permisos_roles.sql
--
--   Es IDEMPOTENTE: se puede correr las veces que sea sin duplicar nada.
--   OJO: la sección 5 asigna los roles por ID de usuario (1 = administrador,
--   2 = dani, 3 = monitoreo). Si en la base destino los usuarios tienen
--   otros ids, ajusta el WHERE de esa sección antes de ejecutar.
--
--   Después de importar, refresca la cache de permisos:
--     php artisan permission:cache-reset
--     (o entra a /livewire/discover, que también la limpia)
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
-- 0) ESTRUCTURA DE LAS TABLAS DE SPATIE
--    Solo hace falta en una base nueva: si ya existen, no se tocan.
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
-- 1) PERMISOS (22): se crean los que falten, los que ya existen no se tocan
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
-- 2) ROLOS (3)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
    ('admin',    'web', NOW(), NOW()),
    ('editor',   'web', NOW(), NOW()),
    ('invitado', 'web', NOW(), NOW());


-- ---------------------------------------------------------------------
-- 3) PERMISOS QUE DEJAN DE EXISTIR
--    Las asignaciones se van solas por ON DELETE CASCADE.
-- ---------------------------------------------------------------------
-- 3.1 Los 3 permisos de API (ya no controlan nada)
DELETE FROM `permissions`
WHERE `name` IN (
    'ver documentacion api',
    'ver api tiempos',
    'actualizar referencias api'
);

-- 3.2 Cualquier otro permiso fuera del catalogo (mismo criterio que el
--     seeder PermisosPanelSeeder). Si en esa base hay permisos de otro
--     sistema, quita este bloque antes de ejecutar.
DELETE FROM `permissions`
WHERE `name` NOT IN (
    'ver llamadas',
    'ver conductores',
    'ver transportistas',
    'ver referencias',
    'ver tiempos',
    'ver reportes',
    'ver scoring',
    'ver lote',
    'ver lista de lotes',
    'ver api',
    'editar palabras clave',
    'etiquetar llamada',
    'importar excel',
    'importar json',
    'importar vapi',
    'compilar promedios',
    'gestionar duplicados conductores',
    'gestionar casos conductores',
    'eliminar lote',
    'fusionar conductores',
    'administrar usuarios',
    'api de obtencion efletexia'
);


-- ---------------------------------------------------------------------
-- 4) PERMISOS POR ROL (se rehace la asignación completa)
--    admin    -> todos
--    editor   -> todos menos los sensibles
--    invitado -> todos menos los sensibles
-- ---------------------------------------------------------------------
DELETE rhp
FROM `role_has_permissions` rhp
JOIN `roles` r ON r.id = rhp.role_id
WHERE r.name IN ('admin', 'editor', 'invitado');

-- admin: todos los permisos
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
CROSS JOIN `roles` r
WHERE r.name = 'admin';

-- editor e invitado: todos menos los tres sensibles
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
CROSS JOIN `roles` r
WHERE r.name IN ('editor', 'invitado')
  AND p.name NOT IN ('eliminar lote', 'fusionar conductores', 'administrar usuarios');


-- ---------------------------------------------------------------------
-- 5) USUARIOS DEL PANEL
--    INSERT IGNORE: solo se crean los que falten (si el id o el email ya
--    existen, la fila se omite y no se pisa nada).
--
--    Las contraseñas van como HASH bcrypt (cost 12), que es como las guarda
--    Laravel. Son los hashes actuales de la base, para que las claves de
--    entrada sigan siendo las mismas. Para cambiar una, genera el hash nuevo:
--      php artisan tinker
--      >>> password_hash('nueva-clave', PASSWORD_BCRYPT, ['cost' => 12])
--    y reemplaza el valor correspondiente.
--
--    `remember_token` se deja NULL a proposito: se genera solo cuando alguien
--    marca "recordarme".
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `users`
    (`id`, `name`, `nombres`, `password`, `activo`, `created_at`, `email`, `email_verified_at`, `updated_at`)
VALUES
    (1, 'admin',     'Pedro Daniel Sotelo Aguirre', '$2y$12$WlgNx2WbOnbOLe3aHfWvB.pwGRk07lkkTFauAfq.NXJzf2mANfCkS', 1, '2026-02-09 17:57:11', 'thedanisa@gmail.com',         '2026-04-08 12:16:59', NOW()),
    (2, 'dani',      '',                          '$2y$12$PgS03YQ4rintdZoi98dilOOFyE5oss8dz3ZDE5xlwMIP/XpIUA3ee', 1, '2026-04-20 17:38:29', 'a@b.com',                     NULL,                  NOW()),
    (3, 'monitoreo', 'MONITO',                    '$2y$12$luRKUlCKW3oOTMaAOzRAgeueGySgjsmtxqHQq53XZTQqW8eITW4O6', 1, '2026-07-25 13:11:21', 'llamadas.ia.crisar@gmail.com', NULL,                  NOW());


-- ---------------------------------------------------------------------
-- 6) ROLES DE LOS USUARIOS
--    1 = administrador (admin) | 2 = dani (editor + invitado) | 3 = monitoreo (invitado)
-- ---------------------------------------------------------------------
DELETE mhr
FROM `model_has_roles` mhr
JOIN `roles` r ON r.id = mhr.role_id
WHERE mhr.model_type = 'App\\Models\\User'
  AND mhr.model_id IN (1, 2, 3);

INSERT IGNORE INTO `model_has_roles` (`role_id`, `model_type`, `model_id`)
SELECT r.id, 'App\\Models\\User', u.id
FROM `roles` r
JOIN `users` u ON u.id IN (1, 2, 3)
WHERE (r.name = 'admin'    AND u.id = 1)
   OR (r.name = 'editor'   AND u.id = 2)
   OR (r.name = 'invitado' AND u.id IN (2, 3));


-- ---------------------------------------------------------------------
-- 7) PERMISOS DIRECTOS DE USUARIOS (no heredados del rol)
--    El administrador (id 1) recibe los 22 permisos del catalogo tambien
--    de forma DIRECTA, para que su acceso no dependa de que el rol
--    `admin` este bien armado en la base.
-- ---------------------------------------------------------------------
DELETE FROM `model_has_permissions`
WHERE `model_type` = 'App\\Models\\User';

INSERT IGNORE INTO `model_has_permissions` (`permission_id`, `model_type`, `model_id`)
SELECT p.id, 'App\\Models\\User', 1
FROM `permissions` p
WHERE p.name IN (
    'ver llamadas',
    'ver conductores',
    'ver transportistas',
    'ver referencias',
    'ver tiempos',
    'ver reportes',
    'ver scoring',
    'ver lote',
    'ver lista de lotes',
    'ver api',
    'editar palabras clave',
    'etiquetar llamada',
    'importar excel',
    'importar json',
    'importar vapi',
    'compilar promedios',
    'gestionar duplicados conductores',
    'gestionar casos conductores',
    'eliminar lote',
    'fusionar conductores',
    'administrar usuarios',
    'api de obtencion efletexia'
);


-- ---------------------------------------------------------------------
-- 8) VERIFICACIÓN
-- ---------------------------------------------------------------------

-- 8.1 Debe salir: admin 22 | editor 19 | invitado 19
SELECT r.name AS rol, COUNT(rhp.permission_id) AS permisos
FROM `roles` r
LEFT JOIN `role_has_permissions` rhp ON rhp.role_id = r.id
GROUP BY r.name
ORDER BY r.name;

-- 8.2 Permisos de cada rol
SELECT r.name AS rol, p.name AS permiso
FROM `role_has_permissions` rhp
JOIN `roles` r ON r.id = rhp.role_id
JOIN `permissions` p ON p.id = rhp.permission_id
ORDER BY r.name, p.name;

-- 8.3 Roles de los usuarios: 1=admin | 2=editor+invitado | 3=invitado
SELECT mhr.model_id AS usuario, r.name AS rol
FROM `model_has_roles` mhr
JOIN `roles` r ON r.id = mhr.role_id
WHERE mhr.model_type = 'App\\Models\\User'
ORDER BY mhr.model_id, r.name;

-- 8.4 Usuarios del panel: deben salir los 3 activos
SELECT u.id, u.name, u.email, u.activo
FROM `users` u
WHERE u.id IN (1, 2, 3)
ORDER BY u.id;

-- 8.5 No debe devolver filas: permisos fuera del catalogo
SELECT p.name
FROM `permissions` p
WHERE p.name NOT IN (
    'ver llamadas', 'ver conductores', 'ver transportistas', 'ver referencias',
    'ver tiempos', 'ver reportes', 'ver scoring', 'ver lote',
    'ver lista de lotes', 'ver api', 'editar palabras clave', 'etiquetar llamada',
    'importar excel', 'importar json', 'importar vapi', 'compilar promedios',
    'gestionar duplicados conductores', 'gestionar casos conductores',
    'eliminar lote', 'fusionar conductores', 'administrar usuarios',
    'api de obtencion efletexia'
);

-- =====================================================================
-- FIN DEL ARCHIVO
-- =====================================================================
