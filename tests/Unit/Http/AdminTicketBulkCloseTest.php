<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\TicketController;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 管理端工单批量关闭：只关闭选中的处理中工单，已关闭的保持不变。
 */
class AdminTicketBulkCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_close_only_closes_selected_open_tickets(): void
    {
        $user = $this->makeUser();

        $openA = $this->makeTicket($user->id, Ticket::STATUS_OPENING);
        $openB = $this->makeTicket($user->id, Ticket::STATUS_OPENING);
        $closed = $this->makeTicket($user->id, Ticket::STATUS_CLOSED);

        $response = app(TicketController::class)->bulkClose(
            Request::create('/api/v2/admin/ticket/bulk-close', 'POST', [
                'ids' => [$openA->id, $openB->id, $closed->id],
            ])
        );

        $payload = $response->getData(true);
        $this->assertSame('success', $payload['status']);
        $this->assertSame(2, (int) $payload['data']['count']);

        $this->assertSame(Ticket::STATUS_CLOSED, (int) $openA->refresh()->status);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $openB->refresh()->status);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $closed->refresh()->status);
    }

    public function test_bulk_close_requires_non_empty_ids(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TicketController::class)->bulkClose(
            Request::create('/api/v2/admin/ticket/bulk-close', 'POST', ['ids' => []])
        );
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

    private function makeTicket(int $userId, int $status): Ticket
    {
        $ticket = new Ticket();
        $ticket->forceFill([
            'user_id' => $userId,
            'subject' => 'subject-' . Helper::guid(),
            'level' => 0,
            'status' => $status,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket->save();

        return $ticket;
    }
}
