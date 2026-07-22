-- Elimina la tabla `aws_plantillas` (clonada desde `datarocketplantillas` en la
-- migracion 20260722_1500). El submodulo "AWS > Plantillas" se descarto y las
-- plantillas pasan a vivir bajo "Sistemas > Datarocket > Plantillas" leyendo/
-- escribiendo contra `datarocket_plantillas` (ver migracion 20260722_1900).
--
-- Los 4 permisos del ABM ya fueron renombrados a
-- `sistemas.datarocket.plantillas.*` en la migracion 20260722_2100, por lo
-- que aca solo hay que soltar la tabla huerfana.
--
-- Idempotente: DROP TABLE IF EXISTS. Compatible con MySQL 8 y MariaDB 10.11.

DROP TABLE IF EXISTS `aws_plantillas`;
