# One image for every app container: web (nginx + php-fpm), queue worker and scheduler.
# Base image docs: https://serversideup.net/open-source/docker-php/
FROM serversideup/php:8.3-fpm-nginx AS base

USER root
RUN install-php-extensions intl
USER www-data

FROM base AS production

ENV PHP_OPCACHE_ENABLE=1

COPY --chown=www-data:www-data composer.json composer.lock /var/www/html/
RUN composer install --no-dev --no-interaction --prefer-dist --no-autoloader --no-scripts

COPY --chown=www-data:www-data . /var/www/html
# Filament's CSS/JS are not in git (they are published on composer install), so publish them here.
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && php artisan filament:assets
