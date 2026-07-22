<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);

        $rate = (float) ($this->server['rate'] ?? 1);
        foreach ($this->data as $uid => $v) {
            // 流量列是整数字节；倍率可能是 1.5 等 float，必须 round 后再写入，
            // 避免 SQLite/MySQL 严格模式下 float 写入 INTEGER 失败或截断不一致。
            $uInc = (int) max(0, (int) round(((float) $v[0]) * $rate));
            $dInc = (int) max(0, (int) round(((float) $v[1]) * $rate));
            if ($uInc === 0 && $dInc === 0) {
                continue;
            }
            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $uInc,
                        'd' => $dInc,
                    ],
                    ['t' => time()]
                );
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
