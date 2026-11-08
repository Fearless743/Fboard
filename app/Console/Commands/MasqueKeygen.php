<?php

namespace App\Console\Commands;

use App\Utils\Helper;
use Illuminate\Console\Command;

class MasqueKeygen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fboard:masque-keygen {--pem : 输出 PEM 格式（默认输出 base64 DER，便于核对）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '生成 MASQUE/usque 端点密钥对（ECDSA P-256），填入节点 protocol_settings 的 endpoint_private_key / endpoint_public_key';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($res === false) {
            $this->error('keygen failed: '.(string) openssl_error_string());

            return 1;
        }
        if (!openssl_pkey_export($res, $privPem)) {
            $this->error('export failed');

            return 1;
        }
        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || empty($details['key'])) {
            $this->error('read public key failed');

            return 1;
        }
        if ($this->option('pem')) {
            $this->line('endpoint_private_key (PEM):');
            $this->line($privPem);
            $this->line('endpoint_public_key (PEM):');
            $this->line($details['key']);
        } else {
            $this->line('endpoint_private_key (base64 DER SEC1, 贴入节点配置后服务端自动识别 PEM/base64)：');
            $this->line((string) Helper::pemToDerBase64($privPem));
            $this->line('endpoint_public_key (base64 DER PKIX)：');
            $this->line((string) Helper::pemToDerBase64($details['key']));
            $this->info('提示：节点 protocol_settings 建议直接粘贴 PEM 文本；mihomo 订阅 public-key 使用上面 base64。');
        }

        return 0;
    }
}
