-- Renombra el label visible de los 4 permisos cloud del ABM `datacount.pagos.*`
-- de "Datacount > Pagos > X" a "Datacount > Ordenes de pago > X". El slug
-- se mantiene intacto (`datacount.pagos.consultar`, `.agregar`, `.editar`,
-- `.eliminar`) para no romper roles ya asignados ni el chequeo de
-- `requirePermCrud('datacount.pagos')` en cloud/api/datacount_pagos.php.
--
-- Idempotente: si el nombre ya esta en la forma nueva, el UPDATE no toca la fila.

UPDATE `permisos` SET `nombre` = 'Datacount > Ordenes de pago > Consultar' WHERE `slug` = 'datacount.pagos.consultar';
UPDATE `permisos` SET `nombre` = 'Datacount > Ordenes de pago > Agregar'   WHERE `slug` = 'datacount.pagos.agregar';
UPDATE `permisos` SET `nombre` = 'Datacount > Ordenes de pago > Editar'    WHERE `slug` = 'datacount.pagos.editar';
UPDATE `permisos` SET `nombre` = 'Datacount > Ordenes de pago > Eliminar'  WHERE `slug` = 'datacount.pagos.eliminar';
