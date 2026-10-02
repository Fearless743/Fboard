<?php

namespace App\Support;

use Exception;
use ZipArchive;

/**
 * ZIP 解压安全封装。
 *
 * PHP 的 ZipArchive::extractTo 不会校验压缩包内条目的路径，攻击者可构造
 * 含 `../` 或绝对路径的条目实现 Zip Slip，把文件写出到目标目录之外。
 * 这里在解压前逐条校验，拒绝逃逸条目。
 */
class SafeZip
{
    /**
     * 校验条目路径后安全解压。
     *
     * @throws Exception 当压缩包包含绝对路径或 `..` 逃逸条目时
     */
    public static function extractTo(ZipArchive $zip, string $targetDir): bool
    {
        self::assertSafeEntries($zip);

        return $zip->extractTo($targetDir);
    }

    /**
     * 逐条校验压缩包条目路径。
     *
     * @throws Exception
     */
    public static function assertSafeEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }

            // 统一分隔符，兼容 Windows 生成的压缩包
            $normalized = str_replace('\\', '/', $name);

            if ($normalized[0] === '/') {
                throw new Exception('压缩包包含绝对路径条目：' . $name);
            }

            // Windows 盘符 / UNC 绝对路径
            if (preg_match('#^[A-Za-z]:#', $normalized)) {
                throw new Exception('压缩包包含绝对路径条目：' . $name);
            }

            // 任意目录穿越片段
            if (preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
                throw new Exception('压缩包包含路径穿越条目：' . $name);
            }

            // 空字节注入
            if (str_contains($normalized, "\0")) {
                throw new Exception('压缩包包含非法条目名：' . $name);
            }
        }
    }
}
