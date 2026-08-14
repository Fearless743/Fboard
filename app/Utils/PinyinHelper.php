<?php

namespace App\Utils;

use Overtrue\Pinyin\Pinyin;

class PinyinHelper
{
    private static ?Pinyin $pinyin = null;

    protected static function pinyin(): Pinyin
    {
        if (self::$pinyin === null) {
            self::$pinyin = app(Pinyin::class);
        }
        return self::$pinyin;
    }

    /**
     * 将中文文本转换为拼音索引字符串（全拼无空格 + 首字母缩写）
     *
     * 用于存储到数据库 pinyin_index 列，支持 LIKE 模糊匹配。
     * 非中文/英文/数字原样保留。
     *
     * 例：
     *   toIndex('服务器')         → "fuwuqi fwq"
     *   toIndex('服务器维护通知')   → "fuwuqiweihutongzhi fwqwhtz"
     *   toIndex('hello world')    → "hello-world hw"
     *   toIndex('服务器ABC节点')   → "fuwuqiABCjiedian fwqABCjd"
     *
     * @param string $text
     * @return string
     */
    public static function toIndex(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $pinyin = self::pinyin();

        // 全拼（无音调，用连字符分隔词）
        $full = $pinyin->permalink($text);  // e.g. "fu-wu-qi"
        // 移除非字母数字（保留连字符用于分隔，但最终要去掉）
        $full = str_replace('-', '', $full); // e.g. "fuwuqi"

        // 首字母缩写
        $abbr = $pinyin->abbr($text);       // e.g. "f w q"
        $abbr = str_replace(' ', '', $abbr); // e.g. "fwq"

        // 如果全拼和缩写相同（纯非中文短文本），只返回一个
        if ($full === $abbr) {
            return $full;
        }

        return $full . ' ' . $abbr;
    }
}