<?php

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\TicketController as UserTicketController;
use App\Http\Controllers\V2\Admin\TicketController;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 管理端工单消息撤回/修改：仅本人、非机器人、5 分钟内可操作。
 */
class AdminTicketMessageModifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_own_message_within_window(): void
    {
        [$admin, $ticket] = $this->makeContext();
        $message = $this->makeMessage($ticket, $admin->id, 'typo');

        $response = $this->callController('updateMessage', $admin, [
            'id' => $message->id,
            'message' => '  fixed  ',
        ]);

        $this->assertSame('success', $response->getData(true)['status']);
        $message->refresh();
        $this->assertSame('fixed', $message->message);
        $this->assertNotNull($message->edited_at);
    }

    public function test_cannot_edit_message_sent_by_another_admin(): void
    {
        [, $ticket] = $this->makeContext();
        $otherAdmin = $this->makeUser();
        $message = $this->makeMessage($ticket, $otherAdmin->id, 'other');
        $currentAdmin = $this->makeUser();

        $this->expectException(ApiException::class);

        app(TicketService::class)->updateMessage((int) $message->id, (int) $currentAdmin->id, 'hacked');
    }

    public function test_cannot_modify_message_after_window(): void
    {
        [$admin, $ticket] = $this->makeContext();
        $message = $this->makeMessage($ticket, $admin->id, 'old');
        $message->created_at = time() - TicketService::MESSAGE_EDIT_WINDOW - 1;
        $message->save();

        $this->expectException(ApiException::class);

        app(TicketService::class)->recallMessage((int) $message->id, (int) $admin->id);
    }

    public function test_cannot_modify_bot_message(): void
    {
        [$admin, $ticket] = $this->makeContext();
        $message = $this->makeMessage($ticket, $admin->id, 'bot says', true);

        $this->expectException(ApiException::class);

        app(TicketService::class)->updateMessage((int) $message->id, (int) $admin->id, 'changed');
    }

    public function test_recall_marks_message_and_syncs_ticket_state(): void
    {
        [$admin, $ticket] = $this->makeContext();
        $this->makeMessage($ticket, $ticket->user_id, 'user question');
        $message = $this->makeMessage($ticket, $admin->id, 'oops');
        $ticket->reply_status = Ticket::REPLY_STATUS_REPLIED;
        $ticket->last_reply_user_id = $admin->id;
        $ticket->save();

        $response = $this->callController('recallMessage', $admin, ['id' => $message->id]);

        $this->assertSame('success', $response->getData(true)['status']);
        $message->refresh();
        $this->assertNotNull($message->recalled_at);

        $ticket->refresh();
        $this->assertSame(Ticket::REPLY_STATUS_WAITING, (int) $ticket->reply_status);
        $this->assertSame((int) $ticket->user_id, (int) $ticket->last_reply_user_id);
    }

    public function test_recalled_message_is_hidden_from_user_fetch(): void
    {
        [$admin, $ticket] = $this->makeContext();
        $user = User::findOrFail($ticket->user_id);
        $visible = $this->makeMessage($ticket, $user->id, 'visible');
        $recalled = $this->makeMessage($ticket, $admin->id, 'revoked');
        app(TicketService::class)->recallMessage((int) $recalled->id, (int) $admin->id);

        $request = Request::create('/api/v1/user/ticket/fetch', 'GET', ['id' => $ticket->id]);
        $request->setUserResolver(fn () => $user);
        $payload = app(UserTicketController::class)->fetch($request)->getData(true);

        $ids = array_column($payload['data']['message'], 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($recalled->id, $ids);
    }

    /**
     * @return array{0: User, 1: Ticket}
     */
    private function makeContext(): array
    {
        $user = $this->makeUser();
        $admin = $this->makeUser();

        $ticket = new Ticket();
        $ticket->forceFill([
            'user_id' => $user->id,
            'subject' => 'subject-' . Helper::guid(),
            'level' => 0,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket->save();

        return [$admin, $ticket];
    }

    private function makeMessage(Ticket $ticket, int $userId, string $content, bool $isBot = false): TicketMessage
    {
        $message = new TicketMessage();
        $message->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'message' => $content,
            'is_bot' => $isBot,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $message->save();

        return $message;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'ticket-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return $user;
    }

    private function callController(string $method, User $admin, array $payload): JsonResponse
    {
        $request = Request::create('/api/v2/admin/ticket/message/' . $method, 'POST', $payload);
        $request->setUserResolver(fn () => $admin);

        return app(TicketController::class)->{$method}($request);
    }
}
