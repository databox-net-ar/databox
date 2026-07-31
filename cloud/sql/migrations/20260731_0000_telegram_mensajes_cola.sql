-- Convierte el envio de `telegram_mensajes` a un flujo ASINCRONO estilo
-- Evolution (WhatsApp): el POST del ABM solo encola con estado='pendiente',
-- y un cron worker (cloud/jobs/telegram_mensajes_enviar.php) los despacha
-- por lotes contra el endpoint MTProto /v4/telegram/mensajes.
--
-- Cambios:
--   1) Nuevo permiso `plataformas.telegram.mensajes.motor` (verbo propio,
--      distinto de los CRUD -- controla el iniciar/detener del motor). Mirror
--      exacto del permiso equivalente de Evolution.
--   2) Nuevo parametro `telegram.mensajes.enviar` (flag tri-estado del motor)
--      con default '1' = ESPERANDO. Semantica identica a la de Evolution:
--          '0' DETENIDO / '1' ESPERANDO / '2' ENVIANDO
--   3) Cambio SEMANTICO (no de schema) de `telegram_mensajes.canal_id`:
--      antes apuntaba a `telegram_bots.id` (envio Bot API), ahora apunta a
--      `telegram_canales.id` (envio MTProto). No hay CONSTRAINT en la tabla,
--      asi que no hace falta ALTER. Los mensajes viejos con canal_id
--      apuntando a bots inexistentes en canales quedan huerfanos -- se
--      limpian aca poniendo canal_id a NULL cuando el id no matchea ningun
--      canal habilitado (en dev al 2026-07-31 no hay mensajes historicos,
--      pero la migracion es defensiva para futuros re-runs).
--   4) Reprograma `desarrollador.permisos` con todos los permisos cloud
--      del env actual, mismo patron que las migraciones anteriores.
--
-- Idempotente en los 3 pasos (INSERT ... WHERE NOT EXISTS + IS NULL guards).

-- ============================================================================
-- Paso 1: alta del permiso del motor.
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permiso_tg_msg_motor (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_tg_msg_motor (slug, nombre) VALUES
('plataformas.telegram.mensajes.motor', 'Plataformas > Telegram > Mensajes > Motor (iniciar / detener)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_tg_msg_motor t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_tg_msg_motor;

-- ============================================================================
-- Paso 2: seed del parametro del motor (tri-estado, default ESPERANDO).
-- ============================================================================

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT 'telegram.mensajes.enviar',
       '1',
       CONCAT('Motor de envio de mensajes Telegram (tri-estado). ',
              '0 = detenido (pausa manual desde el ABM); ',
              '1 = esperando (cola vacia, worker duerme); ',
              '2 = enviando (hay pendientes, worker procesa). ',
              'Ver cloud/jobs/telegram_mensajes_enviar.php.')
WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'telegram.mensajes.enviar');

-- ============================================================================
-- Paso 3: limpiar canal_id huerfanos (defensivo).
-- ============================================================================
-- Si algun telegram_mensajes existente tiene canal_id apuntando a algo que
-- no es un telegram_canales valido, lo ponemos en NULL. El ABM va a exigir
-- canal valido en el proximo POST.

UPDATE `telegram_mensajes` tm
LEFT JOIN `telegram_canales` tc ON tc.id = tm.canal_id
SET tm.canal_id = NULL
WHERE tm.canal_id IS NOT NULL
  AND tc.id IS NULL;

-- ============================================================================
-- Paso 4: registrar el worker en el Programador de tareas.
-- ============================================================================
-- El scheduler minutal (cloud/jobs/_scheduler.php) lee esta tabla y dispara
-- los `script` con la `cron_expr` correspondiente. Mismo patron que
-- evolution/aws (que estan registradas via UI del Programador; aca lo
-- automatizamos para que el flujo funcione post-deploy sin paso manual).

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'telegram_mensajes_enviar',
       'Worker de la cola de mensajes Telegram (MTProto): toma pendientes de telegram_mensajes y los despacha via /v4/telegram/mensajes. Ver cloud/jobs/telegram_mensajes_enviar.php.',
       'php',
       'telegram_mensajes_enviar',
       '* * * * *',
       1,
       'skip',
       120,
       7
WHERE NOT EXISTS (SELECT 1 FROM `tareas` WHERE `nombre` = 'telegram_mensajes_enviar');

-- ============================================================================
-- Paso 5: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
