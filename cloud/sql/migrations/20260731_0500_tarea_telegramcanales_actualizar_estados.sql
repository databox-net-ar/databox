-- Registra la tarea `telegramcanales_actualizar_estados` en el Programador.
-- El worker (cloud/jobs/telegramcanales_actualizar_estados.php) audita la
-- salud de las sesiones MTProto llamando `getSelf()` contra cada canal cuyo
-- `latido` este viejo o nulo -- asi detectamos sesiones caidas
-- (AUTH_KEY_UNREGISTERED / SESSION_REVOKED / USER_DEACTIVATED) incluso cuando
-- no hay trafico organico que fuerce el chequeo.
--
-- Es COMPLEMENTARIO al monitoreo pasivo del sender:
--   - Pasivo (cloud/api/lib/telegram_mensajes_enviar.php): cada envio OK
--     refresca `latido = NOW()` gratis. Cubre el caso "hay trafico".
--   - Activo (este cron): cada 5 min pasa por los canales con latido > 15 min
--     y los pinguea. Cubre el caso "no hay trafico".
--
-- Cadencia deliberadamente baja (cada 5 min, umbral 15 min) porque el cold
-- start de MadelineProto cuando el daemon IPC esta frio cuesta 5-15 s -- no
-- queremos correr esto cada minuto. Ademas compite por locks con el worker
-- de envio; con cadencia baja la colision es rara.
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS.

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'telegramcanales_actualizar_estados',
       'Audita canales Telegram con latido > 15 min: llama getSelf() y refresca online/latido. Complementa el monitoreo pasivo del sender. Ver cloud/jobs/telegramcanales_actualizar_estados.php.',
       'php',
       'telegramcanales_actualizar_estados',
       '*/5 * * * *',
       1,
       'skip',
       300,
       7
WHERE NOT EXISTS (SELECT 1 FROM `tareas` WHERE `nombre` = 'telegramcanales_actualizar_estados');
