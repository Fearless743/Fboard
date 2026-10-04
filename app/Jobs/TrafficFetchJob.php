<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

        if (UserPlan::isEnabled()) {
            $touched = $this->allocateMulti($userIds, $this->data, $rate, $reportTs, $resetAt, $now);
        } else {
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
        }

        if (!empty($touched)) {
            if (UserPlan::isEnabled()) {
                User::whereIn('id', $touched)->update(['t' => $now]);
            }
            Redis::sadd('traffic:pending_check', ...$touched);
        }
    }

    /**
     * 多套餐分摊：按用户批量预加载有效实例，delta 逐实例原子扣减。
     * - 带守卫 UPDATE（(u+d+take) <= quota），按实际影响行数结转；
     * - 取不满时回读一行求剩余容量再补扣，仍不够则顺延下一行；
     * - 全部实例满后仍有剩余，记到最后一行负债（节点非实时断流，超用不丢）；
     * - 行耗尽时顺手置 exhausted_at（只写一次）。
     *
     * @param array<int> $userIds
     * @param array<int, array{0:int,1:int}> $data
     * @param array<int, int> $resetAt user_id => last_reset_at
     * @return array<int> 实际发生扣减的用户 id
     */
    private function allocateMulti(array $userIds, array $data, float $rate, int $reportTs, array $resetAt, int $now): array
    {
        $query = UserPlan::query()->whereIn('user_id', $userIds);
        UserPlan::applyActive($query, $now);
        UserPlan::applyDeductionOrder($query);
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, UserPlan>> $grouped */
        $grouped = $query->get()->groupBy('user_id');

        $touched = [];
        foreach ($data as $uid => $v) {
            $uid = (int) $uid;

            // 防回拨语义与单套餐一致：实例重置会同步刷新 user.last_reset_at，
            // 重置前收到的 report 不计入新周期。
            $lastResetAt = $resetAt[$uid] ?? 0;
            if ($lastResetAt > 0 && $reportTs < $lastResetAt) {
                continue;
            }

            $uInc = (int) max(0, (int) round(((float) $v[0]) * $rate));
            $dInc = (int) max(0, (int) round(((float) $v[1]) * $rate));
            if ($uInc === 0 && $dInc === 0) {
                continue;
            }

            $rows = $grouped->get($uid, collect());
            if ($rows->isEmpty()) {
                continue;
            }

            [$leftU, $leftD] = $this->allocateToRows($rows, $uInc, $dInc, $now);
            if ($leftU === $uInc && $leftD === $dInc) {
                continue;
            }
            $touched[] = $uid;
        }

        return $touched;
    }

    /**
     * 把 (uInc, dInc) 按扣减顺序分摊到给定实例行，返回 [剩余 u, 剩余 d]。
     *
     * @param \Illuminate\Support\Collection<int, UserPlan> $rows 已按扣减顺序排好
     * @return array{0:int, 1:int}
     */
    private function allocateToRows($rows, int $uInc, int $dInc, int $now): array
    {
        $remU = $uInc;
        $remD = $dInc;
        $last = null;

        foreach ($rows as $row) {
            if ($remU <= 0 && $remD <= 0) {
                break;
            }
            $last = $row;

            // 先试足额一次扣完（u/d 合并为一条 UPDATE，守卫总量）。
            $takeU = $remU;
            $takeD = $remD;
            if ($this->guardedTake($row->id, $takeU, $takeD)) {
                $remU = 0;
                $remD = 0;
                $this->markExhaustedIfFull($row, $takeU + $takeD, $now);
                break;
            }

            // 守卫未命中：回读一行求剩余容量再补扣（并发/部分填充场景）。
            $fresh = UserPlan::query()->whereKey($row->id)->first(['id', 'u', 'd', 'transfer_enable', 'exhausted_at']);
            if (!$fresh) {
                continue;
            }
            $capacity = (int) $fresh->transfer_enable - (int) $fresh->u - (int) $fresh->d;
            if ($capacity <= 0) {
                $this->stampExhausted((int) $row->id, $now);
                continue;
            }
            $takeU = min($remU, $capacity);
            $takeD = min($remD, $capacity - $takeU);
            if ($takeU <= 0 && $takeD <= 0) {
                continue;
            }
            if ($this->guardedTake($row->id, $takeU, $takeD)) {
                $remU -= $takeU;
                $remD -= $takeD;
                // 补扣后该行恰好填满：耗尽时间只写一次。
                if ($takeU + $takeD >= $capacity) {
                    $this->stampExhausted((int) $row->id, $now);
                }
            }
            // 竞争失败（影响 0 行）：剩余量原样结转下一行，本行不动。
        }

        // 全部实例已满仍有剩余：记到最后一行负债，不丢流量。
        if (($remU > 0 || $remD > 0) && $last) {
            UserPlan::query()->whereKey($last->id)->update([
                'u' => DB::raw('u + ' . $remU),
                'd' => DB::raw('d + ' . $remD),
            ]);
            $this->stampExhausted((int) $last->id, $now);
            $remU = 0;
            $remD = 0;
        }

        return [$remU, $remD];
    }

    /**
     * 带守卫的单行扣减：(u+d+take) <= quota 才写，返回是否命中。
     */
    private function guardedTake(int $rowId, int $takeU, int $takeD): bool
    {
        if ($takeU === 0 && $takeD === 0) {
            return true;
        }
        if ($takeU < 0 || $takeD < 0) {
            return false;
        }

        return UserPlan::query()->whereKey($rowId)
            ->whereRaw('(u + d + ' . $takeU . ' + ' . $takeD . ') <= transfer_enable')
            ->update([
                'u' => DB::raw('u + ' . $takeU),
                'd' => DB::raw('d + ' . $takeD),
            ]) > 0;
    }

    /**
     * 行可能已满时顺手打耗尽时间：预加载快照提示还有空间就不打（省一次写），
     * 真满的行由守卫 UPDATE 兜底（实际未满则影响 0 行，无害）。
     */
    private function markExhaustedIfFull(UserPlan $row, int $take, int $now): void
    {
        $snapshotUsed = (int) $row->u + (int) $row->d;
        if ($snapshotUsed + $take < (int) $row->transfer_enable) {
            return;
        }
        $this->stampExhausted((int) $row->id, $now);
    }

    /**
     * 耗尽时间只写一次。
     */
    private function stampExhausted(int $rowId, int $now): void
    {
        UserPlan::query()->whereKey($rowId)
            ->whereNull('exhausted_at')
            ->whereRaw('u + d >= transfer_enable')
            ->update(['exhausted_at' => $now]);
    }
}
