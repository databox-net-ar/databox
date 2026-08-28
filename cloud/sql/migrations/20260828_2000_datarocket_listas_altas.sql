-- Datarocket > Listas: historial de altas.
--
-- El espejo de `datarocket_listas_bajas` (migracion 20260828_1400). Aquella
-- responde "por que encogio esta lista"; esta responde "de donde salieron los
-- suscriptos que tiene".
--
-- POR QUE UNA TABLA PROPIA
-- -----------------------
-- La tabla puente `datarocket_prospectos_listas` es PURO ESTADO: PK compuesta
-- (prospecto_id, lista_id) y nada mas. No tiene ni fecha. Sabe QUIEN esta
-- suscripto hoy y no sabe NADA de cuando entro, quien lo metio ni cuantas veces
-- entro y salio. Con solo esa tabla, "la lista paso de 300 a 1200 en marzo" no
-- se puede ni empezar a contestar.
--
-- Agregarle un `fecha_alta` a la puente hubiera sido mas barato, pero guarda una
-- sola alta: el dia que alguien se da de baja y se vuelve a suscribir, la fecha
-- vieja se pierde y con ella el hecho de que hubo un ciclo. Ese ciclo es
-- exactamente lo que interesa cuando se cruza con las bajas.
--
-- ES UN LOG DE EVENTOS, NO UNA TABLA DE ESTADO
-- --------------------------------------------
-- Mismo criterio que las bajas: SIN UNIQUE sobre (lista_id, prospecto_id). Si
-- alguien entra, rebota, se da de baja y lo vuelven a cargar, quedan DOS altas y
-- una baja. Colapsarlas perderia justamente la historia que la tabla existe para
-- guardar. Para saber quien esta suscripto AHORA esta la puente.
--
-- ARRANCA VACIA: NO HAY BACKFILL POSIBLE
-- -------------------------------------
-- Las bajas pudieron backfillearse desde `datarocket_campanas_mensajes.baja_lista`,
-- que guardaba la estampa. Aca no hay equivalente: la puente no tiene fecha, asi
-- que de las suscripciones que YA existen no se puede reconstruir ni cuando ni
-- quien. El historial empieza el dia que se aplica esta migracion.
--
-- Consecuencia practica, y hay que tenerla presente al leer la pestaña: una
-- lista con 5.000 suscriptos y 3 altas registradas NO crecio 3 — crecio 3 desde
-- que esto existe. Por eso la UI muestra el total del historial y no lo compara
-- contra el contador de suscriptos.
--
-- LAS FKs
-- -------
--   lista_id     -> ON DELETE CASCADE. Si la lista no existe, sus altas no
--                   significan nada. Igual que en bajas.
--   prospecto_id -> ON DELETE CASCADE. Si se borra a la persona (tipicamente un
--                   pedido de borrado de datos), su historial se va con ella:
--                   `destino` guarda el correo denormalizado y conservarlo seria
--                   dejar dato personal despues de un borrado.
--
-- NO LLEVA `campana_id` NI `mensaje_id`, al reves que las bajas. Alla existen
-- porque la baja automatica la origina un rebote de una campana concreta y hay
-- que poder responder "que envio la causo". Un alta no se origina nunca en una
-- campana: la escriben los dos ABMs (el editor de suscriptos de la lista y la
-- ficha del prospecto). Agregar las columnas "por simetria" seria dejar dos
-- campos que ningun escritor llena.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin
-- `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ---------------------------------------------------------------------------
-- 1. La tabla
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `datarocket_listas_altas` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `lista_id`     int(11) NOT NULL,
  `prospecto_id` int(11) NOT NULL,
  -- Denormalizado a proposito, mismo criterio que en bajas: es el dato de
  -- contacto que tenia el prospecto EN EL MOMENTO de entrar a la lista. Si
  -- despues se corrige el correo, esta columna sigue diciendo con que dato
  -- entro — que es la mitad de la respuesta cuando una campana le rebota.
  `destino`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Vocabulario en `estados` bajo `datarocket_lista_alta_motivo`.
  `motivo`       varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  -- Detalle legible. Hoy queda NULL en los dos escritores; existe para el dia
  -- que entre una carga masiva y haya que anotar de que lote salio.
  `detalle`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Quien la ejecuto. Mismo criterio que `sucesos.origen`: string libre corto.
  -- Es lo que distingue las dos puertas de entrada que existen hoy:
  -- 'abm/datarocketlistas' (editor de suscriptos de la lista) y
  -- 'abm/datarocketprospectos' (combo de listas en la ficha del prospecto).
  `origen`       varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  -- Usuario que la hizo. NULL si la escribio un automatismo sin sesion.
  `usuario_id`   int(11) NULL DEFAULT NULL,
  `fecha`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_drla_lista_fecha`  (`lista_id`, `fecha`) USING BTREE,
  INDEX `idx_drla_prospecto`    (`prospecto_id`) USING BTREE,
  INDEX `idx_drla_motivo_fecha` (`motivo`, `fecha`) USING BTREE,
  CONSTRAINT `fk_drla_lista`     FOREIGN KEY (`lista_id`)     REFERENCES `datarocket_listas` (`id`)     ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_drla_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ---------------------------------------------------------------------------
-- 2. Catalogo de motivos
-- ---------------------------------------------------------------------------
-- Un solo valor, porque un solo valor tiene escritor: las dos puertas de
-- entrada que existen hoy son las dos manuales y se diferencian por `origen`,
-- no por `motivo`. Sembrar 'importacion' o 'formulario' ahora seria poblar el
-- combo con opciones que nadie escribe. Cuando aparezca un alta no manual
-- (link de suscripcion, importador masivo), se agrega el valor aca y tanto el
-- endpoint como los chips de la UI lo toman solos.
INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
    SELECT 'datarocket_lista_alta_motivo' AS campo, 'manual' AS valor, 'Alta manual' AS texto, 1 AS orden
) x
WHERE NOT EXISTS (
    SELECT 1 FROM `estados` e WHERE e.`campo` = x.campo AND e.`valor` = x.valor
);

-- ---------------------------------------------------------------------------
-- 3. Permiso de consulta del historial
-- ---------------------------------------------------------------------------
-- Solo `consultar`: igual que las bajas, el historial no se edita ni se borra a
-- mano. No hay tabla puente rol-permiso en este esquema: `roles.permisos` es un
-- CSV de ids de `permisos` que resuelve computePermisosUsuario()
-- (cloud/api/lib/auth_check.php).
INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datarocket.listas.altas.consultar' AS s, 'Datarocket > Listas > Altas > Consultar' AS n
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
