-- datarocket_etiquetas: agregar `fecha_uso` — ultima vez que la etiqueta se uso.
--
-- "Usar" una etiqueta es aplicarla a un recurso Datarocket. Hoy el unico
-- recurso que las consume son los prospectos, via la tabla puente
-- `datarocket_prospectos_etiquetas` (migracion 20260811_1600, renombrada en
-- 20260817_2700). Cada vez que un alta / edicion de prospecto escribe esa
-- puente, la capa PHP estampa `NOW()` en las etiquetas involucradas
-- (`marcarUsoEtiquetas()` en cloud/api/lib/datarocket_etiquetas_uso.php, que
-- llaman tanto el ABM cloud como el microservicio v4 de prospectos).
--
-- Para que sirve: el catalogo es global y crece con cada integracion que
-- etiqueta sobre la marcha (`POST /v4/datarocket/etiquetas?resolver=1`).
-- `etiquetados` dice CUANTOS prospectos la tienen puesta, pero no dice si
-- alguien la sigue usando: una etiqueta con 300 etiquetados cargados en 2025 y
-- ninguno desde entonces esta muerta igual que una con 0. `fecha_uso` es el
-- dato que falta para poder podar el catalogo.
--
-- Es NULLABLE a proposito: NULL significa "nunca se uso desde que existe la
-- columna", que no es lo mismo que "se uso en el epoch". El ABM lo pinta como
-- "—".
--
-- OJO — `fecha_uso` NO se recalcula desde la puente en el boton "Recalcular
-- etiquetados" del ABM, y este backfill es la unica vez que se deriva de ahi.
-- Motivo: la puente solo tiene las asignaciones VIGENTES. Si una etiqueta se
-- aplico y despues se quito, la fila desaparece (o la pisa el full-replace de
-- `syncEtiquetas`) y un recalculo la mandaria de vuelta a NULL, borrando un
-- uso que efectivamente ocurrio. La columna solo avanza; nunca retrocede.
--
-- La columna queda entre `fecha_creacion` y `fecha_modificacion` (AFTER
-- `fecha_creacion`) para que el orden fisico acompañe al de la UI, donde
-- "Usada" va justo antes de "Modificada".
--
-- Idempotente en los dos pasos. Compatible MySQL 8 (dev) + MariaDB 10.11
-- (prod): sin `IF NOT EXISTS` de MariaDB (patron information_schema +
-- PREPARE), sin funciones almacenadas y sin tablas temporales.

-- ============================================================================
-- Paso 1: agregar la columna `fecha_uso`.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_etiquetas'
    AND COLUMN_NAME  = 'fecha_uso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `datarocket_etiquetas` ADD COLUMN `fecha_uso` DATETIME NULL DEFAULT NULL AFTER `fecha_creacion`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: backfill desde la puente.
-- ============================================================================
--
-- La mejor aproximacion historica al "ultimo uso" es la asignacion mas
-- reciente que sobrevive en `datarocket_prospectos_etiquetas.fecha_creacion`
-- (datetime NOT NULL DEFAULT CURRENT_TIMESTAMP desde que se creo la tabla).
-- Las etiquetas sin ninguna asignacion vigente quedan en NULL — es la lectura
-- honesta: no hay registro de que se hayan usado.
--
-- El `WHERE fecha_uso IS NULL` deja el paso idempotente y, mas importante, no
-- pisa los usos que ya haya estampado la capa PHP si la migracion se vuelve a
-- correr (el valor en vivo siempre es mas nuevo que el derivado de la puente).
--
-- El `fecha_modificacion = fecha_modificacion` del SET no es redundante: esa
-- columna es `ON UPDATE CURRENT_TIMESTAMP`, asi que sin la asignacion explicita
-- este backfill le pondria la fecha de HOY a todo el catalogo. MySQL y MariaDB
-- suprimen el auto-update cuando la columna se asigna a mano en el UPDATE.
-- Mismo recaudo que toma marcarUsoEtiquetas() en cada estampado en vivo.

UPDATE `datarocket_etiquetas` e
  JOIN (
    SELECT etiqueta_id, MAX(fecha_creacion) AS ultima
      FROM `datarocket_prospectos_etiquetas`
     GROUP BY etiqueta_id
  ) g ON g.etiqueta_id = e.id
   SET e.`fecha_uso`          = g.ultima,
       e.`fecha_modificacion` = e.`fecha_modificacion`
 WHERE e.`fecha_uso` IS NULL;
