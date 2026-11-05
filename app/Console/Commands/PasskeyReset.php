<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserPasskey;
use Illuminate\Console\Command;

class PasskeyReset extends Command
{
    protected $signature = 'fboard:passkey:reset {email} {--list : 仅列出凭证} {--delete-id= : 删除指定 ID 的凭证} {--clear : 删除该管理员全部凭证（将允许其用密码重新绑定）}';

    protected $description = '管理管理员 Passkey 凭证（兜底恢复）';

    public function handle(): int
    {
        $user = User::byEmail($this->argument('email'))->first();
        if (!$user || !$user->is_admin) {
            $this->error('管理员不存在');
            return 1;
        }
        $passkeys = UserPasskey::where('user_id', $user->id)->orderBy('id')->get();
        if ($this->option('list') || (!$this->option('delete-id') && !$this->option('clear'))) {
            if ($passkeys->isEmpty()) {
                $this->info('该管理员暂无 Passkey 凭证');
            } else {
                $this->table(
                    ['ID', 'Name', 'Credential', 'LastUsed', 'Created'],
                    $passkeys->map(fn ($p) => [
                        $p->id,
                        $p->name,
                        substr($p->credential_id, 0, 24) . '...',
                        $p->last_used_at ? date('Y-m-d H:i', $this->toTimestamp($p->last_used_at)) : '-',
                        date('Y-m-d H:i', $this->toTimestamp($p->created_at)),
                    ])->all()
                );
            }
            if (!$this->option('delete-id') && !$this->option('clear')) {
                return 0;
            }
        }
        if ($id = $this->option('delete-id')) {
            $deleted = UserPasskey::where('user_id', $user->id)->where('id', (int) $id)->delete();
            $this->info($deleted ? "已删除凭证 #{$id}" : '凭证不存在');
            return $deleted ? 0 : 1;
        }
        if ($this->option('clear')) {
            if (!$this->confirm("确认删除 {$user->email} 的全部 Passkey 凭证？之后可用密码登录重绑")) {
                return 1;
            }
            UserPasskey::where('user_id', $user->id)->delete();
            $this->info('已清空，该管理员下次用密码登录将进入绑定流程');
            return 0;
        }
        return 0;
    }

    private function toTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        return (int) $value;
    }
}
