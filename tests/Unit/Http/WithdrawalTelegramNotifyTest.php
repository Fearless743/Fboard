<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\TicketController;
use App\Http\Requests\User\TicketWithdraw;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Plugin\HookManager;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Plugin\Telegram\Plugin as TelegramPlugin;
use Tests\TestCase;

class WithdrawalTelegramNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdraw_fires_hook_after_transaction(): void
    {
        admin_setting([
            'withdraw_close_enable' => 0,
            'commission_withdraw_limit' => 1,
            'commission_withdraw_method' => Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT,
        ]);

        $user = $this->createUser(10000);
        Auth::guard('sanctum')->setUser($user);

        $captured = null;
        HookManager::register('withdrawal.create.after', function ($withdrawal) use (&$captured) {
            $captured = $withdrawal;
        });

        $controller = app(TicketController::class);
        $request = TicketWithdraw::create('/api/v1/user/ticket/withdraw', 'POST', [
            'withdraw_method' => Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT[0] ?? '支付宝',
            'withdraw_account' => 'notify@example.com',
            'withdraw_amount' => 10000,
        ]);
        $request->setUserResolver(fn () => $user);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller->withdraw($request);

        $this->assertInstanceOf(Withdrawal::class, $captured);
        $this->assertSame(10000, (int) $captured->amount);
        $this->assertSame('notify@example.com', $captured->withdraw_account);

        HookManager::remove('withdrawal.create.after');
    }

    public function test_plugin_sends_telegram_job_for_withdrawal(): void
    {
        Queue::fake();

        $user = $this->createUser(10000);
        $this->createUser(0, ['is_admin' => 1, 'telegram_id' => 123456]);

        $withdrawal = $this->createWithdrawal($user);

        $plugin = new TelegramPlugin('telegram');
        $plugin->setConfig(['enable_withdrawal_notify' => true]);
        $plugin->boot();
        $plugin->sendWithdrawalNotify($withdrawal);

        Queue::assertPushed(SendTelegramJob::class, function (SendTelegramJob $job) {
            $text = $this->readJobText($job);
            return str_contains($text, '提现申请提醒')
                && str_contains($text, '123.45 元')
                && str_contains($text, 'notify@example.com')
                && str_contains($text, '支付宝');
        });
    }

    public function test_plugin_skips_notification_when_disabled(): void
    {
        Queue::fake();

        $user = $this->createUser(10000);
        $this->createUser(0, ['is_admin' => 1, 'telegram_id' => 123456]);

        $plugin = new TelegramPlugin('telegram');
        $plugin->setConfig(['enable_withdrawal_notify' => false]);
        $plugin->boot();
        $plugin->sendWithdrawalNotify($this->createWithdrawal($user));

        Queue::assertNotPushed(SendTelegramJob::class);
    }

    private function createUser(int $commissionBalance, array $extra = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => 'wd-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'commission_balance' => $commissionBalance,
            'created_at' => time(),
            'updated_at' => time(),
        ], $extra));
        $user->save();

        return $user;
    }

    private function createWithdrawal(User $user): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'withdraw_method' => '支付宝',
            'withdraw_account' => 'notify@example.com',
            'amount' => 12345,
            'status' => Withdrawal::STATUS_PENDING,
        ]);
    }

    private function readJobText(SendTelegramJob $job): string
    {
        $property = new \ReflectionProperty($job, 'text');
        $property->setAccessible(true);

        return (string) $property->getValue($job);
    }
}
