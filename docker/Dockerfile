ARG NEXTCLOUD_IMAGE_TAG=stable
FROM nextcloud:${NEXTCLOUD_IMAGE_TAG}

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
