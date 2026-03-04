#!/bin/sh
cp /dev-config/nextcloud-dev.config.php /var/www/html/config/dev.config.php
chown www-data:www-data /var/www/html/config/dev.config.php
