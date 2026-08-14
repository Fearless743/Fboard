# Certificate Templates

Certificate templates let you save commonly used TLS certificates / private keys as reusable presets, then apply them to any node with one click in the advanced protocol configuration dialog. This avoids pasting the same PEM content repeatedly.

## Database

Templates are stored in the `v2_cert_templates` table:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `name` | string(128) | Template name |
| `description` | string(255), nullable | Optional description |
| `cert_content` | longText | PEM certificate body |
| `key_content` | longText | PEM private key body |
| `created_at` / `updated_at` | timestamp | Timestamps |

`name` and `description` are pinyin-searchable (see [Pinyin search](../upgrade/v0.5.0.md#pinyin-search-pinyin_index)).

## API

All endpoints are under the admin secure path prefix, with `admin` + `log` middleware.

### `GET /{secure_path}/server/cert-template/fetch`

List templates, newest first.

Query params:

- `search` (optional) — fuzzy search over `name` / `description`, supporting pinyin.

Response:

```json
{ "status": 200, "data": [ { "id": 1, "name": "prod-cert", "description": null, "cert_content": "-----BEGIN CERTIFICATE-----...", "key_content": "-----BEGIN PRIVATE KEY-----..." } ] }
```

### `POST /{secure_path}/server/cert-template/save`

Create or update a template.

Body params:

| Param | Required | Description |
|-------|----------|-------------|
| `id` | no | Template id; when present the existing template is updated, otherwise a new one is created |
| `name` | yes | Max 128 chars |
| `description` | no | Max 255 chars |
| `cert_content` | yes | PEM certificate body |
| `key_content` | yes | PEM private key body |

Response: the saved template object. When `id` refers to a non-existent template, returns `400202 模板不存在`.

### `POST /{secure_path}/server/cert-template/drop`

Delete a template.

Body params:

| Param | Required | Description |
|-------|----------|-------------|
| `id` | yes | Template id |

Response: `true` on success. Missing template returns `模板不存在`; a failed delete returns `删除失败`.

## Using a template in the admin panel

1. Open a node's **Edit** dialog, then click **高级设置** (Advanced settings) in the footer.
2. In the **TLS** tab, switch the certificate mode to **content** (paste cert/key directly).
3. The **证书模板** (Certificate templates) section lets you:
   - **Save current content as a template** — give it a name (and optional description), then save. The currently entered cert/key in the dialog is captured.
   - **Search** templates by name/description (pinyin supported).
   - **Apply** a template — sets the certificate mode to `content` and copies the template's `cert_content` / `key_content` into the current node config.
   - **Delete** a template with the trash button.

When you confirm the dialog, `cert_config` (with `cert_mode: "content"` plus `cert_content` / `key_content`) is written back to the server. Saving the server triggers a node config sync so connected nodes pick up the new certificate.

> Note: templates are a standalone library. Applying a template copies its content into the node's own `cert_config`; there is no persistent link between a node and the template afterwards.
