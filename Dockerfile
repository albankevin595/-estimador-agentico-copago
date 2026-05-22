 # Dockerfile - Despliegue del proyecto PHP en Railway
  FROM php:8.2-apache

  # Extensión PDO para conectarse a MySQL
  RUN docker-php-ext-install pdo_mysql

  # Copiar el proyecto al directorio que sirve Apache
  COPY . /var/www/html/

  # Apache escucha en el puerto 80
  EXPOSE 80
