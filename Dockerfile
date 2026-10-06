# NoBlogs4Ever application image: Apache + PHP + WordPress Multisite + curated,
# checksum-locked plugins/themes/languages + the NoBlogs4Ever must-use plugin.
# Tenants can never add code to this image; operators release new images.
FROM php:8.4-apache-bookworm@sha256:9e811795a606ca7f8586dee48d32acf444985c19ac252d70d181cd71a4603898

LABEL org.opencontainers.image.title="NoBlogs4Ever" \
      org.opencontainers.image.description="Spiritual successor to NoBlogs: self-hostable, privacy-first blogging on WordPress Multisite" \
      org.opencontainers.image.licenses="GPL-2.0-or-later" \
      org.opencontainers.image.source="https://github.com/Moe98DE/noblogs4ever"

# Apply current Debian security updates, compile the PHP extensions, then remove
# compilers, kernel headers and -dev packages (including the base image's
# $PHPIZE_DEPS) so only runtime libraries ship. Same pattern as the official image.
RUN apt-get update \
 && apt-get upgrade -y \
 && savedAptMark="$(apt-mark showmanual)" \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" mysqli gd zip intl exif opcache \
 && apt-mark auto '.*' > /dev/null \
 && apt-mark manual $savedAptMark > /dev/null \
 && apt-mark auto $PHPIZE_DEPS libc6-dev > /dev/null \
 && find /usr/local -type f -name '*.so*' -exec ldd '{}' ';' 2>/dev/null | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print so }' | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual > /dev/null \
 && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
 && apt-get install -y --no-install-recommends default-mysql-client unzip ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/nbe

# WordPress core, plugins, themes and language packs: every download is SHA-256 verified.
COPY dependencies.lock.json ./
COPY scripts/fetch.php ./scripts/fetch.php
RUN php scripts/fetch.php /tmp/wordpress && cp -a /tmp/wordpress/. /var/www/html/ && rm -rf /tmp/wordpress

# WP-CLI for operators (checksum-pinned).
COPY scripts/wp-cli.sha256 /tmp/wp-cli.sha256
RUN curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar -o /usr/local/bin/wp \
 && cd /usr/local/bin && sha256sum -c /tmp/wp-cli.sha256 && chmod 755 wp

COPY scripts ./scripts
COPY app ./app
COPY tests ./tests
COPY config ./config

RUN cp -a app/mu-plugins /var/www/html/wp-content/ \
 && cp app/nbe-media.php /var/www/html/nbe-media.php \
 && cp config/wordpress.htaccess /var/www/html/.htaccess \
 && cp config/wp-config.php /var/www/html/wp-config.php \
 && cp config/php.ini /usr/local/etc/php/conf.d/nbe.ini \
 && cp config/uploads.conf /etc/apache2/conf-enabled/nbe.conf \
 && cp config/remoteip.conf /etc/apache2/conf-enabled/remoteip.conf \
 && a2enmod rewrite headers remoteip \
 && mkdir -p /var/lib/nbe/jobs /var/lib/nbe/logs /var/www/html/wp-content/uploads \
 && chown -R www-data:www-data /var/lib/nbe /var/www/html/wp-content/uploads \
 # Code is read-only for the web server user: no plugin/theme/core writes at runtime.
 && chmod -R a-w /var/www/html/wp-admin /var/www/html/wp-includes /var/www/html/wp-content/plugins /var/www/html/wp-content/themes /var/www/html/wp-content/mu-plugins /var/www/html/wp-content/languages \
 && chmod 755 /opt/nbe/scripts/entrypoint.sh \
 # No request logging: the application writes only allowlisted events.
 && sed -i '/CustomLog/d; /ErrorLog/d' /etc/apache2/sites-available/000-default.conf \
 && a2disconf other-vhosts-access-log

WORKDIR /var/www/html
ENTRYPOINT ["/opt/nbe/scripts/entrypoint.sh"]
CMD ["apache2-foreground"]
HEALTHCHECK --interval=30s --start-period=60s CMD php /opt/nbe/scripts/health.php
