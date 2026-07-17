-- Seedea la bandera de coordinacion entre el panel y el agente `openclaw` que
-- sincroniza las SIMs Claro (ver api/clarosims_sync_pedido.php).
--
-- La tabla `parametros` no tiene UNIQUE en `variable` (se comparte con las UIs
-- legacy del grupo que la usan sin constraint), asi que hacemos INSERT ...
-- WHERE NOT EXISTS para que la migracion sea idempotente.
--
-- Se seedea con valor "0" (nada pendiente). El PUT desde el panel la lleva a
-- "1" y el POST de openclaw la vuelve a "0" cuando consume el pedido.

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT
  'pedido_clarosims_sincronizar',
  '0',
  'Cuando =1, openclaw scrapea el portal de Claro (WAF con fingerprint dinamico bloquea a PHP+cURL) y postea el CSV a api/clarosims_sync.php. Se resetea a 0 cuando openclaw consume el pedido. Ver api/clarosims_sync_pedido.php.'
WHERE NOT EXISTS (
  SELECT 1 FROM `parametros` WHERE `variable` = 'pedido_clarosims_sincronizar'
);

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT
  'pedido_clarosims_sincronizar_marcado',
  NULL,
  'Timestamp del ultimo pedido en pedido_clarosims_sincronizar. Solo informativo.'
WHERE NOT EXISTS (
  SELECT 1 FROM `parametros` WHERE `variable` = 'pedido_clarosims_sincronizar_marcado'
);
