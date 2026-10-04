<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\AgentChangesetService;
use PHPUnit\Framework\TestCase;

class AgentChangesetServiceTest extends TestCase
{
    protected const SAMPLE_DIFF = <<<'DIFF'
diff --git a/src/greeting.php b/src/greeting.php
new file mode 100644
--- /dev/null
+++ b/src/greeting.php
@@ -0,0 +1,1 @@
+echo 'HELLO TEMM';
diff --git a/tests/greeting_test.php b/tests/greeting_test.php
--- a/tests/greeting_test.php
+++ b/tests/greeting_test.php
@@ -1,1 +1,1 @@
-assert('HELLO');
+assert('HELLO TEMM');
DIFF;

    public function test_changed_paths_are_extracted(): void
    {
        $paths = AgentChangesetService::changedPaths(self::SAMPLE_DIFF);

        $this->assertSame(['src/greeting.php', 'tests/greeting_test.php'], $paths);
    }

    public function test_summary_counts_files_and_lines(): void
    {
        [$added, $modified, $deleted, $additions, $deletions] = AgentChangesetService::summarize(self::SAMPLE_DIFF);

        $this->assertSame(1, $added);
        $this->assertSame(1, $modified);
        $this->assertSame(0, $deleted);
        $this->assertSame(2, $additions);
        $this->assertSame(1, $deletions);
    }

    public function test_fingerprint_is_stable_for_identical_content(): void
    {
        $base = 'abc123';
        $one = hash('sha256', $base.'|'.self::SAMPLE_DIFF);
        $two = hash('sha256', $base.'|'.self::SAMPLE_DIFF);

        $this->assertSame($one, $two);
        $this->assertNotSame($one, hash('sha256', 'different-rev|'.self::SAMPLE_DIFF));
        $this->assertNotSame($one, hash('sha256', $base.'|'.self::SAMPLE_DIFF.' '));
    }

    public function test_empty_diff_yields_zeroes(): void
    {
        [$added, $modified, $deleted, $additions, $deletions] = AgentChangesetService::summarize('');

        $this->assertSame([0, 0, 0, 0, 0], [$added, $modified, $deleted, $additions, $deletions]);
    }
}
