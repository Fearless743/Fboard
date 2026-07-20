<?php

namespace App\Support;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;

class Setting
{
    const CACHE_KEY = 'admin_settings';

    private Repository $cache;
    private ?array $loadedSettings = null; // 请求内缓存

    public function __construct()
    {
        // 跟随应用默认缓存驱动：生产多为 redis，测试为 array
        $this->cache = Cache::store();
    }

    /**
     * 获取配置.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if ($this->loadedSettings === null) {
            return $default;
        }

        return Arr::get($this->loadedSettings, strtolower($key), $default);
    }

    /**
     * 设置配置信息.
     */
    public function set(string $key, mixed $value = null): bool
    {
        SettingModel::createOrUpdate(strtolower($key), $value);
        $this->flush();
        return true;
    }

    /**
     * 保存配置到数据库.
     */
    public function save(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate(strtolower($key), $value);
        }
        $this->flush();
        return true;
    }

    /**
     * 删除配置信息
     */
    public function remove(string $key): bool
    {
        SettingModel::where('name', $key)->delete();
        $this->flush();
        return true;
    }

    /**
     * 更新单个设置项
     */
    public function update(string $key, $value): bool
    {
        return $this->set($key, $value);
    }

    /**
     * 批量获取配置项
     */
    public function getBatch(array $keys): array
    {
        $this->load();
        $result = [];

        foreach ($keys as $index => $item) {
            $isNumericIndex = is_numeric($index);
            $key = strtolower($isNumericIndex ? $item : $index);
            $default = $isNumericIndex ? config('v2board.' . $item) : (config('v2board.' . $key) ?? $item);

            $result[$item] = $this->loadedSettings === null
                ? $default
                : Arr::get($this->loadedSettings, $key, $default);
        }

        return $result;
    }

    /**
     * 将所有设置转换为数组
     */
    public function toArray(): array
    {
        $this->load();
        return $this->loadedSettings ?? [];
    }

    /**
     * 加载配置到请求内缓存
     */
    private function load(): void
    {
        if ($this->loadedSettings !== null) {
            return;
        }

        try {
            $settings = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
                return array_change_key_case(
                    SettingModel::pluck('value', 'name')->toArray(),
                    CASE_LOWER
                );
            });

            if (!is_array($settings)) {
                $settings = [];
            }

            // 处理JSON格式的值
            foreach ($settings as $key => $value) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $settings[$key] = $decoded;
                    }
                }
            }

            $this->loadedSettings = $settings;
        } catch (\Throwable) {
            // 启动早期（路由注册时迁移尚未完成等）勿锁死空结果，便于后续重试
            $this->loadedSettings = null;
        }
    }

    /**
     * 清空缓存
     */
    private function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->loadedSettings = null;
    }
}
