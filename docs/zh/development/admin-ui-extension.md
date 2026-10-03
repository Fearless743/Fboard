# 管理后台 UI 扩展

插件可在**任意页面的任意位置**注入任意控件，还可添加侧边栏菜单与整页，
无需改动后台 SPA 源码。支持声明式（`config.json`）与程序式（`Plugin.php`）两种写法。

## 概念

| 概念 | 说明 |
|------|------|
| **扩展块**（`admin_ui`） | 一个控件，渲染到具名插槽或 CSS 锚点处 |
| **插槽** | 后台预定义位置，如 `content.before`、`header.actions`、`page.actions` |
| **锚点** | 任意 CSS 选择器，按 `before`/`after`/`prepend`/`append` 注入到匹配节点旁 |
| **页面过滤** | `page` 规则：精确（`user`）、前缀（`config/*`）、全部（`*`） |
| **菜单**（`admin_nav.menus`） | 侧边栏 / 命令面板条目 |
| **整页**（`admin_nav.pages`） | 由兜底路由渲染的独立页面 |
| **i18n**（`admin_nav.i18n`） | 启动时合并进后台翻译 |

## 控件类型

- `component`：插件脚本用 `window.FboardAdmin.registerExtension(id, Component)` 注册的 React 组件，原生外观
- `iframe`：以 iframe 加载 `url`，最大自由度/隔离（任意框架）
- `html`：直接渲染 HTML 字符串
- `button`：声明式按钮，点击调用本插件的 `registerAction('...')` 和/或打开 `url`
- `link`：等价于只带 URL 的 `button`

## 声明式用法（`config.json`）

```json
{
  "admin_ui": [
    {
      "id": "banner",
      "slot": "content.before",
      "type": "component",
      "component": "MyBanner",
      "script": "admin.js",
      "page": ["dashboard", "user/*"]
    },
    {
      "id": "sync",
      "slot": "page.actions",
      "type": "button",
      "label": "同步",
      "action": "sync_now",
      "confirm": "确认同步？",
      "icon": "RefreshCw",
      "variant": "outline"
    },
    {
      "id": "tip",
      "anchor": { "selector": "main h1", "position": "after" },
      "type": "html",
      "html": "<div>注入到任意 DOM 位置</div>"
    }
  ],
  "admin_nav": {
    "menus": [
      { "path": "my-plugin", "label": "我的插件", "i18nKey": "plugin.my_plugin.title",
        "icon": "Blocks", "group": "nav.systemManagement", "order": 90 }
    ],
    "pages": [
      { "path": "my-plugin", "type": "iframe", "url": "page.html" }
    ],
    "i18n": {
      "zh-CN": { "plugin": { "my_plugin": { "title": "我的插件" } } }
    }
  }
}
```

### 扩展块字段

| 字段 | 必填 | 说明 |
|------|------|------|
| `id` | 是 | 插件内唯一 |
| `slot` / `anchor` | 二选一 | 具名插槽，或 `"selector"` / `{"selector","position"}` |
| `type` | 否 | `component`（默认）/ `html` / `iframe` / `button` / `link` |
| `page` | 否 | 字符串/数组，默认 `["*"]` |
| `priority` | 否 | 排序，越小越靠前（默认 20） |
| `component` | component | 注册 id，默认取 `id` |
| `script` / `style` | 否 | 相对插件 `public/` 的路径 |
| `html` / `url` | 视类型 | `html` 内容 / `iframe` 地址 |
| `label` `action` `params` `confirm` `variant` `icon` | button/link | 按钮行为 |
| `context` | 否 | 透传给组件的任意 JSON（`props.extension.context`） |

### 菜单 / 整页字段

- 菜单：`path`、`label`、`i18nKey?`、`icon?`、`group?`、`groupLabel?`、`order?`、`external?`、`target?`
- 整页：`path`、`title?`、`type`(`iframe`|`component`|`html`)、`url?`、`component?`、`html?`、`script?`、`style?`、`height?`

`group` 需命中现有分组 key（如 `nav.systemManagement`）；`group` 不存在且有
`groupLabel` 时新建分组；两者都没有则归入以插件为单位的分组。

## 程序式用法（`Plugin.php`）

```php
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerAction('sync_now', '同步', fn(array $params = []) => [
            'success' => true, 'message' => '完成',
        ]);

        $this->registerAdminExtension([
            'id' => 'badge', 'slot' => 'header.actions',
            'type' => 'component', 'component' => 'MyBadge', 'script' => 'admin.js',
        ]);

        $this->registerAdminMenu([
            'path' => 'my-plugin', 'label' => '我的插件',
            'icon' => 'Blocks', 'group' => 'nav.systemManagement',
        ]);

        $this->registerAdminPage([
            'path' => 'my-plugin', 'type' => 'iframe', 'url' => 'page.html',
        ]);

        $this->registerAdminI18n([
            'zh-CN' => ['plugin' => ['my_plugin' => ['title' => '我的插件']]],
        ]);
    }
}
```

## 插件前端脚本（component 类型）

以普通 `<script>`（IIFE）加载，宿主能力挂在 `window.FboardAdmin`：

```js
(function () {
  var F = window.FboardAdmin;
  var React = F.React;
  var h = React.createElement;

  function MyBanner(props) {
    return h(F.ui.Card, null,
      h(F.ui.CardContent, { className: "py-4" }, "页面 " + props.page));
  }

  F.registerExtension("MyBanner", MyBanner);
})();
```

SDK 能力：

- `React`、`ReactDOM`、`ReactDOMClient`
- `registerExtension(id, Component)` / `getExtension` / `hasExtension` / `listExtensions`
- `ui`：shadcn/ui 子集（`Button, Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter, Input, Label, Badge, Separator, Switch, Textarea, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Tabs, TabsList, TabsTrigger, TabsContent, Tooltip, TooltipTrigger, TooltipContent`）
- `api`：`adminGet`、`adminPost`、`adminUpload`、`get`、`post`
- `lib`：`cn`、`adminPath`
- `toast`、`i18n`、`useTranslation`、`stores.{useAuthStore,useSettingsStore}`

组件 props：`{ extension?, slot, page }`。

## 内置插槽

| 插槽 | 位置 |
|------|------|
| `content.before` / `content.after` | 每个页面内容区顶部 / 底部 |
| `header.left` | 顶栏左侧 |
| `header.actions` | 顶栏右侧操作区 |
| `sidebar.nav` / `sidebar.bottom` | 侧边栏导航之后 / 版本信息之上 |
| `page.actions` | 每个页头操作按钮旁 |
| `dashboard.after` | 仪表盘组件之后 |

其余任意位置用 `anchor` 即可到达。

## 安全与渲染

- 清单接口（`/api/v2/{secure_path}/plugin/ui`、`.../ui/nav`）位于 `admin`
  中间件之后，仅登录管理员可取。
- 仅**已启用**插件生效，禁用后其 UI 立即消失。
- 插件前端脚本以管理员权限在后台页面运行；插件本身已可执行任意服务端 PHP，
  因此不新增权限边界。
- 每个扩展块都有独立错误边界，单个插件出错只显示内联错误，不会拖垮后台。
- 可用的 lucide 图标名是白名单（见 `admin/src/plugin/icons.ts`），未知名称回退 `Puzzle`。
