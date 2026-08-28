-- Datarocket > Listas: historial de bajas.
--
-- POR QUE UNA TABLA PROPIA
-- -----------------------
-- Hasta ahora el unico rastro de una desuscripcion era la estampa `baja_lista`
-- en `datarocket_campanas_mensajes`. Eso tiene dos problemas:
--
--   1. NO SOBREVIVE al borrado de la campana. El padron cuelga de
--      `fk_drcam_campana ... ON DELETE CASCADE`, asi que borrar una campana se
--      lleva puesta la razon por la que 200 personas dejaron de estar en una
--      lista. El mensaje en si sigue en `aws_mensajes` (que no tiene ninguna FK),
--      pero se pierde el vinculo "este rebote causo esta baja".
--   2. SOLO VE LAS BAJAS AUTOMATICAS. Las que hace un operador a mano desde el
--      editor de suscriptos no dejaban ningun rastro. Una estadistica de bajas
--      construida sobre el padron diria "perdimos 3 suscriptos" cuando en
--      realidad alguien saco 200 a mano.
--
-- Esta tabla es un LOG DE EVENTOS, no una tabla de estado: si alguien se da de
-- baja, se vuelve a suscribir a mano y rebota otra vez, quedan DOS filas. Por
-- eso no lleva UNIQUE sobre (lista_id, prospecto_id) — colapsarlas perderia
-- justamente la historia que la tabla existe para guardar.
--
-- LAS FKs: QUE CASCADEA Y QUE NO
-- ------------------------------
--   campana_id   -> ON DELETE SET NULL. Es EL punto de la tabla: la baja tiene
--                   que sobrevivir al borrado de la campana que la origino. La
--                   fila queda huerfana de campana pero conserva cuando, por
--                   que y a quien.
--   lista_id     -> ON DELETE CASCADE. Si la lista no existe, sus bajas no
--                   significan nada: "cuantos se dieron de baja de una lista
--                   borrada" no es una pregunta con respuesta util.
--   prospecto_id -> ON DELETE CASCADE. Si se borra a la persona (tipicamente un
--                   pedido de borrado de datos), su historial de bajas tiene que
--                   irse con ella. Conservarlo — con el correo denormalizado en
--                   `destino` — seria dejar dato personal despues de un borrado.
--
-- `mensaje_id` va SIN FK a proposito, igual que `datarocket_campanas_mensajes`:
-- apunta a `aws_mensajes`, que en este esquema no tiene ninguna FK y es el
-- registro durable de lo que se envio.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin
-- `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ---------------------------------------------------------------------------
-- 1. La tabla
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `datarocket_listas_bajas` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `lista_id`     int(11) NOT NULL,
  `prospecto_id` int(11) NOT NULL,
  -- Denormalizado a proposito: es la direccion CONCRETA que reboto, que puede
  -- no ser la que el prospecto tiene hoy (se pudo corregir despues). Sin esto,
  -- "por que lo dimos de baja" pierde el dato mas importante.
  `destino`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Vocabulario en `estados` bajo `datarocket_lista_baja_motivo`.
  `motivo`       varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  -- Detalle legible: el bounceType de SES ('Permanent'), el feedback type del
  -- complaint, o el texto que quiera dejar el operador en una baja manual.
  `detalle`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Quien la ejecuto. Mismo criterio que `sucesos.origen`: string libre corto
  -- ('cron/datarocket_campanas', 'abm/datarocketlistas').
  `origen`       varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Usuario que la hizo, cuando fue manual. NULL en las automaticas.
  `usuario_id`   int(11) NULL DEFAULT NULL,
  `campana_id`   int(11) NULL DEFAULT NULL,
  `mensaje_id`   int(11) NULL DEFAULT NULL,
  `fecha`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_drlb_lista_fecha`  (`lista_id`, `fecha`) USING BTREE,
  INDEX `idx_drlb_prospecto`    (`prospecto_id`) USING BTREE,
  INDEX `idx_drlb_motivo_fecha` (`motivo`, `fecha`) USING BTREE,
  INDEX `idx_drlb_campana`      (`campana_id`) USING BTREE,
  CONSTRAINT `fk_drlb_lista`     FOREIGN KEY (`lista_id`)     REFERENCES `datarocket_listas` (`id`)    ON DELETE CASCADE  ON UPDATE RESTRICT,
  CONSTRAINT `fk_drlb_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos` (`id`) ON DELETE CASCADE  ON UPDATE RESTRICT,
  CONSTRAINT `fk_drlb_campana`   FOREIGN KEY (`campana_id`)   REFERENCES `datarocket_campanas` (`id`)  ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ---------------------------------------------------------------------------
-- 2. Catalogo de motivos
-- ---------------------------------------------------------------------------
-- 'rebotado' y 'spam' los escribe drcaBajasPorRebote() desde el motor de
-- campanas; 'manual' lo escribe el editor de suscriptos del ABM de listas.
INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
    SELECT 'datarocket_lista_baja_motivo' AS campo, 'rebotado' AS valor, 'Rebote duro' AS texto, 1 AS orden
    UNION ALL SELECT 'datarocket_lista_baja_motivo', 'spam',   'Denuncia de spam', 2
    UNION ALL SELECT 'datarocket_lista_baja_motivo', 'manual', 'Baja manual',      3
) x
WHERE NOT EXISTS (
    SELECT 1 FROM `estados` e WHERE e.`campo` = x.campo AND e.`valor` = x.valor
);

-- ---------------------------------------------------------------------------
-- 3. Backfill desde las bajas ya estampadas en el padron
-- ---------------------------------------------------------------------------
-- Las bajas automaticas que ya ocurrieron viven como `baja_lista` en el padron.
-- Se las trae para que el historial arranque completo en vez de con un hueco.
--
-- El `NOT EXISTS` hace idempotente el paso: correr la migracion dos veces no
-- duplica el backfill. Se aparea por (lista, prospecto, campana) porque es lo
-- que identifica univocamente una baja automatica ya registrada.
--
-- `detalle` queda NULL en el backfill: el bounceType de esos eventos esta en
-- `aws_eventos`, pero reconstruirlo retroactivamente para filas historicas no
-- justifica el JOIN — de aca en adelante lo escribe el motor.
INSERT INTO `datarocket_listas_bajas`
       (`lista_id`, `prospecto_id`, `destino`, `motivo`, `origen`, `campana_id`, `mensaje_id`, `fecha`)
SELECT c.`lista_id`,
       m.`prospecto_id`,
       m.`destino`,
       CASE WHEN m.`resultado` = 'spam' THEN 'spam' ELSE 'rebotado' END,
       'backfill/20260828_1400',
       m.`campana_id`,
       m.`mensaje_id`,
       m.`baja_lista`
  FROM `datarocket_campanas_mensajes` m
  JOIN `datarocket_campanas` c ON c.id = m.`campana_id`
 WHERE m.`baja_lista` IS NOT NULL
   AND c.`lista_id`   IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM `datarocket_listas_bajas` b
         WHERE b.`lista_id`     = c.`lista_id`
           AND b.`prospecto_id` = m.`prospecto_id`
           AND b.`campana_id`   = m.`campana_id`
   );

-- ---------------------------------------------------------------------------
-- 4. Permiso de consulta del historial
-- ---------------------------------------------------------------------------
-- Solo `consultar`: el historial no se edita ni se borra a mano, es un log.
-- No hay tabla puente rol-permiso en este esquema: `roles.permisos` es un CSV
-- de ids de `permisos` que resuelve computePermisosUsuario()
-- (cloud/api/lib/auth_check.php).
INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datarocket.listas.bajas.consultar' AS s, 'Datarocket > Listas > Bajas > Consultar' AS n
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- Reprograma `desarrollador` con el listado completo de permisos cloud, para
-- que el slug nuevo entre sin pasar por el ABM de Roles. Mismo cierre que el
-- resto de las migraciones de permisos del repo. El filtro
-- `slug IS NOT NULL AND slug <> ''` excluye los permisos del sistema legacy,
-- que comparten la tabla.
SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
