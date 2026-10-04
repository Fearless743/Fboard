<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftCardService
{
    // redeem 事务内需 lockForUpdate 后替换为锁定行，不能用 readonly
    protected GiftCardCode $code;
    protected readonly GiftCardTemplate $template;
    protected ?User $user = null;

    public function __construct(string $code)
    {
        $this->code = GiftCardCode::where('code', $code)->first()
            ?? throw new ApiException('兑换码不存在');

        $this->template = $this->code->template;
    }

    /**
     * 设置使用用户
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * 验证兑换码
     */
    public function validate(): self
    {
        $this->validateIsActive();

        $eligibility = $this->checkUserEligibility();
        if (!$eligibility['can_redeem']) {
            throw new ApiException($eligibility['reason']);
        }

        return $this;
    }

    /**
     * 验证礼品卡本身是否可用 (不检查用户条件)
     * @throws ApiException
     */
    public function validateIsActive(): self
    {
        if (!$this->template->isAvailable()) {
            throw new ApiException('该礼品卡类型已停用');
        }

        if (!$this->code->isAvailable()) {
            throw new ApiException('兑换码不可用：' . $this->code->status_name);
        }
        return $this;
    }

    /**
     * 检查用户是否满足兑换条件 (不抛出异常)
     */
    public function checkUserEligibility(): array
    {
        if (!$this->user) {
            return [
                'can_redeem' => false,
                'reason' => '用户信息未提供'
            ];
        }

        if (!$this->template->checkUserConditions($this->user)) {
            return [
                'can_redeem' => false,
                'reason' => '您不满足此礼品卡的使用条件'
            ];
        }

        if (!$this->template->checkUsageLimit($this->user)) {
            return [
                'can_redeem' => false,
                'reason' => '您已达到此礼品卡的使用限制'
            ];
        }

        return ['can_redeem' => true, 'reason' => null];
    }

    /**
     * 使用礼品卡
     */
    public function redeem(array $options = []): array
    {
        if (!$this->user) {
            throw new ApiException('未设置使用用户');
        }

        return DB::transaction(function () use ($options) {
            // 行锁 + 事务内二次校验：构造时已加载的 code 可能过期/被并发兑掉。
            $locked = GiftCardCode::query()
                ->whereKey($this->code->id)
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                throw new ApiException('兑换码不存在');
            }
            $this->code = $locked;

            if (!$this->template->isAvailable()) {
                throw new ApiException('该礼品卡类型已停用');
            }
            if (!$this->code->isAvailable()) {
                throw new ApiException('兑换码不可用：' . $this->code->status_name);
            }

            $eligibility = $this->checkUserEligibility();
            if (!$eligibility['can_redeem']) {
                throw new ApiException($eligibility['reason']);
            }

            $actualRewards = $this->template->calculateActualRewards($this->user);

            if ($this->template->type === GiftCardTemplate::TYPE_MYSTERY) {
                $this->code->setActualRewards($actualRewards);
            }

            $this->giveRewards($actualRewards);

            $inviteRewards = null;
            if ($this->user->invite_user_id && isset($actualRewards['invite_reward_rate'])) {
                $inviteRewards = $this->giveInviteRewards($actualRewards);
            }

            // markAsUsed 内再按 usage_count/max_usage 原子推进，防 max_usage>1 的竞态
            if (!$this->code->markAsUsed($this->user)) {
                throw new ApiException('兑换码使用失败');
            }

            GiftCardUsage::createRecord(
                $this->code,
                $this->user,
                $actualRewards,
                array_merge($options, [
                    'invite_rewards' => $inviteRewards,
                    'multiplier' => $this->calculateMultiplier(),
                ])
            );

            return [
                'rewards' => $actualRewards,
                'invite_rewards' => $inviteRewards,
                'code' => $this->code->code,
                'template_name' => $this->template->name,
            ];
        });
    }

    /**
     * 发放奖励
     */
    protected function giveRewards(array $rewards): void
    {
        $userService = app(UserService::class);

        // 用户行锁：并发兑两张不同码时 transfer_enable/device_limit 读改写不得丢失更新
        $lockedUser = User::query()->lockForUpdate()->find($this->user->id);
        if (!$lockedUser) {
            throw new ApiException('用户不存在');
        }
        $this->user = $lockedUser;

        if (isset($rewards['balance']) && $rewards['balance'] > 0) {
            if (!$userService->addBalance($this->user->id, $rewards['balance'])) {
                throw new ApiException('余额发放失败');
            }
            $this->user->refresh();
        }

        // 套餐卡的流量/设备数由 grantPlan 统一落到该 plan 行（只加一次）；
        // 非套餐卡才走独立奖励行（首个有效 cycle 行，无行回退主表）。
        $giftPlan = isset($rewards['plan_id']) ? Plan::find($rewards['plan_id']) : null;

        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0 && !$giftPlan) {
            $this->grantTransfer($rewards['transfer_enable']);
        }

        if (isset($rewards['device_limit']) && $rewards['device_limit'] > 0 && !$giftPlan) {
            $this->grantDeviceLimit($rewards['device_limit']);
        }

        if (isset($rewards['reset_package']) && $rewards['reset_package']) {
            $this->grantResetPackage($rewards);
        }

        if ($giftPlan) {
            $this->grantPlan($giftPlan, $rewards);
        } else {
            // 只有在不是套餐卡的情况下，才处理独立的有效期奖励
            if (isset($rewards['expire_days']) && $rewards['expire_days'] > 0) {
                $this->grantExpireDays($rewards['expire_days']);
            }
        }

        // 保存用户更改
        if (!$this->user->save()) {
            throw new ApiException('用户信息更新失败');
        }
    }

    /**
     * 多套餐目标行：plan 卡找该 plan 的 cycle 行（没有则建，配额按 plan 快照），
     * 纯流量/设备/有效期奖励找扣减顺序首个有效 cycle 行，都没有则回退主表。
     */
    private function resolveGiftRow(?Plan $plan): ?UserPlan
    {
        if (!UserPlan::isEnabled()) {
            return null;
        }
        // 先锁用户全部实例行，与订单/管理端一致。
        UserPlan::query()->where('user_id', $this->user->id)->lockForUpdate()->get();

        if ($plan) {
            $row = UserPlan::query()
                ->where('user_id', $this->user->id)
                ->where('plan_id', $plan->id)
                ->where('kind', UserPlan::KIND_CYCLE)
                ->orderBy('id', 'desc')
                ->first();
            if (!$row) {
                $row = new UserPlan();
                $row->forceFill([
                    'user_id' => $this->user->id,
                    'plan_id' => $plan->id,
                    'kind' => UserPlan::KIND_CYCLE,
                    'group_id' => $plan->group_id,
                    'order_ids' => [],
                    'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => null,
                    'speed_limit' => $plan->speed_limit,
                    'device_limit' => $plan->device_limit,
                    'sort_order' => 0,
                ]);
                $row->save();
            }

            return $row;
        }

        $query = UserPlan::query()->where('user_id', $this->user->id);
        UserPlan::applyDeductionOrder($query);

        return $query->where('kind', UserPlan::KIND_CYCLE)->first();
    }

    private function grantTransfer(int $bytes): void
    {
        $row = $this->resolveGiftRow(null);
        if ($row) {
            $row->transfer_enable = (int) $row->transfer_enable + $bytes;
            $row->save();
            return;
        }
        $this->user->transfer_enable = ($this->user->transfer_enable ?? 0) + $bytes;
    }

    private function grantDeviceLimit(int $count): void
    {
        $row = $this->resolveGiftRow(null);
        if ($row) {
            $row->device_limit = ((int) $row->device_limit) + $count;
            $row->save();
            return;
        }
        $this->user->device_limit = ($this->user->device_limit ?? 0) + $count;
    }

    private function grantResetPackage(array $rewards): void
    {
        if (!UserPlan::isEnabled()) {
            if ($this->user->plan_id) {
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_GIFT_CARD);
            }
            return;
        }
        $plan = isset($rewards['plan_id']) ? Plan::find($rewards['plan_id']) : null;
        $row = $this->resolveGiftRow($plan);
        if ($row) {
            app(TrafficResetService::class)->resetInstance($row, TrafficResetLog::SOURCE_GIFT_CARD, true);
        } elseif ($this->user->plan_id) {
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_GIFT_CARD);
        }
    }

    private function grantPlan(Plan $plan, array $rewards): void
    {
        if (!UserPlan::isEnabled()) {
            app(UserService::class)->assignPlan(
                $this->user,
                $plan,
                $rewards['plan_validity_days'] ?? 0
            );
            return;
        }
        // 已有行不断行：配额不清零（礼包 transfer 另计），只刷新快照并顺延有效期。
        $row = $this->resolveGiftRow($plan);
        $row->group_id = $plan->group_id;
        $row->speed_limit = $plan->speed_limit;
        $row->device_limit = $plan->device_limit;
        $validityDays = (int) ($rewards['plan_validity_days'] ?? 0);
        if ($validityDays > 0) {
            $base = $row->expired_at ? max((int) $row->expired_at, time()) : time();
            $row->expired_at = $base + $validityDays * 86400;
        }
        $row->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForPlan($plan, $row->expired_at)?->timestamp;
        $row->save();

        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
            $row->transfer_enable = (int) $row->transfer_enable + (int) $rewards['transfer_enable'];
            $row->save();
        }
    }

    private function grantExpireDays(int $days): void
    {
        if (!UserPlan::isEnabled()) {
            app(UserService::class)->extendSubscription($this->user, $days);
            return;
        }
        $row = $this->resolveGiftRow(null);
        if (!$row) {
            app(UserService::class)->extendSubscription($this->user, $days);
            return;
        }
        $base = $row->expired_at ? max((int) $row->expired_at, time()) : time();
        $row->expired_at = $base + $days * 86400;
        $row->save();
    }

    /**
     * 发放邀请人奖励
     */
    protected function giveInviteRewards(array $rewards): ?array
    {
        if (!$this->user->invite_user_id) {
            return null;
        }

        $inviteUser = User::find($this->user->invite_user_id);
        if (!$inviteUser) {
            return null;
        }

        $rate = $rewards['invite_reward_rate'] ?? 0.2;
        $inviteRewards = [];

        $userService = app(UserService::class);

        // 邀请人余额奖励
        if (isset($rewards['balance']) && $rewards['balance'] > 0) {
            $inviteBalance = intval($rewards['balance'] * $rate);
            if ($inviteBalance > 0) {
                $userService->addBalance($inviteUser->id, $inviteBalance);
                $inviteRewards['balance'] = $inviteBalance;
            }
        }

        // 邀请人流量奖励
        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
            $inviteTransfer = intval($rewards['transfer_enable'] * $rate);
            if ($inviteTransfer > 0) {
                if (UserPlan::isEnabled()) {
                    $row = UserPlan::query()->where('user_id', $inviteUser->id)
                        ->where('kind', UserPlan::KIND_CYCLE)
                        ->orderBy('id', 'desc')
                        ->first();
                    if ($row) {
                        $row->transfer_enable = (int) $row->transfer_enable + $inviteTransfer;
                        $row->save();
                        $inviteRewards['transfer_enable'] = $inviteTransfer;
                        return $inviteRewards;
                    }
                }
                $inviteUser->transfer_enable = ($inviteUser->transfer_enable ?? 0) + $inviteTransfer;
                $inviteUser->save();
                $inviteRewards['transfer_enable'] = $inviteTransfer;
            }
        }

        return $inviteRewards;
    }

    /**
     * 计算倍率
     */
    protected function calculateMultiplier(): float
    {
        return $this->getFestivalBonus();
    }

    /**
     * 获取节日加成倍率
     */
    private function getFestivalBonus(): float
    {
        $festivalConfig = $this->template->special_config ?? [];
        $now = time();

        if (
            isset($festivalConfig['start_time'], $festivalConfig['end_time']) &&
            $now >= $festivalConfig['start_time'] &&
            $now <= $festivalConfig['end_time']
        ) {
            return $festivalConfig['festival_bonus'] ?? 1.0;
        }

        return 1.0;
    }

    /**
     * 获取兑换码信息（不包含敏感信息）
     */
    public function getCodeInfo(): array
    {
        $info = [
            'code' => $this->code->code,
            'template' => [
                'name' => $this->template->name,
                'description' => $this->template->description,
                'type' => $this->template->type,
                'type_name' => $this->template->type_name,
                'icon' => $this->template->icon,
                'background_image' => $this->template->background_image,
                'theme_color' => $this->template->theme_color,
            ],
            'status' => $this->code->status,
            'status_name' => $this->code->status_name,
            'expires_at' => $this->code->expires_at,
            'usage_count' => $this->code->usage_count,
            'max_usage' => $this->code->max_usage,
        ];
        if ($this->template->type === GiftCardTemplate::TYPE_PLAN) {
            $plan = Plan::find($this->code->template->rewards['plan_id']);
            if ($plan) {
                $info['plan_info'] = PlanResource::make($plan)->toArray(request());
            }
        }
        return $info;
    }

    /**
     * 预览奖励（不实际发放）
     */
    public function previewRewards(): array
    {
        if (!$this->user) {
            throw new ApiException('未设置使用用户');
        }

        return $this->template->calculateActualRewards($this->user);
    }

    /**
     * 获取兑换码
     */
    public function getCode(): GiftCardCode
    {
        return $this->code;
    }

    /**
     * 获取模板
     */
    public function getTemplate(): GiftCardTemplate
    {
        return $this->template;
    }

    /**
     * 记录日志
     */
    protected function logUsage(string $action, array $data = []): void
    {
        Log::info('礼品卡使用记录', [
            'action' => $action,
            'code' => $this->code->code,
            'template_id' => $this->template->id,
            'user_id' => $this->user?->id,
            'data' => $data,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
