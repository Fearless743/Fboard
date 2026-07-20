<?php

namespace Tests\Unit\Utils;

use App\Support\SudokuKey;
use PHPUnit\Framework\TestCase;

class SudokuHttpMaskPathRootTest extends TestCase
{
    public function test_normalize_accepts_plain_segment(): void
    {
        $this->assertSame('aabbcc', SudokuKey::normalizeHttpMaskPathRoot('aabbcc'));
        $this->assertSame('A_b-9', SudokuKey::normalizeHttpMaskPathRoot('A_b-9'));
    }

    public function test_normalize_strips_surrounding_slashes(): void
    {
        $this->assertSame('aabbcc', SudokuKey::normalizeHttpMaskPathRoot('/aabbcc/'));
        $this->assertSame('aabbcc', SudokuKey::normalizeHttpMaskPathRoot('aabbcc/'));
        $this->assertSame('aabbcc', SudokuKey::normalizeHttpMaskPathRoot('/aabbcc'));
    }

    public function test_normalize_rejects_dot_and_multi_segment(): void
    {
        // mihomo: invalid http-mask-path-root: contains invalid character '.'
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('api.v1'));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('/api/v1'));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('a/b'));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('foo.bar'));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('path with space'));
    }

    public function test_normalize_empty_is_null(): void
    {
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot(null));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot(''));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('   '));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('/'));
        $this->assertNull(SudokuKey::normalizeHttpMaskPathRoot('//'));
    }
}
