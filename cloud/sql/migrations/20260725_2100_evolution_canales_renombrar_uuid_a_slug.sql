-- Renombra `evolution_canales.uuid` -> `evolution_canales.slug`. La columna
-- guarda el instance name del bot en Evolution API (usado como parte del path
-- en /message/sendText/<slug>, ver cloud/api/lib/mensajes_enviar.php). Se
-- renombra a `slug` para exponerlo como identificador humano estable en el
-- microservicio publico api/v4/evolution/mensajes.php (campo `canal_slug`) y
-- alinearlo con la convencion del resto de las tablas del stack
-- (proyectos.slug, datarocket_plantillas.slug).
--
-- El valor de la columna no cambia — los bots ya arriba (ej. "vigicom-bot",
-- "databox-bot") siguen matchando contra Evolution API sin reconfigurar nada
-- del lado del bot.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi corre
-- sobre bases nuevas (schema.sql ya trae `slug`) y sobre bases viejas (aun con
-- `uuid`).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_canales'
        AND COLUMN_NAME = 'slug') > 0,
    'SELECT 1',
    'ALTER TABLE `evolution_canales` CHANGE `uuid` `slug` varchar(50) NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
