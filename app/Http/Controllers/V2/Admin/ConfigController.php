<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use App\Services\MailService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\ProtocolDefinitionRegistry;
use App\Services\TelegramService;
use App\Services\ThemeService;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ConfigController extends Controller
{


    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function testSendMail(Request $request)
    {
        $mailLog = MailService::sendEmail([
            'email' => $request->user()->email,
            'subject' => 'This is fboard test email',
            'template_name' => 'notify',
            'template_value' => [
                'name' => admin_setting('app_name', 'Fboard'),
                'content' => 'This is fboard test email',
                'url' => admin_setting('app_url')
            ]
        ]);
        return response([
            'data' => $mailLog,
        ]);
    }
    public function setTelegramWebhook(Request $request)
    {
        $hookUrl = $this->resolveTelegramWebhookUrl();
        if (blank($hookUrl)) {
            return $this->fail([422, 'Telegram Webhook地址未配置']);
        }
        $hookUrl .= '?' . http_build_query([
            'access_token' => md5(admin_setting('telegram_bot_token', $request->input('telegram_bot_token')))
        ]);
        $telegramService = new TelegramService($request->input('telegram_bot_token'));
        $telegramService->getMe();
        $telegramService->setWebhook(url: $hookUrl);
        $telegramService->registerBotCommands();
        return $this->success([
            'success' => true,
            'webhook_url' => $hookUrl,
            'webhook_base_url' => $this->getTelegramWebhookBaseUrl(),
        ]);
    }

    /** @var array<string, string> 配置字段 key => 协议名 */
    private const SUBSCRIBE_TEMPLATE_KEYS = [
        'subscribe_template_singbox' => 'singbox',
        'subscribe_template_clash' => 'clash',
        'subscribe_template_clashmeta' => 'clashmeta',
        'subscribe_template_stash' => 'stash',
        'subscribe_template_surge' => 'surge',
        'subscribe_template_surfboard' => 'surfboard',
    ];

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $name = $request->input('name');

        // 订阅模板：支持按 name 单独按需获取，避免一次下发全部大模板
        if ($key === 'subscribe_template' && filled($name)) {
            $name = strtolower((string) $name);
            if (!in_array($name, array_values(self::SUBSCRIBE_TEMPLATE_KEYS), true)) {
                return $this->fail([422, 'Invalid subscribe template name']);
            }
            $fieldKey = 'subscribe_template_' . $name;
            return $this->success([
                'subscribe_template' => [
                    $fieldKey => $this->getSubscribeTemplateField($name),
                ],
            ]);
        }

        if ($key) {
            $section = $this->getConfigSection((string) $key);
            if ($section !== null) {
                return $this->success([$key => $section]);
            }
        }

        return $this->success($this->getConfigMappings());
    }

    /**
     * 获取全部配置映射（无 key 时兼容旧调用）
     *
     * @return array<string, array<string, mixed>>
     */
    private function getConfigMappings(): array
    {
        $keys = ['invite', 'site', 'subscribe', 'server', 'email', 'telegram', 'app', 'safe', 'subscribe_template'];
        $mappings = [];
        foreach ($keys as $key) {
            $section = $this->getConfigSection($key);
            if ($section !== null) {
                $mappings[$key] = $section;
            }
        }
        return $mappings;
    }

    /**
     * 按 section 懒加载配置，避免请求其它 section 时加载全部订阅模板
     *
     * @return array<string, mixed>|null
     */
    private function getConfigSection(string $key): ?array
    {
        return match ($key) {
            'invite' => [
                'invite_force' => (bool) admin_setting('invite_force', 0),
                'invite_commission' => admin_setting('invite_commission', 10),
                'invite_gen_limit' => admin_setting('invite_gen_limit', 5),
                'invite_never_expire' => (bool) admin_setting('invite_never_expire', 0),
                'commission_first_time_enable' => (bool) admin_setting('commission_first_time_enable', 1),
                'commission_auto_check_enable' => (bool) admin_setting('commission_auto_check_enable', 1),
                'commission_withdraw_limit' => admin_setting('commission_withdraw_limit', 100),
                'commission_withdraw_method' => admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => (bool) admin_setting('withdraw_close_enable', 0),
                'commission_distribution_enable' => (bool) admin_setting('commission_distribution_enable', 0),
                'commission_distribution_l1' => admin_setting('commission_distribution_l1'),
                'commission_distribution_l2' => admin_setting('commission_distribution_l2'),
                'commission_distribution_l3' => admin_setting('commission_distribution_l3'),
            ],
            'site' => [
                'logo' => admin_setting('logo'),
                'force_https' => (int) admin_setting('force_https', 0),
                'stop_register' => (int) admin_setting('stop_register', 0),
                'app_name' => admin_setting('app_name', 'Fboard'),
                'app_description' => admin_setting('app_description', 'Fboard is best!'),
                'app_url' => admin_setting('app_url'),
                'subscribe_url' => admin_setting('subscribe_url'),
                'try_out_plan_id' => (int) admin_setting('try_out_plan_id', 0),
                'try_out_hour' => (int) admin_setting('try_out_hour', 1),
                'tos_url' => admin_setting('tos_url'),
                'currency' => admin_setting('currency', 'CNY'),
                'currency_symbol' => admin_setting('currency_symbol', '¥'),
                'ticket_must_wait_reply' => (bool) admin_setting('ticket_must_wait_reply', 0),
                'maintenance_mode' => (bool) admin_setting('maintenance_mode', 0),
            ],
            'subscribe' => [
                'plan_change_enable' => (bool) admin_setting('plan_change_enable', 1),
                'reset_traffic_method' => (int) admin_setting('reset_traffic_method', 0),
                'surplus_enable' => (bool) admin_setting('surplus_enable', 1),
                'new_order_event_id' => (int) admin_setting('new_order_event_id', 0),
                'renew_order_event_id' => (int) admin_setting('renew_order_event_id', 0),
                'change_order_event_id' => (int) admin_setting('change_order_event_id', 0),
                'show_info_to_server_enable' => (bool) admin_setting('show_info_to_server_enable', 0),
                'show_protocol_to_server_enable' => (bool) admin_setting('show_protocol_to_server_enable', 0),
                'default_remind_expire' => (bool) admin_setting('default_remind_expire', 1),
                'default_remind_traffic' => (bool) admin_setting('default_remind_traffic', 1),
                'subscribe_path' => admin_setting('subscribe_path', 's'),
                'multi_plan_enable' => (bool) admin_setting('multi_plan_enable', 0),
                'deposit_enable' => (bool) admin_setting('deposit_enable', 1),
                'deposit_min_amount' => (int) admin_setting('deposit_min_amount', 100),
                'deposit_max_amount' => (int) admin_setting('deposit_max_amount', 999999900),
                'deposit_commission_enable' => (bool) admin_setting('deposit_commission_enable', 1),
                'deposit_bonus' => admin_setting('deposit_bonus', admin_setting('deposit_bounus', [])),
            ],
            'server' => [
                'server_token' => admin_setting('server_token'),
                'server_pull_interval' => admin_setting('server_pull_interval', 60),
                'server_push_interval' => admin_setting('server_push_interval', 60),
                'device_limit_mode' => (int) admin_setting('device_limit_mode', 0),
                'server_ws_enable' => (bool) admin_setting('server_ws_enable', 1),
                'server_ws_url' => admin_setting('server_ws_url', ''),
                'server_ws_log_enable' => (bool) admin_setting('server_ws_log_enable', 0),
                'node_install_script_url' => admin_setting('node_install_script_url', ''),
                // 仅具体指纹（不含 random/randomized，二者下发节点表单时自动追加）
                'utls_fingerprints' => Helper::getUtlsFingerprints(),
            ],
            'email' => [
                'email_host' => admin_setting('email_host'),
                'email_port' => admin_setting('email_port'),
                'email_username' => admin_setting('email_username'),
                'email_password' => admin_setting('email_password'),
                'email_encryption' => admin_setting('email_encryption'),
                'email_from_address' => admin_setting('email_from_address'),
                'remind_mail_enable' => (bool) admin_setting('remind_mail_enable', false),
            ],
            'telegram' => [
                'telegram_bot_enable' => (bool) admin_setting('telegram_bot_enable', 0),
                'telegram_bot_token' => admin_setting('telegram_bot_token'),
                'telegram_webhook_url' => admin_setting('telegram_webhook_url'),
                'telegram_discuss_link' => admin_setting('telegram_discuss_link'),
            ],
            'app' => [
                'windows_version' => admin_setting('windows_version', ''),
                'windows_download_url' => admin_setting('windows_download_url', ''),
                'macos_version' => admin_setting('macos_version', ''),
                'macos_download_url' => admin_setting('macos_download_url', ''),
                'android_version' => admin_setting('android_version', ''),
                'android_download_url' => admin_setting('android_download_url', ''),
            ],
            'safe' => [
                'email_verify' => (bool) admin_setting('email_verify', 0),
                'safe_mode_enable' => (bool) admin_setting('safe_mode_enable', 0),
                'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (bool) admin_setting('email_whitelist_enable', 0),
                'email_whitelist_suffix' => admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => (bool) admin_setting('email_gmail_limit_enable', 0),
                'captcha_enable' => (bool) admin_setting('captcha_enable', 0),
                'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
                'recaptcha_key' => admin_setting('recaptcha_key', ''),
                'recaptcha_site_key' => admin_setting('recaptcha_site_key', ''),
                'recaptcha_v3_secret_key' => admin_setting('recaptcha_v3_secret_key', ''),
                'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key', ''),
                'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
                'turnstile_secret_key' => admin_setting('turnstile_secret_key', ''),
                'turnstile_site_key' => admin_setting('turnstile_site_key', ''),
                'register_limit_by_ip_enable' => (bool) admin_setting('register_limit_by_ip_enable', 0),
                'register_limit_count' => admin_setting('register_limit_count', 3),
                'register_limit_expire' => admin_setting('register_limit_expire', 60),
                'password_limit_enable' => (bool) admin_setting('password_limit_enable', 1),
                'password_limit_count' => admin_setting('password_limit_count', 5),
                'password_limit_expire' => admin_setting('password_limit_expire', 60),
                // 保持向后兼容
                'recaptcha_enable' => (bool) admin_setting('captcha_enable', 0),
            ],
            // 无 name 时仍返回全部（兼容旧前端）；新前端应带 name 按需获取
            'subscribe_template' => $this->getAllSubscribeTemplateFields(),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function getAllSubscribeTemplateFields(): array
    {
        $fields = [];
        foreach (self::SUBSCRIBE_TEMPLATE_KEYS as $fieldKey => $name) {
            $fields[$fieldKey] = $this->getSubscribeTemplateField($name);
        }
        return $fields;
    }

    private function getSubscribeTemplateField(string $name): string
    {
        $content = subscribe_template($name) ?? '';
        if ($name === 'singbox') {
            return $this->formatTemplateContent($content, 'json');
        }
        return $content;
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();

        HookManager::call('admin.config.save.before', [
            'data' => $data,
            'request' => $request,
        ]);

        foreach ($data as $k => $v) {
            if (isset(self::SUBSCRIBE_TEMPLATE_KEYS[$k])) {
                SubscribeTemplate::setContent(self::SUBSCRIBE_TEMPLATE_KEYS[$k], $v);
                continue;
            }
            if ($k == 'frontend_theme') {
                $themeService = app(ThemeService::class);
                $themeService->switch($v);
            }
            // 规范化指纹列表：trim + 小写 + 去重；剔除 random/randomized 元选项
            if ($k === 'utls_fingerprints' && is_array($v)) {
                $meta = array_fill_keys(Dict::UTLS_FINGERPRINT_META, true);
                $normalized = [];
                foreach ($v as $item) {
                    $value = strtolower(trim((string) $item));
                    if ($value === '' || isset($meta[$value])) {
                        continue;
                    }
                    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
                        continue;
                    }
                    $normalized[$value] = $value;
                }
                $v = array_values($normalized);
                if ($v === []) {
                    $v = Dict::UTLS_FINGERPRINTS_DEFAULT;
                }
            }
            admin_setting([$k => $v]);
        }

        // 指纹列表变更后重置协议定义缓存，下一次请求重新注入 options
        if (array_key_exists('utls_fingerprints', $data)) {
            try {
                app(ProtocolDefinitionRegistry::class)->reset();
            } catch (\Throwable) {
                // ignore when registry not bound in this context
            }
        }

        if (array_key_exists('maintenance_mode', $data)) {
            NodeSyncService::notifyMaintenanceModeChanged();
        }

        HookManager::call('admin.config.save.after', [
            'data' => $data,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    /**
     * 格式化模板内容
     * 
     * @param mixed $content 模板内容
     * @param string $format 输出格式 (json|string)
     * @return string 格式化后的内容
     */
    private function formatTemplateContent(mixed $content, string $format = 'string'): string
    {
        return match ($format) {
            'json' => match (true) {
                    is_array($content) => json_encode(
                        value: $content,
                        flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),

                    is_string($content) && str($content)->isJson() => rescue(
                        callback: fn() => json_encode(
                            value: json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR),
                            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        rescue: $content,
                        report: false
                    ),

                    default => str($content)->toString()
                },

            default => str($content)->toString()
        };
    }

    private function getTelegramWebhookBaseUrl(): ?string
    {
        $customUrl = trim((string) admin_setting('telegram_webhook_url', ''));
        if ($customUrl !== '') {
            return rtrim($customUrl, '/');
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        return null;
    }

    private function resolveTelegramWebhookUrl(): ?string
    {
        $baseUrl = $this->getTelegramWebhookBaseUrl();
        if (!$baseUrl) {
            return null;
        }

        if (str_contains($baseUrl, '/api/v1/guest/telegram/webhook')) {
            return $baseUrl;
        }

        return $baseUrl . '/api/v1/guest/telegram/webhook';
    }
}
