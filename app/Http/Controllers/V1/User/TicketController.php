<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\User\WithdrawalController;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalMessage;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $inputId = $request->input('id');

            // 根据前缀路由到不同系统
            if (str_starts_with($inputId, 'withdrawal_')) {
                $controller = app(WithdrawalController::class);
                $request->merge(['id' => $inputId]);
                return $controller->detail($request);
            }

            // ticket_ 前缀或无前缀 → 工单系统
            $numericId = preg_replace('/^ticket_/', '', $inputId);

            $ticket = Ticket::where('id', $numericId)
                ->where('user_id', $request->user()->id)
                ->first();
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });

            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }

        // 获取工单列表
        $tickets = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();

        // 获取提现单列表并转换为工单格式
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();

        // 合并两个列表，按创建时间排序
        $allItems = [];
        foreach ($tickets as $ticket) {
            $allItems[] = [
                'type' => 'ticket',
                'created_at_ts' => (int) $ticket->created_at,
                'data' => [
                    'id' => 'ticket_' . $ticket->id,
                    'level' => $ticket->level,
                    'reply_status' => $ticket->reply_status,
                    'status' => $ticket->status,
                    'subject' => $ticket->subject,
                    'message' => null,
                    'created_at' => $ticket->created_at,
                    'updated_at' => $ticket->updated_at,
                    'user_id' => $ticket->user_id,
                ],
            ];
        }

        foreach ($withdrawals as $withdrawal) {
            $allItems[] = [
                'type' => 'withdrawal',
                'created_at_ts' => (int) $withdrawal->created_at,
                'data' => [
                    'id' => 'withdrawal_' . $withdrawal->id,
                    'level' => 2,
                    'reply_status' => $withdrawal->status === Withdrawal::STATUS_PENDING ? 0 : 1,
                    'status' => $withdrawal->status,
                    'subject' => '[提现申请] 金额: ' . sprintf('%.2f', $withdrawal->amount / 100) . ' 元 | 方式: ' . $withdrawal->withdraw_method . ' | 账号: ' . $withdrawal->withdraw_account,
                    'message' => null,
                    'created_at' => $withdrawal->created_at,
                    'updated_at' => $withdrawal->updated_at,
                    'user_id' => $withdrawal->user_id,
                ],
            ];
        }

        // 按创建时间倒序排序
        usort($allItems, function ($a, $b) {
            return $b['created_at_ts'] - $a['created_at_ts'];
        });

        $items = array_column($allItems, 'data');

        return $this->success($items);
    }

    public function save(TicketSave $request)
    {
        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message')
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }

        $inputId = $request->input('id');

        // 根据前缀路由到不同系统
        if (str_starts_with($inputId, 'withdrawal_')) {
            $controller = app(WithdrawalController::class);
            return $controller->reply($request);
        }

        // ticket_ 前缀或无前缀 → 工单系统
        $numericId = preg_replace('/^ticket_/', '', $inputId);

        $ticket = Ticket::where('id', $numericId)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail(codeResponse: [400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $request->input('message'),
                $request->user()->id
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }

        $inputId = $request->input('id');

        // 根据前缀路由到不同系统
        if (str_starts_with($inputId, 'withdrawal_')) {
            $controller = app(WithdrawalController::class);
            return $controller->close($request);
        }

        // ticket_ 前缀或无前缀 → 工单系统
        $numericId = preg_replace('/^ticket_/', '', $inputId);

        $ticket = Ticket::where('id', $numericId)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        HookManager::call('ticket.close.after', [
            'ticket' => $ticket,
            'request' => $request,
        ]);
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        if (
            !in_array(
                $request->input('withdraw_method'),
                admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
            )
        ) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }

        $limit = (float) admin_setting('commission_withdraw_limit', 100) * 100;
        $amountCents = (int) $request->input('withdraw_amount', 0);

        if ($amountCents <= 0) {
            return $this->fail([422, __('Withdrawal amount must be greater than 0')]);
        }
        if ($limit > $amountCents) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit / 100])]);
        }

        try {
            // 锁用户行：校验额度 + 扣除申请金额，防止同余额重复提现
            DB::transaction(function () use ($request, $amountCents, $limit) {
                $user = User::query()->lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new ApiException(__('The user does not exist'));
                }

                $balanceCents = (int) $user->commission_balance;
                if ($amountCents > $balanceCents) {
                    throw new ApiException(__('Insufficient commission balance'));
                }
                if ($balanceCents <= 0) {
                    throw new ApiException(__('Insufficient commission balance'));
                }

                // 仅扣除申请金额，保留剩余余额
                $user->commission_balance = $balanceCents - $amountCents;
                if (!$user->save()) {
                    throw new ApiException(__('Transfer failed'));
                }

                $withdrawal = Withdrawal::create([
                    'user_id' => $user->id,
                    'withdraw_method' => $request->input('withdraw_method'),
                    'withdraw_account' => $request->input('withdraw_account'),
                    'amount' => $amountCents,
                    'status' => Withdrawal::STATUS_PENDING,
                ]);

                // 创建提现单系统消息
                WithdrawalMessage::create([
                    'withdrawal_id' => $withdrawal->id,
                    'user_id' => $user->id,
                    'message' => "金额：" . sprintf('%.2f', $amountCents / 100.0) . " 元\r\n方式：" . $request->input('withdraw_method') . "\r\n账号：" . $request->input('withdraw_account'),
                    'is_admin' => 0,
                ]);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }

        return $this->success(true);
    }
}
