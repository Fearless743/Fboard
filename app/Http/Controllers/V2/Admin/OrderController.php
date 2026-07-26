<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function detail(Request $request)
    {
        $order = Order::with(['user', 'plan', 'commission_log', 'invite_user'])->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $order['period'] = PlanService::getLegacyPeriod((string) $order->period);
        return $this->success($order);
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);
        // 列表需要展示用户邮箱；仅选 id/email 避免把用户整行敏感字段带出
        $orderModel = Order::with(['plan:id,name', 'user:id,email']);

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
        $paginatedResults = $orderModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        $paginatedResults->getCollection()->transform(function ($order) {
            $orderArray = $order->toArray();
            $orderArray['period'] = PlanService::getLegacyPeriod((string) $order->period);
            return $orderArray;
        });

        return $this->paginate($paginatedResults);
    }

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    /**
     * 仅这些字段在「无操作符 / 裸数字」时按数值精确匹配。
     * trade_no、callback_no 等是字符串标识（generateOrderNo 产出纯数字长串），
     * 若 is_numeric + (int) 会溢出/截断导致搜不到订单。
     */
    private const INTEGER_FILTER_FIELDS = [
        'id',
        'user_id',
        'invite_user_id',
        'plan_id',
        'payment_id',
        'status',
        'commission_status',
        'type',
        'total_amount',
        'commission_balance',
        'discount_amount',
        'balance_amount',
        'handling_amount',
        'refund_amount',
    ];

    private function isIntegerFilterField(string $field): bool
    {
        return in_array($field, self::INTEGER_FILTER_FIELDS, true);
    }

    private function castNumericFilterValue(mixed $value): mixed
    {
        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // 邮箱在 user 表，走关联筛选（OrderFetch 白名单含 email）
        if ($field === 'email') {
            $this->buildUserEmailFilterQuery($query, $value);
            return;
        }

        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // 数值状态类（status / commission_status / type 等）必须精确匹配；
        // 若走 LIKE "%0%" 会把 10、20 等一并命中，也会扫出默认 0 的无意义行。
        // 注意：不可对 trade_no 等字符串字段做 is_numeric 强转。
        if ($this->isIntegerFilterField($field)
            && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && !str_contains($value, ':')))
        ) {
            $query->where($field, $this->castNumericFilterValue($value));
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 仅整型列把操作符右侧 cast 成数字；字符串订单号保持原样
        if ($this->isIntegerFilterField($field) && is_numeric($filterValue)) {
            $filterValue = $this->castNumericFilterValue($filterValue);
        }

        // Apply operator
        $operatorKey = strtolower($operator);
        if ($operatorKey === 'null') {
            $query->whereNull($field);
            return;
        }
        if ($operatorKey === 'notnull') {
            $query->whereNotNull($field);
            return;
        }

        $query->where($field, match ($operatorKey) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            default => 'like'
        }, match ($operatorKey) {
            'like', 'notlike' => "%{$filterValue}%",
            default => $filterValue
        });
    }

    /**
     * 按用户邮箱筛选订单（关联 v2_user.email，大小写不敏感）。
     */
    private function buildUserEmailFilterQuery(Builder $query, mixed $value): void
    {
        if (is_array($value)) {
            $emails = array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? strtolower(trim($v)) : null,
                $value
            )));
            if ($emails === []) {
                $query->whereRaw('0 = 1');
                return;
            }
            $query->whereHas('user', function (Builder $userQuery) use ($emails) {
                $userQuery->whereIn('email', $emails);
            });
            return;
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        if (!str_contains($value, ':')) {
            $email = strtolower(trim($value));
            $query->whereHas('user', function (Builder $userQuery) use ($email) {
                $userQuery->where('email', 'like', "%{$email}%");
            });
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);
        $operatorKey = strtolower($operator);
        $email = strtolower(trim($filterValue));

        if ($operatorKey === 'null') {
            $query->whereDoesntHave('user');
            return;
        }
        if ($operatorKey === 'notnull') {
            $query->whereHas('user');
            return;
        }

        $query->whereHas('user', function (Builder $userQuery) use ($operatorKey, $email) {
            $userQuery->where('email', match ($operatorKey) {
                'eq' => '=',
                'like' => 'like',
                'notlike' => 'not like',
                default => 'like'
            }, match ($operatorKey) {
                'eq' => $email,
                'like', 'notlike' => "%{$email}%",
                default => "%{$email}%",
            });
        });
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        try {
            $order->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }

        return $this->success(true);
    }

    public function assign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::byEmail($request->input('email'))->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            // 与用户侧 createFromRequest 对齐：事务内锁用户行再查未完成订单，避免并发 assign 叠 PENDING
            $tradeNo = DB::transaction(function () use ($request, $plan, $user) {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                if (!$lockedUser) {
                    throw new ApiException('该用户不存在');
                }

                $userService = new UserService();
                if ($userService->isNotCompleteOrderByUserId($lockedUser->id)) {
                    throw new ApiException('该用户还有待支付的订单，无法分配');
                }

                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $lockedUser->id;
                $order->plan_id = $plan->id;
                $period = $request->input('period');
                $order->period = PlanService::getPeriodKey((string) $period);
                $order->trade_no = Helper::guid();
                // 管理端表单按「元」输入，库内统一存「分」
                $order->total_amount = Helper::yuanToCents($request->input('total_amount'));
                // 显式 PENDING：否则 save 后内存 status 为 null，立即 paid() 会跳过入账
                $order->status = Order::STATUS_PENDING;

                if (PlanService::getPeriodKey((string) $order->period) === Plan::PERIOD_RESET_TRAFFIC) {
                    $order->type = Order::TYPE_RESET_TRAFFIC;
                } else if ($lockedUser->plan_id !== NULL && $order->plan_id !== $lockedUser->plan_id) {
                    $order->type = Order::TYPE_UPGRADE;
                } else if ($lockedUser->expired_at > time() && $order->plan_id == $lockedUser->plan_id) {
                    $order->type = Order::TYPE_RENEWAL;
                } else {
                    $order->type = Order::TYPE_NEW_PURCHASE;
                }

                $orderService->setInvite($lockedUser);

                if (!$order->save()) {
                    throw new ApiException('订单创建失败');
                }

                return $order->trade_no;
            });
        } catch (ApiException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '订单创建失败']);
        }

        return $this->success($tradeNo);
    }
}
