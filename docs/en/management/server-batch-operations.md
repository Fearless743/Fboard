# Server Batch Operations

The **batch replace** API lets you replace a substring across all servers at once. It replaces the older "select rows + single-field" behavior with **full-table field replacement**.

> ⚠️ This operation applies to **every** server in the table — there is no row selection and no group/server filter. Test with a dry run on a non-production environment first.

## API

### `POST /{secure_path}/server/manage/batchReplace`

Body params (JSON):

| Param | Required | Description |
|-------|----------|-------------|
| `field` | yes | The field to operate on. Must be one of the whitelisted fields below. |
| `search` | yes | The substring to find. |
| `replace` | yes | The replacement text. May be empty string (deletion-style replace). |

Response:

```json
{ "status": 200, "data": { "changed": 12 } }
```

`changed` is the number of servers actually modified (rows where the replacement produced a different value are skipped).

## Supported fields

The controller enforces a whitelist; any other field returns `400 该字段不支持批量替换`.

**Scalar fields** — plain substring replacement on the field value:

- `name`
- `host`
- `port`
- `code`

**JSON fields** — substring replacement on the **serialized JSON text** of the column, covering array elements and nested values:

- `group_ids`
- `route_ids`
- `tags`
- `protocol_settings`
- `custom_outbounds`
- `custom_routes`
- `cert_config`
- `rate_time_ranges`

## How JSON fields are replaced

For JSON fields the raw JSON string is read straight from the database and `str_replace($search, $replace, $rawJson)` runs on that text. This matches keys, values, and array elements as literal substrings. After the replacement the text is decoded back and saved.

Behavior details and risks:

- **Substring semantics, not structural.** Replacing `"1"` will also match inside `"11"`, `"21"`, and JSON key names, not just the array value `1`. Be as specific as possible with your search string.
- **Invalid JSON aborts.** If a replacement corrupts the JSON (e.g. unbalanced quotes), the save fails and the API returns `400`; the transaction rolls back so nothing is partially applied.
- **`protocol_settings` is re-cast on save.** The `Server` model normalizes `protocol_settings` against the protocol definition (including REALITY settings normalization) when it is assigned. A replacement that passes the raw-text phase can still be transformed by this re-casting on save.
- **No-op rows are skipped.** Rows where `replace` produces no change are not counted in `changed`.

## Hooks

Two hooks fire around the operation, so plugins can react:

- `admin.server.batch_replace.before`
- `admin.server.batch_replace.after`

## Admin UI

In **节点管理** (Server management), the batch replace dialog exposes the same 12 whitelisted fields with friendly labels. Enter the field, the search string, and the replacement, then confirm. The UI shows the number of servers changed in a success toast.

## Migration notes

The pre-0.5.0 behavior let you select rows and replace a single value field on just those rows. The `ids` parameter no longer exists. If your workflow relied on row-scoped replacement, update it to target unique substrings (e.g. a distinctive `host` prefix) instead.
