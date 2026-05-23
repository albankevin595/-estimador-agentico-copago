FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html/

# Escuchar en [::] (IPv6 dual-stack) - obligatorio en Railway porque su red interna es IPv6
CMD ["sh", "-c", "php -S [::]:${PORT} -t /var/www/html"]
