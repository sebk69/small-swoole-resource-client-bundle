FROM php:8.3-cli

# install swoole
RUN apt update && apt-get install -y libbrotli-dev
RUN pecl install swoole-5.1.6 && docker-php-ext-enable swoole

# libs
RUN docker-php-ext-install bcmath
RUN pecl install pcov && docker-php-ext-enable pcov

RUN apt-get update && apt-get install -y zlib1g-dev libpng-dev libzip-dev libfreetype-dev fonts-liberation2
RUN docker-php-ext-configure gd --with-freetype
RUN docker-php-ext-install gd

# Install composer
RUN apt update && apt-get install -y git
RUN apt update && apt-get install -y libzip-dev zip
RUN pecl install zip && docker-php-ext-enable zip
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php --install-dir=/usr/bin --filename=composer

# Prepare app
RUN mkdir /usr/src/small-swoole-entity-manager-bundle
COPY . /usr/src/small-swoole-resource-client-bundle
RUN cd /usr/src/small-swoole-resource-client-bundle && COMPOSER_ALLOW_SUPERUSER=1 composer update
WORKDIR /usr/src/small-swoole-resource-client-bundle

ENTRYPOINT sleep infinity