FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/storage/manuscripts \
    && chown -R www-data:www-data /var/www/html/storage

ENV PORT=10000
EXPOSE 10000

CMD ["/usr/local/bin/entrypoint.sh"]