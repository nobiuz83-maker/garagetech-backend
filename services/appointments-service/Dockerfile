FROM vicartech-laravel-runtime:stage1
USER root
RUN a2enmod ssl && chown -R 1000:1000 /tmp/apache2 /tmp/apache2-lock /var/www/html
WORKDIR /var/www/html
COPY --chown=1000:1000 . .
COPY docker/http-apache.conf /etc/apache2/sites-enabled/000-default.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/vicartech-service-entrypoint
RUN composer dump-autoload --no-scripts --no-interaction
USER 1000:1000
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD curl --fail --silent http://127.0.0.1:8080/api/health > /dev/null || exit 1
ENTRYPOINT ["/usr/local/bin/vicartech-service-entrypoint"]
CMD ["apache2-foreground"]
