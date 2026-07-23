# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# 优化说明：
# - 不再在 Dockerfile 内 git clone，改为 COPY 构建上下文中的源码
# - 将 composer 依赖与业务代码分层：composer.json/lock 单独 COPY
#   → 只要 composer.lock 不变，依赖安装层就不会重跑
# - 使用 BuildKit --mount=type=cache 实现 composer 下载缓存跨构建复用
# - 前端 SPA 在 CI 中预先构建至 public/assets/admin/（不在 Dockerfile 内构建）
# ---------------------------------------------------------------------------
FROM phpswoole/swoole:php8.5-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

# Layer 1: Docker runtime config files (rarely changes)
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-fboard.ini /usr/local/etc/php/conf.d/zz-fboard.ini

# Layer 2: Composer dependencies
# composer.lock 不变时该层命中缓存，不重跑 composer install
COPY composer.json composer.lock /www/
RUN --mount=type=cache,id=composer,target=/root/.composer/cache \
    composer install --no-dev --no-security-blocking

# Layer 3: Application source code (changes most often)
# CI 构建前已将 admin SPA 产物输出到 public/assets/admin/，会随 COPY 一并带入
COPY . /www/

# Layer 4: Storage link & permissions
RUN php artisan storage:link && \
    chown -R www:www /www && \
    chmod -R 775 /www && \
    mkdir -p /data && \
    chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
