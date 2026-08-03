<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\WithdrawalMessage;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    /**
     * 获取用户的提现单列表
     */
    public function fetch(Request $request)
    {
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->latest('created_at')
            ->get();

        return $this->success($withdrawals->map(function ($w) {
            return [
                'id' => 'withdrawal_' . $w->id,
                'withdrawal_id' => $w->id,
                'status' => $w->status,
                'status_text' => Withdrawal::$statusMap[$w->status] ?? '',
                'amount' => $w->amount,
                'withdraw_method' => $w->withdraw_method,
                'withdraw_account' => $w->withdraw_account,
                'remark' => $w->remark,
                'created_at' => $w->created_at,
                'updated_at' => $w->updated_at,
            ];
        }));
    }

    /**
     * 获取单个提现单详情及对话消息
     */
    public function detail(Request $request)
    {
        $inputId = $request->input('id');
        $numericId = preg_replace('/^withdrawal_/', '', $inputId);

        $withdrawal = Withdrawal::where('id', $numericId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$withdrawal) {
            return $this->fail([400, __('Withdrawal does not exist')]);
        }

        $data = [
            'id' => 'withdrawal_' . $withdrawal->id,
            'withdrawal_id' => $withdrawal->id,
            'status' => $withdrawal->status,
            'status_text' => Withdrawal::$statusMap[$withdrawal->status] ?? '',
            'amount' => $withdrawal->amount,
            'withdraw_method' => $withdrawal->withdraw_method,
            'withdraw_account' => $withdrawal->withdraw_account,
            'remark' => $withdrawal->remark,
            'created_at' => $withdrawal->created_at,
            'updated_at' => $withdrawal->updated_at,
        ];

        // 加载提现单自己的消息
        $messages = WithdrawalMessage::where('withdrawal_id', $withdrawal->id)
            ->latest()
            ->get();

        $messages->each(function ($msg) use ($withdrawal) {
            $msg['is_me'] = ($msg->user_id == $withdrawal->user_id);
            $msg['is_admin'] = (bool) $msg->is_admin;
        });

        $data['messages'] = $messages;

        return $this->success($data);
    }

    /**
     * 用户回复提现单对话
     */
    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }

        $inputId = $request->input('id');
        $numericId = preg_replace('/^withdrawal_/', '', $inputId);

        $withdrawal = Withdrawal::where('id', $numericId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$withdrawal) {
            return $this->fail([400, __('Withdrawal does not exist')]);
        }

        if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
            return $this->fail([400, __('This withdrawal has been processed')]);
        }

        // 写入提现单消息
        WithdrawalMessage::create([
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $request->user()->id,
            'message' => $request->input('message'),
            'is_admin' => 0,
        ]);

        return $this->success(true);
    }

    /**
     * 用户取消提现申请
     */
    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }

        $inputId = $request->input('id');
        $numericId = preg_replace('/^withdrawal_/', '', $inputId);

        $withdrawal = Withdrawal::where('id', $numericId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$withdrawal) {
            return $this->fail([400, __('Withdrawal does not exist')]);
        }

        if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
            return $this->fail([400, __('This withdrawal has been processed')]);
        }

        // 返还佣金
        $user = \App\Models\User::find($withdrawal->user_id);
        if ($user) {
            $user->commission_balance += $withdrawal->amount;
            $user->save();
        }

        // 更新提现单状态
        $withdrawal->status = Withdrawal::STATUS_CLOSED;
        $withdrawal->save();

        // 写入提现单系统消息
        WithdrawalMessage::create([
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $request->user()->id,
            'message' => "【系统】用户取消了提现申请\r\n金额：" . sprintf('%.2f', $withdrawal->amount / 100) . " 元已返还",
            'is_admin' => 1,
        ]);

        return $this->success(true);
    }
}
