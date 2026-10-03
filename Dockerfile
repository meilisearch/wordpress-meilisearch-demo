# syntax=docker/dockerfile:1.7
# Production image for one demo site (SITE=blog|shop): WordPress + WP-CLI + the plugin built from the
# `plugin` build context + the site's theme, setup and data. Built by bin/fly-deploy.sh, which stages the plugin into .plugin/ (fly deploy has no named build contexts).
ARG WORDPRESS_VERSION=7.1.2

FROM composer:2 AS plugin-vendor
WORKDIR /app
COPY .plugin/composer.json .plugin/composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress --prefer-dist
COPY .plugin/src ./src
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM node:22-alpine AS plugin-assets
WORKDIR /app
COPY .plugin/package.json .plugin/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY .plugin/assets ./assets
RUN npm run build

FROM wordpress:${WORDPRESS_VERSION}-php8.3-apache AS base
ARG SITE
ARG WOOCOMMERCE_VERSION
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV SITE=${SITE} DEMO_ROOT=/var/www/html WP_CLI_CACHE_DIR=/tmp/wp-cli-cache WP_CLI_ALLOW_ROOT=1
RUN apt-get update && apt-get install -y --no-install-recommends less mariadb-client unzip && rm -rf /var/lib/apt/lists/* \
	&& curl -fsSL -o /usr/local/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
	&& echo "be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832  /usr/local/bin/wp" | sha512sum -c - \
	&& chmod +x /usr/local/bin/wp
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-demo.ini
COPY docker/apache-demo.conf /etc/apache2/conf-enabled/zz-demo.conf
# The base image's vhost sets DocumentRoot /var/www/html, which wins over a server-level DocumentRoot.
# ${DEMO_ROOT} stays literal on purpose: Apache expands it from the environment at runtime.
# hadolint ignore=SC2016
RUN sed -ri 's#/var/www/html#${DEMO_ROOT}#g' /etc/apache2/sites-available/000-default.conf

# Plugin (built), WooCommerce (shop only).
COPY .plugin/meilisearch.php .plugin/uninstall.php .plugin/readme.txt /opt/plugins/meilisearch/
COPY .plugin/src /opt/plugins/meilisearch/src
COPY .plugin/assets /opt/plugins/meilisearch/assets
COPY .plugin/languages /opt/plugins/meilisearch/languages
COPY --from=plugin-vendor /app/vendor /opt/plugins/meilisearch/vendor
COPY --from=plugin-assets /app/assets/js/autocomplete.min.js /opt/plugins/meilisearch/assets/js/autocomplete.min.js
RUN if [ "$SITE" = "shop" ]; then \
		curl -fsSL -o /tmp/wc.zip "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
		&& unzip -q /tmp/wc.zip -d /opt/plugins && rm /tmp/wc.zip; \
	fi

# Demo site: theme, setup, data, mu-plugin, scripts.
COPY ${SITE}/ /opt/demo/site/
COPY data/${SITE}/ /opt/demo/data/
COPY shared/mu-plugins/ /opt/demo/mu-plugins/
COPY docker/demo-entrypoint.sh bin/site-start.sh bin/site-first-boot.sh /opt/demo/bin/
RUN chmod +x /opt/demo/bin/*.sh

ENTRYPOINT ["/opt/demo/bin/demo-entrypoint.sh"]
CMD ["apache2-foreground"]

FROM base AS fly
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server supervisor && rm -rf /var/lib/apt/lists/*
COPY docker/supervisord.conf /etc/supervisor/conf.d/demo.conf
COPY docker/fly-start.sh /opt/demo/bin/fly-start.sh
RUN chmod +x /opt/demo/bin/fly-start.sh \
	&& sed -i 's/^\(opcache.validate_timestamps\).*/\1=0/' /usr/local/etc/php/conf.d/zz-demo.ini
ENV DEMO_ROOT=/data/html
ENTRYPOINT ["/opt/demo/bin/fly-start.sh"]
CMD []
