ARG ESPOCRM_VERSION=10.0.10

FROM composer:2.8 AS mq-dependencies

WORKDIR /app
COPY docker/runtime/mq-composer.json composer.json
COPY docker/runtime/mq-composer.lock composer.lock
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-req=ext-sockets \
    --optimize-autoloader

FROM espocrm/espocrm:${ESPOCRM_VERSION}-apache

USER root

COPY --from=mq-dependencies --chown=root:root /app/vendor/ /opt/studio-mq-vendor/
RUN docker-php-ext-install sockets pcntl \
    && php -r "require '/opt/studio-mq-vendor/autoload.php'; if (!extension_loaded('sockets') || !class_exists(\\PhpAmqpLib\\Connection\\AMQPStreamConnection::class)) { exit(1); }"

COPY --chown=root:root custom/Espo/Modules/StudioManagement/ /opt/studio-management/server/
COPY --chown=root:root client/custom/modules/studio-management/ /opt/studio-management/client/
COPY --chown=www-data:www-data client/res/templates/site/footer.tpl /usr/src/espocrm/client/res/templates/site/footer.tpl
COPY --chown=www-data:www-data client/res/templates/site/footer.tpl /var/www/html/client/res/templates/site/footer.tpl
COPY --chown=www-data:www-data html/main.html /usr/src/espocrm/html/main.html
COPY --chown=www-data:www-data html/main.html /var/www/html/html/main.html
COPY --chown=root:root docker/runtime/studio-template-files.conf /etc/apache2/conf-enabled/studio-template-files.conf
COPY --chmod=755 docker/runtime/studio-entrypoint.sh /usr/local/bin/studio-entrypoint.sh
COPY --chmod=755 docker/runtime/apache2-studio-bootstrap /usr/local/bin/apache2-studio-bootstrap

ENTRYPOINT ["studio-entrypoint.sh"]
CMD ["apache2-foreground"]
