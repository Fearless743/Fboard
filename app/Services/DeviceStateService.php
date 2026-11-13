<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Fleet-wide device (alive IP) state.
 *
 * Data model (Redis, one hash per user):
 *   user_devices:<uid>   field "<nodeId>:<ip>" => last-seen unix ts, TTL 300s
 *
 * Two index sets keep the read paths proportional to the number of users that
 * actually have devices instead of the whole user base:
 *   user_devices_index   uids with at least one device field (any node)
 *   node_devices:<nid>   uids with device fields reported by node <nid>
 *
 * Before the indexes existed every sync.devices fan-out iterated *all* users
 * of a node and issued one HGETALL each (and getNodeDevices used Redis::KEYS),
 * which blocked the single-threaded Workerman WS worker for seconds at a time
 * and starved WebSocket handshakes. All reads below are O(active users) and
 * use a single pipeline round-trip.
 */
class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const INDEX_KEY = 'user_devices_index';
    private const NODE_INDEX_PREFIX = 'node_devices:';
    private const TTL = 300;                     // device state ttl
    private const NOTIFY_CHUNK = 500;            // rows per bulk online_count update

    private static function nodeIndexKey(int $nodeId): string
    {
        return self::NODE_INDEX_PREFIX . $nodeId;
    }

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     *
     * Only this node's fields are replaced; other nodes' entries for the same
     * user are preserved. $previousIps lets the WS hot path skip the HKEYS
     * round-trip when the caller already knows the previous IPs for this node.
     * Pass $notify=false when the caller batches the online_count write-back
     * with notifyUpdates().
     */
    public function setDevices(int $userId, int $nodeId, array $ips, bool $notify = true, ?array $previousIps = null): void
    {
        $key = self::PREFIX . $userId;
        $nodeKey = self::nodeIndexKey($nodeId);
        $prefix = "{$nodeId}:";
        $timestamp = time();

        // Normalize: strip port suffix and deduplicate
        $ips = array_values(array_unique(array_filter(
            array_map([self::class, 'normalizeIP'], $ips),
            static fn (string $ip): bool => $ip !== ''
        )));

        if ($previousIps !== null) {
            $toDelete = array_map(static fn (string $ip): string => $prefix . $ip, $previousIps);
        } else {
            $toDelete = array_values(array_filter(
                Redis::hkeys($key),
                static fn (string $field): bool => str_starts_with($field, $prefix)
            ));
        }

        $fields = [];
        foreach ($ips as $ip) {
            $fields[$prefix . $ip] = $timestamp;
        }

        // One round-trip per user. HDEL removes the hash automatically when the
        // last field is deleted; stale entries in the fleet index are pruned by
        // getActiveDevices() on the next read.
        $this->pipeline(function ($pipe) use ($key, $nodeKey, $userId, $toDelete, $fields) {
            if (!empty($toDelete)) {
                $pipe->hdel($key, ...$toDelete);
            }
            if (!empty($fields)) {
                $pipe->hmset($key, $fields);
                $pipe->expire($key, self::TTL);
                $pipe->sadd(self::INDEX_KEY, $userId);
                $pipe->sadd($nodeKey, $userId);
            } else {
                $pipe->srem($nodeKey, $userId);
            }
        });

        if ($notify) {
            $this->notifyUpdates([$userId]);
        }
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     *
     * Uses the per-node index set; no Redis::KEYS scan.
     */
    public function getNodeDevices(int $nodeId): array
    {
        $nodeKey = self::nodeIndexKey($nodeId);
        $uids = array_values(array_unique(array_map('intval', (array) Redis::smembers($nodeKey))));
        if (empty($uids)) {
            return [];
        }

        $hashes = $this->hgetallMany($uids);
        $now = time();
        $prefix = "{$nodeId}:";
        $result = [];
        $staleGlobal = [];
        $staleNode = [];

        foreach ($uids as $uid) {
            $data = $hashes[$uid];
            if (empty($data)) {
                // Hash expired/never written — self-heal both indexes.
                $staleGlobal[] = $uid;
                $staleNode[] = $uid;
                continue;
            }

            $ips = [];
            $foreignFields = 0;
            foreach ($data as $field => $ts) {
                if (!str_starts_with((string) $field, $prefix)) {
                    $foreignFields++;
                    continue;
                }
                if ($now - (int) $ts <= self::TTL) {
                    $ips[] = substr((string) $field, strlen($prefix));
                }
            }

            if (!empty($ips)) {
                $result[$uid] = array_values(array_unique($ips));
            } else {
                // No live device for this node — drop it from the node index.
                $staleNode[] = $uid;
                if ($foreignFields === 0) {
                    // Nothing left for any other node either.
                    $staleGlobal[] = $uid;
                }
            }
        }

        if (!empty($staleNode)) {
            Redis::srem($nodeKey, ...$staleNode);
        }
        if (!empty($staleGlobal)) {
            Redis::srem(self::INDEX_KEY, ...$staleGlobal);
        }

        return $result;
    }

    /**
     * Fleet-wide snapshot used for sync.devices in both WS and REST paths:
     * only users that actually have device fields are read (one pipeline),
     * not the node's whole user list.
     *
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getActiveDevices(): array
    {
        $uids = array_values(array_unique(array_map('intval', (array) Redis::smembers(self::INDEX_KEY))));
        if (empty($uids)) {
            return [];
        }

        $hashes = $this->hgetallMany($uids);
        $now = time();
        $result = [];
        $stale = [];

        foreach ($uids as $uid) {
            $data = $hashes[$uid];
            if (empty($data)) {
                $stale[] = $uid;
                continue;
            }

            $ips = [];
            foreach ($data as $field => $ts) {
                if ($now - (int) $ts > self::TTL) {
                    continue;
                }
                $pos = strpos((string) $field, ':');
                if ($pos === false) {
                    continue;
                }
                $ips[] = substr((string) $field, $pos + 1);
            }

            if (!empty($ips)) {
                $result[$uid] = array_values(array_unique($ips));
            } else {
                // All fields expired — prune the index entry.
                $stale[] = $uid;
            }
        }

        if (!empty($stale)) {
            Redis::srem(self::INDEX_KEY, ...$stale);
        }

        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $nodeKey = self::nodeIndexKey($nodeId);
        $prefix = "{$nodeId}:";

        $toDelete = array_values(array_filter(
            Redis::hkeys($key),
            static fn (string $field): bool => str_starts_with($field, $prefix)
        ));

        // Stale fleet-index entries are pruned lazily by getActiveDevices().
        $this->pipeline(function ($pipe) use ($key, $nodeKey, $userId, $toDelete) {
            if (!empty($toDelete)) {
                $pipe->hdel($key, ...$toDelete);
            }
            $pipe->srem($nodeKey, $userId);
        });
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     * Returns the affected user IDs so the caller can batch online_count writes.
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $nodeKey = self::nodeIndexKey($nodeId);
        $uids = array_values(array_unique(array_map('intval', (array) Redis::smembers($nodeKey))));

        if (empty($uids)) {
            Redis::del($nodeKey);

            return [];
        }

        $hashes = $this->hgetallMany($uids);
        $prefix = "{$nodeId}:";
        $affected = [];
        $toDelete = [];
        $toDrop = [];

        foreach ($uids as $uid) {
            $data = $hashes[$uid];
            $del = [];
            foreach ($data as $field => $ts) {
                if (str_starts_with((string) $field, $prefix)) {
                    $del[] = $field;
                }
            }
            if (!empty($del)) {
                $affected[] = $uid;
                $toDelete[$uid] = $del;
            }
            if (count($data) - count($del) <= 0) {
                // No devices from any node left — delete the hash and index entry.
                $toDrop[] = $uid;
            }
        }

        // One pipeline for all deletes instead of one round-trip per user.
        $this->pipeline(function ($pipe) use ($toDelete, $toDrop, $nodeKey) {
            foreach ($toDelete as $uid => $fields) {
                $pipe->hdel(self::PREFIX . $uid, ...$fields);
            }
            foreach ($toDrop as $uid) {
                $pipe->del(self::PREFIX . $uid);
                $pipe->srem(self::INDEX_KEY, $uid);
            }
            $pipe->del($nodeKey);
        });

        return $affected;
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        return $this->getDeviceCounts([$userId])[$userId] ?? 0;
    }

    /**
     * Batch variant of getDeviceCount() — one pipeline for all users.
     *
     * @param  int[]  $userIds
     * @return array<int, int>
     */
    public function getDeviceCounts(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $hashes = $this->hgetallMany($userIds);
        $now = time();
        $result = [];

        foreach ($userIds as $uid) {
            $ips = [];
            foreach ($hashes[$uid] as $field => $ts) {
                if ($now - (int) $ts > self::TTL) {
                    continue;
                }
                $pos = strpos((string) $field, ':');
                if ($pos === false) {
                    continue;
                }
                $ips[] = substr((string) $field, $pos + 1);
            }
            $result[$uid] = count(array_unique($ips));
        }

        return $result;
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $counts = $this->getDeviceCounts($users->pluck('id')->all());

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $hashes = $this->hgetallMany($userIds);
        $now = time();
        $result = [];

        foreach ($userIds as $uid) {
            $ips = [];
            foreach ($hashes[$uid] as $field => $ts) {
                if ($now - (int) $ts > self::TTL) {
                    continue;
                }
                $pos = strpos((string) $field, ':');
                if ($pos === false) {
                    continue;
                }
                $ips[] = substr((string) $field, $pos + 1);
            }
            if (!empty($ips)) {
                $result[$uid] = array_values(array_unique($ips));
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * One round-trip HGETALL for many user hashes.
     *
     * @param  int[]  $userIds
     * @return array<int, array<string, int|string>>
     */
    private function hgetallMany(array $userIds): array
    {
        $keys = array_map(static fn (int $uid): string => self::PREFIX . $uid, $userIds);
        $rows = $this->pipelineHgetall($keys);

        $result = [];
        foreach ($userIds as $index => $uid) {
            $row = $rows[$index] ?? [];
            $result[$uid] = is_array($row) ? $row : [];
        }

        return $result;
    }

    /**
     * Queue commands and execute them in one round-trip.
     *
     * Uses the underlying phpredis client directly because the facade's
     * pipeline() signature is not exposed by the Connection contract.
     */
    private function pipeline(callable $queue): void
    {
        $pipeline = Redis::connection()->client()->pipeline();
        $queue($pipeline);
        $pipeline->exec();
    }

    /**
     * HGETALL many keys in one pipeline round-trip.
     *
     * @param  string[]  $keys
     * @return array<int, mixed>
     */
    private function pipelineHgetall(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $pipeline = Redis::connection()->client()->pipeline();
        foreach ($keys as $key) {
            $pipeline->hgetall($key);
        }
        $rows = $pipeline->exec();

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * notify update (throttle control) — single-user compatibility wrapper.
     */
    public function notifyUpdate(int $userId): void
    {
        $this->notifyUpdates([$userId]);
    }

    /**
     * Write online_count / last_online_at for many users with one query per
     * chunk. The old code issued one UPDATE (plus one HGETALL) per user, which
     * made a device report for N users cost 2N Redis round-trips + N SQL
     * writes on the single WS worker.
     *
     * @param  int[]  $userIds
     */
    public function notifyUpdates(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if (empty($userIds)) {
            return;
        }

        $counts = $this->getDeviceCounts($userIds);
        $table = (new User())->getTable();
        $now = now()->toDateTimeString();

        foreach (array_chunk($counts, self::NOTIFY_CHUNK, true) as $chunk) {
            $cases = [];
            $bindings = [];
            $ids = [];
            foreach ($chunk as $id => $count) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = (int) $count;
                $ids[] = $id;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE {$table} SET online_count = CASE id "
                . implode(' ', $cases)
                . " ELSE online_count END, last_online_at = ? WHERE id IN ({$placeholders})";

            DB::update($sql, array_merge($bindings, [$now], $ids));
        }
    }
}
