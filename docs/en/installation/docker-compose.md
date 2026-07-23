# Quick Deployment Guide with Docker Compose

This guide explains how to quickly deploy Fboard using Docker Compose. By default, **a single service** covers Web (Octane), Horizon queues, and the node WebSocket server. Redis and Caddy also run inside the same image; only port `7001` is published. SQLite is available so you do not need a separate MySQL install for a quick trial.

### 1. Environment Preparation

Install Docker:
```bash
curl -sSL https://get.docker.com | bash

# For CentOS systems, also run:
systemctl enable docker
systemctl start docker
```

### 2. Deployment Steps

1. Clone the `compose` branch (ships `compose.yaml` as the all-in-one default, plus optional `compose.*.sample.yaml` variants):
   ```bash
   git clone -b compose --depth 1 https://github.com/Fearless743/Fboard
   cd Fboard
   # compose.yaml is already the single-service template; no copy needed.
   # Optional: cp compose.host.sample.yaml compose.yaml
   ```

2. Install database:

- Quick installation (Recommended for beginners)
```bash
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    fboard php artisan fboard:install
```
- Custom configuration installation (Advanced users)
```bash
docker compose run -it --rm fboard php artisan fboard:install
```
> Please save the admin dashboard URL, username, and password shown after installation.
>
> | File | Network | When to use |
> |------|---------|-------------|
> | `compose.yaml` / `compose.sample.yaml` | bridge + ports `7001:7001` | bare docker, custom reverse proxy, aaPanel + Docker (**default, single service**) |
> | `compose.host.sample.yaml` | `network_mode: host` | aaPanel native (openresty on host) |
> | `compose.1panel.sample.yaml` | bridge + external `1panel-network` | 1Panel users (so the container can reach 1Panel-managed MySQL/Redis) |
> | `compose.split.sample.yaml` | multi-container (web/horizon/ws-server/redis split) | K8s migration, advanced scaling only |
>
> Inside the default container, supervisord starts Octane + Horizon + `ws-server` (+ embedded Redis/Caddy). You do **not** need separate `web` / `horizon` / `ws-server` services unless you deliberately use the split template.

3. Start services:
```bash
docker compose up -d
```

4. Access the site:
- Default port: 7001
- Website URL: http://your-server-ip:7001

### 3. Version Updates

```bash
cd Fboard
docker compose pull && docker compose up -d
```

The container always runs `php artisan fboard:update` (migrate + plugin install + version cache + theme refresh) on boot, so no extra command is required.

> **Using a multi-service `compose.yaml` from before the all-in-one change?** Replace it with the new single-service `compose.yaml` (service name `fboard`), then:
> ```bash
> docker compose down
> docker compose pull && docker compose up -d
> ```
> Legacy templates that did not auto-run `fboard:update` on start can still use:
> ```bash
> docker compose pull && docker compose run -it --rm fboard php artisan fboard:update && docker compose up -d
> ```

### 4. Version Rollback

1. Modify the image tag in `compose.yaml` to the version you want to roll back to
2. Execute: `docker compose up -d`

### Important Notes

- If you need to use MySQL, please install it separately and redeploy
- Code changes require service restart to take effect
- You can configure Nginx reverse proxy to use port 80
- Node WebSocket no longer needs a separate host port `8076`; Caddy on `7001` upgrades `/ws` internally
