<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/** File storage via server key (`storage:read` / `storage:write` scopes). */
class Storage
{
    public function __construct(private readonly HttpClient $http) {}

    /** Multipart upload (field `file`). Returns the StorageObject array. */
    public function upload(string $bucket, string $filePath, array $opts = []): array
    {
        $fields = [];
        if (isset($opts['key'])) {
            $fields['key'] = $opts['key'];
        }
        if (isset($opts['visibility'])) {
            $fields['visibility'] = $opts['visibility'];
        }
        $res = $this->http->upload(
            '/api/v1/storage/'.rawurlencode($bucket).'/upload',
            $filePath,
            $fields,
            $opts,
        );

        return $res['data'];
    }

    /** Full signed-URL download link (signature is the bearer; URL expires). */
    public function signedUrl(string $bucket, string $key, int $expiresInSeconds = 3600): string
    {
        $res = $this->http->post(
            '/api/v1/storage/'.rawurlencode($bucket).'/signed-url',
            ['key' => $key, 'expires_in' => $expiresInSeconds],
        );

        return $res['data']['url'];
    }

    public function remove(string $bucket, string $key): void
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $this->http->delete('/api/v1/storage/'.rawurlencode($bucket).'/'.$encoded);
    }
}
