# Fboard — Docker Compose 部署分支

本分支仅用于快速部署，默认 **一个服务** 同时覆盖：

| 能力 | 容器内进程 |
|------|------------|
| Web / API | Octane (Swoole) |
| 队列 | Horizon |
| 节点 WebSocket | `ws-server` |

镜像内还自带 Redis（Unix socket）与 Caddy（对外只暴露 `7001`，HTTP 与 WebSocket 同端口）。

## 快速开始

```bash
git clone -b compose --depth 1 https://github.com/Fearless743/Fboard
cd Fboard

# 一键安装（SQLite + 内置 Redis，适合体验）
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    xboard php artisan xboard:install

# 启动
docker compose up -d
```

访问：`http://SERVER_IP:7001`  
安装结束后请保存终端里打印的管理后台路径、账号与密码。

## 可选模板

默认 `compose.yaml` 即单服务 all-in-one。若有特殊网络需求，可覆盖：

| 文件 | 场景 |
|------|------|
| `compose.host.sample.yaml` | `network_mode: host`（宝塔本机 openresty） |
| `compose.1panel.sample.yaml` | 接入 1Panel 的 `1panel-network` |
| `compose.split.sample.yaml` | 拆成 web / horizon / ws-server（K8s 扩缩容） |

```bash
cp compose.host.sample.yaml compose.yaml   # 示例
```

## 更新

```bash
docker compose pull && docker compose up -d
```

容器启动时会自动执行 `php artisan xboard:update`（迁移 + 插件 + 主题刷新）。

## 从旧版「多服务」compose 迁移

若你仍在使用旧的 `web` + `horizon` + `ws-server` + `redis` 四服务模板：

1. `docker compose down`
2. 用本分支新的 `compose.yaml` 覆盖本地文件（服务名改为 `xboard`，不再需要独立 redis 服务）
3. `docker compose pull && docker compose up -d`
4. 节点侧若曾单独配置 WebSocket 端口 `8076`，可改为走面板 `7001` 同源路径（镜像内 Caddy 会转发）

## 镜像

- 仓库：`ghcr.io/fearless743/fboard`
- 默认 tag：`latest`
- 完整源码与 Dockerfile 见 `master` 分支
