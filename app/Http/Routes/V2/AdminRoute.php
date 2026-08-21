<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\MailTemplateController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\ProtocolDefinitionController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Http\Controllers\V2\Admin\Server\NetworkSettingsTemplateController;
use App\Http\Controllers\V2\Admin\Server\CertTemplateController;
use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V2\Admin\NoticeController;
use App\Http\Controllers\V2\Admin\TicketController;
use App\Http\Controllers\V2\Admin\CouponController;
use App\Http\Controllers\V2\Admin\GiftCardController;
use App\Http\Controllers\V2\Admin\KnowledgeController;
use App\Http\Controllers\V2\Admin\PaymentController;
use App\Http\Controllers\V2\Admin\PluginController;
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\ThemeController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
use App\Http\Controllers\V2\Admin\WithdrawalController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            // Config
            $router->group([
                'prefix' => 'config'
            ], function ($router) {
                $router->get('/fetch', [ConfigController::class, 'fetch']);
                $router->post('/save', [ConfigController::class, 'save']);
                $router->get('/getEmailTemplate', [ConfigController::class, 'getEmailTemplate']);
                $router->get('/getThemeTemplate', [ConfigController::class, 'getThemeTemplate']);
                $router->post('/setTelegramWebhook', [ConfigController::class, 'setTelegramWebhook']);
                $router->post('/testSendMail', [ConfigController::class, 'testSendMail']);
            });

            // Mail Templates
            $router->group([
                'prefix' => 'mail/template'
            ], function ($router) {
                $router->get('/list', [MailTemplateController::class, 'list']);
                $router->get('/get', [MailTemplateController::class, 'get']);
                $router->post('/save', [MailTemplateController::class, 'save']);
                $router->post('/reset', [MailTemplateController::class, 'reset']);
                $router->post('/test', [MailTemplateController::class, 'test']);
            });

            // Plan
            $router->group([
                'prefix' => 'plan'
            ], function ($router) {
                $router->get('/fetch', [PlanController::class, 'fetch']);
                $router->post('/save', [PlanController::class, 'save']);
                $router->post('/drop', [PlanController::class, 'drop']);
                $router->post('/update', [PlanController::class, 'update']);
                $router->post('/sort', [PlanController::class, 'sort']);
            });

            // Server
            $router->group([
                'prefix' => 'server/group'
            ], function ($router) {
                $router->get('/fetch', [GroupController::class, 'fetch']);
                $router->post('/save', [GroupController::class, 'save']);
                $router->post('/drop', [GroupController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/route'
            ], function ($router) {
                $router->get('/fetch', [RouteController::class, 'fetch']);
                $router->post('/save', [RouteController::class, 'save']);
                $router->post('/drop', [RouteController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/network-settings-template'
            ], function ($router) {
                $router->get('/fetch', [NetworkSettingsTemplateController::class, 'fetch']);
                $router->post('/save', [NetworkSettingsTemplateController::class, 'save']);
                $router->post('/drop', [NetworkSettingsTemplateController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/cert-template'
            ], function ($router) {
                $router->get('/fetch', [CertTemplateController::class, 'fetch']);
                $router->post('/save', [CertTemplateController::class, 'save']);
                $router->post('/drop', [CertTemplateController::class, 'drop']);
            });
            // 节点管理接口
            $router->group([
                'prefix' => 'server/manage'
            ], function ($router) {
                $router->get('/getNodes', [ManageController::class, 'getNodes']);
                $router->get('/get-sort-nodes', [ManageController::class, 'getSortNodes']);
                $router->post('/update', [ManageController::class, 'update']);
                $router->post('/save', [ManageController::class, 'save']);
                $router->post('/create-child-node', [ManageController::class, 'createChildNode']);
                $router->post('/update-child-node', [ManageController::class, 'updateChildNode']);
                $router->post('/save-virtual-nodes/{id}', [ManageController::class, 'saveVirtualNodes']);
                $router->get('/get-virtual-nodes/{id}', [ManageController::class, 'getVirtualNodes']);
                $router->get('/get-children/{id}', [ManageController::class, 'getChildren']);
                $router->post('/drop', [ManageController::class, 'drop']);
                $router->post('/copy', [ManageController::class, 'copy']);
                $router->post('/sort', [ManageController::class, 'sort']);
                $router->post('/batchDelete', [ManageController::class, 'batchDelete']);
                $router->post('/batchUpdate', [ManageController::class, 'batchUpdate']);
                $router->post('/batchReplace', [ManageController::class, 'batchReplace']);
                $router->post('/resetTraffic', [ManageController::class, 'resetTraffic']);
                $router->post('/batchResetTraffic', [ManageController::class, 'batchResetTraffic']);
                $router->post('/restart', [ManageController::class, 'restart']);
                $router->post('/upgrade', [ManageController::class, 'upgrade']);
                $router->post('/batchUpgrade', [ManageController::class, 'batchUpgrade']);
            });

            // 协议定义接口（插件协议动态注册）
            $router->group([
                'prefix' => 'server/protocols'
            ], function ($router) {
                $router->get('/definitions', [ProtocolDefinitionController::class, 'index']);
                $router->get('/types', [ProtocolDefinitionController::class, 'types']);
                $router->get('/definition/{type}', [ProtocolDefinitionController::class, 'show']);
                $router->get('/definition/{type}/fields', [ProtocolDefinitionController::class, 'configFields']);
                $router->get('/definition/{type}/validation', [ProtocolDefinitionController::class, 'validationRules']);
            });

            // 机器管理接口
            $router->group([
                'prefix' => 'server/machine'
            ], function ($router) {
                $router->get('/fetch', [MachineController::class, 'fetch']);
                $router->post('/save', [MachineController::class, 'save']);
                $router->post('/drop', [MachineController::class, 'drop']);
                $router->post('/resetToken', [MachineController::class, 'resetToken']);
                $router->get('/getToken', [MachineController::class, 'getToken']);
                $router->get('/installCommand', [MachineController::class, 'installCommand']);
                $router->get('/nodes', [MachineController::class, 'nodes']);
                $router->get('/available-nodes', [MachineController::class, 'availableNodes']);
                $router->post('/bind-nodes', [MachineController::class, 'bindNodes']);
                $router->post('/unbind-node', [MachineController::class, 'unbindNode']);
                $router->get('/history', [MachineController::class, 'history']);
                $router->post('/upgrade', [MachineController::class, 'upgrade']);
                $router->post('/restart', [MachineController::class, 'restart']);
                $router->post('/stop', [MachineController::class, 'stop']);
                $router->post('/start', [MachineController::class, 'start']);
                $router->post('/reload', [MachineController::class, 'reload']);
                $router->post('/batchUpgrade', [MachineController::class, 'batchUpgrade']);
                $router->get('/logs', [MachineController::class, 'logs']);
            });

            // Order
            $router->group([
                'prefix' => 'order'
            ], function ($router) {
                $router->any('/fetch', [OrderController::class, 'fetch']);
                $router->post('/update', [OrderController::class, 'update']);
                $router->post('/assign', [OrderController::class, 'assign']);
                $router->post('/paid', [OrderController::class, 'paid']);
                $router->post('/cancel', [OrderController::class, 'cancel']);
                $router->post('/detail', [OrderController::class, 'detail']);
            });

            // User
            $router->group([
                'prefix' => 'user'
            ], function ($router) {
                $router->any('/fetch', [UserController::class, 'fetch']);
                $router->post('/update', [UserController::class, 'update']);
                $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
                $router->post('/generate', [UserController::class, 'generate']);
                $router->post('/dumpCSV', [UserController::class, 'dumpCSV']);
                $router->post('/sendMail', [UserController::class, 'sendMail']);
                $router->post('/ban', [UserController::class, 'ban']);
                $router->post('/resetSecret', [UserController::class, 'resetSecret']);
                $router->post('/setInviteUser', [UserController::class, 'setInviteUser']);
                $router->get('/inviteList', [UserController::class, 'inviteList']);
                $router->get('/loginLogs', [UserController::class, 'loginLogs']);
                $router->post('/destroy', [UserController::class, 'destroy']);
            });

            // Stat
            $router->group([
                'prefix' => 'stat'
            ], function ($router) {
                $router->get('/getOverride', [StatController::class, 'getOverride']);
                $router->get('/getStats', [StatController::class, 'getStats']);
                $router->get('/getServerLastRank', [StatController::class, 'getServerLastRank']);
                $router->get('/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
                $router->get('/getOrder', [StatController::class, 'getOrder']);
                $router->any('/getStatUser', [StatController::class, 'getStatUser']);
                $router->get('/getRanking', [StatController::class, 'getRanking']);
                $router->get('/getStatRecord', [StatController::class, 'getStatRecord']);
                $router->get('/getTrafficRank', [StatController::class, 'getTrafficRank']);
            });

            // Notice
            $router->group([
                'prefix' => 'notice'
            ], function ($router) {
                $router->get('/fetch', [NoticeController::class, 'fetch']);
                $router->get('/detail', [NoticeController::class, 'detail']);
                $router->post('/save', [NoticeController::class, 'save']);
                $router->post('/update', [NoticeController::class, 'save']);
                $router->post('/drop', [NoticeController::class, 'drop']);
                $router->post('/show', [NoticeController::class, 'show']);
                $router->post('/sort', [NoticeController::class, 'sort']);
            });

            // Ticket
            $router->group([
                'prefix' => 'ticket'
            ], function ($router) {
                $router->any('/fetch', [TicketController::class, 'fetch']);
                $router->post('/reply', [TicketController::class, 'reply']);
                $router->post('/close', [TicketController::class, 'close']);
            });

            // Withdrawal
            $router->group([
                'prefix' => 'withdrawal'
            ], function ($router) {
                $router->any('/fetch', [WithdrawalController::class, 'fetch']);
                $router->post('/confirm', [WithdrawalController::class, 'confirm']);
                $router->post('/close', [WithdrawalController::class, 'close']);
                $router->get('/{withdrawal}/messages', [WithdrawalController::class, 'messages']);
                $router->post('/{withdrawal}/reply', [WithdrawalController::class, 'reply']);
            });

            // Coupon
            $router->group([
                'prefix' => 'coupon'
            ], function ($router) {
                $router->any('/fetch', [CouponController::class, 'fetch']);
                $router->post('/generate', [CouponController::class, 'generate']);
                $router->post('/drop', [CouponController::class, 'drop']);
                $router->post('/show', [CouponController::class, 'show']);
                $router->post('/update', [CouponController::class, 'update']);
                $router->post('/batchDrop', [CouponController::class, 'batchDrop']);
                $router->post('/dropExpired', [CouponController::class, 'dropExpired']);
            });

            // Gift Card
            $router->group([
                'prefix' => 'gift-card'
            ], function ($router) {
                // Template management
                $router->any('/templates', [GiftCardController::class, 'templates']);
                $router->post('/create-template', [GiftCardController::class, 'createTemplate']);
                $router->post('/update-template', [GiftCardController::class, 'updateTemplate']);
                $router->post('/delete-template', [GiftCardController::class, 'deleteTemplate']);
                $router->post('/sort-templates', [GiftCardController::class, 'sortTemplates']);

                // Code management
                $router->post('/generate-codes', [GiftCardController::class, 'generateCodes']);
                $router->any('/codes', [GiftCardController::class, 'codes']);
                $router->post('/toggle-code', [GiftCardController::class, 'toggleCode']);
                $router->get('/export-codes', [GiftCardController::class, 'exportCodes']);
                $router->post('/update-code', [GiftCardController::class, 'updateCode']);
                $router->post('/delete-code', [GiftCardController::class, 'deleteCode']);

                // Usage records
                $router->any('/usages', [GiftCardController::class, 'usages']);

                // Statistics
                $router->any('/statistics', [GiftCardController::class, 'statistics']);
                $router->get('/types', [GiftCardController::class, 'types']);
            });

            // Knowledge
            $router->group([
                'prefix' => 'knowledge'
            ], function ($router) {
                $router->get('/fetch', [KnowledgeController::class, 'fetch']);
                $router->get('/getCategory', [KnowledgeController::class, 'getCategory']);
                $router->post('/save', [KnowledgeController::class, 'save']);
                $router->post('/show', [KnowledgeController::class, 'show']);
                $router->post('/drop', [KnowledgeController::class, 'drop']);
                $router->post('/sort', [KnowledgeController::class, 'sort']);
            });

            // Payment  
            $router->group([
                'prefix' => 'payment'
            ], function ($router) {
                $router->get('/fetch', [PaymentController::class, 'fetch']);
                $router->get('/getPaymentMethods', [PaymentController::class, 'getPaymentMethods']);
                $router->post('/getPaymentForm', [PaymentController::class, 'getPaymentForm']);
                $router->post('/save', [PaymentController::class, 'save']);
                $router->post('/drop', [PaymentController::class, 'drop']);
                $router->post('/copy', [PaymentController::class, 'copy']);
                $router->post('/show', [PaymentController::class, 'show']);
                $router->post('/sort', [PaymentController::class, 'sort']);
            });

            // System
            $router->group([
                'prefix' => 'system'
            ], function ($router) {
                $router->get('/getSystemStatus', [SystemController::class, 'getSystemStatus']);
                $router->get('/getQueueStats', [SystemController::class, 'getQueueStats']);
                $router->get('/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
                $router->get('/getQueueMasters', '\\Laravel\\Horizon\\Http\\Controllers\\MasterSupervisorController@index');
                $router->get('/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
                $router->any('/getAuditLog', [SystemController::class, 'getAuditLog']);
            });

            // Update
            // $router->group([
            //     'prefix' => 'update'
            // ], function ($router) {
            //     $router->get('/check', [UpdateController::class, 'checkUpdate']);
            //     $router->post('/execute', [UpdateController::class, 'executeUpdate']);
            // });

            // Theme
            $router->group([
                'prefix' => 'theme'
            ], function ($router) {
                $router->get('/getThemes', [ThemeController::class, 'getThemes']);
                $router->post('/upload', [ThemeController::class, 'upload']);
                $router->post('/delete', [ThemeController::class, 'delete']);
                $router->post('/saveThemeConfig', [ThemeController::class, 'saveThemeConfig']);
                $router->post('/getThemeConfig', [ThemeController::class, 'getThemeConfig']);
            });

            // Plugin
            $router->group([
                'prefix' => 'plugin'
            ], function ($router) {
                $router->get('/types', [PluginController::class, 'types']);
                $router->get('/getPlugins', [PluginController::class, 'index']);
                $router->post('/upload', [PluginController::class, 'upload']);
                $router->post('/delete', [PluginController::class, 'delete']);
                $router->post('install', [PluginController::class, 'install']);
                $router->post('uninstall', [PluginController::class, 'uninstall']);
                $router->post('enable', [PluginController::class, 'enable']);
                $router->post('disable', [PluginController::class, 'disable']);
                $router->get('config', [PluginController::class, 'getConfig']);
                $router->post('config', [PluginController::class, 'updateConfig']);
                $router->post('upgrade', [PluginController::class, 'upgrade']);
                $router->get('readme', [PluginController::class, 'getReadme']);
                $router->get('staticFiles', [PluginController::class, 'staticFiles']);
                $router->post('action', [PluginController::class, 'executeAction']);
            });

            // 流量重置管理
            $router->group([
                'prefix' => 'traffic-reset'
            ], function ($router) {
                $router->get('logs', [TrafficResetController::class, 'logs']);
                $router->get('stats', [TrafficResetController::class, 'stats']);
                $router->get('user/{userId}/history', [TrafficResetController::class, 'userHistory']);
                $router->post('reset-user', [TrafficResetController::class, 'resetUser']);
            });
        });

    }
}
