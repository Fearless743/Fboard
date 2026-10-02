<?php

namespace Tests\Unit\Support;

use App\Support\SafeZip;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class SafeZipTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            } elseif (File::exists($path)) {
                File::delete($path);
            }
        }
        $this->cleanup = [];

        parent::tearDown();
    }

    public function test_extracts_safe_archive(): void
    {
        [$zipPath, $target] = $this->makeZip([
            'plugin/config.json' => '{}',
            'plugin/Plugin.php' => '<?php',
        ]);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertTrue(SafeZip::extractTo($zip, $target));
        $zip->close();

        $this->assertFileExists($target . '/plugin/config.json');
        $this->assertSame('{}', file_get_contents($target . '/plugin/config.json'));
    }

    public function test_rejects_path_traversal_entry(): void
    {
        [$zipPath, $target] = $this->makeZip([
            '../evil.txt' => 'pwned',
            'ok.txt' => 'fine',
        ]);
        File::ensureDirectoryExists($target);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $thrown = false;
        try {
            SafeZip::extractTo($zip, $target);
        } catch (\Exception $e) {
            $thrown = true;
        }
        $zip->close();

        $this->assertTrue($thrown, 'traversal entry must be rejected');
        $this->assertFileDoesNotExist($target . '/../evil.txt');
        $this->assertFileDoesNotExist(dirname($target) . '/evil.txt');
    }

    public function test_rejects_absolute_path_entry(): void
    {
        [$zipPath, $target] = $this->makeZip([
            '/tmp/safezip-evil.txt' => 'pwned',
        ]);
        File::ensureDirectoryExists($target);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $thrown = false;
        try {
            SafeZip::extractTo($zip, $target);
        } catch (\Exception $e) {
            $thrown = true;
        }
        $zip->close();

        $this->assertTrue($thrown, 'absolute entry must be rejected');
    }

    /**
     * @param  array<string, string>  $entries
     * @return array{0: string, 1: string} [zipPath, targetDir]
     */
    private function makeZip(array $entries): array
    {
        $base = sys_get_temp_dir() . '/safezip-' . uniqid();
        File::ensureDirectoryExists($base);
        $this->cleanup[] = $base;

        $zipPath = $base . '/test.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return [$zipPath, $base . '/out'];
    }
}
