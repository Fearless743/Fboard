# 机器级节点绑定

**机器**（`v2_server_machine`）代表一台运行 Fboard-Node 守护进程（机器模式）的物理/虚拟主机。把已有节点绑定到机器，是机器模式动态发现的前提：机器连接面板时，面板会把所有已绑定节点的完整配置下发给它。

本文档覆盖管理端绑定 API 与界面。

## 概念

- 节点通过 `v2_server.machine_id` **至多归属一台**机器。
- `machine_id` 为空的节点以独立模式运行，仍可通过普通节点连接访问。
- 一台机器可以拥有**多个**节点。
- 虚拟节点（`type = virtual`）不能绑定到机器，绑定对话框会将其排除。

## API

所有接口位于管理端安全路径前缀下，并带有 `admin` + `log` 中间件。

### `GET /{secure_path}/server/machine/available-nodes`

分页获取**未绑定**节点列表（用于「绑定已有节点」对话框）。

查询参数：

| 参数 | 说明 |
|------|------|
| `current` | 页码，默认 1 |
| `pageSize` | 每页数量，默认 20，最大 200 |
| `search` | 按节点名称 / 主机 / id 模糊搜索，支持拼音 |
| `type` | 可选协议类型过滤 |

始终排除虚拟节点。每个节点带 `available` 状态标记。

### `POST /{secure_path}/server/machine/bind-nodes`

批量将节点绑定到机器。

请求体参数：

| 参数 | 必填 | 说明 |
|------|------|------|
| `machine_id` | 是 | 机器 id（必须存在于 `v2_server_machine`） |
| `ids` | 是 | 节点 id 数组，至少 1 个 |

已被**其它**机器占用的节点会被跳过（不报错）。虚拟节点会被跳过。绑定后触发 `NodeSyncService::notifyMachineNodesChanged()`，使已连接的机器立即收到新的节点列表。

响应：

```json
{ "status": 200, "data": { "bound": 3, "skipped": 1 } }
```

### `POST /{secure_path}/server/machine/unbind-node`

将单个节点从机器解绑。

请求体参数：

| 参数 | 必填 | 说明 |
|------|------|------|
| `machine_id` | 是 | 机器 id |
| `id` | 是 | 节点 id |

若节点不属于该机器，返回 `400 该节点不属于此机器`。解绑同样会向机器发送同步通知。

## 按机器过滤节点

`GET /{secure_path}/server/manage/getNodes` 新增可选参数 `machine_id`。当其为数字时，只返回绑定到该机器的节点。管理后台的机器过滤节点列表（`/server/manage?machineId=...`）即基于此。

## 管理后台 UI

### 机器详情

在**机器管理**中打开机器详情，可看到其绑定的节点列表（含在线状态），每行有**解绑**操作（带确认）。

详情页顶部有两个按钮：

- **添加节点到该服务器** — 跳转到节点管理并携带 `?createWithMachineId=<id>`，新建节点时预选该机器。
- **打开节点管理** — 跳转到节点管理并携带 `?machineId=<id>`，仅显示该机器的节点。

### 绑定已有节点对话框

**绑定已有节点**对话框列出未绑定节点（拼音搜索 + 协议类型过滤，分页）。多选后确认，机器即拥有所选节点。

### 节点列表

节点管理中每个节点展示**部署**单元格：机器名称加在线/离线徽标；未绑定机器时显示 `standalone`。通过 `?machineId=` 进入时出现机器过滤横幅。

## 机器模式与节点同步

机器模式守护进程通过 WebSocket 连接面板（`/api/v1/server/machine/nodes`，以 `machine_id` + `token` 认证）时，面板会下发每个已绑定节点的完整配置。绑定 / 解绑 / 切换 `enabled` / 删除节点都会触发 `notifyMachineNodesChanged()`，重新计算机器的节点列表并通过 Redis 推送给机器连接。

## 相关

- 节点部署文档：`Fboard-Node/README.md`（机器模式）
