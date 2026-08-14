<?php

namespace App\Console\Commands;

use App\Models\CertTemplate;
use App\Models\Coupon;
use App\Models\GiftCardTemplate;
use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\Ticket;
use Illuminate\Console\Command;

class SyncPinyinIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fboard:sync-pinyin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '为所有支持拼音搜索的表重新生成 pinyin_index 索引';

    /**
     * 所有启用了 HasPinyinSearch trait 的模型。
     */
    private array $models = [
        Notice::class,
        Knowledge::class,
        Coupon::class,
        Payment::class,
        Plan::class,
        Server::class,
        ServerMachine::class,
        CertTemplate::class,
        GiftCardTemplate::class,
        Ticket::class,
    ];

    public function handle(): void
    {
        foreach ($this->models as $modelClass) {
            $count = $modelClass::query()->count();
            $this->info("[{$modelClass}] 开始同步 {$count} 条记录...");

            $modelClass::query()
                ->orderBy('id')
                ->chunkById(200, function ($models) use ($modelClass) {
                    /** @var \Illuminate\Database\Eloquent\Model $model */
                    foreach ($models as $model) {
                        $model->setPinyinIndex();
                        // 直接用 query builder 更新，避免触发 saving 事件
                        // （如 Server 的 group_ids 权限校验会对历史脏数据抛异常）
                        $modelClass::query()
                            ->whereKey($model->getKey())
                            ->update(['pinyin_index' => $model->pinyin_index]);
                    }
                });

            $this->info("[{$modelClass}] 完成");
        }

        $this->info('全部拼音索引同步完成');
    }
}