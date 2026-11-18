FROM php:8.2-apache

# zip: necesario para exportar a Excel (.xlsx). SQLite ya viene incluido en la imagen.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/fiesta-entrypoint
RUN chmod +x /usr/local/bin/fiesta-entrypoint && rm -f /var/www/html/docker-entrypoint.sh /var/www/html/Dockerfile

# La base de datos (fiesta.sqlite) se guarda aquí: monta un volumen persistente en /data
ENV FIESTA_DATA_DIR=/data
VOLUME /data

EXPOSE 80
ENTRYPOINT ["fiesta-entrypoint"]
CMD ["apache2-foreground"]
