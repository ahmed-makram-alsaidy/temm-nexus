<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class TemplateProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $marker = 'probe') {}

    public function handle(): void
    {
        // Proof-of-queue: worker reached here. Namespaced by CACHE_PREFIX.
        Cache::put("queue-probe:{$this->marker}", now()->toIso8601String(), 600);
    }
}
