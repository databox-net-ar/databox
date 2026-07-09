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

# Crontab del "Programador de tareas" del panel cloud.
# Este archivo es estatico (versionado en cloud/jobs/crontab); las tareas
# concretas viven en la tabla `tareas` y las dispara el scheduler minutal.
# cron requiere owner root y no world-writable — le ponemos root:root 644.
if [ -f /etc/cron.d/databox-cloud ]; then
  chown root:root /etc/cron.d/databox-cloud 2>/dev/null || true
  chmod 644 /etc/cron.d/databox-cloud 2>/dev/null || true
fi

# Asegurar el log dir de las ejecuciones (por si el volumen se recreo).
mkdir -p /var/log/databox/cloud/ejecuciones 2>/dev/null || true
touch /var/log/databox/cloud/scheduler.log 2>/dev/null || true
chown -R www-data:www-data /var/log/databox 2>/dev/null || true
chmod 644 /var/log/databox/cloud/scheduler.log 2>/dev/null || true

service cron start

exec apache2-foreground
