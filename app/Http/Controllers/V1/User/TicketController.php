<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
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
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first()
                ->load('message');
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
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
        $ticket = Ticket::where('id', $request->input('id'))
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
        $ticket = Ticket::where('id', $request->input('id'))
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

        $limit = (float) admin_setting('commission_withdraw_limit', 100);
        $ticket = null;

        try {
            // 锁用户行：校验额度 + 冻结(扣减)全部可提现佣金 + 建工单，防止关单后同余额再开提现单双付
            $ticket = DB::transaction(function () use ($request, $limit) {
                $user = User::query()->lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new ApiException(__('The user does not exist'));
                }

                $balanceCents = (int) $user->commission_balance;
                if ($limit > ($balanceCents / 100)) {
                    throw new ApiException(
                        __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])
                    );
                }
                if ($balanceCents <= 0) {
                    throw new ApiException(__('Insufficient commission balance'));
                }

                // 无 amount 字段：整笔佣金一并冻结；运营按工单金额打款
                $user->commission_balance = 0;
                if (!$user->save()) {
                    throw new ApiException(__('Transfer failed'));
                }

                $ticketService = new TicketService();
                $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
                $message = sprintf(
                    "%s\r\n%s\r\n%s",
                    __('Withdrawal method') . "：" . $request->input('withdraw_method'),
                    __('Withdrawal account') . "：" . $request->input('withdraw_account'),
                    __('Withdrawal amount') . "：" . number_format($balanceCents / 100, 2)
                );

                return $ticketService->createTicket(
                    $user->id,
                    $subject,
                    2,
                    $message
                );
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }

        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);
    }
}
