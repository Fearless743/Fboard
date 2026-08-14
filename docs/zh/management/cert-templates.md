# 证书模板

证书模板功能允许你将常用的 TLS 证书 / 私钥保存为可复用的预设，然后在高级协议配置对话框中一键应用到任意节点，避免反复粘贴相同的 PEM 内容。

## 数据库

模板存储于 `v2_cert_templates` 表：

| 列 | 类型 | 说明 |
|----|------|------|
| `id` | bigint | 主键 |
| `name` | string(128) | 模板名称 |
| `description` | string(255)，可空 | 模板描述 |
| `cert_content` | longText | 证书 PEM 内容 |
| `key_content` | longText | 私钥 PEM 内容 |
| `created_at` / `updated_at` | timestamp | 创建 / 更新时间 |

`name` 与 `description` 支持拼音搜索（见[拼音搜索](../upgrade/v0.5.0.md#拼音搜索-pinyin_index)）。

## API

所有接口位于管理端安全路径前缀下，并带有 `admin` + `log` 中间件。

### `GET /{secure_path}/server/cert-template/fetch`

获取模板列表，按 id 倒序。

查询参数：

- `search`（可选）— 对 `name` / `description` 模糊搜索，支持拼音。

响应：

```json
{ "status": 200, "data": [ { "id": 1, "name": "prod-cert", "description": null, "cert_content": "-----BEGIN CERTIFICATE-----...", "key_content": "-----BEGIN PRIVATE KEY-----..." } ] }
```

### `POST /{secure_path}/server/cert-template/save`

新增或更新模板。

请求体参数：

| 参数 | 必填 | 说明 |
|------|------|------|
| `id` | 否 | 模板 id；存在时更新该模板，否则新建 |
| `name` | 是 | 最长 128 字符 |
| `description` | 否 | 最长 255 字符 |
| `cert_content` | 是 | 证书 PEM 内容 |
| `key_content` | 是 | 私钥 PEM 内容 |

响应：保存后的模板对象。`id` 对应的模板不存在时返回 `400202 模板不存在`。

### `POST /{secure_path}/server/cert-template/drop`

删除模板。

请求体参数：

| 参数 | 必填 | 说明 |
|------|------|------|
| `id` | 是 | 模板 id |

响应：成功返回 `true`。模板不存在返回 `模板不存在`；删除失败返回 `删除失败`。

## 在管理后台使用模板

1. 打开节点的**编辑**对话框，点击底部**高级设置**按钮。
2. 在 **TLS** 标签页将证书模式切换为 **content**（直接粘贴证书/私钥）。
3. 在**证书模板**区域可以：
   - **将当前内容保存为模板** — 填写名称（可选描述）后保存，对话框里当前输入的证书/私钥会被捕获。
   - **搜索**模板 — 支持名称/描述（含拼音）。
   - **应用**模板 — 将证书模式设为 `content`，并把模板的 `cert_content` / `key_content` 复制到当前节点配置。
   - 点击删除按钮**删除**模板。

确认对话框后，`cert_config`（包含 `cert_mode: "content"` 以及 `cert_content` / `key_content`）会被写回服务器。保存节点会触发节点配置同步，连接的节点即可获取新证书。

> 注意：模板是独立的库。应用模板只是把内容复制进节点自身的 `cert_config`，节点与模板之间不会建立持续关联。
