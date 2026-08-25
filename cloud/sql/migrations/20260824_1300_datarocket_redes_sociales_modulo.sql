-- Datarocket > Redes sociales: alta del submodulo.
--
-- Cada fila es UNA CUENTA de una red social perteneciente a un proyecto del
-- grupo (la pagina de Facebook de Databox, el Instagram de Bataller, el canal
-- de YouTube de tal producto, etc.), con sus credenciales y los datos que hacen
-- falta para publicar en ella.
--
-- POR QUE UNA SOLA TABLA Y NO UNA POR RED
-- ---------------------------------------
-- Todas las redes se consumen igual: un perfil identificado por un handle y un
-- id externo, un par de credenciales OAuth (app_id + app_secret) y un token de
-- acceso con vencimiento. Lo que cambia entre Instagram y Telegram no es la
-- estructura sino QUE campos extra hace falta guardar — eso vive en
-- `datos_extra` (JSON libre) y no justifica una tabla por plataforma.
--
-- El catalogo de plataformas va en `estados` (campo
-- `datarocket_red_social_plataforma`) y no en un ENUM: agregar una red nueva
-- tiene que poder hacerse desde Herramientas > Editor de estados sin migracion.
-- Los `valor` estan elegidos para coincidir con los identificadores de
-- proveedor de Postiz (`x`, `linkedin-page`, `instagram-standalone`, ...), asi
-- el mapeo al publicar es directo y no hace falta una tabla de traduccion.
--
-- POSTIZ
-- ------
-- Postiz es quien va a ejecutar las publicaciones. Su modelo es: una
-- "integration" por cuenta conectada, identificada por un id opaco. Esta tabla
-- guarda ese id en `postiz_integration_id` para poder decir "esta cuenta
-- nuestra es aquella integracion de Postiz" a la hora de programar un post.
--
-- La API key de Postiz NO va aca: es por organizacion, no por cuenta, asi que
-- su lugar es la tabla `parametros` (Herramientas > Editor de parametros) o un
-- registro en `accesos`. Una copia por red social serian N copias del mismo
-- secreto para rotar.
--
-- SECRETOS
-- --------
-- `contrasena`, `app_secret`, `access_token` y `refresh_token` se guardan
-- cifrados con encriptar()/desencriptar() (cloud/api/db.php), la cifra
-- reversible legacy del grupo — misma decision que `accesos.contrasena` y
-- `datacount_bancos_cuentas.contrasena`. Son credenciales operativas que el
-- operador necesita poder copiar en claro, no hashes de autenticacion.
--
-- Idempotente en los 4 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: `datarocket_redes_sociales` — las cuentas.
-- ============================================================================
--
-- `slug` es el identificador estable de la cuenta (kebab-case, UNIQUE global),
-- mismo criterio que `datarocket_listas`, `datarocket_embudos` y
-- `datarocket_etiquetas`: es lo que van a referenciar los jobs de publicacion
-- sin depender del id autoincremental ni del nombre editable.
--
-- `proyecto_id` es NULLABLE y queda SIN foreign key a proposito: `proyectos` es
-- una tabla compartida con las apps legacy del grupo y ninguna de las tablas
-- Datarocket que la referencian (`datarocket_listas`, `datarocket_plantillas`)
-- lleva FK contra ella. Agregarla solo aca crearia una asimetria que despues
-- muerde en los borrados.
--
-- `cuenta_externa_id` es el id que usa la plataforma (page id de Facebook,
-- channel id de YouTube, business account id de Instagram, chat id de
-- Telegram). Es varchar y no bigint porque no todas las redes usan numeros.
--
-- `token_expira` NULL significa "el token no vence" (app passwords de Bluesky,
-- bot tokens de Telegram) — no "se desconoce": el ABM lo pinta como "Sin
-- vencimiento".

CREATE TABLE IF NOT EXISTS `datarocket_redes_sociales` (
  `id`                    int(11)      NOT NULL AUTO_INCREMENT,
  `proyecto_id`           int(11)      NULL DEFAULT NULL,
  `plataforma`            varchar(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nombre`                varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug`                  varchar(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tipo_cuenta`           varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `usuario`               varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `url`                   varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cuenta_externa_id`     varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `correo`                varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `contrasena`            varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `app_id`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `app_secret`            varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `access_token`          text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `refresh_token`         text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `token_expira`          datetime     NULL DEFAULT NULL,
  `postiz_integration_id` varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `postiz_estado`         varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pendiente',
  `postiz_sync`           datetime     NULL DEFAULT NULL,
  `datos_extra`           text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `observaciones`         text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activa`                tinyint(1)   NOT NULL DEFAULT 1,
  `fecha_creacion`        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_drrs_slug`(`slug`) USING BTREE,
  INDEX `idx_drrs_proyecto`(`proyecto_id`) USING BTREE,
  INDEX `idx_drrs_plataforma`(`plataforma`) USING BTREE,
  INDEX `idx_drrs_activa`(`activa`) USING BTREE,
  INDEX `idx_drrs_postiz_estado`(`postiz_estado`) USING BTREE,
  INDEX `idx_drrs_postiz_integration`(`postiz_integration_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: catalogo `estados` — plataformas, tipo de cuenta y estado Postiz.
-- ============================================================================
--
-- `estados` no tiene UNIQUE, asi que cada INSERT va con su NOT EXISTS.
--
-- Los `valor` de plataforma son los identificadores de proveedor de Postiz.
-- Si se agrega una red nueva desde el Editor de estados, usar el identificador
-- que use Postiz para esa red (no un nombre libre) o el mapeo al publicar deja
-- de funcionar.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_red_social_plataforma' AS c, 'Facebook'               AS t, 'facebook'              AS v,  1 AS o UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Instagram',                  'instagram',                  2 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Instagram (standalone)',     'instagram-standalone',       3 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'X (Twitter)',                'x',                          4 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'LinkedIn (perfil)',          'linkedin',                   5 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'LinkedIn (pagina)',          'linkedin-page',              6 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Threads',                    'threads',                    7 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'YouTube',                    'youtube',                    8 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'TikTok',                     'tiktok',                     9 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Pinterest',                  'pinterest',                 10 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Reddit',                     'reddit',                    11 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Telegram',                   'telegram',                  12 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Discord',                    'discord',                   13 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Slack',                      'slack',                     14 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Mastodon',                   'mastodon',                  15 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Bluesky',                    'bluesky',                   16 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Farcaster / Warpcast',       'farcaster',                 17 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Lemmy',                      'lemmy',                     18 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Nostr',                      'nostr',                     19 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'VK',                         'vk',                        20 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Dribbble',                   'dribbble',                  21 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'WordPress',                  'wordpress',                 22 UNION ALL
  SELECT 'datarocket_red_social_plataforma',       'Otra',                       'otra',                      99
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_red_social_tipo_cuenta' AS c, 'Perfil personal' AS t, 'personal'  AS v, 1 AS o UNION ALL
  SELECT 'datarocket_red_social_tipo_cuenta',       'Pagina',              'pagina',         2 UNION ALL
  SELECT 'datarocket_red_social_tipo_cuenta',       'Cuenta de negocio',   'negocio',        3 UNION ALL
  SELECT 'datarocket_red_social_tipo_cuenta',       'Canal',               'canal',          4 UNION ALL
  SELECT 'datarocket_red_social_tipo_cuenta',       'Grupo / comunidad',   'grupo',          5 UNION ALL
  SELECT 'datarocket_red_social_tipo_cuenta',       'Bot',                 'bot',            6
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- Ciclo de vida del vinculo con Postiz:
--   pendiente    -> cargada en el panel, todavia no conectada en Postiz
--   vinculada    -> tiene `postiz_integration_id` y publica
--   expirada     -> el token vencio; hay que reconectar desde Postiz
--   error        -> Postiz devuelve error al publicar
--   desvinculada -> se saco de Postiz a proposito (la cuenta sigue registrada)
INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_red_social_postiz_estado' AS c, 'Pendiente' AS t, 'pendiente'    AS v, 1 AS o UNION ALL
  SELECT 'datarocket_red_social_postiz_estado',       'Vinculada',      'vinculada',         2 UNION ALL
  SELECT 'datarocket_red_social_postiz_estado',       'Expirada',       'expirada',          3 UNION ALL
  SELECT 'datarocket_red_social_postiz_estado',       'Con error',      'error',             4 UNION ALL
  SELECT 'datarocket_red_social_postiz_estado',       'Desvinculada',   'desvinculada',      5
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 3: permisos del submodulo.
-- ============================================================================
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datarocket.redes_sociales.consultar' AS s, 'Datarocket > Redes sociales > Consultar' AS n UNION ALL
  SELECT 'datarocket.redes_sociales.agregar',        'Datarocket > Redes sociales > Agregar'          UNION ALL
  SELECT 'datarocket.redes_sociales.editar',         'Datarocket > Redes sociales > Editar'           UNION ALL
  SELECT 'datarocket.redes_sociales.eliminar',       'Datarocket > Redes sociales > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 4: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================
--
-- Mismo cierre que el resto de las migraciones de permisos: reprograma el rol
-- con el listado completo para que los slugs nuevos queden incluidos sin pasar
-- por el ABM de Roles. El filtro `slug IS NOT NULL AND slug <> ''` excluye los
-- permisos del sistema legacy, que comparten tabla.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
