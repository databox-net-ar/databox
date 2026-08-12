-- aws_servidores: renombrar `estado` -> `existe` — repurposar la vieja bandera
-- local (Activo/Inactivo, que no venia de AWS y ya no era editable desde la
-- UI) como marcador de soft-delete manejado por el boton "Obtener":
--
--   '1' = la instancia EC2 esta presente en AWS (fue vista en la ultima sync).
--   '0' = la instancia ya no aparece en AWS (fue eliminada desde la consola
--         del cliente); mantenemos la fila con sus SSH / notas por si el
--         miss fue transitorio o hay que preservar el registro historico.
--
-- Los valores existentes se preservan tal cual (todos son '1' porque nunca
-- se tocaba). El default de columna sigue en '1'.
--
-- Idempotente: solo renombra si `estado` existe y `existe` no.
-- Compatible MySQL 8 + MariaDB 10.11 (usamos CHANGE por portabilidad
-- historica, aunque RENAME COLUMN tambien funcionaria en ambos).

SET @colEstado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'estado'
);
SET @colExiste := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'existe'
);
SET @sql := IF(@colEstado = 1 AND @colExiste = 0,
  'ALTER TABLE aws_servidores CHANGE `estado` `existe` VARCHAR(1) NULL DEFAULT ''1''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
