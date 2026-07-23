FROM php:8.2-apache

# Zona horaria del contenedor (America/Argentina/Buenos_Aires).
# Fuente de verdad del grupo — alinea de una sola vez:
#   - PHP: date_default_timezone_get() (via /usr/local/etc/php/conf.d/timezone.ini)
#   - libc: date(), time(), strftime() de PHP heredan la del TZ del entorno
#   - Cron: lee /etc/localtime -> dispara jobs en hora local
#   - Bash del contenedor: `date` muestra hora local, logs en hora local
# El SET time_zone de MySQL es independiente — vive en cloud/api/db.php.
# Ver el modulo "Herramientas > Editar zona horaria" del panel cloud para
# confirmar que todas las capas quedan alineadas.
ENV TZ=America/Argentina/Buenos_Aires
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
 && echo $TZ > /etc/timezone

# Deps del OS + extensiones PHP.
#   - cron: dispara el tick minutal del scheduler del "Programador de tareas".
#   - coreutils / procps: 'timeout', 'stdbuf', 'kill' — usados por el scheduler
#     para lanzar procesos en background con timeout y line-buffering.
#   - pcntl: signal handler en _bootstrap.php de los jobs para cerrar la fila
#     como 'timeout' cuando `timeout` de coreutils dispara SIGTERM.
#   - pdo_mysql: acceso a la BD.
RUN apt-get update \
 && apt-get install -y --no-install-recommends cron coreutils procps tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql pcntl \
 && echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini \
 && a2enmod rewrite \
 && sed -i 's/Listen 80/Listen 8091/' /etc/apache2/ports.conf \
 && echo 'Listen 8113' >> /etc/apache2/ports.conf \
 && echo 'Listen 8094' >> /etc/apache2/ports.conf \
 && sed -i 's|<VirtualHost \*:80>|<VirtualHost *:8091>|' /etc/apache2/sites-available/000-default.conf \
 && printf '<VirtualHost *:8113>\n\
    ServerName www.databox.net.ar\n\
    DocumentRoot /var/www/www\n\
    <Directory /var/www/www>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/www-error.log\n\
    CustomLog ${APACHE_LOG_DIR}/www-access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/www.conf \
 && a2ensite www \
 && printf '<VirtualHost *:8094>\n\
    ServerName robot.databox.net.ar\n\
    DocumentRoot /var/www/robot\n\
    <Directory /var/www/robot>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/robot-error.log\n\
    CustomLog ${APACHE_LOG_DIR}/robot-access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/robot.conf \
 && a2ensite robot \
 && mkdir -p /var/log/databox/cloud/ejecuciones \
 && chown -R www-data:www-data /var/log/databox \
 && chmod -R 755 /var/log/databox

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 8091 8113 8094

CMD ["/usr/local/bin/entrypoint.sh"]
