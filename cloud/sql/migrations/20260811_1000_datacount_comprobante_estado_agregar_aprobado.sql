-- Agrega el valor '5' Aprobado al catalogo `estados` para el campo
-- `datacount_comprobante_estado`. Se usa como equivalente a Autorizado
-- para comprobantes NO fiscales (talonarios que no van contra AFIP):
-- presupuestos, remitos internos, notas de entrega, etc.
--
-- Flujo: Pendiente ('2') -> Aprobado ('5') via
--   POST cloud/api/datacount_comprobantes.php?action=aprobar
-- (equivalente al ?action=autorizar del flujo fiscal, pero sin llamar a AFIP).
-- Cuando se aprueba, el backend asigna `serie` = ultimo numero usado en el
-- mismo talonario + 1, y adelanta `datacount_talonarios.serie` con el mismo
-- patron GREATEST() que usa la autorizacion fiscal.
--
-- Se posiciona con `orden = 5` para respetar el orden natural del catalogo
-- ('1' Preparacion, '2' Pendiente, '3' Autorizado, '0' Rechazado, '4'
-- Anulado, '5' Aprobado).
--
-- Idempotente: INSERT ... WHERE NOT EXISTS. La tabla `estados` no tiene
-- UNIQUE por diseno (se comparte con apps del grupo). Patron compatible
-- con MySQL 8 y MariaDB 10.11.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'datacount_comprobante_estado', 'Aprobado', '5', 5
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'datacount_comprobante_estado' AND `valor` = '5');
