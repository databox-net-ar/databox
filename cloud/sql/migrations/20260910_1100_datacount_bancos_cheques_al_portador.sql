-- Datacount > Cheques: `beneficiario_razon` pasa a ser opcional.
--
--   beneficiario_razon varchar(255) NOT NULL
--   -> beneficiario_razon varchar(255) NULL DEFAULT NULL
--
-- POR QUE
-- -------
-- No todo cheque tiene beneficiario nominado: el librado AL PORTADOR se cobra
-- por simple entrega y en el papel no lleva a nombre de quien. Con la columna
-- NOT NULL el ABM obligaba a inventar un texto ("portador", "-", el nombre de
-- quien lo recibio) para poder guardar, y eso ensuciaba las busquedas y las
-- comparaciones contra `datacount_proveedores.cuit`.
--
-- A partir de aca NULL significa exactamente "al portador": no es un dato que
-- falte cargar, es la ausencia de beneficiario. La UI lo muestra como
-- "Al portador" en el listado, en la ficha y en la confirmacion de baja.
--
-- Se elige NULL y no la cadena vacia para que haya UNA sola forma de
-- representarlo: con las dos conviviendo, cualquier chequeo de "es al portador"
-- tendria que preguntar por las dos y tarde o temprano alguna consulta se
-- olvida de una. El paso 1 normaliza los '' que pudieran existir antes de
-- aflojar la restriccion.
--
-- `beneficiario_cuit` y `beneficiario_correo` ya eran nullables y no se tocan:
-- un cheque al portador tampoco los lleva, y el ABM los limpia solo.
--
-- ---------------------------------------------------------------------------
-- ORDEN DE DESPLIEGUE
-- ---------------------------------------------------------------------------
-- Esta migracion se aplica ANTES de publicar el codigo que deja guardar sin
-- beneficiario (cloud/api/datacount_bancos_cheques.php y
-- cloud/assets/js/app.js). Al reves, el alta sin beneficiario muere con un
-- error 1048 de columna NOT NULL.
--
-- Aflojar una restriccion es compatible hacia atras: el codigo viejo sigue
-- mandando siempre la razon social y nunca ve un NULL que no haya escrito el
-- codigo nuevo.
--
-- Idempotente en los dos pasos. Compatible MySQL 8 (dev) + MariaDB 10.11
-- (prod): sin sintaxis MariaDB-only, sin funciones almacenadas.


-- ---------------------------------------------------------------------------
-- 1) Normalizar los '' que pudieran existir
-- ---------------------------------------------------------------------------
-- Con la columna NOT NULL y el endpoint exigiendo la razon social no deberia
-- haber ninguno, pero un INSERT hecho por fuera del ABM pudo dejarlo. Corre
-- antes del MODIFY para que despues del paso 2 exista una unica
-- representacion de "al portador".
UPDATE `datacount_bancos_cheques`
   SET `beneficiario_razon` = NULL
 WHERE `beneficiario_razon` IS NOT NULL
   AND TRIM(`beneficiario_razon`) = '';

-- ---------------------------------------------------------------------------
-- 2) Aflojar la restriccion
-- ---------------------------------------------------------------------------
-- El UPDATE de arriba escribe NULL en una columna que todavia es NOT NULL: en
-- modo estricto eso es error 1048, asi que este paso tiene que correr igual
-- aunque el paso 1 no haya tocado ninguna fila -- y por eso el paso 1 filtra
-- por `TRIM() = ''`, que en la practica no matchea nada.
--
-- Se repite la definicion completa (varchar(255) + charset + collation) porque
-- MODIFY COLUMN la reescribe entera: omitir el COLLATE la haria caer en el
-- default del servidor, que en prod no es necesariamente el de la tabla.
--
-- Condicionado a `IS_NULLABLE = 'NO'` para que reaplicar la migracion no
-- reescriba la columna al pedo (un MODIFY sobre una tabla grande copia la
-- tabla entera en MariaDB).
SET @nn := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'datacount_bancos_cheques'
               AND COLUMN_NAME  = 'beneficiario_razon'
               AND IS_NULLABLE  = 'NO');
SET @sql := IF(@nn = 1,
  'ALTER TABLE `datacount_bancos_cheques`
     MODIFY COLUMN `beneficiario_razon` varchar(255)
       CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
       NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
