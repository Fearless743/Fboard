# 多套餐（multi-plan）运维手册

> 实例表 `v2_user_plan` 是套餐数据唯一权威源。`v2_user` 的 9 个套餐列
> （`plan_id/group_id/transfer_enable/u/d/expired_at/speed_limit/device_limit/next_reset_at`）
> 已删除；读取由 `User` 模型 accessor 实时聚合。
>
> 开关 `multi_plan_enable`（订阅配置段）控制**业务模式**：
> 关闭 = 单套餐语义（每人至多一个有效实例行，新购/换套餐替换旧行）；
> 开启 = 多套餐并行（同 plan 续费累加、不同 plan 并存）。
> 数据始终以 `v2_user_plan` 为准，与已删的主表列无关。

## 发版顺序

1. **建表 + 迁移**：`2026_10_04_000001_create_v2_user_plan_table` 建表并在同一
   migration 内自动迁入存量单套餐用户（`UserPlanMigrator::run()`，幂等可重跑）。
   无需任何手动命令。
2. **删列**：`2026_10_05_000001_drop_plan_columns_from_v2_user_table` 删除主表 9 列
   （自动先删依赖索引）。大表先确认 instant drop 支持（MySQL 8.0.29+），否则低峰或 pt-osc。

## 迁移（存量数据）

- 建表 migration 内自动执行，单套餐用户建 cycle 行（`kind=1`，`order_ids=[]`，
  9 列值照抄含 `u/d` 全额与 `group_id` 快照）；`expired_at` 0/null 归一为永久。
- 脏数据：`plan_id` null 跳过；指向已删套餐建行但限速置空+日志；
  零配额无订阅不建行；已有 cycle 行跳过。

## 一致性检查（常驻）

```bash
php artisan fboard:check-user-plans
```

- 扫 `(user,plan,kind=1)` 重复行；
- 扫 pack 行残留 `next_reset_at`（应为 null）；
- 扫负配额/负用量等脏数据；
- 只报不改；定时每日 01:30 自动跑。

## 回滚

```bash
php artisan migrate:rollback   # 回滚删列 migration
```

- down() 自动重建 9 列，并从 `v2_user_plan` 有效实例聚合反填回 `v2_user`；
- 反填口径 = 实例聚合（配额/用量求和、到期取最晚、限速/设备取 max、
  分组/套餐单值才回填，`next_reset_at` 取最早已有效实例），与 `getPlanAggregate()` 一致。

## 清理

```bash
php artisan fboard:prune-user-plans --limit=1000
```

- 只删 `kind=2 AND exhausted_at < now-90天` 的行；时钟从耗尽日起算
  （退役/续买不重置时钟）；`exhausted_at` 为空的耗尽行先补打时间戳下轮再删；
  cycle 行永不删。
- 定时：每日 01:00 自动跑。
- 代价：行删后该包明细丢失（订单表记录不受影响）；
  没用完就过期的包默认不清。

## 行为速查

- 消耗顺序：`sort_order ASC（0 沉底）→ expired_at ASC（永久沉底）→ created_at ASC`；
  用户端 `POST /api/v1/user/planSort` 收全量有序实例 id 数组自定义顺序。
- 升级折抵禁用：多套餐下收到升级单会失败，提示按新购下单。
- 重置包只重置所购 plan 对应的周期行。
- 耗尽包 90 天删除（仅 pack）；节点按下发时按 user id 去重。
