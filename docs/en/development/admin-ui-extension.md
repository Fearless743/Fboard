# Admin UI Extension

Plugins can inject arbitrary controls into any position of any admin page, add
sidebar menu items and full pages, without modifying the admin SPA source.

## Mental model

| Concept | What it does |
|---------|--------------|
| **Extension block** (`admin_ui`) | A control rendered at a named slot or a CSS anchor |
| **Slot** | A predefined position in the admin layout/pages, e.g. `content.before`, `header.actions`, `page.actions` |
| **Anchor** | Any CSS selector; the block is inserted `before`/`after`/`prepend`/`append` to the matched DOM node |
| **Page filter** | Blocks only render on matching routes: exact (`user`), prefix (`config/*`), or all (`*`) |
| **Menu** (`admin_nav.menus`) | Sidebar / command-palette entries |
| **Page** (`admin_nav.pages`) | A full page rendered by the catch-all route |
| **i18n** (`admin_nav.i18n`) | Translations merged into the admin i18n at startup |

## Control types

- `component` — a React component registered from a plugin script via
  `window.FboardAdmin.registerExtension(id, Component)`. Native look & feel,
  uses the host React + UI kit.
- `iframe` — loads `url` in an iframe. Maximum freedom/isolation (any framework).
- `html` — renders an HTML string.
- `button` — declarative button; on click calls this plugin's
  `registerAction('...')` and/or opens `url`.
- `link` — like `button` but URL-only.

## Declarative usage (`config.json`)

```json
{
  "name": "My Plugin",
  "code": "my_plugin",
  "version": "1.0.0",
  "description": "...",
  "author": "...",
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
      "label": "Sync",
      "action": "sync_now",
      "confirm": "Run sync?",
      "icon": "RefreshCw",
      "variant": "outline"
    },
    {
      "id": "tip",
      "anchor": { "selector": "main h1", "position": "after" },
      "type": "html",
      "html": "<div>Injected anywhere</div>"
    }
  ],
  "admin_nav": {
    "menus": [
      { "path": "my-plugin", "label": "My Plugin", "i18nKey": "plugin.my_plugin.title",
        "icon": "Blocks", "group": "nav.systemManagement", "order": 90 }
    ],
    "pages": [
      { "path": "my-plugin", "type": "iframe", "url": "page.html" }
    ],
    "i18n": {
      "en-US": { "plugin": { "my_plugin": { "title": "My Plugin" } } }
    }
  }
}
```

### Extension block fields

| Field | Required | Notes |
|-------|----------|-------|
| `id` | yes | Unique within the plugin |
| `slot` / `anchor` | one of | Named slot, or `"selector"` / `{"selector","position"}` |
| `type` | no | `component` (default) / `html` / `iframe` / `button` / `link` |
| `page` | no | String/array route rules, default `["*"]` |
| `priority` | no | Sort order, lower first (default 20) |
| `component` | component | Registration id, default = `id` |
| `script` / `style` | no | Paths relative to the plugin `public/` dir |
| `html` / `url` | per type | Content for `html` / target for `iframe` |
| `label` `action` `params` `confirm` `variant` `icon` | button/link | Button behaviour |
| `context` | no | Arbitrary JSON passed to the component (`props.extension.context`) |

### Menu / page fields

- menu: `path`, `label`, `i18nKey?`, `icon?`, `group?`, `groupLabel?`, `order?`,
  `external?`, `target?`
- page: `path`, `title?`, `type` (`iframe`|`component`|`html`), `url?`,
  `component?`, `html?`, `script?`, `style?`, `height?`

`group` must match an existing group key (e.g. `nav.systemManagement`).
`groupLabel` creates a new group when `group` doesn't exist. If neither is set,
the item is grouped under the plugin.

## Programmatic usage (`Plugin.php`)

```php
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerAction('sync_now', 'Sync', function (array $params = []) {
            return ['success' => true, 'message' => 'done'];
        });

        $this->registerAdminExtension([
            'id' => 'badge',
            'slot' => 'header.actions',
            'type' => 'component',
            'component' => 'MyBadge',
            'script' => 'admin.js',
        ]);

        $this->registerAdminMenu([
            'path' => 'my-plugin',
            'label' => 'My Plugin',
            'icon' => 'Blocks',
            'group' => 'nav.systemManagement',
        ]);

        $this->registerAdminPage([
            'path' => 'my-plugin',
            'type' => 'iframe',
            'url' => 'page.html',
        ]);

        $this->registerAdminI18n([
            'en-US' => ['plugin' => ['my_plugin' => ['title' => 'My Plugin']]],
        ]);
    }
}
```

## Plugin frontend script (component type)

A plain IIFE loaded with `<script>` (`script` field). Host capabilities are on
`window.FboardAdmin` (SDK version 1.0.0):

```js
(function () {
  var F = window.FboardAdmin;
  var React = F.React;
  var h = React.createElement;

  function MyBanner(props) {
    return h(F.ui.Card, null,
      h(F.ui.CardContent, { className: "py-4" }, "Hello " + props.page));
  }

  F.registerExtension("MyBanner", MyBanner);
})();
```

Host SDK surface:

- `React`, `ReactDOM`, `ReactDOMClient`
- `registerExtension(id, Component)` / `getExtension` / `hasExtension` / `listExtensions`
- `ui` — shadcn/ui subset: `Button, Card, CardHeader, CardTitle, CardDescription,
  CardContent, CardFooter, Input, Label, Badge, Separator, Switch, Textarea,
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Tabs, TabsList,
  TabsTrigger, TabsContent, Tooltip, TooltipTrigger, TooltipContent`
- `api` — `adminGet`, `adminPost`, `adminUpload`, `get`, `post`
- `lib` — `cn`, `adminPath`
- `toast`, `i18n`, `useTranslation`, `stores.{useAuthStore,useSettingsStore}`

Component props: `{ extension?, slot, page }`.

## Built-in slots

| Slot | Position |
|------|----------|
| `content.before` / `content.after` | Top / bottom of every page's content area |
| `header.left` | Left side of the top bar |
| `header.actions` | Right-side action area of the top bar |
| `sidebar.nav` / `sidebar.bottom` | Below the sidebar nav / above the version footer |
| `page.actions` | Next to every page header's action buttons |
| `dashboard.after` | Below the dashboard widgets |

Any other position can be reached with an `anchor`.

## Security & rendering

- The manifest endpoints (`/api/v2/{secure_path}/plugin/ui`, `.../ui/nav`) are
  behind the `admin` middleware, so only authenticated admins receive them.
- Only **enabled** plugins contribute. Disable a plugin and its UI disappears.
- Component plugins run in the admin page context with the admin's privileges —
  plugins already execute arbitrary server-side PHP, so this adds no privilege.
- Each extension block is wrapped in an error boundary; a broken plugin shows an
  inline error instead of crashing the panel.
- Available lucide icon names are a curated whitelist (see
  `admin/src/plugin/icons.ts`); unknown names fall back to `Puzzle`.
