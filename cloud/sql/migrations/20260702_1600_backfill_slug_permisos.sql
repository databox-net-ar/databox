-- Backfill de `permisos.slug` a partir de `permisos.nombre`.
--
-- Genera un slug automatico normalizando el nombre:
--   1. LOWER (respeta la collation utf8mb4_general_ci que ya mapea A/E/I/O/U con tilde a su version minuscula).
--   2. Strip de acentos y diacriticos comunes en espanol (nested REPLACE).
--   3. Todo lo que no sea a-z0-9 pasa a '_' (colapsa runs consecutivos).
--   4. Trim de '_' al inicio (para que arranque con [a-z0-9], requisito del validador de la API).
--   5. Truncado a 50 caracteres (VARCHAR(50) de la columna).
--   6. Trim de '_' al final por si el truncado dejo uno colgando.
--
-- Nota: la API de permisos admite tambien '.' en el slug (para jerarquias tipo
-- 'usuarios.editar'), pero el backfill nunca lo introduce porque el nombre libre
-- no tiene marcadores confiables de jerarquia; el usuario puede editar el slug
-- despues si quiere usar puntos.
--
-- Idempotente: solo actualiza filas donde slug es NULL o vacio, asi que
-- correrla dos veces no pisa slugs ya seteados a mano ni por una corrida previa.
-- La tabla `permisos` no tiene UNIQUE(slug) en DB (convencion del grupo), asi que
-- si dos nombres colapsan al mismo slug van a coexistir; la API los detecta al
-- editar (assertSlugDisponible) y el usuario los diferencia manualmente.

UPDATE `permisos`
SET `slug` = REGEXP_REPLACE(
  LEFT(
    REGEXP_REPLACE(
      REGEXP_REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
          LOWER(`nombre`),
          'ñ','n'),'ü','u'),
          'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),
          'ç','c'),
        '[^a-z0-9]+', '_'
      ),
      '^_+', ''
    ),
    50
  ),
  '_+$', ''
)
WHERE (`slug` IS NULL OR `slug` = '')
  AND `nombre` IS NOT NULL
  AND TRIM(`nombre`) <> '';
