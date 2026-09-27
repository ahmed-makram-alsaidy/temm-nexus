<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Phase 20P local webhook test fixture (LOCAL env only, throttled).
 * Records the last receipt per token in cache so tests/GUI can assert
 * signature validity and retry behavior without any external service.
 */
class WebhookFixtureController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        // Local + testing only (automated proof runs as APP_ENV=testing).
        abort_unless(in_array(app()->environment(), ['local', 'testing'], true), 404);
        abort_unless(preg_match('/^[A-Za-z0-9]{8,64}$/', $token) === 1, 404);

        // SSRF redirect-proof fixture: redirects must NOT be followed.
        if ($request->query('redirect')) {
            return redirect()->away((string) $request->query('redirect'));
        }

        if ($request->query('fail') === '1') {
            return response()->json(['ok' => false], 500);
        }

        $body = $request->getContent();
        $secret = $request->query('secret', '');
        $sig = (string) $request->header('X-CP-Signature', '');
        $timestamp = (string) $request->header('X-CP-Timestamp', '');
        $valid = $secret !== '' && hash_equals(
            'sha256='.\App\Services\ControlPlane\WebhookService::sign($secret, $timestamp, $body),
            $sig
        );
        cache()->put("cp_fixture:{$token}", [
            'event' => $request->header('X-CP-Event'),
            'request_id' => $request->header('X-CP-Request-ID'),
            'signature_valid' => $valid,
            'body' => mb_substr($body, 0, 4000),
            'at' => now()->toDateTimeString(),
        ], 600);

        return response()->json(['ok' => true, 'signature_valid' => $valid]);
    }
}
