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
    /** 面板收到这份 report 的时刻，用于判断流量产生于哪次流量重置之前 */
    protected $reportTs;

    // 单个 Job 内是「每个用户一条 UPDATE」的串行写，超时会被整批丢弃且不进
    // failed_jobs。tries=1 时一次瞬时故障就永久丢流量，必须允许重试。
    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(array $server, array $data, $protocol, int $timestamp, ?int $reportTs = null)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->reportTs = $reportTs;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);
        if (empty($userIds)) {
            return;
        }

        // 节点倍率被配成 0 / NULL 时不应把所有流量记成 0（那样等于白送带宽）。
        $rate = (float) ($this->server['rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1.0;
        }

        // 旧版本已经入队的 Job 没有 reportTs，退回「按现在处理」，不丢流量。
        $reportTs = (int) ($this->reportTs ?: time());

        // 注意用 toBase()：Eloquent 的 pluck() 会应用 'timestamp' cast，
        // last_reset_at 会被转成 Carbon，(int) 转换会出错。
        $resetAt = User::toBase()
            ->whereIn('id', $userIds)
            ->pluck('last_reset_at', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $now = time();
        $touched = [];

        foreach ($this->data as $uid => $v) {
            $uid = (int) $uid;

            // 这份 report 是重置之前收到的：期间流量已由 performReset 清零。
            // 若照常累加，队列积压时会出现「刚重置完，u/d 又瞬间弹回超额」的假象，
            // 让用户以为刚买的套餐几分钟就没了。旧周期流量不该计入新额度。
            $lastResetAt = $resetAt[$uid] ?? 0;
            if ($lastResetAt > 0 && $reportTs < $lastResetAt) {
                continue;
            }

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
                    ['t' => $now]
                );

            $touched[] = $uid;
        }

        if (!empty($touched)) {
            Redis::sadd('traffic:pending_check', ...$touched);
        }
    }
}
