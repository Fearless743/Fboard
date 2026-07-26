# Fboard — AGENTS.md

This is **Fboard**, a Laravel 12 + Octane (Swoole) proxy protocol management panel. Package name is `fboard/fboard`; Artisan commands use the `fboard:*` prefix.

## Quick commands

| What | Command |
|------|---------|
| Dev server (Octane/Swoole) | `php artisan octane:start --server=swoole` |
| Queue (sync by default) | `php artisan horizon` (production) or `QUEUE_CONNECTION=sync` |
| Install | `php artisan fboard:install` |
| Update (migrate+plugins) | `php artisan fboard:update` |
| List all hooks | `php artisan hook:list` |
| Run tests | `php artisan test` or `./vendor/bin/phpunit` |
| PHPStan (level 5) | `./vendor/bin/phpstan analyse` |
| Single test | `php artisan test --filter=ServerHandshakeTest` |

## Architecture

- **Octane (Swoole)** replaces FPM — uses `config/octane.php`, runs on port 7001. Swoole-specific config under `OCTANE_*` env vars.
- **Dual API versions**: `app/Http/Routes/V1/` (legacy) and `app/Http/Routes/V2/` (current). Controllers mirror this split.
- **Plugin system**: plugins live in `plugins/` (user) and `plugins-core/` (built-in), PHP namespace `Plugin\`. Plugin manager is a scoped singleton via `PluginServiceProvider`. Hook system uses `HookManager`.
- **Theme system**: `theme/` directory with frontend Vue3 views. Default theme is `Fboard`. Theme assets are copied to `public/theme/{name}` at runtime.
- **Admin panel**: React app at `public/assets/admin/` (compiled, no source in this repo).
- **WebSocket server**: `app/WebSocket/NodeWorker.php` — separate workerman process for node communication.
- **Settings**: stored in DB (`v2_settings` table), accessed via `admin_setting('key')` helper (see `app/Helpers/Functions.php`).
- **Protocol definitions**: `plugins-core/CoreProtocols/` registers schemas via hooks; subscription clients (`Clash`, `SingBox`, …) extend `App\Support\AbstractProtocol`. Protocol plugins may also use `ProtocolPluginInterface` / `AbstractProtocolPlugin`.
- **Jobs** use Redis + Horizon in production. Queue names: `traffic_fetch`, `stat`, `order_handle`, `send_email`, `send_telegram`, `node_sync`, `user_alive_sync`.

## Key directories

| Path | Purpose |
|------|---------|
| `app/Http/Routes/V1/` | Legacy API routes |
| `app/Http/Routes/V2/` | Current API routes |
| `plugins-core/` | Built-in protocol/plugin implementations |
| `plugins/` | Third-party/user plugins (initially empty) |
| `theme/Fboard/` | Default user frontend (Vue3) |
| `public/assets/admin/` | Admin panel built assets (React SPA) |

## Conventions

- Code style follows PSR-4 with namespaces: `App\`, `Library\`, `Plugin\`.
- `library/` is a top-level PSR-4 namespace (separate from `app/`).
- PHPStan at **level 5** analyzing `app/` only.
- **Helper function** `admin_setting()` is auto-loaded via `composer.json` files array (`app/Helpers/Functions.php`).
- Queue is `sync` in `.env` (local dev). Production uses Horizon with three supervisor queues: `data-pipeline`, `business`, `notification`.
- Octane pre-warms `PluginManager` and flushes `HookManager` per request.

## Docker details

- Based on `phpswoole/swoole:php8.5-alpine`.
- Caddy reverse-proxies to Octane (default) or Octane binds directly (`ENABLE_CADDY=false`).
- Entrypoint auto-tunes worker counts based on cgroup memory/cpu limits.
- `fboard:update` runs on every container start.

## Testing

- PHPUnit 12 with standard Laravel `RefreshDatabase` trait.
- Sparse tests: `tests/Feature/Server/ServerHandshakeTest.php` and `tests/Unit/Services/Auth/`.
- No CI workflows in `.github/workflows/`.
