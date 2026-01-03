FROM php:8.1-apache

RUN apt-get update \
    && apt-get install -y git unzip libpng-dev libonig-dev \
    && docker-php-ext-install pdo pdo_mysql gd \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html

RUN echo "date.timezone=UTC" > /usr/local/etc/php/conf.d/timezone.ini

EXPOSE 80
CMD ["apache2-foreground"]
