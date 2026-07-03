#!/bin/sh
# Entrypoint del contenedor databox.
# Arranca cron (para las tareas del worker en /var/www/robot) y despues
# Apache en foreground — Apache es el proceso "visible" para Docker; si
# muere, el contenedor se reinicia. Cron corre en background del mismo
# contenedor: no es un servicio separado.
#
# El archivo /etc/cron.d/databox viene bind-mounteado desde ./robot/crontab
# (ver docker-compose.yml). Cron.d requiere que sea root:? y no world-writable.
# Le seteamos root:www-data + modo 664 para que:
#   - Cron lo acepte (owner root, no world-writable).
#   - El endpoint del panel (que corre como www-data) lo pueda re-escribir
#     desde la UI del Editor de cron sin necesidad de sudo.
# Cron re-lee /etc/cron.d/* automaticamente cuando cambia el mtime, asi que
# los cambios desde la UI se aplican solos dentro del minuto siguiente.
set -e

if [ -f /etc/cron.d/databox ]; then
  chown root:www-data /etc/cron.d/databox 2>/dev/null || true
  chmod 664 /etc/cron.d/databox 2>/dev/null || true
fi

service cron start

exec apache2-foreground
