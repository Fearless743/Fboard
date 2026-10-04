<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Jobs\NodeUserSyncJob;
use App\Models\CommissionLog;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Models\UserPlan;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    use QueryOperators;

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, '用户不存在']);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        $result = $user->save();

        if ($result) {
            HookManager::call('admin.user.secret.reset', [
                'user' => $user,
                'request' => $request,
            ]);
        }

        return $this->success($result);
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];
            $logic = strtolower($filter['logic'] ?? 'and');

            // 列名白名单校验：拒绝表达式/函数等非标识符字段
            if (!is_string($field) || !$this->isSafeQueryField($field)) {
                return;
            }

            if ($logic === 'or') {
                $builder->orWhere(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            } else {
                $builder->where(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            }
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // 列名白名单校验（关联字段允许单点）
        if (!$this->isSafeQueryField($field)) {
            return;
        }

        // 处理关联查询
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        // 处理数组值的 'in' 操作
        if (is_array($value)) {
            $query->whereIn($field === 'group_ids' ? 'group_id' : $field, $value);
            return;
        }

        // 处理基于运算符的过滤
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 转换数字字符串为适当的类型
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // 处理计算字段
        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            default => $field
        };

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'] ?? null;
            if (!$field || !is_string($field) || !$this->isSafeQueryField($field)) {
                return;
            }
            $direction = !empty($sort['desc']) ? 'DESC' : 'ASC';
            // 计算字段需用表达式排序（selectRaw 别名在部分驱动/分页下不可靠）
            $orderField = match ($field) {
                'total_used' => DB::raw('(u + d)'),
                default => $field,
            };
            $builder->orderBy($orderField, $direction);
        });
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        if (UserPlan::isEnabled()) {
            // 聚合列一次查完 + 实例明细预加载（transform 里拼 plan_list，禁 N+1）
            $userModel->withPlanAggregate()->with('userPlans');
        }

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $this->applyFiltersAndSorts($request, $userModel);

        // 无自定义排序时默认按 id 降序；有 sort 时不再强制追加 id，避免覆盖主排序
        $hasCustomSort = $request->has('sort')
            && is_array($request->input('sort'))
            && collect($request->input('sort'))->contains(fn ($s) => !empty($s['id'] ?? null));
        if (!$hasCustomSort) {
            $userModel->orderBy('id', 'desc');
        }

        $users = $userModel
            ->paginate($pageSize, ['*'], 'page', $current);

        $users->getCollection()->transform(function ($user): array {
            return self::transformUserData($user);
        });

        return $this->paginate($users);
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        if (UserPlan::isEnabled()) {
            // legacy 字段名不变，值为实例聚合；另加 plan_list（含耗尽行）。
            $computed = $model->getComputedPlanFields();
            if (!empty($computed)) {
                $user = array_merge($user, $computed);
            }
            $user['plan_list'] = $model->getPlanList();
            unset($user['userPlans'], $user['user_plans']);
        }
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'))->load('invite_user');
        if (UserPlan::isEnabled()) {
            $user->loadMissing('userPlans');
            foreach ($user->getComputedPlanFields() as $key => $value) {
                $user->setAttribute($key, $value);
            }
            $user->setAttribute('plan_list', $user->getPlanList());
        }
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        if (!UserPlan::isEnabled() && ($request->exists('plans') || !empty($params['clear_plans']))) {
            return $this->fail([400201, '多套餐功能未开启']);
        }
        if (UserPlan::isEnabled()) {
            // 旧列冻结：主表 9 套餐列零读写，一律走 plans[] 实例 diff。
            $frozen = ['plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'expired_at', 'speed_limit', 'device_limit', 'next_reset_at'];
            foreach ($frozen as $field) {
                if (array_key_exists($field, $params) && $params[$field] !== null) {
                    return $this->fail([400201, "多套餐模式下【{$field}】请通过套餐实例编辑"]);
                }
            }
        }
        if (isset($params['email'])) {
            if (User::byEmail($params['email'])->first() && $user->email !== $params['email']) {
                return $this->fail([400201, '邮箱已被使用']);
            }
        }
        // 处理密码
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // 处理订阅计划
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->fail([400202, '订阅计划不存在']);
            }
            $params['group_id'] = $plan->group_id;
        }
        // 处理邀请用户：优先 invite_user_id；兼容旧字段 invite_user_email
        // 仅在明确传入时更新；空值清空邀请关系
        if ($request->exists('invite_user_id') || array_key_exists('invite_user_id', $params)) {
            $rawInviteUserId = $request->input('invite_user_id');
            if ($rawInviteUserId === null || $rawInviteUserId === '' || (int) $rawInviteUserId === 0) {
                $params['invite_user_id'] = null;
            } else {
                $inviteUserId = (int) $rawInviteUserId;
                if ($inviteUserId === (int) $user->id) {
                    return $this->fail([400201, '不能将自己设为邀请人']);
                }
                if (!User::where('id', $inviteUserId)->exists()) {
                    return $this->fail([400202, '邀请人不存在']);
                }
                $params['invite_user_id'] = $inviteUserId;
            }
        } elseif ($request->exists('invite_user_email') || array_key_exists('invite_user_email', $params)) {
            // 兼容旧管理端：通过邮箱设置邀请人
            $inviteUserEmail = trim((string) ($request->input('invite_user_email') ?? ''));
            unset($params['invite_user_email']);
            if ($inviteUserEmail === '') {
                $params['invite_user_id'] = null;
            } else {
                $inviteUser = User::byEmail($inviteUserEmail)->first();
                if (!$inviteUser) {
                    return $this->fail([400202, '邀请人不存在']);
                }
                if ((int) $inviteUser->id === (int) $user->id) {
                    return $this->fail([400201, '不能将自己设为邀请人']);
                }
                $params['invite_user_id'] = $inviteUser->id;
            }
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            // 管理端按「元」输入，库内存「分」；禁止 float * 100（IEEE 精度会丢分）
            $params['balance'] = Helper::yuanToCents($params['balance']);
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = Helper::yuanToCents($params['commission_balance']);
        }

        $params = HookManager::filter('admin.user.update.params', $params, $request, $user);

        HookManager::call('admin.user.update.before', [
            'user' => $user,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            DB::transaction(function () use ($user, $params, $request) {
                // 三铁律：管理端写实例前永远先锁用户行。
                $locked = User::query()->lockForUpdate()->find($user->id);
                if (!$locked) {
                    throw new \RuntimeException('用户不存在');
                }
                UserPlan::query()->where('user_id', $user->id)->lockForUpdate()->get();

                $base = $params;
                unset($base['plans'], $base['clear_plans']);
                if (UserPlan::isEnabled()) {
                    // 旧列冻结：null 值也不得落库（fill 会清空主表）。
                    unset($base['plan_id'], $base['group_id'], $base['transfer_enable'], $base['u'], $base['d'], $base['expired_at'], $base['speed_limit'], $base['device_limit'], $base['next_reset_at']);
                }
                $locked->fill($base);
                if (!$locked->save()) {
                    throw new \RuntimeException('保存失败');
                }

                $this->syncUserPlans($locked, $request, $params);
            });

            // 管理端实例 diff 不走 observer（只改实例表），显式通知节点。
            if (UserPlan::isEnabled() && ($request->exists('plans') || !empty($params['clear_plans']))) {
                NodeUserSyncJob::dispatch($user->id, 'updated');
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, $e instanceof \RuntimeException ? $e->getMessage() : '保存失败']);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    /**
     * 多套餐实例 diff（与基字段同事务）。
     * - plans 缺席 = 不碰实例；空数组 = 不操作（清空必须 clear_plans=true 二次确认）；
     * - 非空 plans = 全量期望集合：更新列出的行、新增无 id 的行、删除未列出的旧行；
     * - 可编辑：plan_id/expired_at/限速/设备数；sort_order 与剩余额度（transfer/u/d）只读不动；
     * - 新增行配额默认取 plan 快照，可用 transfer_enable 覆盖。
     */
    private function syncUserPlans(User $user, Request $request, array $params): void
    {
        $clear = (bool) ($params['clear_plans'] ?? false);
        if (!UserPlan::isEnabled()) {
            return;
        }

        if ($clear) {
            if ($request->exists('plans') && !empty($params['plans'])) {
                throw new \RuntimeException('清空与 plans 不能同时提交');
            }
            UserPlan::query()->where('user_id', $user->id)->delete();
            return;
        }

        if (!$request->exists('plans')) {
            return;
        }
        $plans = $params['plans'] ?? null;
        if (!is_array($plans) || empty($plans)) {
            return;
        }

        // 先校验全部 plan_id，再动任何行。
        $planIds = collect($plans)->pluck('plan_id')->map(fn ($id) => (int) $id)->unique()->all();
        $existingPlans = Plan::query()->whereIn('id', $planIds)->get()->keyBy('id');
        foreach ($planIds as $planId) {
            if (!$existingPlans->has($planId)) {
                throw new \RuntimeException("订阅计划不存在: {$planId}");
            }
        }

        $existing = UserPlan::query()->where('user_id', $user->id)->get()->keyBy('id');
        $keepIds = [];
        foreach ($plans as $item) {
            $rowId = isset($item['id']) ? (int) $item['id'] : 0;
            $plan = $existingPlans->get((int) $item['plan_id']);
            if ($rowId > 0) {
                $row = $existing->get($rowId);
                if (!$row) {
                    throw new \RuntimeException("套餐实例不存在或不属于该用户: {$rowId}");
                }
                $row->forceFill([
                    'plan_id' => $plan->id,
                    'group_id' => $plan->group_id,
                    'expired_at' => $item['expired_at'] ?? null,
                    'speed_limit' => $item['speed_limit'] ?? null,
                    'device_limit' => $item['device_limit'] ?? null,
                ]);
                $row->save();
                $keepIds[] = $row->id;
            } else {
                $row = new UserPlan();
                $row->forceFill([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'kind' => UserPlan::KIND_CYCLE,
                    'group_id' => $plan->group_id,
                    'order_ids' => [],
                    'transfer_enable' => isset($item['transfer_enable'])
                        ? (int) $item['transfer_enable']
                        : (int) $plan->transfer_enable * 1073741824,
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => $item['expired_at'] ?? null,
                    'speed_limit' => $item['speed_limit'] ?? $plan->speed_limit,
                    'device_limit' => $item['device_limit'] ?? $plan->device_limit,
                    'sort_order' => 0,
                ]);
                $row->save();
                $keepIds[] = $row->id;
            }
        }

        UserPlan::query()->where('user_id', $user->id)->whereNotIn('id', $keepIds)->delete();
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        ini_set('memory_limit', '-1');
        gc_enable(); // 启用垃圾回收

        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        // 优化查询：使用with预加载plan关系，避免N+1问题
        $query = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'id',
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if (UserPlan::isEnabled()) {
            $query->with('userPlans');
        }

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $this->applyFiltersAndSorts($request, $query);
        } // all: ignore filter/sort

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // 打开输出流
            $output = fopen('php://output', 'w');

            // 添加BOM标记，确保Excel正确显示中文
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 写入CSV头部
            fputcsv($output, [
                '邮箱',
                '余额',
                '推广佣金',
                '总流量',
                '剩余流量',
                '套餐到期时间',
                '订阅计划',
                '订阅地址'
            ]);

            // 分批处理数据以减少内存使用
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $quota = $user->transfer_enable;
                        $used = $user->u + $user->d;
                        $expiredAt = $user->expired_at;
                        $planName = $user->plan ? $user->plan->name : '无订阅';
                        if (UserPlan::isEnabled()) {
                            // 与列表同一聚合入口；订阅计划列展示全部有效实例名
                            $computed = $user->getComputedPlanFields();
                            if (!empty($computed)) {
                                $quota = $computed['transfer_enable'];
                                $used = $computed['u'] + $computed['d'];
                                $expiredAt = $computed['expired_at'];
                            }
                            $names = array_unique(array_column($user->getPlanList(), 'name'));
                            $planName = !empty($names) ? implode(';', $names) : '无订阅';
                        }
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($quota),
                            Helper::trafficConvert($quota - $used),
                            $expiredAt ? date('Y-m-d H:i:s', $expiredAt) : '长期有效',
                            $planName,
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV导出错误: ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // 继续处理下一条记录
                    }
                }

                // 清理内存
                gc_collect_cycles();
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            // If generate_count is specified with email_prefix, generate multiple users with incremented emails
            if ($request->input('generate_count')) {
                return $this->multiGenerateWithPrefix($request);
            }
            
            // Single user generation with email_prefix
            $email = $request->input('email_prefix') . '@' . $request->input('email_suffix');

            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, '邮箱已存在于系统中']);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, '生成失败']);
            }
            $userService->seedInitialPlanRow($user);
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $email = Helper::randomChar(6) . '@' . $request->input('email_suffix');
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }



        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $userService->seedInitialPlanRow($user);
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    private function multiGenerateWithPrefix(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];
        $emailPrefix = $request->input('email_prefix');
        $emailSuffix = $request->input('email_suffix');
        $generateCount = $request->input('generate_count');

        // Check if any of the emails with prefix already exist
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            if (User::where('email', $email)->exists()) {
                return $this->fail([400201, '邮箱 ' . $email . ' 已存在于系统中']);
            }
        }

        // Generate user data for batch creation
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }

        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $userService->seedInitialPlanRow($user);
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        ini_set('memory_limit', '-1');
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'desc');

        if (UserPlan::isEnabled()) {
            $builder->with('userPlans');
        }

        if ($scope === 'filtered') {
            // filtered: apply filters/sort
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'Fboard');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;

        $builder->chunk($chunkSize, function ($users) use ($subject, $content, $appName, $appUrl) {
            foreach ($users as $user) {
                $planName = $user->plan?->name ?? '';
                $expiredAt = $user->expired_at;
                $quota = (int) ($user->transfer_enable ?? 0);
                $used = (int) (($user->u ?? 0) + ($user->d ?? 0));
                if (UserPlan::isEnabled()) {
                    // 与列表同一聚合入口；多实例时套餐名用分号拼接
                    $computed = $user->getComputedPlanFields();
                    if (!empty($computed)) {
                        $quota = (int) $computed['transfer_enable'];
                        $used = (int) $computed['u'] + (int) $computed['d'];
                        $expiredAt = $computed['expired_at'];
                    }
                    $names = array_unique(array_column($user->getPlanList(), 'name'));
                    if (!empty($names)) {
                        $planName = implode(';', $names);
                    }
                }
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $planName,
                    'user.expired_at' => $expiredAt ? date('Y-m-d H:i:s', $expiredAt) : '',
                    'user.transfer_enable' => $quota,
                    'user.transfer_used' => $used,
                    'user.transfer_left' => (int) ($quota - $used),
                ];

                $templateValue = [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    'vars' => $vars,
                    'content_mode' => 'text',
                ];

                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: keep current semantics
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        try {
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '处理失败']);
        }
        // Full refresh not implemented.
        return $this->success(true);
    }

    public function inviteList(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:App\Models\User,id'
        ]);

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);
        $userId = (int) $request->input('user_id');

        $invitedUsers = User::where('invite_user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->paginate($pageSize, ['id', 'email', 'created_at'], 'page', $current);

        // 展示该用户从每个下线身上赚到的佣金（v2_commission_log.user_id 为产生订单的下线）。
        $inviteeIds = $invitedUsers->getCollection()->pluck('id')->all();
        $commissionByInvitee = empty($inviteeIds)
            ? collect()
            : CommissionLog::where('invite_user_id', $userId)
                ->whereIn('user_id', $inviteeIds)
                ->select('user_id', DB::raw('SUM(get_amount) as total'))
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

        $invitedUsers->getCollection()->transform(function ($user) use ($commissionByInvitee) {
            return [
                'invitee_email' => $user->email,
                'commission_balance' => (int) ($commissionByInvitee[$user->id] ?? 0) / 100,
                'created_at' => $user->created_at,
            ];
        });

        return $this->paginate($invitedUsers);
    }

    /**
     * 用户登录历史（分页，每用户最多保留 20 条）
     */
    public function loginLogs(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:App\Models\User,id',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:50',
        ]);

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $userId = (int) $request->input('user_id');

        $logs = UserLoginLog::where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($pageSize, ['id', 'ip', 'user_agent', 'method', 'created_at'], 'page', $current);

        return $this->paginate($logs);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在'
        ]);
        $user = User::find($request->input('id'));
        HookManager::call('admin.user.destroy.before', [
            'user' => $user,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();

            HookManager::call('admin.user.destroy.after', [
                'user' => $user,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }
    }
}
