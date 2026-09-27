<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 20L signed downloads: HMAC-signed, expiring URLs generated from the
 * Storage Studio. No session needed — the signature IS the bearer (like S3
 * pre-signed URLs). Traversal still guarded by the storage manager.
 */
class SignedDownloadController extends Controller
{
    public const MAX_TTL_SECONDS = 86400;

    public static function sign(Project $project, string $bucket, string $key, int $expires): string
    {
        $payload = implode('|', [$project->slug, $bucket, $key, $expires]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public static function url(Project $project, string $bucket, string $key, int $ttlSeconds): string
    {
        $ttlSeconds = min(max(60, $ttlSeconds), self::MAX_TTL_SECONDS);
        $expires = time() + $ttlSeconds;

        return route('control-plane.signed-download', [
            'project' => $project->id, 'bucket' => $bucket, 'key' => $key,
            'expires' => $expires, 'sig' => self::sign($project, $bucket, $key, $expires),
        ]);
    }

    public function __invoke(Request $request, Project $project, string $bucket): StreamedResponse
    {
        $key = (string) $request->route('key');
        $expires = (int) $request->query('expires', 0);
        $sig = (string) $request->query('sig', '');
        abort_if($expires < time(), 403, 'Link expired.');
        abort_unless(hash_equals(self::sign($project, $bucket, $key, $expires), $sig), 403, 'Bad signature.');

        $manager = ProjectStorageManager::for($project);
        try {
            $file = $manager->read($bucket, $key);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            abort($e->getStatusCode());
        }

        AdminAudit::record('STORAGE_FILE_DOWNLOADED', $project, 'file', $bucket.'/'.$key, ['via' => 'signed-url']);

        return response()->streamDownload(function () use ($file) {
            echo $file['contents'];
        }, $file['name'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, max-age=60']);
    }
}
