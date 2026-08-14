# Machine-Level Node Binding

A **machine** (`v2_server_machine`) represents a physical / virtual host running the Fboard-Node daemon in machine mode. Binding existing nodes to a machine is the prerequisite for machine-mode dynamic discovery: when the machine connects, the panel hands it the full config for every bound node.

This page covers the admin-side binding APIs and UI.

## Concepts

- A node belongs to **at most one** machine via `v2_server.machine_id`.
- A node with `machine_id` null runs standalone; it is still reachable by a regular node connection.
- A machine can own **many** nodes.
- Virtual nodes (`type = virtual`) cannot be bound to a machine and are excluded from binding dialogs.

## API

All endpoints are under the admin secure path prefix, with `admin` + `log` middleware.

### `GET /{secure_path}/server/machine/available-nodes`

Paginated list of **unbound** nodes (for the "bind existing nodes" dialog).

Query params:

| Param | Description |
|-------|-------------|
| `current` | Page number, default 1 |
| `pageSize` | Page size, default 20, max 200 |
| `search` | Fuzzy search over node name / host / id, supporting pinyin |
| `type` | Optional protocol type filter |

Virtual nodes are always excluded. Each node carries an `available` status flag.

### `POST /{secure_path}/server/machine/bind-nodes`

Batch-bind nodes to a machine.

Body params:

| Param | Required | Description |
|-------|----------|-------------|
| `machine_id` | yes | Machine id (must exist in `v2_server_machine`) |
| `ids` | yes | Array of node ids, at least 1 |

Nodes already claimed by a **different** machine are skipped (not an error). Virtual nodes are skipped. After binding, `NodeSyncService::notifyMachineNodesChanged()` fires so connected machines immediately receive the new node list.

Response:

```json
{ "status": 200, "data": { "bound": 3, "skipped": 1 } }
```

### `POST /{secure_path}/server/machine/unbind-node`

Unbind a single node from a machine.

Body params:

| Param | Required | Description |
|-------|----------|-------------|
| `machine_id` | yes | Machine id |
| `id` | yes | Node id |

If the node is not bound to the given machine, returns `400 该节点不属于此机器`. A node config change also triggers a sync notification to the machine.

## Filtering nodes by machine

`GET /{secure_path}/server/manage/getNodes` now accepts an optional `machine_id` param. When present (numeric), only nodes bound to that machine are returned. This drives the machine-filtered node list in the admin panel (`/server/manage?machineId=...`).

## Admin UI

### Machine detail

In **机器管理** (Machines), opening a machine's detail shows its bound nodes with online status and an **unbind** action per row (with confirmation).

Two buttons live at the top of the detail:

- **添加节点到该服务器** — navigates to node management with `?createWithMachineId=<id>`, so a newly created node is pre-assigned to this machine.
- **打开节点管理** — navigates to node management with `?machineId=<id>`, showing only this machine's nodes.

### Bind existing nodes dialog

The **绑定已有节点** dialog lists unbound nodes (search with pinyin + protocol type filter, paginated). Multi-select and confirm; the machine then owns the selected nodes.

### Node list

In node management, each node shows a **部署** (Deployment) cell: the machine name plus online/offline badge, or `standalone` when no machine is bound. A machine-filter banner appears when arriving via `?machineId=`.

## Machine mode & node sync

When a machine-mode daemon connects to the panel WebSocket (`/api/v1/server/machine/nodes`, authenticated by `machine_id` + `token`), the panel pushes each bound node's full config. Rebinding / unbinding / toggling `enabled` / deleting a node all fire `notifyMachineNodesChanged()`, which recomputes the machine's node list and pushes the update over Redis to the machine connection.

## Related

- Node deployment docs: `Fboard-Node/README.md` (machine mode)
