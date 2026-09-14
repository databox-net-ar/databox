-- Datarocket > Expertos: alta del submodulo.
--
-- Cada fila es UN EXPERTO: la personalidad con la que una IA responde las
-- consultas de los interesados en UN proyecto del grupo. Lo que define al
-- experto es su `contexto` — el prompt de sistema que se le antepone al modelo
-- antes de la consulta del interesado: quien es, que producto representa, que
-- sabe, que precios maneja, que tono usa y que no debe contestar.
--
-- POR QUE UNA TABLA Y NO UN PARAMETRO
-- -----------------------------------
-- El prompt podria vivir en `parametros` como una clave por proyecto, pero es
-- un texto largo que se edita seguido y que hay que poder versionar por
-- proyecto, activar/desactivar sin borrarlo y referenciar desde los canales de
-- entrada (whatsapp, correo, web). Una clave suelta en `parametros` no da nada
-- de eso: `parametros` es para valores de runtime, no para contenido editorial.
--
-- POR QUE MARKDOWN
-- ----------------
-- El `contexto` se guarda en Markdown crudo, no en HTML ni en texto plano:
--   * Es el formato que los modelos de lenguaje leen mejor — encabezados,
--     listas y tablas le dan estructura al prompt sin gastar tokens en markup.
--   * El panel ya sabe renderizarlo (mdRender() en assets/js/app.js, el mismo
--     que usa la vista Documentacion), asi que la vista previa del ABM sale
--     gratis y sin sumar librerias.
--   * Se manda al modelo TAL CUAL esta guardado. No hay conversion de ida ni de
--     vuelta, que es donde se pierden los prompts.
--
-- POR QUE `proyecto_id` SIN FOREIGN KEY
-- -------------------------------------
-- `proyectos` es una tabla compartida con las apps legacy del grupo y las
-- tablas Datarocket nuevas que la referencian (`datarocket_redes_sociales`,
-- `datarocket_campanas`) no llevan FK contra ella. Agregarla solo aca crearia
-- una asimetria que despues muerde en los borrados. NULLABLE ademas porque un
-- experto puede ser transversal al grupo (el que contesta "quienes son
-- ustedes") y no pertenecer a ningun proyecto en particular.
--
-- Idempotente en los 3 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: `datarocket_expertos` — los expertos.
-- ============================================================================
--
-- `slug` es el identificador estable del experto (kebab-case, UNIQUE global),
-- mismo criterio que `datarocket_listas`, `datarocket_embudos`,
-- `datarocket_etiquetas` y `datarocket_redes_sociales`: es lo que van a
-- referenciar los canales de entrada ("contestá esta consulta con el experto
-- `databox-comercial`") sin depender del id autoincremental ni del nombre, que
-- es editable.
--
-- `contexto` es MEDIUMTEXT y no TEXT: un prompt de sistema con catalogo de
-- productos y preguntas frecuentes pasa los 64 KB de TEXT sin esfuerzo, y el
-- truncado de MySQL es silencioso — se perderia la cola del prompt sin que el
-- ABM avise. NULLABLE porque el experto se puede dar de alta primero y
-- redactarle el contexto despues.
--
-- `activo` = 0 saca al experto de la oferta sin borrarle el prompt, que es el
-- trabajo que costo hacer.

CREATE TABLE IF NOT EXISTS `datarocket_expertos` (
  `id`                 int(11)      NOT NULL AUTO_INCREMENT,
  `proyecto_id`        int(11)      NULL DEFAULT NULL,
  `slug`               varchar(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nombre`             varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `contexto`           mediumtext   CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activo`             tinyint(1)   NOT NULL DEFAULT 1,
  `fecha_creacion`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_drex_slug`(`slug`) USING BTREE,
  INDEX `idx_drex_proyecto`(`proyecto_id`) USING BTREE,
  INDEX `idx_drex_activo`(`activo`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: permisos del submodulo.
-- ============================================================================
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datarocket.expertos.consultar' AS s, 'Datarocket > Expertos > Consultar' AS n UNION ALL
  SELECT 'datarocket.expertos.agregar',        'Datarocket > Expertos > Agregar'        UNION ALL
  SELECT 'datarocket.expertos.editar',         'Datarocket > Expertos > Editar'         UNION ALL
  SELECT 'datarocket.expertos.eliminar',       'Datarocket > Expertos > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual.
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
