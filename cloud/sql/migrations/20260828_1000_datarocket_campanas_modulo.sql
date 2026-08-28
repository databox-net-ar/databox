-- Datarocket > Campanas: alta del submodulo.
--
-- La campana es el eslabon que faltaba entre lo que Datarocket ya tenia
-- separado: el A QUIEN (`datarocket_listas` + la puente
-- `datarocket_prospectos_listas`), el QUE (`datarocket_plantillas`) y el POR
-- DONDE (los canales de `aws_canales` / `evolution_canales` /
-- `telegram_canales`). Hasta ahora los ABMs de mensajes encolaban de a UNO:
-- para mandarle a una lista de 3.000 prospectos habia que abrir el modal 3.000
-- veces. La campana es el registro que dice "esta lista x esta plantilla por
-- este canal" y que el expansor convierte en N filas de cola.
--
-- POR QUE DOS TABLAS Y NO UNA
-- ---------------------------
-- `datarocket_campanas`          -> la definicion (1 fila por campana).
-- `datarocket_campanas_mensajes` -> el PADRON (1 fila por destinatario).
--
-- El padron es lo que permite responder "a quien le toca, quien ya recibio y
-- quien quedo afuera y por que" sin depender de la cola del canal. La cola
-- (`aws_mensajes`, `evolution_mensajes`, `telegram_mensajes`) es TRANSPORTE:
-- se purga, rota y no guarda el motivo por el que un prospecto NO entro.
--
-- El padron NO duplica el mensaje. No tiene `asunto` ni `cuerpo`: eso vive en
-- la plantilla (texto fuente) y en la fila de la cola (texto ya resuelto por
-- prospecto). Guardar una tercera copia garantizaria drift. El padron guarda
-- solo identidad + resultado, y apunta a la fila de cola por `mensaje_id`.
--
-- El otro motivo para tener el padron es el THROTTLE: con el, el expansor no
-- necesita volcar 3.000 filas de golpe en `aws_mensajes`. Da de alta el padron
-- completo (barato, una tabla propia) y despues alimenta la cola de a lotes.
-- Pausar una campana pasa a ser "dejar de alimentar", no un UPDATE masivo
-- sobre la cola compartida con los envios manuales.
--
-- ALCANCE DE ESTA MIGRACION
-- -------------------------
-- Crea las tablas, los catalogos y los permisos del ABM. El job expansor
-- (`jobs/datarocket_campanas_expandir.php`) y la columna `campana_id` en las
-- tres colas de canal NO entran aca: son la etapa siguiente. Hasta que ese job
-- exista, el padron se puebla vacio y el ABM lo muestra como tal.
--
-- Idempotente en los 5 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: `datarocket_campanas` — la definicion.
-- ============================================================================
--
-- `slug` es el identificador estable (kebab-case, UNIQUE global), mismo
-- criterio que `datarocket_listas`, `_embudos`, `_etiquetas` y
-- `_redes_sociales`: es lo que van a referenciar los jobs sin depender del id
-- autoincremental ni del nombre editable.
--
-- `proyecto_id` es NULLABLE y queda SIN foreign key a proposito: `proyectos`
-- es una tabla compartida con las apps legacy del grupo y las tablas
-- Datarocket que la referencian no llevan FK contra ella (ver la migracion
-- 20260824_1300). Agregarla solo aca crearia una asimetria.
--
-- `canal_id` tampoco lleva FK: apunta a `aws_canales`, `evolution_canales` o
-- `telegram_canales` SEGUN `medio`. Una FK solo puede apuntar a una tabla, asi
-- que el destino lo resuelve el endpoint leyendo `medio` primero.
--
-- `lista_id` y `plantilla_id` SI llevan FK con ON DELETE RESTRICT: son tablas
-- Datarocket propias, y borrar la lista o la plantilla que una campana usa
-- dejaria la campana sin poder reconstruir a quien le mandaba ni con que.
--
-- Los contadores (`total`, `encolados`, `enviados`, `fallidos`, `omitidos`)
-- estan denormalizados sobre `datarocket_campanas_mensajes`. Los escribe el
-- expansor a medida que avanza; el ABM ofrece recalcularlos desde el padron
-- (?action=recalcular), igual que `datarocket_etiquetas.etiquetados`.
--
-- `programada` NULL en estado 'borrador' significa "todavia sin fecha". Una
-- campana pasa a 'programada' recien cuando tiene fecha: es el estado que el
-- expansor busca para levantarla.

CREATE TABLE IF NOT EXISTS `datarocket_campanas` (
  `id`                 int(11)      NOT NULL AUTO_INCREMENT,
  `proyecto_id`        int(11)      NULL DEFAULT NULL,
  `nombre`             varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug`               varchar(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion`        text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `medio`              varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `canal_id`           int(11)      NULL DEFAULT NULL,
  `lista_id`           int(11)      NULL DEFAULT NULL,
  `plantilla_id`       int(11)      NULL DEFAULT NULL,
  `prioridad`          tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `estado`             varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'borrador',
  `programada`         datetime     NULL DEFAULT NULL,
  `iniciada`           datetime     NULL DEFAULT NULL,
  `completada`         datetime     NULL DEFAULT NULL,
  `total`              int(11)      NOT NULL DEFAULT 0,
  `encolados`          int(11)      NOT NULL DEFAULT 0,
  `enviados`           int(11)      NOT NULL DEFAULT 0,
  `fallidos`           int(11)      NOT NULL DEFAULT 0,
  `omitidos`           int(11)      NOT NULL DEFAULT 0,
  `observaciones`      text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `fecha_creacion`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_drca_slug`(`slug`) USING BTREE,
  INDEX `idx_drca_proyecto`(`proyecto_id`) USING BTREE,
  INDEX `idx_drca_medio`(`medio`) USING BTREE,
  INDEX `idx_drca_estado`(`estado`) USING BTREE,
  INDEX `idx_drca_lista`(`lista_id`) USING BTREE,
  INDEX `idx_drca_plantilla`(`plantilla_id`) USING BTREE,
  -- El expansor levanta por (estado, programada): indice compuesto para que el
  -- barrido del cron no escanee la tabla entera en cada tick.
  INDEX `idx_drca_estado_programada`(`estado`, `programada`) USING BTREE,
  CONSTRAINT `fk_drca_lista`     FOREIGN KEY (`lista_id`)     REFERENCES `datarocket_listas`     (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_drca_plantilla` FOREIGN KEY (`plantilla_id`) REFERENCES `datarocket_plantillas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: `datarocket_campanas_mensajes` — el padron.
-- ============================================================================
--
-- Una fila por (campana, prospecto). El UNIQUE sobre ese par es lo que hace
-- REANUDABLE al expansor: correrlo dos veces sobre la misma campana no
-- duplica destinatarios, y el Migrador corta a los 30 s asi que el expansor va
-- a tener que reanudar si o si.
--
-- `destino` es el correo / celular RESUELTO al momento de expandir, no el que
-- tenga el prospecto hoy: si el prospecto cambia de mail despues del envio, el
-- padron tiene que seguir diciendo a donde se mando de verdad.
--
-- `mensaje_id` apunta a la fila de la cola del canal (`aws_mensajes`,
-- `evolution_mensajes` o `telegram_mensajes`, segun `datarocket_campanas.medio`).
-- Sin FK por el mismo motivo que `canal_id`: el destino depende del medio.
-- Ademas las colas se purgan, y una FK convertiria esa purga en un problema.
--
-- `motivo` es el campo que justifica la tabla: es donde queda escrito POR QUE
-- un prospecto no recibio (sin correo cargado, vetado, duplicado, rebote). La
-- cola del canal no puede guardar eso porque el mensaje nunca llego a existir.
--
-- `prospecto_id` lleva FK con ON DELETE CASCADE (a diferencia de la campana,
-- que restringe): si se borra un prospecto, su renglon del padron deja de
-- tener sentido y no hay nada que preservar — el resultado del envio, si
-- existio, sigue en la cola y en `datarocket_interacciones`.

CREATE TABLE IF NOT EXISTS `datarocket_campanas_mensajes` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `campana_id`     int(11)      NOT NULL,
  `prospecto_id`   int(11)      NOT NULL,
  `destino`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `estado`         varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pendiente',
  `motivo`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `mensaje_id`     int(11)      NULL DEFAULT NULL,
  `encolado`       datetime     NULL DEFAULT NULL,
  `enviado`        datetime     NULL DEFAULT NULL,
  `fecha_creacion` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_drcam_campana_prospecto`(`campana_id`, `prospecto_id`) USING BTREE,
  -- El expansor pide "los pendientes de esta campana" en cada lote, y el ABM
  -- cuenta por estado: el compuesto sirve a los dos.
  INDEX `idx_drcam_campana_estado`(`campana_id`, `estado`) USING BTREE,
  INDEX `idx_drcam_prospecto`(`prospecto_id`) USING BTREE,
  INDEX `idx_drcam_mensaje`(`mensaje_id`) USING BTREE,
  CONSTRAINT `fk_drcam_campana`   FOREIGN KEY (`campana_id`)   REFERENCES `datarocket_campanas`  (`id`) ON DELETE CASCADE  ON UPDATE RESTRICT,
  CONSTRAINT `fk_drcam_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos`(`id`) ON DELETE CASCADE  ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 3: catalogo `estados` — medio, estado de campana y estado del padron.
-- ============================================================================
--
-- `estados` no tiene UNIQUE, asi que cada INSERT va con su NOT EXISTS.
-- Convencion de `campo`: snake_case con el modelo en SINGULAR
-- (`datarocket_campana_medio`, no `datarocket_campanas.medio`).
--
-- Los `valor` del medio son legibles (`correo`, `whatsapp`, `telegram`) y NO
-- las letras legacy. `datarocket_plantillas.medio` sigue guardando 'C' / 'W'
-- (varchar(1) heredado), asi que el endpoint traduce con un mapa chico al
-- filtrar plantillas. Se eligio la forma legible porque es la que ya usa
-- `datarocket_interacciones`, que es donde la campana va a dejar rastro.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_campana_medio' AS c, 'Correo'   AS t, 'correo'   AS v, 1 AS o UNION ALL
  SELECT 'datarocket_campana_medio',       'WhatsApp',      'whatsapp',      2 UNION ALL
  SELECT 'datarocket_campana_medio',       'Telegram',      'telegram',      3
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- Ciclo de vida de la campana:
--   borrador    -> se esta armando; el expansor la ignora
--   programada  -> tiene fecha; el expansor la levanta cuando programada <= NOW()
--   expandiendo -> el expansor esta dando de alta el padron
--   enviando    -> el padron esta completo y se esta alimentando la cola
--   pausada     -> gate manual del operador; deja de alimentarse la cola
--   completada  -> no quedan pendientes en el padron
--   cancelada   -> se corto a proposito; los pendientes quedan en 'cancelado'
INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_campana_estado' AS c, 'Borrador' AS t, 'borrador'  AS v, 1 AS o UNION ALL
  SELECT 'datarocket_campana_estado',       'Programada',    'programada',     2 UNION ALL
  SELECT 'datarocket_campana_estado',       'Expandiendo',   'expandiendo',    3 UNION ALL
  SELECT 'datarocket_campana_estado',       'Enviando',      'enviando',       4 UNION ALL
  SELECT 'datarocket_campana_estado',       'Pausada',       'pausada',        5 UNION ALL
  SELECT 'datarocket_campana_estado',       'Completada',    'completada',     6 UNION ALL
  SELECT 'datarocket_campana_estado',       'Cancelada',     'cancelada',      7
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- Ciclo de vida de cada renglon del padron:
--   pendiente -> todavia no se encolo
--   encolado  -> ya existe la fila en la cola del canal (`mensaje_id`)
--   enviado   -> el motor del canal lo despacho OK
--   fallido   -> el motor devolvio error (el detalle va en `motivo`)
--   omitido   -> nunca se encolo a proposito (sin dato de contacto, vetado,
--                duplicado). El por que va en `motivo`.
--   cancelado -> la campana se cancelo antes de que le tocara
INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_campana_mensaje_estado' AS c, 'Pendiente' AS t, 'pendiente' AS v, 1 AS o UNION ALL
  SELECT 'datarocket_campana_mensaje_estado',       'Encolado',       'encolado',       2 UNION ALL
  SELECT 'datarocket_campana_mensaje_estado',       'Enviado',        'enviado',        3 UNION ALL
  SELECT 'datarocket_campana_mensaje_estado',       'Fallido',        'fallido',        4 UNION ALL
  SELECT 'datarocket_campana_mensaje_estado',       'Omitido',        'omitido',        5 UNION ALL
  SELECT 'datarocket_campana_mensaje_estado',       'Cancelado',      'cancelado',      6
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 4: permisos del submodulo.
-- ============================================================================
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datarocket.campanas.consultar' AS s, 'Datarocket > Campañas > Consultar' AS n UNION ALL
  SELECT 'datarocket.campanas.agregar',        'Datarocket > Campañas > Agregar'          UNION ALL
  SELECT 'datarocket.campanas.editar',         'Datarocket > Campañas > Editar'           UNION ALL
  SELECT 'datarocket.campanas.eliminar',       'Datarocket > Campañas > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 5: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================
--
-- Mismo cierre que el resto de las migraciones de permisos: reprograma el rol
-- con el listado completo para que los slugs nuevos queden incluidos sin pasar
-- por el ABM de Roles. El filtro `slug IS NOT NULL AND slug <> ''` excluye los
-- permisos del sistema legacy, que comparten tabla.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
