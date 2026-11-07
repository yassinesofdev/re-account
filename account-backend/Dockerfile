# account-backend — RE-Account Laravel API
# Targets:
#   dev  → used by the root docker-compose.yml (code is bind-mounted)
#   prod → default target, the image pushed to ECR for EKS
# Web server listens on 8080 inside the container (serversideup default).

FROM serversideup/php:8.4-fpm-nginx AS base
USER root
RUN install-php-extensions intl
USER www-data
WORKDIR /var/www/html

FROM base AS dev

FROM base AS prod
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY --chown=www-data:www-data . .
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
 && composer dump-autoload --optimize --no-dev
ENV PHP_OPCACHE_ENABLE=1
