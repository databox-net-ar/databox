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
#
# Docker no permite chown sobre un bind mount desde adentro del contenedor,
# asi que NO podemos montar el archivo directo en /etc/cron.d/: aunque el
# entrypoint intente chown root:root, el archivo queda con el uid del host
# (tipicamente 1000). Cron lo tolera un rato pero termina dejando de
# tomarlo (incidente prod 2026-07-23: scheduler dejo de disparar despues
# de ~2 horas de arrancar el contenedor).
#
# Solucion: el bind mount monta el archivo en /opt/databox/crontab_cloud_source
# (ver docker-compose.yml) y aca lo copiamos a /etc/cron.d/ como root:root 644.
# La copia es un archivo REAL del contenedor, no un bind mount, asi que el
# chown funciona y cron lo acepta indefinidamente.
if [ -f /opt/databox/crontab_cloud_source ]; then
  cp /opt/databox/crontab_cloud_source /etc/cron.d/databox-cloud
  chown root:root /etc/cron.d/databox-cloud
  chmod 644 /etc/cron.d/databox-cloud
fi

# Asegurar el log dir de las ejecuciones (por si el volumen se recreo).
mkdir -p /var/log/databox/cloud/ejecuciones 2>/dev/null || true
touch /var/log/databox/cloud/scheduler.log 2>/dev/null || true
chown -R www-data:www-data /var/log/databox 2>/dev/null || true
chmod 644 /var/log/databox/cloud/scheduler.log 2>/dev/null || true

service cron start

exec apache2-foreground
