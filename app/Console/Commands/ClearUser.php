<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;

class ClearUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理用户';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 主表已无套餐列：无任何实例行 = 无套餐。
        $builder = User::query()
            ->whereNull('last_login_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('v2_user_plan')
                    ->whereColumn('v2_user_plan.user_id', 'v2_user.id');
            });
        $count = $builder->count();
        if ($builder->delete()) {
            $this->info("已删除{$count}位没有任何数据的用户");
        }
    }
}
