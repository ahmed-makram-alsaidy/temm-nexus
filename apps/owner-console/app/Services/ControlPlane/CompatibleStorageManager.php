<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Storage Studio adapter for a project's managed Supabase-compatible runtime. */
class CompatibleStorageManager extends ProjectStorageManager
{
    public function __construct(Project $project, private string $endpoint, private string $credential)
    {
        parent::__construct($project, '');
    }

    public static function forProject(Project $project): self
    {
        $environment = EnvironmentContext::active($project);
        $secret = ProjectSecret::query()->where('project_id', $project->id)
            ->where('environment_id', $environment->id)->where('status', 'active')
            ->where('name', 'SUPABASE_SERVICE_ROLE_KEY')->first();
        $endpoint = rtrim((string) $environment->api_base_url, '/');
        abort_unless(str_starts_with($endpoint, 'https://') && $secret, 503, 'Storage is not configured for this environment.');

        return new self($project, $endpoint.'/storage/v1', $secret->value);
    }

    public function root(): string
    {
        abort(422, 'Managed storage does not expose local filesystem paths.');
    }

    public function resolve(string $relative, bool $mustExist = true): string
    {
        abort(422, 'Managed storage does not expose local filesystem paths.');
    }

    public function buckets(): array
    {
        $buckets = $this->request('GET', '/bucket')->json();
        $out = [];
        foreach ($buckets as $bucket) {
            $files = 0;
            $bytes = 0;
            foreach ($this->objects($bucket['id']) as $file) {
                $files++;
                $bytes += $file['size'] ?? 0;
            }
            $out[] = ['name' => $bucket['id'], 'files' => $files, 'bytes' => $bytes,
                'visibility' => ($bucket['public'] ?? false) ? 'public' : 'private'];
        }

        return $out;
    }

    public function files(string $bucket, string $prefix = ''): array
    {
        $bucket = $this->path($bucket, false);
        $prefix = $this->path($prefix);
        $out = [];
        $offset = 0;
        do {
            $rows = $this->request('POST', '/object/list/'.rawurlencode($bucket), [
                'prefix' => $prefix, 'limit' => 1000, 'offset' => $offset,
                'sortBy' => ['column' => 'name', 'order' => 'asc'],
            ])->json();
            foreach ($rows as $row) {
                $folder = ($row['id'] ?? null) === null;
                $out[] = ['name' => $row['name'], 'path' => ltrim($prefix.'/'.$row['name'], '/'),
                    'type' => $folder ? 'folder' : 'file',
                    'size' => $folder ? null : (isset($row['metadata']['size']) ? (int) $row['metadata']['size'] : null),
                    'mime' => $row['metadata']['mimetype'] ?? null, 'modified' => $row['updated_at'] ?? null];
            }
            $offset += count($rows);
        } while (count($rows) === 1000);

        return $out;
    }

    /** @return \Generator<array> */
    private function objects(string $bucket, string $prefix = ''): \Generator
    {
        foreach ($this->files($bucket, $prefix) as $file) {
            if ($file['type'] === 'folder') {
                yield from $this->objects($bucket, $file['path']);
            } else {
                yield $file;
            }
        }
    }

    public function createBucket(string $name, string $visibility = 'private'): void
    {
        abort_unless(preg_match(self::FILENAME_PATTERN, $name) === 1, 422, 'Invalid bucket name.');
        $this->request('POST', '/bucket', ['id' => $name, 'name' => $name, 'public' => $visibility === 'public']);
    }

    public function setVisibility(string $bucket, string $visibility): void
    {
        $this->request('PUT', '/bucket/'.rawurlencode($this->path($bucket, false)), ['public' => $visibility === 'public']);
    }

    public function deleteBucket(string $name): void
    {
        $bucket = rawurlencode($this->path($name, false));
        $this->request('POST', '/bucket/'.$bucket.'/empty', []);
        $this->request('DELETE', '/bucket/'.$bucket);
    }

    public function read(string $bucket, string $key): array
    {
        $response = $this->request('GET', '/object/authenticated/'.$this->objectPath($bucket, $key));

        return ['contents' => $response->body(), 'mime' => $response->header('Content-Type') ?: 'application/octet-stream',
            'size' => strlen($response->body()), 'name' => basename($key)];
    }

    public function store(string $bucket, string $prefix, string $filename, string $contents): string
    {
        abort_unless(preg_match(self::FILENAME_PATTERN, $filename) === 1, 422, 'Invalid filename.');
        abort_if(strlen($contents) > self::MAX_UPLOAD_BYTES, 422, 'File exceeds 24 MB limit.');
        $key = ltrim($this->path($prefix).'/'.$filename, '/');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        $this->request('POST', '/object/'.$this->objectPath($bucket, $key), null, $contents, $mime);

        return $key;
    }

    public function move(string $bucket, string $from, string $toFolder, ?string $newName = null): string
    {
        $bucket = $this->path($bucket, false);
        $from = $this->path($from);
        $name = $newName ?? basename($from);
        abort_unless(preg_match(self::FILENAME_PATTERN, $name) === 1, 422, 'Invalid filename.');
        $destination = ltrim($this->path($toFolder).'/'.$name, '/');
        $this->request('POST', '/object/move', ['bucketId' => $bucket, 'sourceKey' => $from, 'destinationKey' => $destination]);

        return $destination;
    }

    public function delete(string $bucket, string $key): void
    {
        $bucket = $this->path($bucket, false);
        $key = $this->path($key);
        $this->request('DELETE', '/object/'.$this->objectPath($bucket, $key));
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->buckets(), 'bytes'));
    }

    private function objectPath(string $bucket, string $key): string
    {
        abort_if($key === '', 422, 'An object key is required.');

        return rawurlencode($this->path($bucket, false)).'/'.implode('/', array_map('rawurlencode', explode('/', $this->path($key))));
    }

    private function path(string $value, bool $nested = true): string
    {
        abort_if(str_contains($value, "\0") || str_contains($value, '\\'), 422, 'Invalid storage path.');
        $parts = explode('/', trim($value, '/'));
        abort_if(in_array('..', $parts, true) || in_array('.', $parts, true), 422, 'Path traversal blocked.');
        abort_if(! $nested && (count($parts) !== 1 || $parts[0] === ''), 422, 'Invalid bucket name.');

        return implode('/', $parts);
    }

    private function request(string $method, string $path, ?array $json = null, ?string $body = null, string $mime = 'application/octet-stream'): Response
    {
        $request = Http::withToken($this->credential)->withHeaders(['apikey' => $this->credential])
            ->connectTimeout(5)->timeout(30)->withoutRedirecting();
        try {
            $response = $body !== null
                ? $request->withBody($body, $mime)->send($method, $this->endpoint.$path)
                : $request->send($method, $this->endpoint.$path, $json === null ? [] : ['json' => $json]);
        } catch (ConnectionException) {
            abort(503, 'Storage is temporarily unavailable.');
        }
        if (! $response->successful()) {
            $status = (int) ($response->json('statusCode') ?? $response->status());
            abort(in_array($status, [404, 409, 413, 422], true) ? $status : 503, 'Storage operation could not be completed.');
        }

        return $response;
    }
}
