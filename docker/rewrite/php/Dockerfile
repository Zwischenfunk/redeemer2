FROM php:8.4.27-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libldap2-dev libpq-dev libicu-dev unzip \
    && docker-php-ext-install -j"$(nproc)" ldap pdo_pgsql intl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/cache/redeemer /var/lib/redeemer/sessions \
    && chown www-data:www-data /var/cache/redeemer /var/lib/redeemer/sessions

COPY --from=composer:2.11 /usr/bin/composer /usr/local/bin/composer
COPY pool.conf /usr/local/etc/php-fpm.d/zz-redeemer.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-redeemer.ini

WORKDIR /srv/app
