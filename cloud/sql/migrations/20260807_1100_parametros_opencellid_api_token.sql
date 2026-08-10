-- Seed del parametro `opencellid.api_token` que consumen las vistas
-- Movistar > Zona de cobertura y Claro > Zona de cobertura para renderizar
-- el mapa Leaflet propio (reemplaza el iframe original a opencellid.org).
-- El token es el mismo formato pk.<hex32> que Unwired Labs entrega para
-- LocationIQ / OpenCellID y se usa en el proxy `api/cobertura_cells.php`
-- del lado servidor — nunca viaja al SPA.
--
-- Idempotente:
--   - INSERT protegido con WHERE NOT EXISTS (no pisa un token rotado a mano).
--   - UPDATE solo refresca el comentario (documenta la semantica actual).
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT 'opencellid.api_token',
       'pk.7accf98b48ca0294d7fd3afc2fe592dd',
       'Token Unwired Labs (formato pk.<hex32>) usado por api/cobertura_cells.php para consultar antenas en OpenCellID desde las vistas Movistar/Claro > Zona de cobertura. Rotar aca si se regenera en https://opencellid.org/.'
 WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'opencellid.api_token');

UPDATE `parametros`
   SET `comentario` = 'Token Unwired Labs (formato pk.<hex32>) usado por api/cobertura_cells.php para consultar antenas en OpenCellID desde las vistas Movistar/Claro > Zona de cobertura. Rotar aca si se regenera en https://opencellid.org/.'
 WHERE `variable` = 'opencellid.api_token';
