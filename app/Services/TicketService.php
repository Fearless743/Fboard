<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    /**
     * 消息可撤回/修改的时间窗口（秒）
     */
    public const MESSAGE_EDIT_WINDOW = 300;

    public function reply($ticket, $message, $userId)
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            $isAdmin = $userId !== $ticket->user_id;
            $ticket->reply_status = $isAdmin
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_WAITING;
            $ticket->last_reply_user_id = $userId;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    /**
     * 机器人自动回复工单
     * 插件可通过 hook 调用此方法，使用预设的机器人 user_id 进行回复
     */
    public function replyByBot(int $ticketId, string $message, ?int $botUserId = null): ?TicketMessage
    {
        $ticket = Ticket::find($ticketId);
        if (!$ticket) {
            return null;
        }

        $botUserId = $botUserId ?? config('ticket.bot_user_id', 0);

        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $botUserId,
                'ticket_id' => $ticket->id,
                'message' => $message,
                'is_bot' => true,
            ]);
            $ticket->reply_status = Ticket::REPLY_STATUS_REPLIED;
            $ticket->last_reply_user_id = $botUserId;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollBack();
            return null;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        $ticket = Ticket::where('id', $ticketId)->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticketMessage = $this->reply($ticket, $message, $userId);
        if (!$ticketMessage) {
            throw new ApiException('工单回复失败');
        }
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    /**
     * 判断消息是否仍允许当前管理员撤回/修改
     */
    public function canModifyMessage(TicketMessage $message, int $userId): bool
    {
        if ((bool) $message->is_bot || $message->recalled_at !== null) {
            return false;
        }
        if ((int) $message->user_id !== $userId) {
            return false;
        }
        $createdAt = $message->getAttribute('created_at');
        $createdAt = $createdAt instanceof \DateTimeInterface
            ? $createdAt->getTimestamp()
            : (int) $createdAt;
        return $createdAt >= time() - self::MESSAGE_EDIT_WINDOW;
    }

    /**
     * 修改管理员自己发送的消息
     */
    public function updateMessage(int $messageId, int $userId, string $message): TicketMessage
    {
        $ticketMessage = TicketMessage::find($messageId);
        if (!$ticketMessage) {
            throw new ApiException('消息不存在');
        }
        if (!$this->canModifyMessage($ticketMessage, $userId)) {
            throw new ApiException('该消息已超过可修改时间或无权操作');
        }
        $content = trim($message);
        if ($content === '') {
            throw new ApiException('消息内容不能为空');
        }
        $ticketMessage->message = $content;
        $ticketMessage->edited_at = time();
        if (!$ticketMessage->save()) {
            throw new ApiException('消息修改失败');
        }
        return $ticketMessage;
    }

    /**
     * 撤回管理员自己发送的消息（软撤回，内容不再对用户展示）
     */
    public function recallMessage(int $messageId, int $userId): TicketMessage
    {
        $ticketMessage = TicketMessage::find($messageId);
        if (!$ticketMessage) {
            throw new ApiException('消息不存在');
        }
        if (!$this->canModifyMessage($ticketMessage, $userId)) {
            throw new ApiException('该消息已超过可撤回时间或无权操作');
        }
        DB::transaction(function () use ($ticketMessage) {
            $ticketMessage->recalled_at = time();
            if (!$ticketMessage->save()) {
                throw new ApiException('消息撤回失败');
            }
            $this->syncTicketReplyState(Ticket::find($ticketMessage->ticket_id));
        });
        return $ticketMessage;
    }

    /**
     * 撤回后同步工单的最后回复状态，避免已撤回的消息继续影响待回复/已回复判断
     */
    private function syncTicketReplyState(?Ticket $ticket): void
    {
        if (!$ticket) {
            return;
        }
        $lastMessage = TicketMessage::where('ticket_id', $ticket->id)
            ->whereNull('recalled_at')
            ->orderBy('id', 'desc')
            ->first();
        $ticket->last_reply_user_id = $lastMessage
            ? (int) $lastMessage->user_id
            : (int) $ticket->user_id;
        $ticket->reply_status = $lastMessage && (int) $lastMessage->user_id !== (int) $ticket->user_id
            ? Ticket::REPLY_STATUS_REPLIED
            : Ticket::REPLY_STATUS_WAITING;
        $ticket->save();
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level,
                'reply_status' => Ticket::REPLY_STATUS_WAITING,
                'last_reply_user_id' => $userId,
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                throw new ApiException('工单消息创建失败');
            }
            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'Fboard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'Fboard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
