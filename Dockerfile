FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html/

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /var/www/html"]
