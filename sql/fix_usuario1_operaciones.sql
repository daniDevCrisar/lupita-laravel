-- =====================================================================
-- ACCESO TOTAL DEL USUARIO 1 (admin) - SQL DIRECTO PARA phpMyAdmin
--
-- PEGAR Y EJECUTAR en la base de PRODUCCION.
-- No necesita nada mas: solo INSERT/DELETE sobre las tablas de
-- permisos de Spatie para el usuario 1.
--
-- QUE HACE (4 pasos, en orden):
--   1. Crea el rol `admin` y los 22 permisos si no existen.
--   2. Borra TODAS las asignaciones del usuario 1 (empieza de cero,
--      asi no queda ni una fila huerfana ni un model_type raro).
--   3. Le asigna el ROL `admin`.
--   4. Le asigna los 22 PERMISOS DIRECTOS (asi el acceso no depende
--      de que el rol este bien armado en la base).
--
-- IMPORTANTE DESPUES:
--   php artisan optimize:clear
--   php artisan permission:cache-reset
--   y cerrar sesion / entrar de nuevo.
--   (Spatie cachea los permisos 24 h; sin esto el menu sigue igual)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) CREAR LO QUE FALTE (roles + los 22 permisos)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
    ('admin', 'web', NOW(), NOW());

INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
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
    ('editar palabras clave',                 'web', NOW(), NOW()),
    ('etiquetar llamada',                     'web', NOW(), NOW()),
    ('importar excel',                        'web', NOW(), NOW()),
    ('importar json',                         'web', NOW(), NOW()),
    ('importar vapi',                         'web', NOW(), NOW()),
    ('compilar promedios',                    'web', NOW(), NOW()),
    ('gestionar duplicados conductores',      'web', NOW(), NOW()),
    ('gestionar casos conductores',            'web', NOW(), NOW()),
    ('eliminar lote',                         'web', NOW(), NOW()),
    ('fusionar conductores',                  'web', NOW(), NOW()),
    ('administrar usuarios',                  'web', NOW(), NOW()),
    ('api de obtencion efletexia',            'web', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 2) LIMPIAR AL USUARIO 1 (deja solo lo que se asigna abajo)
--    Borra tambien las filas con model_type equivocado, que son
--    justamente las que Spatie no lee y por eso el usuario sale
--    restringido.
-- ---------------------------------------------------------------------
DELETE FROM `model_has_roles`       WHERE `model_id` = 1;
DELETE FROM `model_has_permissions` WHERE `model_id` = 1;

-- ---------------------------------------------------------------------
-- 3) ASIGNAR EL ROL `admin` AL USUARIO 1
--    El `model_type` debe ser el nombre de la clase del modelo:
--    App\Models\User
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `model_has_roles` (`role_id`, `model_type`, `model_id`)
SELECT r.`id`, 'App\\Models\\User', 1
FROM `roles` r
WHERE r.`name` = 'admin' AND r.`guard_name` = 'web';

-- ---------------------------------------------------------------------
-- 4) ASIGNAR LOS 22 PERMISOS DIRECTAMENTE AL USUARIO 1
--    Con esto el usuario 1 pasa TODAS las rutas con `permission:`
--    aunque el rol admin no este bien armado.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `model_has_permissions` (`permission_id`, `model_type`, `model_id`)
SELECT p.`id`, 'App\\Models\\User', 1
FROM `permissions` p
WHERE p.`guard_name` = 'web'
  AND p.`name` IN (
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

-- el administrador siempre activo
UPDATE `users` SET `activo` = 1 WHERE `id` = 1;


-- ---------------------------------------------------------------------
-- 5) VERIFICACION (debe salir: rol=admin, permisos=22)
-- ---------------------------------------------------------------------
SELECT 'ROL DEL USUARIO 1' AS verificacion,
       r.`name` AS rol,
       r.`guard_name` AS guard
FROM `model_has_roles` mhr
JOIN `roles` r ON r.`id` = mhr.`role_id`
WHERE mhr.`model_id` = 1
  AND mhr.`model_type` = 'App\\Models\\User';

SELECT 'PERMISOS DIRECTOS DEL USUARIO 1' AS verificacion,
       COUNT(*) AS cantidad
FROM `model_has_permissions` mhp
JOIN `permissions` p ON p.`id` = mhp.`permission_id`
WHERE mhp.`model_id` = 1
  AND mhp.`model_type` = 'App\\Models\\User'
  AND p.`guard_name` = 'web';

SELECT p.`name` AS permiso
FROM `model_has_permissions` mhp
JOIN `permissions` p ON p.`id` = mhp.`permission_id`
WHERE mhp.`model_id` = 1
  AND mhp.`model_type` = 'App\\Models\\User'
  AND p.`guard_name` = 'web'
ORDER BY p.`name`;
