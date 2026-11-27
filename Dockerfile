# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.3.36-apache-bookworm
ARG COMPOSER_IMAGE=composer:2.9.8
FROM ${COMPOSER_IMAGE} AS composer_binary
FROM ${PHP_IMAGE} AS extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite bcmath intl mbstring zip opcache \
    && rm -rf /var/lib/apt/lists/*

FROM ${PHP_IMAGE} AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates openssl libicu72 libzip4 libonig5 libsqlite3-0 curl unzip \
    && rm -rf /var/lib/apt/lists/* \
    && update-ca-certificates \
    && test -s /etc/ssl/certs/ca-certificates.crt \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's@/var/run/apache2@/tmp/apache2@g; s@/var/lock/apache2@/tmp/apache2-lock@g' /etc/apache2/envvars \
    && mkdir -p /tmp/apache2 /tmp/apache2-lock /var/www/html \
    && chown -R www-data:www-data /tmp/apache2 /tmp/apache2-lock /var/www/html
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-vicartech.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/vicartech-entrypoint
ENV APP_ENV=local APP_DEBUG=false LOG_CHANNEL=stderr COMPOSER_HOME=/tmp/composer
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD curl --fail --silent http://127.0.0.1:8080/api/health > /dev/null || exit 1
ENTRYPOINT ["/usr/local/bin/vicartech-entrypoint"]
CMD ["apache2-foreground"]

FROM runtime AS development
ARG COMPOSER_CA_BUNDLE_SHA256=
COPY --chown=www-data:www-data . .
RUN --mount=type=secret,id=composer_ca_bundle,uid=33,gid=33,mode=0400 \
    if [ -f /run/secrets/composer_ca_bundle ]; then \
        test -n "$COMPOSER_CA_BUNDLE_SHA256" \
        && printf '%s  %s\n' "$COMPOSER_CA_BUNDLE_SHA256" /run/secrets/composer_ca_bundle | sha256sum --check --status \
        || exit 1; \
        export COMPOSER_CAFILE=/run/secrets/composer_ca_bundle \
            CURL_CA_BUNDLE=/run/secrets/composer_ca_bundle \
            SSL_CERT_FILE=/run/secrets/composer_ca_bundle \
            GIT_SSL_CAINFO=/run/secrets/composer_ca_bundle; \
    fi \
    && curl --fail --silent --show-error --location --max-time 30 https://api.github.com > /dev/null \
    && composer install --prefer-dist --no-interaction --no-progress --no-scripts

FROM runtime AS production_dependencies
ARG COMPOSER_CA_BUNDLE_SHA256=
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN --mount=type=secret,id=composer_ca_bundle,uid=33,gid=33,mode=0400 \
    if [ -f /run/secrets/composer_ca_bundle ]; then \
        test -n "$COMPOSER_CA_BUNDLE_SHA256" \
        && printf '%s  %s\n' "$COMPOSER_CA_BUNDLE_SHA256" /run/secrets/composer_ca_bundle | sha256sum --check --status \
        || exit 1; \
        export COMPOSER_CAFILE=/run/secrets/composer_ca_bundle \
            CURL_CA_BUNDLE=/run/secrets/composer_ca_bundle \
            SSL_CERT_FILE=/run/secrets/composer_ca_bundle \
            GIT_SSL_CAINFO=/run/secrets/composer_ca_bundle; \
    fi \
    && curl --fail --silent --show-error --location --max-time 30 https://api.github.com > /dev/null \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM runtime AS production
ENV APP_ENV=production APP_DEBUG=false
COPY --from=production_dependencies --chown=www-data:www-data /var/www/html /var/www/html
