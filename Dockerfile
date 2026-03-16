FROM php:8.2-fpm

# PHP-Extensions installieren
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Composer installieren (falls benötigt)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Arbeitsverzeichnis setzen
WORKDIR /var/www/html

# Quellcode kopieren
COPY src/ /var/www/html/

# Berechtigungen setzen
RUN chown -R www-data:www-data /var/www/html
