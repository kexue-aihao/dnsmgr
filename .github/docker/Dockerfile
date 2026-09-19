ARG ALPINE_VERSION=3.24
FROM alpine:${ALPINE_VERSION}
# Setup document root
WORKDIR /app/www

# Install packages and remove default server definition
ARG SWOOLE_VERSION=6.2.2
RUN set -eux; \
    apk add --no-cache \
      bash \
      curl \
      nginx \
      ca-certificates \
      php83 \
      php83-ctype \
      php83-curl \
      php83-dom \
      php83-fileinfo \
      php83-fpm \
      php83-ftp \
      php83-gd \
      php83-gettext \
      php83-intl \
      php83-iconv \
      php83-mbstring \
      php83-mysqli \
      php83-opcache \
      php83-openssl \
      php83-phar \
      php83-sodium \
      php83-session \
      php83-simplexml \
      php83-tokenizer \
      php83-xml \
      php83-xmlreader \
      php83-xmlwriter \
      php83-zip \
      php83-pdo \
      php83-pdo_mysql \
      php83-pdo_sqlite \
      php83-pecl-swoole \
      php83-pecl-ssh2 \
      supervisor \
      && ln -sf /usr/bin/php83 /usr/bin/php; \
    \
    apk add --no-cache --virtual .swoole-build-deps \
      php83-dev \
      php83-pear \
      autoconf \
      automake \
      make \
      gcc \
      g++ \
      libc-dev \
      linux-headers \
      pkgconf \
      re2c \
      binutils \
      openssl-dev \
      curl-dev \
      pcre2-dev; \
    \
    curl -fsSL \
        "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" \
        -o /tmp/swoole.tgz; \
    mkdir -p /usr/src/swoole; \
    tar -xzf /tmp/swoole.tgz \
        -C /usr/src/swoole \
        --strip-components=1; \
    \
    cd /usr/src/swoole; \
    phpize83; \
    ./configure \
        --with-php-config=/usr/bin/php-config83 \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-cares \
        --enable-brotli \
        --enable-zstd; \
    make -j"$(getconf _NPROCESSORS_ONLN)"; \
    make install; \
    strip --strip-unneeded /usr/lib/php83/modules/swoole.so; \
    \
    cd /; \
    rm -rf /usr/src/swoole /tmp/swoole.tgz; \
    apk del .swoole-build-deps; \
    rm -rf /var/cache/apk/* /tmp/*

# Configure nginx - http
COPY .github/docker/config/nginx.conf /etc/nginx/nginx.conf

# Configure PHP-FPM
ENV PHP_INI_DIR /etc/php83
COPY .github/docker/config/fpm-pool.conf ${PHP_INI_DIR}/php-fpm.d/www.conf
COPY .github/docker/config/php.ini ${PHP_INI_DIR}/conf.d/custom.ini

# Configure supervisord
COPY .github/docker/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 工作流可用 CACHE_BUST 刷新 Composer 安装层；源码变化由 COPY 的内容哈希处理。
ARG CACHE_BUST=local

# Build the checked-out application, including local extensions and migrations.
# The repository-root .dockerignore excludes local configuration and backups.
COPY . /usr/src/www

# Install composer（与下面 install 一并随 CACHE_BUST 失效）
RUN echo "$CACHE_BUST" >/dev/null && wget https://getcomposer.org/download/latest-stable/composer.phar -O /usr/local/bin/composer && chmod +x /usr/local/bin/composer

RUN mkdir -p /usr/src/www/runtime && echo "$CACHE_BUST" >/dev/null && composer install -d /usr/src/www --no-interaction --no-dev --optimize-autoloader --no-cache

RUN adduser -D -s /sbin/nologin -g www www && chown -R www:www /usr/src/www /var/lib/nginx /var/log/nginx

# crontab
RUN echo "* * * * * cd /app/www && /usr/bin/php83 think certtask" | crontab -u www -

COPY .github/docker/config/run_tasks.sh /app/run_tasks.sh
RUN chmod +x /app/run_tasks.sh

# copy entrypoint script
COPY .github/docker/entrypoint.sh /entrypoint.sh
ENTRYPOINT ["sh", "/entrypoint.sh"]

# Expose the port nginx is reachable on
EXPOSE 80

# Let supervisord start nginx & php-fpm
CMD /usr/sbin/crond && /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

# Configure a healthcheck to validate that everything is up&running
HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1/fpm-ping || exit 1
