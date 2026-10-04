# 多套餐（multi-plan）运维手册

> 开关：`multi_plan_enable`（订阅配置段，默认关闭）。关闭 = 单套餐旧行为；
> 开启后用户可同时持有多个套餐，新购不再替换已有。

## 发版顺序

1. **发版 N**：建表 + 代码全切新表 + 迁移/对账/反填/一致性命令。旧列冻结
   （多套餐下管理端直写主表 9 列会被拒绝，测试断言零读写）。
2. 开开关前先迁移，再开开关。
3. **发版 N+1**：对账零差异 → 删列 migration（独立 PR）。

## 迁移

```bash
# 先看数，不写库
php artisan fboard:migrate-user-plans --dry-run
# 正式迁移（可重跑可中断）
php artisan fboard:migrate-user-plans
```

- 单套餐用户建 cycle 行（`kind=1`，`order_ids=[]`，9 列值照抄含 `u/d` 全额与
  `group_id` 快照）；`expired_at` 0/null 归一为永久。
- 脏数据：`plan_id` null 跳过；指向已删套餐建行但限速置空+日志；
  零配额无订阅不建行；已有 cycle 行跳过。
- 开关开启不触发迁移（迁移是独立一次性命令）。

## 对账与一致性检查（常驻）

```bash
php artisan fboard:check-user-plans
```

- 扫 `(user,plan,kind=1)` 重复行；
- 有实例行的用户：实例聚合必须 == 主表快照，差异即失败退出（供 cron 告警）；
- 只报不改。
- 定时：每日 01:30 自动跑。

## 回滚

- **删列前**：关开关即回滚（主表快照仍在，开通/扣减/重置走旧路径）。
  注意删列前主表已冻结：开关关闭期间的新购不会写主表，
  回滚后这部分数据只在实例表，需人工核对。
- **删列后**：重建列 + 反填脚本（与删列同 PR，演练一次）。
  反填口径 = 实例聚合（配额/用量求和、到期取最晚、限速/设备取 max、
  分组取有效实例第一个），与 `getPlanAggregate()` 一致。

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
