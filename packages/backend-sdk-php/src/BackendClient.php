<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/**
 * Server-to-server client for the Laravel Backend Platform (API v1).
 *
 * Uses a SERVER-ONLY API key (scopes: read:data, write:data, storage:*,
 * functions:invoke). Never expose this client — or its key — to browsers,
 * mobile apps, or version control. User-token flows (login/logout) are
 * intentionally absent here; see the JS/Dart SDKs for those.
 *
 *   $backend = new BackendClient('https://api.example.com', getenv('API_SECRET_KEY'), 'acme');
 *   $health = $backend->health();            // ['ok' => true, ...]
 *   $res = $backend->functions()->invoke('hello-platform', ['name' => 'Ada']);
 */
class BackendClient
{
    public readonly HttpClient $http;
    public readonly HttpClient $functionsHttp;
    public readonly ?string $projectSlug;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?string $projectSlug = null,
        ?string $functionsBaseUrl = null,
        string $apiKeyPlacement = 'bearer',
        int $timeoutMs = 15000,
        ?callable $transport = null,
    ) {
        $this->http = new HttpClient($baseUrl, $apiKey, $apiKeyPlacement, $timeoutMs, $transport);
        $this->functionsHttp = new HttpClient($functionsBaseUrl ?? $baseUrl, $apiKey, $apiKeyPlacement, $timeoutMs, $transport);
        $this->projectSlug = $projectSlug;
    }

    public function setApiKey(?string $key): void
    {
        $this->http->apiKey = $key;
        $this->functionsHttp->apiKey = $key;
    }

    /** GET /api/health — never throws auth errors. */
    public function health(): array
    {
        return $this->http->get('/api/health')['data'];
    }

    public function functions(): Functions
    {
        return new Functions($this->functionsHttp, $this->projectSlug);
    }

    public function storage(): Storage
    {
        return new Storage($this->http);
    }

    /** Contract query helper: ?filter[x]=&search=&sort=&page=&per_page= */
    public static function qs(
        ?int $page = null,
        ?int $perPage = null,
        ?string $search = null,
        string|array|null $sort = null,
        ?array $filter = null,
    ): array {
        $out = [];
        if ($page !== null) {
            $out['page'] = $page;
        }
        if ($perPage !== null) {
            $out['per_page'] = $perPage;
        }
        if ($search !== null && $search !== '') {
            $out['search'] = $search;
        }
        if ($sort !== null) {
            $out['sort'] = is_array($sort) ? implode(',', $sort) : $sort;
        }
        foreach ($filter ?? [] as $k => $v) {
            $out["filter[{$k}]"] = $v;
        }

        return $out;
    }
}
