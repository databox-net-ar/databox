FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite \
 && sed -i 's/Listen 80/Listen 8091/' /etc/apache2/ports.conf \
 && echo 'Listen 8094' >> /etc/apache2/ports.conf \
 && sed -i 's|<VirtualHost \*:80>|<VirtualHost *:8091>|' /etc/apache2/sites-available/000-default.conf \
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
 && a2ensite robot

WORKDIR /var/www/html

EXPOSE 8091 8094
