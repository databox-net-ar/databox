-- Agrega el permiso cloud `seguridad.usuarios.invitar` al catalogo. No estaba
-- en el seed inicial (20260711_1300_crear_permisos_cloud.sql) porque el flujo
-- de magic link se sumo despues.
--
-- Idempotente: mismo patron LEFT JOIN + IS NULL que la 1300.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT 'seguridad.usuarios.invitar' AS slug, 'Seguridad > Usuarios > Invitar' AS nombre) AS t
WHERE NOT EXISTS (SELECT 1 FROM `permisos` WHERE `slug` = 'seguridad.usuarios.invitar');
