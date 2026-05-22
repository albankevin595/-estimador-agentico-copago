  # Dockerfile - Despliegue del proyecto PHP en Railway
  FROM php:8.2-apache

  # Forzar SOLO mpm_prefork: borrar todos los MPMs habilitados y dejar solo prefork
  RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf \
            /etc/apache2/mods-enabled/mpm_*.load \
   && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
   && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

  # Extensión PDO para conectarse a MySQL
  RUN docker-php-ext-install pdo_mysql

  # Copiar el proyecto al directorio que sirve Apache
  COPY . /var/www/html/

  # Railway entrega el puerto en la variable PORT; Apache debe escucharlo
  RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
   && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf
