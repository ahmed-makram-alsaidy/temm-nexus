<?php

namespace Tests\Feature\Phase261;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/** Phase 26.1E — support bundle: creation + zero secret leakage. */
class SupportBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function bundle(string $target): string
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('platform:support-bundle', ['--output' => $target]);
        $this->assertSame(0, $exit, 'support bundle must build successfully');

        return $target;
    }

    protected function zipContents(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $contents[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $contents;
    }

    public function test_bundle_is_created_with_manifest_and_doctor(): void
    {
        $path = storage_path('app/private/support-bundles/test-bundle.zip');
        @unlink($path);
        $this->bundle($path);
        $this->assertFileExists($path);

        $contents = $this->zipContents($path);
        $this->assertArrayHasKey('manifest.json', $contents);
        $this->assertArrayHasKey('doctor.json', $contents);
        $manifest = json_decode($contents['manifest.json'], true);
        $this->assertSame(config('platform.version'), $manifest['platform_version']);
        @unlink($path);
    }

    public function test_bundle_never_leaks_planted_secrets(): void
    {
        // 26.1E.3 redaction drill: plant fake secrets where the bundle's
        // collectors could plausibly reach, then verify zero leakage.
        $fakeLog = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');
        @file_put_contents($fakeLog, implode(PHP_EOL, [
            '['.now()->toDateTimeString().'] production.ERROR: connection refused for DB_PASSWORD=PlantedFake!Secret42 {"token":"sk-test-PLANTEDFAKEKEY1234567890"}',
            '['.now()->toDateTimeString().'] production.ERROR: supabase PAT sbp_PLANTEDFAKEPAT123456789012345 rejected',
        ]).PHP_EOL);

        $path = storage_path('app/private/support-bundles/test-redaction.zip');
        @unlink($path);
        $this->bundle($path);

        $contents = $this->zipContents($path);
        foreach ($contents as $name => $content) {
            $this->assertStringNotContainsString('PlantedFake!Secret42', $content, "planted secret leaked in {$name}");
            $this->assertStringNotContainsString('sk-test-PLANTEDFAKEKEY', $content, "planted token leaked in {$name}");
            $this->assertStringNotContainsString('sbp_PLANTEDFAKEPAT', $content, "planted PAT leaked in {$name}");
        }

        // Platform secret material must never appear either.
        $appKey = (string) config('app.key');
        if ($appKey !== '') {
            foreach ($contents as $name => $content) {
                $this->assertStringNotContainsString($appKey, $content, "APP_KEY leaked in {$name}");
            }
        }

        @unlink($fakeLog);
        @unlink($path);
    }
}
