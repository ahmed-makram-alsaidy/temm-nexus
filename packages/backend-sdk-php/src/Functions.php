<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/** Server functions: POST /f/{project}/{function} on the functions host. */
class Functions
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly ?string $projectSlug,
    ) {}

    private function path(string $slug): string
    {
        if ($this->projectSlug === null || $this->projectSlug === '') {
            throw new \LogicException('Functions not configured: pass $projectSlug to BackendClient.');
        }

        return '/f/'.rawurlencode($this->projectSlug).'/'.rawurlencode($slug);
    }

    /**
     * @return array{data:mixed,status:int,requestId:string,durationMs:int,version:?int}
     */
    public function invoke(string $slug, mixed $body = [], array $opts = []): array
    {
        $started = (int) (microtime(true) * 1000);
        $method = strtoupper($opts['method'] ?? 'POST');
        $req = [
            'query' => $opts['query'] ?? [],
            'headers' => $opts['headers'] ?? [],
            'body' => $method === 'GET' ? null : $body,
        ];
        if (array_key_exists('timeoutMs', $opts)) {
            $req['timeoutMs'] = $opts['timeoutMs'];
        }
        if (array_key_exists('apiKey', $opts)) {
            $req['apiKey'] = $opts['apiKey'];
        }
        $res = $this->http->request($method, $this->path($slug), $req);
        $data = $res['data'];
        $version = is_array($data) && isset($data['version']) && is_int($data['version']) ? $data['version'] : null;

        return [
            'data' => $data,
            'status' => $res['status'],
            'requestId' => $res['requestId'],
            'durationMs' => (int) (microtime(true) * 1000) - $started,
            'version' => $version,
        ];
    }
}
