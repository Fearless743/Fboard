<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // 本项目 sqlite 连接会把 DB_DATABASE 包一层 base_path()，
        // 导致 ":memory:" 变成磁盘文件；测试强制真正的内存库。
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.prefix', '');
        if (isset($app['db'])) {
            $app['db']->purge();
        }

        return $app;
    }
}
