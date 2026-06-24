FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite \
 && sed -i 's/Listen 80/Listen 8091/' /etc/apache2/ports.conf \
 && sed -i 's|<VirtualHost \*:80>|<VirtualHost *:8091>|' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

EXPOSE 8091
