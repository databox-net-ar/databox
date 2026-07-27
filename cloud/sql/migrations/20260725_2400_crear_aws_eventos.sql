-- Crea `aws_eventos`: log de notificaciones que AWS SES publica en SNS y
-- que se reciben en `api/v4/aws/eventos.php`. Sirve para:
--   - actualizar `aws_mensajes.resultado` cruzando por `uuid` = SES MessageId
--   - dejar el raw del evento (util para debug / replay / auditoria)
--   - deduplicar re-envios de SNS via `sns_message_id`
--
-- Columnas:
--   uuid           : mail.messageId del SES event (indice, para join contra
--                    aws_mensajes.uuid)
--   sns_message_id : envelope.MessageId de la notificacion SNS (indice
--                    UNIQUE para deduplicar reentregas)
--   tipo           : eventType del SES event ('Delivery'/'Bounce'/
--                    'Complaint'/'Reject'/'Open'/'Click'/'Send'/'DeliveryDelay'/
--                    'RenderingFailure' — se guarda lowercased para
--                    consultar/filtrar)
--   subtipo        : detalle segundario (para Bounce: 'Permanent'/'Transient';
--                    para Complaint: 'abuse'/'fraud'/'virus'/'other';
--                    NULL para tipos sin subtipo natural)
--   destino        : email afectado (bouncedRecipients / complainedRecipients
--                    / mail.destination[0]) — util para columna del listado
--   raw            : JSON completo del SES event (mediumtext)
--   recibido       : datetime del ingreso al webhook (NOW en zona AR)
--
-- Idempotente: chequea existencia antes del CREATE.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_eventos') = 0,
    'CREATE TABLE `aws_eventos` (
       `id`              int(11) NOT NULL AUTO_INCREMENT,
       `uuid`            varchar(255) NULL DEFAULT NULL,
       `sns_message_id`  varchar(255) NULL DEFAULT NULL,
       `tipo`            varchar(30)  NULL DEFAULT NULL,
       `subtipo`         varchar(50)  NULL DEFAULT NULL,
       `destino`         varchar(255) NULL DEFAULT NULL,
       `raw`             mediumtext   NULL,
       `recibido`        datetime     NULL DEFAULT NULL,
       PRIMARY KEY (`id`),
       KEY `idx_uuid`      (`uuid`),
       KEY `idx_recibido`  (`recibido`),
       UNIQUE KEY `uk_sns_message_id` (`sns_message_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
