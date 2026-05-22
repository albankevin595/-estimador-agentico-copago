  # Dockerfile - Despliegue del proyecto PHP en Railway
  FROM php:8.2-apache

  # Forzar que solo el MPM prefork esté activo (mod_php lo requiere)
  RUN a2dismod mpm_event 2>/dev/null; \
      a2dismod mpm_worker 2>/dev/null; \
      a2enmod mpm_prefork

  # Extensión PDO para conectarse a MySQL
  RUN docker-php-ext-install pdo_mysql

  # Copiar el proyecto al directorio que sirve Apache
  COPY . /var/www/html/

  # Railway entrega el puerto en la variable PORT; Apache debe escucharlo
  RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
   && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf
