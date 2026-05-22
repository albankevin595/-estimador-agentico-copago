  # Dockerfile - Despliegue del proyecto PHP (Railway)
  FROM php:8.2-apache

  # Extensión PDO para conectarse a MySQL
  RUN docker-php-ext-install pdo_mysql

  # Copiar el proyecto al directorio que sirve Apache
  COPY . /var/www/html/

  # Railway asigna el puerto en la variable PORT; Apache debe escucharlo
  RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
   && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf
