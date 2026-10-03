/**
 * Extension Demo —— 插件前端脚本。
 *
 * 以普通 <script>（IIFE）加载，从 window.FboardAdmin 取宿主能力，
 * 用宿主 React 渲染组件并通过 registerExtension 注册。
 */
(function () {
  var F = window.FboardAdmin;
  if (!F) {
    console.warn("[ExtensionDemo] window.FboardAdmin 未就绪");
    return;
  }

  var React = F.React;
  var h = React.createElement;

  /** content.before 插槽里的横幅组件（用宿主 shadcn 组件，视觉与后台一致） */
  function DemoBanner(props) {
    var Card = F.ui.Card;
    var CardContent = F.ui.CardContent;
    var Button = F.ui.Button;
    var Badge = F.ui.Badge;
    var count = React.useState(0);
    var value = count[0];
    var setValue = count[1];

    return h(
      Card,
      null,
      h(
        CardContent,
        { className: "flex flex-wrap items-center gap-3 py-4" },
        h(Badge, { variant: "secondary" }, "插件扩展"),
        h(
          "span",
          { className: "text-sm text-muted-foreground" },
          "当前页面：" + (props && props.page ? props.page : "-"),
        ),
        h("span", { className: "text-sm" }, "点击次数：" + value),
        h(
          Button,
          {
            size: "sm",
            variant: "outline",
            onClick: function () {
              setValue(value + 1);
              F.toast.success("来自扩展演示的问候");
            },
          },
          "点我",
        ),
      ),
    );
  }

  /** header.actions 插槽里的徽标 */
  function DemoHeaderBadge() {
    return h(
      "button",
      {
        type: "button",
        title: "Extension Demo",
        className:
          "inline-flex items-center rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/20",
        onClick: function () {
          F.toast.success("这是头部插槽注入的控件");
        },
      },
      "DEMO",
    );
  }

  F.registerExtension("DemoBanner", DemoBanner);
  F.registerExtension("DemoHeaderBadge", DemoHeaderBadge);
})();
