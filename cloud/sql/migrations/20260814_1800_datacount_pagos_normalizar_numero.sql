-- Normaliza `datacount_pagos.numero` a la forma canonica `0001-00000123`:
-- bloques de digitos separados por un unico guion.
--
-- Es el mismo criterio que aplica ahora dcpNormalizarNumero() en
-- api/datacount_pagos.php sobre toda alta y modificacion; esta migracion pone
-- al dia lo que ya estaba cargado.
--
-- Que toca:
--   1) espacios al principio y al final  -> se recortan;
--   2) espacios del medio                -> pasan a ser un guion
--      ("00003 00000879" queda "00003-00000879");
--   3) separadores repetidos o mezclados ("0280 - 01678510", "0280--01678510")
--      -> colapsan a un unico guion;
--   4) guiones sobrantes en los extremos -> se descartan.
--
-- Que NO toca: los numeros cargados sin separador ("000500001234") y las
-- numeraciones que no siguen el formato AFIP (VEPs, comprobantes del exterior).
-- Partirlos a ciegas en punto de venta + numero seria inventar datos.
--
-- En la base de desarrollo al momento de escribir esto habia 4 filas con
-- espacios internos (ids 1442, 1531, 1535, 1626) y ninguna con espacios en los
-- extremos. En produccion puede ser otra cantidad — el UPDATE es idempotente y
-- solo alcanza a las filas que efectivamente tengan algo que corregir.
--
-- REGEXP_REPLACE existe en MariaDB 10.11 (prod) y en MySQL 8 (dev).

UPDATE `datacount_pagos`
   SET `numero` = TRIM(BOTH '-' FROM
                    REGEXP_REPLACE(TRIM(`numero`), '[[:space:]-]+', '-')
                  )
 WHERE `numero` IS NOT NULL
   AND TRIM(`numero`) <> ''
   AND `numero` <> TRIM(BOTH '-' FROM
                     REGEXP_REPLACE(TRIM(`numero`), '[[:space:]-]+', '-')
                   );

-- Un numero que quede vacio despues de limpiar (era solo espacios o guiones)
-- no aporta nada y ademas ensucia el control de duplicados: a NULL.
UPDATE `datacount_pagos`
   SET `numero` = NULL
 WHERE `numero` IS NOT NULL
   AND TRIM(`numero`) = '';
