<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
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
            if (!$field) {
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
        // 多套餐支持：累计流量、最早到期时间、套餐列表
        $user['transfer_enable'] = $user->getTotalTransferEnable();
        $user['expired_at'] = $user->getEffectiveExpiredAt();
        $activePlans = $user->activeUserPlans()->with('plan:id,name')->get();
        $user['plan_list'] = $activePlans->map(function (UserPlan $up) {
            return [
                'id' => $up->plan_id,
                'name' => $up->plan?->name ?? '',
                'expired_at' => $up->expired_at,
                'speed_limit' => $up->speed_limit ?? $up->plan?->speed_limit ?? null,
            ];
        })->values()->all();
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::with(['invite_user', 'userPlans.plan:id,name'])->find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        // 同步聚合字段，方便前端直接使用
        $user['transfer_enable'] = $user->getTotalTransferEnable();
        $user['expired_at'] = $user->getEffectiveExpiredAt();
        $user['plan_list'] = $user->activeUserPlans()->with('plan:id,name')->get()->map(function (UserPlan $up) {
            return [
                'id' => $up->plan_id,
                'name' => $up->plan?->name ?? '',
                'expired_at' => $up->expired_at,
                'speed_limit' => $up->speed_limit ?? $up->plan?->speed_limit ?? null,
            ];
        })->values()->all();
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
            $user->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    /**
     * 多套餐模式：更新用户的套餐列表
     * 接收 plan_list: [{plan_id, expired_at, speed_limit}, ...]
     * plan_id=null 表示保留主订阅；expired_at=null 表示永久
     */
    public function updateUserPlans(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'plan_list' => 'nullable|array',
            'plan_list.*.plan_id' => 'nullable|integer',
            'plan_list.*.expired_at' => 'nullable|integer',
            'plan_list.*.speed_limit' => 'nullable|integer',
        ]);

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }

        // 检查是否开启多套餐模式
        $enableMulti = (int) admin_setting('multi_plan_enable', 0);

        $planList = $request->input('plan_list');

        if ($enableMulti && is_array($planList) && count($planList) > 0) {
            // 多套餐模式：清理旧的多套餐记录，只保留主订阅字段
            UserPlan::where('user_id', $user->id)->delete();

            foreach ($planList as $item) {
                if (!isset($item['plan_id']) || !$item['plan_id']) {
                    continue;
                }
                $plan = Plan::find($item['plan_id']);
                if (!$plan) {
                    continue;
                }
                UserPlan::create([
                    'user_id' => $user->id,
                    'plan_id' => $item['plan_id'],
                    'expired_at' => $item['expired_at'] ?? null,
                    'speed_limit' => $item['speed_limit'] ?? null,
                ]);
            }

            // 重置主订阅字段为多套餐聚合逻辑的基准值
            // 主字段保留最后一个或默认值，实际聚合由 User 模型方法完成
            if (isset($planList[0]['plan_id'])) {
                $firstPlan = Plan::find($planList[0]['plan_id']);
                if ($firstPlan) {
                    $user->plan_id = $firstPlan->id;
                    $user->group_id = $firstPlan->group_id;
                    $user->save();
                }
            }
        } else if (!$enableMulti && is_array($planList) && count($planList) > 0) {
            // 单套餐模式：取第一个 plan_id 作为主订阅（兼容旧逻辑）
            $firstItem = $planList[0];
            if (isset($firstItem['plan_id']) && $firstItem['plan_id']) {
                $plan = Plan::find($firstItem['plan_id']);
                if ($plan) {
                    $user->plan_id = $plan->id;
                    $user->group_id = $plan->group_id;
                    $user->expired_at = $firstItem['expired_at'] ?? $user->expired_at;
                    $user->speed_limit = $firstItem['speed_limit'] ?? $user->speed_limit;
                    $user->save();
                }
            }
            // 清理旧的多套餐记录
            UserPlan::where('user_id', $user->id)->delete();
        }

        return $this->success(true);
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
                '订阅地址',
                '多套餐列表'
            ]);

            // 分批处理数据以减少内存使用
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        // 多套餐：累计流量 + 各套餐到期时间列表
                        $planNames = $user->plan ? [$user->plan->name] : [];
                        if (isset($user['plan_list'])) {
                            foreach ($user['plan_list'] as $p) {
                                $planNames[] = $p['name'] . ($p['expired_at'] ? ' (' . date('Y-m-d', $p['expired_at']) . ')' : ' (永久)');
                            }
                        }
                        $row = [
                            $user->email,
                            number_format($user['balance'] / 100, 2),
                            number_format($user['commission_balance'] / 100, 2),
                            Helper::trafficConvert($user['transfer_enable']),
                            Helper::trafficConvert($user['transfer_enable'] - ($user['u'] + $user['d'])),
                            $user['expired_at'] ? date('Y-m-d H:i:s', $user['expired_at']) : '长期有效',
                            implode(', ', $planNames) ?: '无订阅',
                            Helper::getSubscribeUrl($user['token']),
                            implode(' | ', array_unique($planNames)) ?: '无套餐'
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
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $user->plan?->name ?? '',
                    'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                    'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                    'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                    'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
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
            ->paginate($pageSize, ['id', 'email', 'commission_balance', 'created_at'], 'page', $current);

        $invitedUsers->getCollection()->transform(function ($user) {
            return [
                'invitee_email' => $user->email,
                'commission_balance' => $user->commission_balance / 100,
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
