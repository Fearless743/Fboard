# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Stage 1: build the admin SPA from the Fboard-admin source repository.
# The resulting /out directory is consumed by the final stage so the runtime
# image never has to ship Node.js or pnpm.
# ---------------------------------------------------------------------------
FROM node:20-alpine AS admin-builder

ARG ADMIN_REPO_URL=https://github.com/Fearless743/Fboard-admin
ARG ADMIN_BRANCH_NAME=master

RUN apk add --no-cache git
WORKDIR /build

RUN git clone --depth 1 --branch ${ADMIN_BRANCH_NAME} ${ADMIN_REPO_URL} . \
    && corepack enable \
    && pnpm install --frozen-lockfile \
    && sed -i 's|path.resolve(__dirname, "../Fboard/public/assets/admin")|"/out"|' vite.config.ts \
    && pnpm build

# ---------------------------------------------------------------------------
# Stage 2: PHP + Swoole runtime for the Xboard panel.
# ---------------------------------------------------------------------------
FROM phpswoole/swoole:php8.2-alpine

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
ARG REPO_URL=https://github.com/cedar2025/Xboard
ARG BRANCH_NAME=master
ARG ADMIN_REPO_URL=https://github.com/Fearless743/Fboard-admin
ARG ADMIN_BRANCH_NAME=master

RUN echo "Attempting to clone branch: ${BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} . && \
    git submodule update --init --recursive --force && \
    rm -rf public/assets/admin/.git public/assets/admin/* \
    && mkdir -p public/assets/admin

# Overlay the freshly-built admin SPA on top of the upstream submodule slot.
COPY --from=admin-builder /out /www/public/assets/admin

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

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
