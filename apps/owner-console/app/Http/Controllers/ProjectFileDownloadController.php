<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated file downloads from project storage.
 * Owner-only, project-confined, traversal-guarded. Downloads are audit logged.
 */
class ProjectFileDownloadController extends Controller
{
    public function __invoke(Request $request, string $project, string $bucket): StreamedResponse
    {
        $owner = $request->user();
        abort_unless($owner && (bool) ($owner->is_admin ?? false), 403);

        $record = Project::where('slug', $project)->firstOrFail();
        $key = (string) $request->route('key');
        $manager = ProjectStorageManager::for($record);

        try {
            $file = $manager->read($bucket, $key);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            abort($e->getStatusCode());
        }

        AdminAudit::record('STORAGE_FILE_DOWNLOADED', $record, 'file', $bucket.'/'.$key);

        return response()->streamDownload(function () use ($file) {
            echo $file['contents'];
        }, $file['name'], ['Content-Type' => $file['mime']]);
    }
}
