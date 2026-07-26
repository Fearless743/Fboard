<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        $schedule->command('fboard:statistics')->dailyAt('0:10')->onOneServer();
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:commission')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:traffic-exceeded')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        $schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->command('reset:log')->daily()->onOneServer();
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer();
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        $schedule->command('cleanup:online-status')->everyFiveMinutes()->onOneServer();
        // 自动备份：ENABLE_AUTO_BACKUP_AND_UPDATE=true 时由运维自行加入 schedule 或 cron
        app(PluginManager::class)->registerPluginSchedules($schedule);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Throwable $e) {
            Log::warning('Failed to initialize plugins in console: ' . $e->getMessage());
        }
        require base_path('routes/console.php');
    }
}
