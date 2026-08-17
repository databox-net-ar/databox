-- datarocket_contactos: garantiza la invariante de identidad — el campo de
-- nombre que corresponde al `tipo` del contacto NUNCA puede quedar vacio,
-- porque es el que alimenta a `nombre`:
--
--   tipo = 'persona'  ->  persona_nombre  obligatorio  ->  nombre = persona_nombre
--   tipo = 'empresa'  ->  empresa_nombre  obligatorio  ->  nombre = empresa_nombre
--
-- CONTEXTO — por que hacen falta estos backfills
--
-- Al 17/08 habia 17.122 filas (de 43.240) donde el campo del tipo estaba
-- vacio pero `nombre` SI tenia el dato. Dos causas distintas:
--
--   * Bloque migrado (`id <= 148286`, 42.251 filas): viene del legacy
--     `datarocketcontactos`, que no tenia columna `tipo` — el tipo se asigno
--     despues por etiqueta en la serie 20260811_1900..2300. Ese proceso
--     completo `tipo` pero nunca replico `nombre` al campo del tipo, asi que
--     quedaron desalineados desde el dia uno.
--   * Bloque importado (`id > 148286`, 989 filas): lote cargado despues del
--     split. El importador estampo `tipo='persona'` por default (no tienen
--     ninguna de las etiquetas que usan las migraciones 1900..2300, sino
--     `Vigicom`/`Vigia`/`Reactor`/`Causam`, ids 32-35) y dejo
--     `persona_nombre` NULL en las 989.
--
-- QUE HACE Y QUE NO HACE
--
-- Copia `nombre` al campo del tipo cuando ese campo esta vacio. Nada mas.
-- En particular NO toca:
--
--   * `nombre` — no se reescribe ninguna fila. Las 5.133 filas que usan el
--     formato compuesto `EMPRESA - Persona` (heredado de datamarketcontactos)
--     se dejan como estan: tienen los dos campos cargados, o sea que ya
--     cumplen la invariante, y decidir si se colapsan al formato simple es
--     una decision de negocio aparte.
--   * `empresa_nombre` en contactos persona — 886 filas tipo='persona' tienen
--     algo cargado ahi. A veces es legitimo (la empresa donde trabaja la
--     persona, simetrico a `persona_nombre` en un contacto empresa = quien
--     atiende), a veces es basura del importador (un rubro, o directamente un
--     barrio: `Barrio pocito conjunto 4`, `El siambon. Tucuman`). Limpiar eso
--     requiere criterio caso por caso y NO bloquea la invariante — la fila
--     queda valida igual con `persona_nombre` cargado. Queda para un pase
--     posterior.
--
-- FILAS QUE ESTA MIGRACION NO PUEDE ARREGLAR
--
-- 5 contactos tienen `nombre`, `empresa_nombre` y `persona_nombre` los tres
-- NULL — el unico dato identificatorio es el celular (ids 148494, 148498,
-- 148633, 148769, 149209). No hay de donde copiar, asi que se dejan intactos:
-- inventarles un nombre seria peor que el problema. La validacion nueva del
-- endpoint los va a frenar la primera vez que alguien los edite, que es
-- exactamente cuando hay un humano para resolverlos.
--
-- ORDEN DE DESPLIEGUE: indistinto respecto del codigo. Los backfills solo
-- rellenan huecos; el codigo nuevo (validacion + derivacion de `nombre` en
-- cloud/api/datarocketcontactos.php y api/v4/datarocket/contactos.php) es
-- compatible con la tabla antes y despues de correr esto.
--
-- Idempotente: el WHERE exige que el destino este vacio, asi que la segunda
-- corrida matchea 0 filas. Solo DML, sin DDL — no hace falta el patron
-- information_schema + PREPARE. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) tipo = 'empresa' -> empresa_nombre = nombre   (~7.534 filas)
-- ---------------------------------------------------------------------------
UPDATE `datarocket_contactos`
   SET `empresa_nombre` = `nombre`
 WHERE `tipo` = 'empresa'
   AND COALESCE(`empresa_nombre`, '') = ''
   AND COALESCE(`nombre`, '')        <> '';


-- ---------------------------------------------------------------------------
-- 2) tipo = 'persona' -> persona_nombre = nombre   (~9.583 filas)
--
-- Incluye a proposito las 886 filas que ademas tienen `empresa_nombre`
-- cargado: la invariante mira SOLO el campo del tipo, y `empresa_nombre` se
-- deja tal cual esta (ver "QUE HACE Y QUE NO HACE" arriba).
-- ---------------------------------------------------------------------------
UPDATE `datarocket_contactos`
   SET `persona_nombre` = `nombre`
 WHERE `tipo` = 'persona'
   AND COALESCE(`persona_nombre`, '') = ''
   AND COALESCE(`nombre`, '')        <> '';
