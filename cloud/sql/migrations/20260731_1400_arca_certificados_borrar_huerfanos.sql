-- arca_certificados: borra las filas huerfanas -- las que no estan
-- referenciadas por ninguna empresa via `datacount_empresas.certificado_id`.
--
-- Motivo:
--   La migracion de import 20260731_1200_import_certificados_legacy.sql
--   trae los tres certs que estaban cargados en `datacountempresas`
--   (Alvarez, Wescom, Alfatec). Al inspeccionarlos con openssl_x509_parse
--   se descubrio que la fila "Wescom" del legacy tenia por error el mismo
--   cert que Alvarez (mismo subject CN=databox, mismo Serial Number
--   CUIT=20248369451, mismo SHA1). Alguien la cargo mal en su momento.
--
--   Si algun dia se le asignara ese cert a la empresa Wescom real (CUIT
--   30-71488674-2) desde el ABM, cualquier request a AFIP fallaria porque
--   el `<Auth><Cuit>` iria con el CUIT de la empresa y el TA vendria
--   firmado con el cert de otro CUIT. Para evitarlo, se elimina toda fila
--   huerfana de `arca_certificados`.
--
--   La consulta es GENERICA (borra cualquier cert sin uso, no solo el de
--   Wescom) y IDEMPOTENTE (segunda corrida no borra nada porque ya no hay
--   huerfanos). Cuando en el futuro cargue certs nuevos desde el ABM y aun
--   no los asigne a ninguna empresa, esta migracion no los toca porque ya
--   esta marcada como aplicada en `migraciones` -- solo corre una vez.

DELETE ac
  FROM arca_certificados ac
  LEFT JOIN datacount_empresas dn ON dn.certificado_id = ac.id
 WHERE dn.certificado_id IS NULL;
