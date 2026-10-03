<?php

namespace Plugin\ExtensionDemo;

use App\Services\Plugin\AbstractPlugin;

/**
 * 管理后台 UI 扩展示例插件。
 *
 * 本插件演示了后台扩展的全部能力，声明式部分在 config.json 的
 * `admin_ui` / `admin_nav` 中，程序式部分在 boot() 中：
 * - 具名插槽（content.before / header.actions / page.actions）
 * - CSS 锚点注入
 * - 声明式按钮，点击调用 registerAction
 * - 侧边栏菜单 + 整页（iframe）
 * - 插件 i18n 合并
 */
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 1) 注册一个动作，供 config.json 里的声明式按钮调用
        $this->registerAction(
            name: 'sync_demo',
            label: '同步演示数据',
            handler: function (array $params = []) {
                return [
                    'success' => true,
                    'message' => '演示同步完成，参数：' . json_encode($params, JSON_UNESCAPED_UNICODE),
                ];
            },
            options: ['icon' => '🔄', 'type' => 'default']
        );

        // 2) 程序式注册一个头部徽标组件（声明式部分见 config.json）
        $this->registerAdminExtension([
            'id' => 'demo_header_badge',
            'slot' => 'header.actions',
            'type' => 'component',
            'component' => 'DemoHeaderBadge',
            'script' => 'admin.js',
        ]);
    }
}
