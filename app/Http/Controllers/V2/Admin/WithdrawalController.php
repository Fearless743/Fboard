<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function fetch(Request $request)
    {
        $query = Withdrawal::with(['user', 'operator'])
            ->when($request->has('status'), function ($q) use ($request) {
                $q->where('status', $request->input('status'));
            })
            ->when($request->has('user_id'), function ($q) use ($request) {
                $q->where('user_id', $request->input('user_id'));
            })
            ->when($request->has('email'), function ($q) use ($request) {
                $q->whereHas('user', function ($sub) use ($request) {
                    $sub->where('email', 'like', "%{$request->input('email')}%");
                });
            });

        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($query) {
                $key = $filter['id'];
                $value = $filter['value'];
                $query->where(function ($q) use ($key, $value) {
                    if (is_array($value)) {
                        $q->whereIn($key, $value);
                    } else {
                        $q->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($query) {
                $key = $sort['id'];
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $query->orderBy($key, $value);
            });
        }

        $withdrawals = $query
            ->latest('created_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        $items = collect($withdrawals->items())->map(function ($item) {
            $data = $item->toArray();
            $data['user'] = $item->user ? UserController::transformUserData($item->user) : null;
            $data['operator'] = $item->operator ? [
                'id' => $item->operator->id,
                'email' => $item->operator->email,
            ] : null;
            return $data;
        })->all();

        return response([
            'data' => $items,
            'total' => $withdrawals->total(),
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'remark' => 'nullable|string|max:500',
        ], [
            'id.required' => '提现单ID不能为空',
        ]);

        $withdrawal = Withdrawal::where('id', $request->input('id'))
            ->where('status', Withdrawal::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if (!$withdrawal) {
            return $this->fail([400202, '提现单不存在或状态不正确']);
        }

        DB::transaction(function () use ($withdrawal, $request) {
            $withdrawal->status = Withdrawal::STATUS_CONFIRMED;
            $withdrawal->remark = $request->input('remark');
            $withdrawal->operator_id = $request->user()->id;
            $withdrawal->save();

            // 写入提现单系统消息
            WithdrawalMessage::create([
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $request->user()->id,
                'message' => "【系统】提现已确认\r\n金额：" . sprintf('%.2f', $withdrawal->amount / 100) . " 元",
                'is_admin' => 1,
            ]);
        });

        return $this->success(true);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'remark' => 'nullable|string|max:500',
        ], [
            'id.required' => '提现单ID不能为空',
        ]);

        $withdrawal = Withdrawal::where('id', $request->input('id'))
            ->where('status', Withdrawal::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if (!$withdrawal) {
            return $this->fail([400202, '提现单不存在或状态不正确']);
        }

        DB::transaction(function () use ($withdrawal, $request) {
            $withdrawal->status = Withdrawal::STATUS_CLOSED;
            $withdrawal->remark = $request->input('remark');
            $withdrawal->operator_id = $request->user()->id;
            $withdrawal->save();

            // 返还佣金
            $user = User::find($withdrawal->user_id);
            if ($user) {
                $user->commission_balance = ($user->commission_balance ?? 0) + $withdrawal->amount;
                $user->save();
            }

            // 写入提现单系统消息
            WithdrawalMessage::create([
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $request->user()->id,
                'message' => "【系统】提现已拒绝\r\n金额：" . sprintf('%.2f', $withdrawal->amount / 100) . " 元已返还",
                'is_admin' => 1,
            ]);
        });

        return $this->success(true);
    }

    public function messages(Request $request, Withdrawal $withdrawal)
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $request->integer('limit', 50);

        $messages = WithdrawalMessage::where('withdrawal_id', $withdrawal->id)
            ->latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->values();

        return response([
            'data' => $messages->map(function ($msg) use ($withdrawal) {
                $isAdmin = (bool) $msg->is_admin;
                return [
                    'id' => $msg->id,
                    'withdrawal_id' => $msg->withdrawal_id,
                    'user_id' => $msg->user_id,
                    'message' => $msg->message,
                    'is_admin' => $isAdmin,
                    'created_at' => $msg->created_at?->timestamp ?? null,
                ];
            })->toArray(),
        ]);
    }

    public function reply(Request $request, Withdrawal $withdrawal)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ], [
            'message.required' => '消息不能为空',
            'message.max' => '消息长度不能超过2000字符',
        ]);

        if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
            return $this->fail([400202, '提现单已处理，无法继续回复']);
        }

        // 写入提现单系统消息
        $withdrawalMessage = WithdrawalMessage::create([
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $request->user()->id,
            'message' => $request->input('message'),
            'is_admin' => 1,
        ]);

        return response([
            'data' => [
                'id' => $withdrawalMessage->id,
                'withdrawal_id' => $withdrawalMessage->withdrawal_id,
                'user_id' => $withdrawalMessage->user_id,
                'message' => $withdrawalMessage->message,
                'is_admin' => true,
                'created_at' => $withdrawalMessage->created_at?->timestamp ?? null,
            ],
        ]);
    }
}
