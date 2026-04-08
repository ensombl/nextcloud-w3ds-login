ARG NEXTCLOUD_IMAGE_TAG=stable
FROM nextcloud:${NEXTCLOUD_IMAGE_TAG}

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgmp-dev \
    && docker-php-ext-install gmp \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
COPY entrypoint-wrapper.sh /entrypoint-wrapper.sh
RUN chmod +x /entrypoint-wrapper.sh

ENTRYPOINT ["/entrypoint-wrapper.sh"]
CMD ["apache2-foreground"]
