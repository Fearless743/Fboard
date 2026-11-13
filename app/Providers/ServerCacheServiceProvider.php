<?php

namespace App\Providers;

use App\Support\ServerCacheBag;
use Illuminate\Support\ServiceProvider;

class ServerCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 请求级节点缓存快照：Octane 每个请求结束会清理 scoped 实例
        $this->app->scoped(ServerCacheBag::class, fn () => new ServerCacheBag());
    }
}
