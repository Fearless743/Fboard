# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# 前端构建说明（重要！）：
# Dockerfile 不再内嵌构建 admin SPA，而是直接 COPY 已构建好的产物到
# /www/public/assets/admin/。请在 docker build 之前先构建前端：
#
#   cd ../admin && bun install && bun run build
#
# 该命令会通过 admin/vite.config.ts 中配置的 outDir
# (../Fboard/public/assets/admin) 直接把产物输出到本仓库内。
# CI 流程在 .github/workflows/docker-publish.yml 中已加入对应步骤。
#
# 这种"先构建再打包"的模式可以彻底规避 BuildKit 对远程 git 仓库的
# 缓存失效难题，前端版本与本地 Fboard 仓代码完全一致。
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

COPY .docker /

# Add build arguments
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/Fearless743/Fboard
ARG BRANCH_NAME=master

RUN echo "Attempting to clone branch: ${BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} . \
    && mkdir -p public/assets/admin

# 前端 SPA 产物在镜像构建前由 admin/ 仓的 `bun run build` 输出到
# public/assets/admin/。Dockerfile 仅负责 COPY，不再内嵌构建步骤，
# 以彻底规避 BuildKit 缓存陷阱。
COPY public/assets/admin/ /www/public/assets/admin/

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-fboard.ini /usr/local/etc/php/conf.d/zz-fboard.ini

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

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
